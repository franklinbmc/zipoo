<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/vat_lib.php';
require_once dirname(__DIR__) . '/vendor/autoload.php';

use Dompdf\Dompdf;
use Dompdf\Options;
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception as MailException;

session_start();

function respond(int $status, array $payload): void
{
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    http_response_code($status);
    echo json_encode($payload);
    exit;
}

function require_user(): int
{
    $userId = (int) ($_SESSION['zipoo_user_id'] ?? 0);
    if ($userId <= 0) {
        respond(401, ['ok' => false, 'message' => 'Please login first.']);
    }

    return $userId;
}

function get_active_business(PDO $pdo, int $userId): array
{
    $businessId = isset($_SESSION['zipoo_business_id']) ? (int) $_SESSION['zipoo_business_id'] : 0;
    if ($businessId > 0) {
        $stmt = $pdo->prepare('SELECT * FROM tbl_businesses WHERE id = :bid AND (owner_user_id = :uid OR id = :check_bid) LIMIT 1');
        $stmt->execute([':bid' => $businessId, ':uid' => $userId, ':check_bid' => $businessId]);
        $b = $stmt->fetch();
        if ($b) {
            return $b;
        }
    }

    $stmt = $pdo->prepare('SELECT business_id FROM tbl_users WHERE id = :uid LIMIT 1');
    $stmt->execute([':uid' => $userId]);
    $userBid = (int) ($stmt->fetchColumn() ?: 0);
    if ($userBid > 0) {
        $stmt = $pdo->prepare('SELECT * FROM tbl_businesses WHERE id = :bid LIMIT 1');
        $stmt->execute([':bid' => $userBid]);
        $b = $stmt->fetch();
        if ($b) {
            $_SESSION['zipoo_business_id'] = $userBid;
            return $b;
        }
    }

    $stmt = $pdo->prepare('SELECT * FROM tbl_businesses WHERE owner_user_id = :uid ORDER BY id ASC LIMIT 1');
    $stmt->execute([':uid' => $userId]);
    $first = $stmt->fetch();
    if ($first) {
        $_SESSION['zipoo_business_id'] = (int) $first['id'];
        return $first;
    }

    return [];
}

function load_group_settings(PDO $pdo, int $businessId, string $group): array
{
    if ($businessId <= 0) {
        return [];
    }

    try {
        $stmt = $pdo->prepare(
            'SELECT setting_key, setting_value
             FROM tbl_business_settings
             WHERE business_id = :bid AND setting_group = :grp'
        );
        $stmt->execute([':bid' => $businessId, ':grp' => $group]);
        $rows = $stmt->fetchAll();
    } catch (Throwable $e) {
        // Table not created yet (settings never saved) — treat as unconfigured.
        return [];
    }
    $out = [];
    foreach ($rows as $r) {
        $out[$r['setting_key']] = $r['setting_value'];
    }
    return $out;
}

function generate_po_pdf_html(array $po, array $supplier, array $items, array $biz): string
{
    $currency = (string) ($biz['currency'] ?? 'TZS');
    $poNumber = htmlspecialchars((string) ($po['po_number'] ?? ''));
    $orderDate = htmlspecialchars((string) ($po['order_date'] ?? ''));
    $expectedDate = htmlspecialchars((string) ($po['expected_date'] ?? ''));
    $bizName = htmlspecialchars((string) ($biz['business_name'] ?? 'Zipoo Business'));
    $bizType = htmlspecialchars((string) ($biz['business_type'] ?? ''));
    $supplierName = htmlspecialchars((string) ($supplier['supplier_name'] ?? ''));
    $supplierPhone = htmlspecialchars((string) ($supplier['phone'] ?? ''));
    $supplierEmail = htmlspecialchars((string) ($supplier['email'] ?? ''));
    $supplierAddress = htmlspecialchars((string) ($supplier['address'] ?? ''));
    $supplierTin = htmlspecialchars((string) ($supplier['tin'] ?? ''));
    $supplierVrn = htmlspecialchars((string) ($supplier['vrn'] ?? ''));
    $bizTin = htmlspecialchars((string) ($biz['tin'] ?? ''));
    $bizVrn = htmlspecialchars((string) ($biz['vrn'] ?? ''));
    $notes = htmlspecialchars((string) ($po['notes'] ?? ''));
    $footerNote = htmlspecialchars((string) ($biz['receipt_footer'] ?? 'Thank you for doing business with us!'));

    $subtotal = (float) ($po['subtotal'] ?? 0);
    $taxRate = (float) ($po['tax_rate'] ?? 0);
    $taxAmount = (float) ($po['tax_amount'] ?? 0);
    $totalAmount = (float) ($po['total_amount'] ?? 0);

    $itemsHtml = '';
    $i = 1;
    foreach ($items as $item) {
        $name = htmlspecialchars((string) ($item['item_name'] ?? ''));
        $qty = number_format((float) ($item['quantity'] ?? 0), 2);
        $cost = number_format((float) ($item['unit_cost'] ?? 0), 0);
        $total = number_format((float) ($item['line_total'] ?? 0), 0);

        $itemsHtml .= "
        <tr>
            <td style='text-align: center; border-bottom: 1px solid #e2e8f0; padding: 8px 6px;'>{$i}</td>
            <td style='border-bottom: 1px solid #e2e8f0; padding: 8px 10px;'>{$name}</td>
            <td style='text-align: right; border-bottom: 1px solid #e2e8f0; padding: 8px 10px;'>{$qty}</td>
            <td style='text-align: right; border-bottom: 1px solid #e2e8f0; padding: 8px 10px;'>{$cost} {$currency}</td>
            <td style='text-align: right; border-bottom: 1px solid #e2e8f0; padding: 8px 10px; font-weight: bold;'>{$total} {$currency}</td>
        </tr>";
        $i++;
    }

    return "
    <!DOCTYPE html>
    <html>
    <head>
        <meta charset='utf-8'>
        <style>
            body { font-family: Helvetica, Arial, sans-serif; color: #1e293b; font-size: 13px; line-height: 1.5; margin: 0; padding: 24px; }
            .header { border-bottom: 2px solid #0e74db; padding-bottom: 16px; margin-bottom: 24px; }
            .biz-title { font-size: 24px; font-weight: bold; color: #0e74db; margin: 0; }
            .po-title { font-size: 20px; font-weight: bold; color: #0f172a; text-align: right; margin: 0; }
            .badge { display: inline-block; padding: 3px 8px; border-radius: 4px; font-size: 11px; font-weight: bold; background: #e0f2fe; color: #0369a1; }
            .grid { width: 100%; margin-bottom: 24px; }
            .grid td { vertical-align: top; }
            .box { background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 6px; padding: 12px; }
            .box-title { font-size: 11px; text-transform: uppercase; color: #64748b; font-weight: bold; margin-bottom: 6px; }
            table.items { width: 100%; border-collapse: collapse; margin-bottom: 20px; }
            table.items th { background: #0e74db; color: #ffffff; text-align: left; padding: 8px 10px; font-size: 12px; }
            .totals { width: 45%; margin-left: auto; border-collapse: collapse; }
            .totals td { padding: 6px 10px; }
            .grand-total { font-size: 16px; font-weight: bold; color: #0e74db; border-top: 2px solid #0e74db; }
            .footer { margin-top: 40px; border-top: 1px solid #e2e8f0; padding-top: 12px; font-size: 11px; color: #64748b; text-align: center; }
        </style>
    </head>
    <body>
        <table class='header' style='width: 100%;'>
            <tr>
                <td>
                    <h1 class='biz-title'>{$bizName}</h1>
                    <div style='color: #64748b; font-size: 12px;'>{$bizType}</div>
                    " . ($bizTin ? "<div style='color: #64748b; font-size: 12px;'>TIN: {$bizTin}</div>" : "") . "
                    " . ($bizVrn ? "<div style='color: #64748b; font-size: 12px;'>VRN: {$bizVrn}</div>" : "") . "
                </td>
                <td style='text-align: right;'>
                    <h2 class='po-title'>PURCHASE ORDER</h2>
                    <div style='font-size: 15px; font-weight: bold; color: #0e74db; margin-top: 4px;'>{$poNumber}</div>
                    <div style='color: #64748b; font-size: 12px; margin-top: 4px;'>Date: {$orderDate}</div>
                    " . ($expectedDate ? "<div style='color: #64748b; font-size: 12px;'>Expected: {$expectedDate}</div>" : "") . "
                </td>
            </tr>
        </table>

        <table class='grid'>
            <tr>
                <td style='width: 50%; padding-right: 12px;'>
                    <div class='box'>
                        <div class='box-title'>Vendor / Supplier</div>
                        <div style='font-size: 14px; font-weight: bold; color: #0f172a;'>{$supplierName}</div>
                        " . ($supplierPhone ? "<div>Phone: {$supplierPhone}</div>" : "") . "
                        " . ($supplierEmail ? "<div>Email: {$supplierEmail}</div>" : "") . "
                        " . ($supplierAddress ? "<div>Address: {$supplierAddress}</div>" : "") . "
                        " . ($supplierTin ? "<div>TIN: {$supplierTin}</div>" : "") . "
                        " . ($supplierVrn ? "<div>VRN: {$supplierVrn}</div>" : "") . "
                    </div>
                </td>
                <td style='width: 50%; padding-left: 12px;'>
                    <div class='box'>
                        <div class='box-title'>Ship / Deliver To</div>
                        <div style='font-size: 14px; font-weight: bold; color: #0f172a;'>{$bizName}</div>
                        <div>Attn: Purchasing Department</div>
                        <div>Status: <span class='badge'>" . strtoupper((string) ($po['status'] ?? 'DRAFT')) . "</span></div>
                    </div>
                </td>
            </tr>
        </table>

        <table class='items'>
            <thead>
                <tr>
                    <th style='width: 30px; text-align: center;'>#</th>
                    <th>Item Description</th>
                    <th style='width: 80px; text-align: right;'>Qty</th>
                    <th style='width: 120px; text-align: right;'>Unit Cost</th>
                    <th style='width: 130px; text-align: right;'>Line Total</th>
                </tr>
            </thead>
            <tbody>
                {$itemsHtml}
            </tbody>
        </table>

        <table style='width: 100%;'>
            <tr>
                <td style='vertical-align: top; width: 55%; padding-right: 20px;'>
                    " . ($notes ? "
                    <div class='box' style='background: #fff; border-color: #cbd5e1;'>
                        <div class='box-title'>Notes / Terms</div>
                        <div style='font-size: 12px;'>{$notes}</div>
                    </div>" : "") . "
                </td>
                <td style='vertical-align: top; width: 45%;'>
                    <table class='totals'>
                        <tr>
                            <td>Subtotal:</td>
                            <td style='text-align: right;'>" . number_format($subtotal, 0) . " {$currency}</td>
                        </tr>
                        " . ($taxRate > 0 ? "
                        <tr>
                            <td>Tax ({$taxRate}%):</td>
                            <td style='text-align: right;'>" . number_format($taxAmount, 0) . " {$currency}</td>
                        </tr>" : "") . "
                        <tr class='grand-total'>
                            <td>Total Amount:</td>
                            <td style='text-align: right;'>" . number_format($totalAmount, 0) . " {$currency}</td>
                        </tr>
                    </table>
                </td>
            </tr>
        </table>

        <div class='footer'>
            <div>{$footerNote}</div>
            <div style='margin-top: 4px;'>Generated by Zipoo POS &bull; " . date('Y-m-d H:i:s') . "</div>
        </div>
    </body>
    </html>
    ";
}

function render_po_pdf(array $po, array $supplier, array $items, array $biz): string
{
    $html = generate_po_pdf_html($po, $supplier, $items, $biz);
    $options = new Options();
    $options->set('isHtml5ParserEnabled', true);
    $options->set('isRemoteEnabled', true);
    $options->set('defaultFont', 'Helvetica');

    $dompdf = new Dompdf($options);
    $dompdf->loadHtml($html);
    $dompdf->setPaper('A4', 'portrait');
    $dompdf->render();

    return $dompdf->output();
}

try {
    $userId = require_user();
    $pdo = db();
    $biz = get_active_business($pdo, $userId);

    if (empty($biz)) {
        respond(400, ['ok' => false, 'message' => 'No active business selected.']);
    }

    $businessId = (int) $biz['id'];

    ensure_vat_columns($pdo);

    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $poId = isset($_GET['id']) ? (int) $_GET['id'] : 0;
        $isPdf = isset($_GET['action']) && $_GET['action'] === 'pdf';

        if ($poId > 0) {
            $stmt = $pdo->prepare(
                'SELECT po.*, s.supplier_name, s.phone as supplier_phone, s.email as supplier_email, s.address as supplier_address,
                        s.tin as supplier_tin, s.vrn as supplier_vrn
                 FROM tbl_purchase_orders po
                 LEFT JOIN tbl_suppliers s ON s.id = po.supplier_id
                 WHERE po.id = :id AND po.business_id = :bid LIMIT 1'
            );
            $stmt->execute([':id' => $poId, ':bid' => $businessId]);
            $po = $stmt->fetch();

            if (!$po) {
                respond(404, ['ok' => false, 'message' => 'Purchase order not found.']);
            }

            // Fetch items
            $itemStmt = $pdo->prepare('SELECT * FROM tbl_purchase_order_items WHERE purchase_order_id = :id ORDER BY id ASC');
            $itemStmt->execute([':id' => $poId]);
            $items = $itemStmt->fetchAll();

            $supplier = [
                'supplier_name' => $po['supplier_name'],
                'phone' => $po['supplier_phone'],
                'email' => $po['supplier_email'],
                'address' => $po['supplier_address'],
                'tin' => $po['supplier_tin'] ?? '',
                'vrn' => $po['supplier_vrn'] ?? '',
            ];

            if ($isPdf) {
                $pdfData = render_po_pdf($po, $supplier, $items, $biz);
                $filename = 'PO-' . preg_replace('/[^a-zA-Z0-9_-]/', '', (string)$po['po_number']) . '.pdf';
                header('Content-Type: application/pdf');
                header('Content-Disposition: inline; filename="' . $filename . '"');
                header('Content-Length: ' . strlen($pdfData));
                echo $pdfData;
                exit;
            }

            // Fetch receiving log if any
            $grStmt = $pdo->prepare(
                'SELECT gr.*, u.full_name as received_by_name
                 FROM tbl_goods_received gr
                 LEFT JOIN tbl_users u ON u.id = gr.created_by
                 WHERE gr.purchase_order_id = :id AND gr.business_id = :bid
                 ORDER BY gr.id DESC'
            );
            $grStmt->execute([':id' => $poId, ':bid' => $businessId]);
            $goodsReceived = $grStmt->fetchAll();

            respond(200, [
                'ok' => true,
                'po' => $po,
                'items' => $items,
                'supplier' => $supplier,
                'goods_received' => $goodsReceived,
            ]);
        }

        $status = trim((string) ($_GET['status'] ?? ''));
        $search = trim((string) ($_GET['q'] ?? ''));

        $conditions = ['po.business_id = :bid'];
        $params = [':bid' => $businessId];

        if ($status !== '' && $status !== 'all') {
            $conditions[] = 'po.status = :status';
            $params[':status'] = $status;
        }

        if ($search !== '') {
            $conditions[] = '(po.po_number LIKE :q OR s.supplier_name LIKE :q)';
            $params[':q'] = '%' . $search . '%';
        }

        $whereSql = implode(' AND ', $conditions);
        $stmt = $pdo->prepare(
            "SELECT po.*, s.supplier_name, s.email as supplier_email,
                    (SELECT COUNT(*) FROM tbl_purchase_order_items WHERE purchase_order_id = po.id) as items_count
             FROM tbl_purchase_orders po
             LEFT JOIN tbl_suppliers s ON s.id = po.supplier_id
             WHERE {$whereSql}
             ORDER BY po.id DESC"
        );
        $stmt->execute($params);
        $rows = $stmt->fetchAll();

        // Get aggregate statistics
        $statsStmt = $pdo->prepare(
            "SELECT 
                COUNT(*) as total_orders,
                SUM(CASE WHEN status = 'draft' THEN 1 ELSE 0 END) as draft_count,
                SUM(CASE WHEN status = 'sent' THEN 1 ELSE 0 END) as sent_count,
                SUM(CASE WHEN status = 'partially_received' THEN 1 ELSE 0 END) as partial_count,
                SUM(CASE WHEN status = 'received' THEN 1 ELSE 0 END) as received_count,
                SUM(total_amount) as total_purchased
             FROM tbl_purchase_orders
             WHERE business_id = :bid"
        );
        $statsStmt->execute([':bid' => $businessId]);
        $stats = $statsStmt->fetch() ?: [];

        respond(200, [
            'ok' => true,
            'orders' => $rows,
            'stats' => [
                'total_orders' => (int) ($stats['total_orders'] ?? 0),
                'draft' => (int) ($stats['draft_count'] ?? 0),
                'sent' => (int) ($stats['sent_count'] ?? 0),
                'partial' => (int) ($stats['partial_count'] ?? 0),
                'received' => (int) ($stats['received_count'] ?? 0),
                'total_purchased' => (float) ($stats['total_purchased'] ?? 0),
            ],
        ]);
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $action = trim((string) ($_POST['action'] ?? ''));

        if ($action === 'create_po') {
            $supplierId = (int) ($_POST['supplier_id'] ?? 0);
            $orderDate = trim((string) ($_POST['order_date'] ?? date('Y-m-d')));
            $expectedDate = trim((string) ($_POST['expected_date'] ?? ''));
            $notes = trim((string) ($_POST['notes'] ?? ''));
            // VAT is automatic: rate comes from business settings, applied only to VAT-applicable items.
            $vatEnabled = (int) ($biz['vat_enabled'] ?? 0) === 1;
            $taxRate = $vatEnabled ? (float) ($biz['tax_rate'] ?? 0) : 0.0;
            $itemsRaw = $_POST['items'] ?? '[]';
            $items = is_string($itemsRaw) ? json_decode($itemsRaw, true) : $itemsRaw;

            if ($supplierId <= 0) {
                respond(422, ['ok' => false, 'message' => 'Please select a supplier.']);
            }

            if (!is_array($items) || empty($items)) {
                respond(422, ['ok' => false, 'message' => 'Please add at least one line item to the order.']);
            }

            // Verify supplier exists
            $sCheck = $pdo->prepare('SELECT id, supplier_name FROM tbl_suppliers WHERE id = :id AND business_id = :bid LIMIT 1');
            $sCheck->execute([':id' => $supplierId, ':bid' => $businessId]);
            $supplier = $sCheck->fetch();
            if (!$supplier) {
                respond(404, ['ok' => false, 'message' => 'Supplier not found.']);
            }

            // Calculate totals
            $subtotal = 0.0;
            $vatBase = 0.0;
            $validatedItems = [];
            foreach ($items as $item) {
                $itemId = (int) ($item['item_id'] ?? 0);
                $qty = (float) ($item['quantity'] ?? 1);
                $cost = (float) ($item['unit_cost'] ?? 0);

                if ($itemId <= 0 || $qty <= 0) {
                    continue;
                }

                $iStmt = $pdo->prepare('SELECT id, name, vat_applicable FROM tbl_items WHERE id = :id AND business_id = :bid LIMIT 1');
                $iStmt->execute([':id' => $itemId, ':bid' => $businessId]);
                $iRow = $iStmt->fetch();
                if (!$iRow) {
                    continue;
                }

                $lineTotal = $qty * $cost;
                $subtotal += $lineTotal;
                $lineVat = ($vatEnabled && (int) ($iRow['vat_applicable'] ?? 1) === 1) ? 1 : 0;
                if ($lineVat === 1) {
                    $vatBase += $lineTotal;
                }

                $validatedItems[] = [
                    'item_id' => $itemId,
                    'item_name' => (string) $iRow['name'],
                    'quantity' => $qty,
                    'unit_cost' => $cost,
                    'line_total' => $lineTotal,
                    'vat_applicable' => $lineVat,
                ];
            }

            if (empty($validatedItems)) {
                respond(422, ['ok' => false, 'message' => 'No valid items provided in the order.']);
            }

            $taxAmount = round(($vatBase * $taxRate) / 100, 2);
            $totalAmount = $subtotal + $taxAmount;

            // Generate PO Number
            $countStmt = $pdo->prepare('SELECT COUNT(*) FROM tbl_purchase_orders WHERE business_id = :bid');
            $countStmt->execute([':bid' => $businessId]);
            $seq = ((int) $countStmt->fetchColumn()) + 1;
            $poNumber = 'PO-' . date('Ym') . '-' . str_pad((string)$seq, 4, '0', STR_PAD_LEFT);

            // Insert PO
            $pdo->beginTransaction();
            try {
                $poStmt = $pdo->prepare(
                    'INSERT INTO tbl_purchase_orders
                     (business_id, po_number, supplier_id, order_date, expected_date, status, subtotal, tax_rate, tax_amount, total_amount, notes, created_by)
                     VALUES (:bid, :po_num, :sid, :odate, :edate, "draft", :subtotal, :trate, :tamt, :totamt, :notes, :uid)'
                );
                $poStmt->execute([
                    ':bid' => $businessId,
                    ':po_num' => $poNumber,
                    ':sid' => $supplierId,
                    ':odate' => $orderDate,
                    ':edate' => $expectedDate !== '' ? $expectedDate : null,
                    ':subtotal' => $subtotal,
                    ':trate' => $taxRate,
                    ':tamt' => $taxAmount,
                    ':totamt' => $totalAmount,
                    ':notes' => $notes !== '' ? $notes : null,
                    ':uid' => $userId,
                ]);

                $poId = (int) $pdo->lastInsertId();

                $poiStmt = $pdo->prepare(
                    'INSERT INTO tbl_purchase_order_items
                     (purchase_order_id, item_id, item_name, quantity, unit_cost, line_total, vat_applicable, received_quantity)
                     VALUES (:poid, :item_id, :item_name, :qty, :cost, :line_total, :vat, 0.00)'
                );

                foreach ($validatedItems as $v) {
                    $poiStmt->execute([
                        ':poid' => $poId,
                        ':item_id' => $v['item_id'],
                        ':item_name' => $v['item_name'],
                        ':qty' => $v['quantity'],
                        ':cost' => $v['unit_cost'],
                        ':line_total' => $v['line_total'],
                        ':vat' => $v['vat_applicable'],
                    ]);
                }

                $pdo->commit();

                respond(201, [
                    'ok' => true,
                    'message' => "Purchase order {$poNumber} created successfully.",
                    'po_id' => $poId,
                    'po_number' => $poNumber,
                ]);
            } catch (Throwable $e) {
                $pdo->rollBack();
                throw $e;
            }
        }

        if ($action === 'email_po') {
            $poId = (int) ($_POST['po_id'] ?? 0);
            $recipientEmail = trim((string) ($_POST['email'] ?? ''));

            if ($poId <= 0) {
                respond(422, ['ok' => false, 'message' => 'Invalid Purchase Order ID.']);
            }

            $stmt = $pdo->prepare(
                'SELECT po.*, s.supplier_name, s.phone as supplier_phone, s.email as supplier_email, s.address as supplier_address,
                        s.tin as supplier_tin, s.vrn as supplier_vrn
                 FROM tbl_purchase_orders po
                 LEFT JOIN tbl_suppliers s ON s.id = po.supplier_id
                 WHERE po.id = :id AND po.business_id = :bid LIMIT 1'
            );
            $stmt->execute([':id' => $poId, ':bid' => $businessId]);
            $po = $stmt->fetch();
            if (!$po) {
                respond(404, ['ok' => false, 'message' => 'Purchase order not found.']);
            }

            $emailTo = $recipientEmail !== '' ? $recipientEmail : (string) ($po['supplier_email'] ?? '');
            if (!filter_var($emailTo, FILTER_VALIDATE_EMAIL)) {
                respond(422, ['ok' => false, 'message' => 'Supplier does not have a valid email address. Please enter an email.']);
            }

            // Fetch items
            $itemStmt = $pdo->prepare('SELECT * FROM tbl_purchase_order_items WHERE purchase_order_id = :id ORDER BY id ASC');
            $itemStmt->execute([':id' => $poId]);
            $items = $itemStmt->fetchAll();

            $supplier = [
                'supplier_name' => $po['supplier_name'],
                'phone' => $po['supplier_phone'],
                'email' => $po['supplier_email'],
                'address' => $po['supplier_address'],
                'tin' => $po['supplier_tin'] ?? '',
                'vrn' => $po['supplier_vrn'] ?? '',
            ];

            // Render PDF
            $pdfBytes = render_po_pdf($po, $supplier, $items, $biz);
            $pdfFilename = 'Purchase_Order_' . $po['po_number'] . '.pdf';

            // Send via PHPMailer
            $smtpSettings = load_group_settings($pdo, $businessId, 'smtp');
            if (empty($smtpSettings['smtp_host']) || empty($smtpSettings['from_email'])) {
                respond(422, ['ok' => false, 'message' => 'SMTP is not configured yet. Please configure SMTP in Settings > System Settings.']);
            }

            $mail = new PHPMailer(true);
            try {
                $mail->isSMTP();
                $mail->Host = $smtpSettings['smtp_host'];
                $mail->Port = (int) ($smtpSettings['smtp_port'] ?? 587);
                $mail->CharSet = 'UTF-8';
                $mail->Timeout = 15;

                $encryption = $smtpSettings['smtp_encryption'] ?? 'tls';
                if ($encryption === 'ssl') {
                    $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
                } elseif ($encryption === 'tls') {
                    $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
                } else {
                    $mail->SMTPSecure = false;
                    $mail->SMTPAutoTLS = false;
                }

                if (!empty($smtpSettings['smtp_username'])) {
                    $mail->SMTPAuth = true;
                    $mail->Username = $smtpSettings['smtp_username'];
                    $mail->Password = $smtpSettings['smtp_password'] ?? '';
                }

                $bizName = (string) ($biz['business_name'] ?? 'Zipoo Business');
                $mail->setFrom($smtpSettings['from_email'], $bizName);
                $mail->addAddress($emailTo, (string)$supplier['supplier_name']);
                $mail->addStringAttachment($pdfBytes, $pdfFilename, 'base64', 'application/pdf');

                $mail->Subject = "Purchase Order {$po['po_number']} from {$bizName}";
                $mail->Body = "Dear " . ($supplier['supplier_name'] ?: 'Vendor') . ",\n\nPlease find attached Purchase Order {$po['po_number']} from {$bizName}.\n\nTotal Order Amount: " . number_format((float)$po['total_amount'], 0) . " " . ($biz['currency'] ?? 'TZS') . "\nOrder Date: " . $po['order_date'] . "\n\nPlease review and confirm receipt.\n\nBest regards,\n{$bizName}";

                $mail->send();

                // If status was draft, mark sent
                if ($po['status'] === 'draft') {
                    $pdo->prepare('UPDATE tbl_purchase_orders SET status = "sent" WHERE id = :id')->execute([':id' => $poId]);
                }

                respond(200, [
                    'ok' => true,
                    'message' => "Purchase Order PDF has been emailed successfully to {$emailTo}.",
                ]);
            } catch (MailException $e) {
                respond(500, ['ok' => false, 'message' => 'Failed to send email: ' . ($mail->ErrorInfo ?: $e->getMessage())]);
            }
        }

        if ($action === 'receive_goods') {
            $poId = (int) ($_POST['po_id'] ?? 0);
            $receivedDate = trim((string) ($_POST['received_date'] ?? date('Y-m-d')));
            $notes = trim((string) ($_POST['notes'] ?? ''));
            $itemsRaw = $_POST['items'] ?? '[]';
            $items = is_string($itemsRaw) ? json_decode($itemsRaw, true) : $itemsRaw;

            if ($poId <= 0) {
                respond(422, ['ok' => false, 'message' => 'Invalid Purchase Order ID.']);
            }

            if (!is_array($items) || empty($items)) {
                respond(422, ['ok' => false, 'message' => 'Please provide the items being received.']);
            }

            // Verify PO exists
            $poStmt = $pdo->prepare('SELECT * FROM tbl_purchase_orders WHERE id = :id AND business_id = :bid LIMIT 1');
            $poStmt->execute([':id' => $poId, ':bid' => $businessId]);
            $po = $poStmt->fetch();
            if (!$po) {
                respond(404, ['ok' => false, 'message' => 'Purchase order not found.']);
            }

            if ($po['status'] === 'received') {
                respond(422, ['ok' => false, 'message' => 'This purchase order has already been fully received.']);
            }
            if ($po['status'] === 'cancelled') {
                respond(422, ['ok' => false, 'message' => 'Cannot receive goods for a cancelled purchase order.']);
            }

            // Generate GRN Number
            $grnCountStmt = $pdo->prepare('SELECT COUNT(*) FROM tbl_goods_received WHERE business_id = :bid');
            $grnCountStmt->execute([':bid' => $businessId]);
            $grnSeq = ((int) $grnCountStmt->fetchColumn()) + 1;
            $grnNumber = 'GRN-' . date('Ym') . '-' . str_pad((string)$grnSeq, 4, '0', STR_PAD_LEFT);

            $pdo->beginTransaction();
            try {
                // Insert GRN header
                $grStmt = $pdo->prepare(
                    'INSERT INTO tbl_goods_received
                     (business_id, purchase_order_id, grn_number, received_date, notes, created_by)
                     VALUES (:bid, :poid, :grn, :rdate, :notes, :uid)'
                );
                $grStmt->execute([
                    ':bid' => $businessId,
                    ':poid' => $poId,
                    ':grn' => $grnNumber,
                    ':rdate' => $receivedDate,
                    ':notes' => $notes !== '' ? $notes : null,
                    ':uid' => $userId,
                ]);
                $grnId = (int) $pdo->lastInsertId();

                $griStmt = $pdo->prepare(
                    'INSERT INTO tbl_goods_received_items
                     (goods_received_id, po_item_id, item_id, quantity_received, unit_cost)
                     VALUES (:grid, :poi_id, :item_id, :qty, :cost)'
                );

                $uItemStmt = $pdo->prepare('UPDATE tbl_items SET current_stock = current_stock + :qty WHERE id = :id AND business_id = :bid');
                $uPoiStmt = $pdo->prepare('UPDATE tbl_purchase_order_items SET received_quantity = received_quantity + :qty WHERE id = :poi_id');

                $mStmt = $pdo->prepare(
                    'INSERT INTO tbl_stock_movements
                     (business_id, item_id, movement_type, quantity, previous_stock, new_stock, unit_cost, reference_type, reference_id, notes, created_by)
                     VALUES (:bid, :item_id, "purchase_receive", :qty, :prev_stock, :new_stock, :cost, "PO", :ref_id, :notes, :uid)'
                );

                $totalItemsReceived = 0;

                foreach ($items as $item) {
                    $poiId = (int) ($item['po_item_id'] ?? 0);
                    $qtyReceived = (float) ($item['quantity_received'] ?? 0);

                    if ($poiId <= 0 || $qtyReceived <= 0) {
                        continue;
                    }

                    // Check PO line item
                    $poiCheck = $pdo->prepare('SELECT * FROM tbl_purchase_order_items WHERE id = :poi_id AND purchase_order_id = :poid LIMIT 1');
                    $poiCheck->execute([':poi_id' => $poiId, ':poid' => $poId]);
                    $poi = $poiCheck->fetch();
                    if (!$poi) {
                        continue;
                    }

                    $itemId = (int) $poi['item_id'];
                    $unitCost = (float) $poi['unit_cost'];

                    // Get current stock
                    $sStmt = $pdo->prepare('SELECT current_stock, type FROM tbl_items WHERE id = :id AND business_id = :bid LIMIT 1');
                    $sStmt->execute([':id' => $itemId, ':bid' => $businessId]);
                    $itemRow = $sStmt->fetch();

                    if (!$itemRow || $itemRow['type'] === 'service') {
                        // Services don't alter physical stock, but we still record PO item received
                        $uPoiStmt->execute([':qty' => $qtyReceived, ':poi_id' => $poiId]);
                        continue;
                    }

                    $prevStock = (float) $itemRow['current_stock'];
                    $newStock = $prevStock + $qtyReceived;

                    // Update PO item received quantity
                    $uPoiStmt->execute([':qty' => $qtyReceived, ':poi_id' => $poiId]);

                    // Increase item stock
                    $uItemStmt->execute([':qty' => $qtyReceived, ':id' => $itemId, ':bid' => $businessId]);

                    // Insert GRN line item
                    $griStmt->execute([
                        ':grid' => $grnId,
                        ':poi_id' => $poiId,
                        ':item_id' => $itemId,
                        ':qty' => $qtyReceived,
                        ':cost' => $unitCost,
                    ]);

                    // Insert stock movement record
                    $mStmt->execute([
                        ':bid' => $businessId,
                        ':item_id' => $itemId,
                        ':qty' => $qtyReceived,
                        ':prev_stock' => $prevStock,
                        ':new_stock' => $newStock,
                        ':cost' => $unitCost,
                        ':ref_id' => (string) $po['po_number'],
                        ':notes' => "Received under {$grnNumber} for PO {$po['po_number']}",
                        ':uid' => $userId,
                    ]);

                    $totalItemsReceived++;
                }

                if ($totalItemsReceived === 0) {
                    $pdo->rollBack();
                    respond(422, ['ok' => false, 'message' => 'No items were selected for receiving.']);
                }

                // Check overall PO status: check if all items are fully received
                $checkAllStmt = $pdo->prepare(
                    'SELECT 
                        SUM(CASE WHEN received_quantity < quantity THEN 1 ELSE 0 END) as remaining_count,
                        SUM(received_quantity) as total_received
                     FROM tbl_purchase_order_items
                     WHERE purchase_order_id = :id'
                );
                $checkAllStmt->execute([':id' => $poId]);
                $checkStatus = $checkAllStmt->fetch();

                $remaining = (int) ($checkStatus['remaining_count'] ?? 0);
                $totalRec = (float) ($checkStatus['total_received'] ?? 0);

                $newPoStatus = ($remaining === 0 && $totalRec > 0) ? 'received' : 'partially_received';
                $pdo->prepare('UPDATE tbl_purchase_orders SET status = :status WHERE id = :id')->execute([
                    ':status' => $newPoStatus,
                    ':id' => $poId,
                ]);

                $pdo->commit();

                respond(200, [
                    'ok' => true,
                    'message' => "Goods successfully received under {$grnNumber}. Inventory stock has been updated.",
                    'grn_number' => $grnNumber,
                    'po_status' => $newPoStatus,
                ]);
            } catch (Throwable $e) {
                $pdo->rollBack();
                throw $e;
            }
        }

        if ($action === 'cancel_po') {
            $poId = (int) ($_POST['po_id'] ?? 0);
            if ($poId <= 0) {
                respond(422, ['ok' => false, 'message' => 'Invalid Purchase Order ID.']);
            }

            $stmt = $pdo->prepare('SELECT id, status FROM tbl_purchase_orders WHERE id = :id AND business_id = :bid LIMIT 1');
            $stmt->execute([':id' => $poId, ':bid' => $businessId]);
            $po = $stmt->fetch();
            if (!$po) {
                respond(404, ['ok' => false, 'message' => 'Purchase order not found.']);
            }

            if ($po['status'] === 'received' || $po['status'] === 'partially_received') {
                respond(422, ['ok' => false, 'message' => 'Cannot cancel a purchase order that has already received goods.']);
            }

            $pdo->prepare('UPDATE tbl_purchase_orders SET status = "cancelled" WHERE id = :id')->execute([':id' => $poId]);

            respond(200, ['ok' => true, 'message' => 'Purchase order cancelled.']);
        }

        if ($action === 'uncancel_po') {
            $poId = (int) ($_POST['po_id'] ?? 0);
            if ($poId <= 0) {
                respond(422, ['ok' => false, 'message' => 'Invalid Purchase Order ID.']);
            }

            $stmt = $pdo->prepare('SELECT id, status FROM tbl_purchase_orders WHERE id = :id AND business_id = :bid LIMIT 1');
            $stmt->execute([':id' => $poId, ':bid' => $businessId]);
            $po = $stmt->fetch();
            if (!$po) {
                respond(404, ['ok' => false, 'message' => 'Purchase order not found.']);
            }

            if ($po['status'] !== 'cancelled') {
                respond(422, ['ok' => false, 'message' => 'Only a cancelled purchase order can be reactivated.']);
            }

            // Reactivate back to draft so it can be edited, sent or received again.
            $pdo->prepare('UPDATE tbl_purchase_orders SET status = "draft" WHERE id = :id')->execute([':id' => $poId]);

            respond(200, ['ok' => true, 'message' => 'Purchase order reactivated.']);
        }

        respond(422, ['ok' => false, 'message' => 'Unknown purchasing action.']);
    }
} catch (Throwable $e) {
    respond(500, ['ok' => false, 'message' => 'Unable to process purchasing: ' . $e->getMessage()]);
}
