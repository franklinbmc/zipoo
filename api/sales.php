<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/accounts_lib.php';
require_once __DIR__ . '/vat_lib.php';
require_once dirname(__DIR__) . '/vendor/autoload.php';

use Dompdf\Dompdf;
use Dompdf\Options;

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

function ensure_sales_tables(PDO $pdo): void
{
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS tbl_sales (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            business_id BIGINT UNSIGNED NOT NULL,
            sale_type ENUM("invoice", "pos") NOT NULL DEFAULT "invoice",
            invoice_number VARCHAR(50) NOT NULL,
            customer_id BIGINT UNSIGNED NULL,
            customer_name VARCHAR(190) NULL,
            status ENUM("draft", "sent", "paid", "overdue", "cancelled") NOT NULL DEFAULT "draft",
            issue_date DATE NOT NULL,
            due_date DATE NULL,
            subtotal DECIMAL(14, 2) NOT NULL DEFAULT 0.00,
            discount DECIMAL(14, 2) NOT NULL DEFAULT 0.00,
            tax_rate DECIMAL(6, 2) NOT NULL DEFAULT 0.00,
            tax_amount DECIMAL(14, 2) NOT NULL DEFAULT 0.00,
            total_amount DECIMAL(14, 2) NOT NULL DEFAULT 0.00,
            amount_paid DECIMAL(14, 2) NOT NULL DEFAULT 0.00,
            notes TEXT NULL,
            created_by BIGINT UNSIGNED NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_biz_invoice (business_id, invoice_number),
            KEY idx_biz_status (business_id, status),
            KEY idx_biz_type (business_id, sale_type),
            KEY idx_biz_issue (business_id, issue_date),
            KEY idx_customer (customer_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS tbl_sale_items (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            sale_id BIGINT UNSIGNED NOT NULL,
            item_id INT UNSIGNED NULL,
            item_name VARCHAR(190) NOT NULL,
            quantity DECIMAL(12, 2) NOT NULL DEFAULT 1.00,
            unit_price DECIMAL(14, 2) NOT NULL DEFAULT 0.00,
            line_total DECIMAL(14, 2) NOT NULL DEFAULT 0.00,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            KEY idx_sale (sale_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );
}

// Effective status: a "sent" invoice past its due date reads as "overdue".
function effective_status(array $row): string
{
    $status = (string) ($row['status'] ?? 'draft');
    if ($status === 'sent' && !empty($row['due_date'])) {
        if ($row['due_date'] < date('Y-m-d')) {
            return 'overdue';
        }
    }
    return $status;
}

function present_sale(array $row, int $itemsCount = 0): array
{
    return [
        'id' => (int) $row['id'],
        'invoice_number' => (string) $row['invoice_number'],
        'customer_id' => $row['customer_id'] !== null ? (int) $row['customer_id'] : null,
        'customer_name' => (string) ($row['customer_name'] ?? ''),
        'status' => (string) $row['status'],
        'effective_status' => effective_status($row),
        'issue_date' => (string) $row['issue_date'],
        'due_date' => $row['due_date'] !== null ? (string) $row['due_date'] : null,
        'subtotal' => (float) $row['subtotal'],
        'discount' => (float) $row['discount'],
        'tax_rate' => (float) $row['tax_rate'],
        'tax_amount' => (float) $row['tax_amount'],
        'total_amount' => (float) $row['total_amount'],
        'amount_paid' => (float) $row['amount_paid'],
        'balance_due' => (float) $row['total_amount'] - (float) $row['amount_paid'],
        'notes' => (string) ($row['notes'] ?? ''),
        'items_count' => $itemsCount,
        'created_at' => (string) ($row['created_at'] ?? ''),
    ];
}

function generate_invoice_pdf_html(array $sale, array $items, array $customer, array $biz): string
{
    $currency = (string) ($biz['currency'] ?? 'TZS');
    $invNumber = htmlspecialchars((string) ($sale['invoice_number'] ?? ''));
    $issueDate = htmlspecialchars((string) ($sale['issue_date'] ?? ''));
    $dueDate = htmlspecialchars((string) ($sale['due_date'] ?? ''));
    $status = strtoupper((string) ($sale['status'] ?? 'DRAFT'));

    $bizName = htmlspecialchars((string) ($biz['business_name'] ?? 'Zipoo Business'));
    $bizType = htmlspecialchars((string) ($biz['business_type'] ?? ''));
    $bizTin = htmlspecialchars((string) ($biz['tin'] ?? ''));
    $bizVrn = htmlspecialchars((string) ($biz['vrn'] ?? ''));
    $footerNote = htmlspecialchars((string) ($biz['receipt_footer'] ?? 'Thank you for your business!'));

    $custName = htmlspecialchars((string) ($customer['full_name'] ?? ($sale['customer_name'] ?? 'Walk-in Customer')));
    $custPhone = htmlspecialchars((string) ($customer['phone'] ?? ''));
    $custEmail = htmlspecialchars((string) ($customer['email'] ?? ''));
    $custAddress = htmlspecialchars((string) ($customer['address'] ?? ''));
    $custTin = htmlspecialchars((string) ($customer['tin'] ?? ''));
    $custVrn = htmlspecialchars((string) ($customer['vrn'] ?? ''));

    $subtotal = (float) ($sale['subtotal'] ?? 0);
    $discount = (float) ($sale['discount'] ?? 0);
    $taxRate = (float) ($sale['tax_rate'] ?? 0);
    $taxAmount = (float) ($sale['tax_amount'] ?? 0);
    $totalAmount = (float) ($sale['total_amount'] ?? 0);
    $notes = htmlspecialchars((string) ($sale['notes'] ?? ''));

    $itemsHtml = '';
    $i = 1;
    foreach ($items as $item) {
        $name = htmlspecialchars((string) ($item['item_name'] ?? ''));
        $qty = number_format((float) ($item['quantity'] ?? 0), 2);
        $price = number_format((float) ($item['unit_price'] ?? 0), 2);
        $total = number_format((float) ($item['line_total'] ?? 0), 2);
        $vatTag = ((int) ($item['vat_applicable'] ?? 0) === 1) ? " <span style='color:#64748b;font-size:10px;'>(VAT)</span>" : '';

        $itemsHtml .= "
        <tr>
            <td style='text-align: center; border-bottom: 1px solid #e2e8f0; padding: 8px 6px;'>{$i}</td>
            <td style='border-bottom: 1px solid #e2e8f0; padding: 8px 10px;'>{$name}{$vatTag}</td>
            <td style='text-align: right; border-bottom: 1px solid #e2e8f0; padding: 8px 10px;'>{$qty}</td>
            <td style='text-align: right; border-bottom: 1px solid #e2e8f0; padding: 8px 10px;'>{$price} {$currency}</td>
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
            .doc-title { font-size: 20px; font-weight: bold; color: #0f172a; text-align: right; margin: 0; }
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
                    <h2 class='doc-title'>" . ($taxRate > 0 ? "TAX INVOICE" : "INVOICE") . "</h2>
                    <div style='font-size: 15px; font-weight: bold; color: #0e74db; margin-top: 4px;'>{$invNumber}</div>
                    <div style='color: #64748b; font-size: 12px; margin-top: 4px;'>Issue Date: {$issueDate}</div>
                    " . ($dueDate ? "<div style='color: #64748b; font-size: 12px;'>Due: {$dueDate}</div>" : "") . "
                </td>
            </tr>
        </table>

        <table class='grid'>
            <tr>
                <td style='width: 50%; padding-right: 12px;'>
                    <div class='box'>
                        <div class='box-title'>Bill To</div>
                        <div style='font-size: 14px; font-weight: bold; color: #0f172a;'>{$custName}</div>
                        " . ($custPhone ? "<div>Phone: {$custPhone}</div>" : "") . "
                        " . ($custEmail ? "<div>Email: {$custEmail}</div>" : "") . "
                        " . ($custAddress ? "<div>Address: {$custAddress}</div>" : "") . "
                        " . ($custTin ? "<div>TIN: {$custTin}</div>" : "") . "
                        " . ($custVrn ? "<div>VRN: {$custVrn}</div>" : "") . "
                    </div>
                </td>
                <td style='width: 50%; padding-left: 12px;'>
                    <div class='box'>
                        <div class='box-title'>From</div>
                        <div style='font-size: 14px; font-weight: bold; color: #0f172a;'>{$bizName}</div>
                        " . ($bizTin ? "<div>TIN: {$bizTin}</div>" : "") . "
                        " . ($bizVrn ? "<div>VRN: {$bizVrn}</div>" : "") . "
                        <div>Status: <span class='badge'>{$status}</span></div>
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
                    <th style='width: 120px; text-align: right;'>Unit Price</th>
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
                        <div class='box-title'>Notes</div>
                        <div style='font-size: 12px;'>{$notes}</div>
                    </div>" : "") . "
                </td>
                <td style='vertical-align: top; width: 45%;'>
                    <table class='totals'>
                        <tr>
                            <td>Subtotal:</td>
                            <td style='text-align: right;'>" . number_format($subtotal, 2) . " {$currency}</td>
                        </tr>
                        " . ($discount > 0 ? "
                        <tr>
                            <td>Discount:</td>
                            <td style='text-align: right;'>-" . number_format($discount, 2) . " {$currency}</td>
                        </tr>" : "") . "
                        " . ($taxRate > 0 ? "
                        <tr>
                            <td>VAT ({$taxRate}%):</td>
                            <td style='text-align: right;'>" . number_format($taxAmount, 2) . " {$currency}</td>
                        </tr>" : "") . "
                        <tr class='grand-total'>
                            <td>Total:</td>
                            <td style='text-align: right;'>" . number_format($totalAmount, 2) . " {$currency}</td>
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

function render_invoice_pdf(array $sale, array $items, array $customer, array $biz): string
{
    $html = generate_invoice_pdf_html($sale, $items, $customer, $biz);
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
    $pdo = db();
    $userId = require_user();
    $biz = get_active_business($pdo, $userId);
    $businessId = (int) ($biz['id'] ?? 0);
    if ($businessId <= 0) {
        respond(422, ['ok' => false, 'message' => 'No active business selected.']);
    }

    ensure_sales_tables($pdo);
    ensure_accounts_tables($pdo);
    ensure_vat_columns($pdo);

    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        // Single invoice detail
        if (isset($_GET['id'])) {
            $id = (int) $_GET['id'];
            $stmt = $pdo->prepare('SELECT * FROM tbl_sales WHERE id = :id AND business_id = :bid LIMIT 1');
            $stmt->execute([':id' => $id, ':bid' => $businessId]);
            $sale = $stmt->fetch();
            if (!$sale) {
                respond(404, ['ok' => false, 'message' => 'Invoice not found.']);
            }

            $iStmt = $pdo->prepare('SELECT * FROM tbl_sale_items WHERE sale_id = :sid ORDER BY id ASC');
            $iStmt->execute([':sid' => $id]);
            $items = $iStmt->fetchAll();

            $customer = null;
            if (!empty($sale['customer_id'])) {
                $cStmt = $pdo->prepare('SELECT id, full_name, phone, email, address, tin, vrn FROM tbl_customers WHERE id = :cid AND business_id = :bid LIMIT 1');
                $cStmt->execute([':cid' => (int) $sale['customer_id'], ':bid' => $businessId]);
                $customer = $cStmt->fetch() ?: null;
            }

            // Printable invoice PDF
            if (($_GET['action'] ?? '') === 'pdf') {
                $pdfData = render_invoice_pdf($sale, $items, $customer ?: [], $biz);
                $filename = 'Invoice-' . preg_replace('/[^a-zA-Z0-9_-]/', '', (string) $sale['invoice_number']) . '.pdf';
                header('Content-Type: application/pdf');
                header('Content-Disposition: inline; filename="' . $filename . '"');
                header('Content-Length: ' . strlen($pdfData));
                echo $pdfData;
                exit;
            }

            $out = present_sale($sale, count($items));
            $out['items'] = array_map(static function ($it) {
                return [
                    'id' => (int) $it['id'],
                    'item_id' => $it['item_id'] !== null ? (int) $it['item_id'] : null,
                    'item_name' => (string) $it['item_name'],
                    'quantity' => (float) $it['quantity'],
                    'unit_price' => (float) $it['unit_price'],
                    'line_total' => (float) $it['line_total'],
                    'vat_applicable' => (int) ($it['vat_applicable'] ?? 1),
                ];
            }, $items);
            $out['customer'] = $customer ? [
                'id' => (int) $customer['id'],
                'full_name' => (string) $customer['full_name'],
                'phone' => (string) ($customer['phone'] ?? ''),
                'email' => (string) ($customer['email'] ?? ''),
                'address' => (string) ($customer['address'] ?? ''),
                'tin' => (string) ($customer['tin'] ?? ''),
                'vrn' => (string) ($customer['vrn'] ?? ''),
            ] : null;

            respond(200, ['ok' => true, 'invoice' => $out]);
        }

        // List invoices
        $status = trim((string) ($_GET['status'] ?? 'all'));
        $q = trim((string) ($_GET['q'] ?? ''));

        $conditions = ['business_id = :bid', 'sale_type = "invoice"'];
        $params = [':bid' => $businessId];

        if ($status === 'overdue') {
            $conditions[] = 'status = "sent" AND due_date IS NOT NULL AND due_date < CURDATE()';
        } elseif ($status === 'sent') {
            $conditions[] = 'status = "sent" AND (due_date IS NULL OR due_date >= CURDATE())';
        } elseif (in_array($status, ['draft', 'paid', 'cancelled'], true)) {
            $conditions[] = 'status = :status';
            $params[':status'] = $status;
        }

        if ($q !== '') {
            $conditions[] = '(invoice_number LIKE :q OR customer_name LIKE :q)';
            $params[':q'] = '%' . $q . '%';
        }

        $where = implode(' AND ', $conditions);
        $stmt = $pdo->prepare(
            "SELECT s.*, (SELECT COUNT(*) FROM tbl_sale_items si WHERE si.sale_id = s.id) AS items_count
             FROM tbl_sales s WHERE {$where} ORDER BY s.id DESC"
        );
        $stmt->execute($params);
        $rows = $stmt->fetchAll();

        $invoices = array_map(static function ($r) {
            return present_sale($r, (int) ($r['items_count'] ?? 0));
        }, $rows);

        // Stats
        $statStmt = $pdo->prepare(
            'SELECT
                COUNT(*) AS total_count,
                SUM(CASE WHEN status = "draft" THEN 1 ELSE 0 END) AS draft_count,
                SUM(CASE WHEN status = "sent" AND (due_date IS NULL OR due_date >= CURDATE()) THEN 1 ELSE 0 END) AS sent_count,
                SUM(CASE WHEN status = "paid" THEN 1 ELSE 0 END) AS paid_count,
                SUM(CASE WHEN status = "sent" AND due_date IS NOT NULL AND due_date < CURDATE() THEN 1 ELSE 0 END) AS overdue_count,
                SUM(CASE WHEN status IN ("sent") THEN (total_amount - amount_paid) ELSE 0 END) AS amount_due,
                SUM(CASE WHEN status != "cancelled" AND issue_date = CURDATE() THEN total_amount ELSE 0 END) AS sales_today,
                SUM(CASE WHEN status != "cancelled" AND issue_date = CURDATE() THEN 1 ELSE 0 END) AS txn_today,
                SUM(CASE WHEN status != "cancelled" AND YEAR(issue_date) = YEAR(CURDATE()) AND MONTH(issue_date) = MONTH(CURDATE()) THEN total_amount ELSE 0 END) AS sales_month
             FROM tbl_sales WHERE business_id = :bid AND sale_type = "invoice"'
        );
        $statStmt->execute([':bid' => $businessId]);
        $stats = $statStmt->fetch() ?: [];

        respond(200, [
            'ok' => true,
            'invoices' => $invoices,
            'stats' => [
                'total' => (int) ($stats['total_count'] ?? 0),
                'draft' => (int) ($stats['draft_count'] ?? 0),
                'sent' => (int) ($stats['sent_count'] ?? 0),
                'paid' => (int) ($stats['paid_count'] ?? 0),
                'overdue' => (int) ($stats['overdue_count'] ?? 0),
                'amount_due' => (float) ($stats['amount_due'] ?? 0),
                'sales_today' => (float) ($stats['sales_today'] ?? 0),
                'txn_today' => (int) ($stats['txn_today'] ?? 0),
                'sales_month' => (float) ($stats['sales_month'] ?? 0),
            ],
        ]);
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $action = trim((string) ($_POST['action'] ?? ''));

        if ($action === 'create_invoice' || $action === 'update_invoice') {
            $isEditing = ($action === 'update_invoice');
            $saleId = (int) ($_POST['invoice_id'] ?? 0);
            $customerId = (int) ($_POST['customer_id'] ?? 0);
            $customerNameInput = trim((string) ($_POST['customer_name'] ?? ''));
            $issueDate = trim((string) ($_POST['issue_date'] ?? date('Y-m-d')));
            $dueDate = trim((string) ($_POST['due_date'] ?? ''));
            $notes = trim((string) ($_POST['notes'] ?? ''));
            // VAT is automatic: rate comes from business settings, applied only to VAT-applicable items.
            $vatEnabled = (int) ($biz['vat_enabled'] ?? 0) === 1;
            $taxRate = $vatEnabled ? (float) ($biz['tax_rate'] ?? 0) : 0.0;
            $discount = (float) ($_POST['discount'] ?? 0);
            $itemsRaw = $_POST['items'] ?? '[]';
            $items = is_string($itemsRaw) ? json_decode($itemsRaw, true) : $itemsRaw;

            if (!is_array($items) || empty($items)) {
                respond(422, ['ok' => false, 'message' => 'Please add at least one line item to the invoice.']);
            }

            // Resolve customer (optional)
            $customerName = $customerNameInput;
            if ($customerId > 0) {
                $cStmt = $pdo->prepare('SELECT id, full_name FROM tbl_customers WHERE id = :cid AND business_id = :bid LIMIT 1');
                $cStmt->execute([':cid' => $customerId, ':bid' => $businessId]);
                $cRow = $cStmt->fetch();
                if (!$cRow) {
                    respond(404, ['ok' => false, 'message' => 'Selected customer not found.']);
                }
                $customerName = (string) $cRow['full_name'];
            } else {
                $customerId = 0;
                if ($customerName === '') {
                    $customerName = 'Walk-in Customer';
                }
            }

            // Validate line items
            $subtotal = 0.0;
            $vatBase = 0.0;
            $validatedItems = [];
            foreach ($items as $item) {
                $itemId = (int) ($item['item_id'] ?? 0);
                $qty = (float) ($item['quantity'] ?? 1);
                $price = (float) ($item['unit_price'] ?? 0);
                $nameFallback = trim((string) ($item['item_name'] ?? ''));

                if ($qty <= 0) {
                    continue;
                }

                $itemName = $nameFallback;
                $resolvedItemId = null;
                $itemVat = 1; // free-text lines default to VAT-applicable when VAT is on
                if ($itemId > 0) {
                    $iStmt = $pdo->prepare('SELECT id, name, vat_applicable FROM tbl_items WHERE id = :id AND business_id = :bid LIMIT 1');
                    $iStmt->execute([':id' => $itemId, ':bid' => $businessId]);
                    $iRow = $iStmt->fetch();
                    if ($iRow) {
                        $resolvedItemId = (int) $iRow['id'];
                        $itemName = (string) $iRow['name'];
                        $itemVat = (int) ($iRow['vat_applicable'] ?? 1);
                    }
                }

                if ($itemName === '') {
                    continue;
                }

                $lineTotal = round($qty * $price, 2);
                $subtotal += $lineTotal;
                $lineVat = ($vatEnabled && $itemVat === 1) ? 1 : 0;
                if ($lineVat === 1) {
                    $vatBase += $lineTotal;
                }
                $validatedItems[] = [
                    'item_id' => $resolvedItemId,
                    'item_name' => $itemName,
                    'quantity' => $qty,
                    'unit_price' => $price,
                    'line_total' => $lineTotal,
                    'vat_applicable' => $lineVat,
                ];
            }

            if (empty($validatedItems)) {
                respond(422, ['ok' => false, 'message' => 'No valid line items provided.']);
            }

            if ($discount < 0) {
                $discount = 0.0;
            }
            if ($discount > $subtotal) {
                $discount = $subtotal;
            }
            // Discount reduces the VAT base proportionally so tax stays consistent with the net amount.
            $discountRatio = $subtotal > 0 ? ($discount / $subtotal) : 0.0;
            $effectiveVatBase = $vatBase * (1 - $discountRatio);
            $taxAmount = round(($effectiveVatBase * $taxRate) / 100, 2);
            $totalAmount = round(($subtotal - $discount) + $taxAmount, 2);

            $pdo->beginTransaction();
            try {
                if ($isEditing) {
                    $chk = $pdo->prepare('SELECT id, status FROM tbl_sales WHERE id = :id AND business_id = :bid LIMIT 1');
                    $chk->execute([':id' => $saleId, ':bid' => $businessId]);
                    $existing = $chk->fetch();
                    if (!$existing) {
                        respond(404, ['ok' => false, 'message' => 'Invoice not found.']);
                    }
                    if ($existing['status'] === 'paid' || $existing['status'] === 'cancelled') {
                        respond(422, ['ok' => false, 'message' => 'A paid or cancelled invoice cannot be edited.']);
                    }

                    $upd = $pdo->prepare(
                        'UPDATE tbl_sales SET customer_id = :cid, customer_name = :cname, issue_date = :idate,
                            due_date = :ddate, subtotal = :sub, discount = :disc, tax_rate = :trate,
                            tax_amount = :tamt, total_amount = :tot, notes = :notes
                         WHERE id = :id AND business_id = :bid'
                    );
                    $upd->execute([
                        ':cid' => $customerId > 0 ? $customerId : null,
                        ':cname' => $customerName,
                        ':idate' => $issueDate,
                        ':ddate' => $dueDate !== '' ? $dueDate : null,
                        ':sub' => $subtotal,
                        ':disc' => $discount,
                        ':trate' => $taxRate,
                        ':tamt' => $taxAmount,
                        ':tot' => $totalAmount,
                        ':notes' => $notes !== '' ? $notes : null,
                        ':id' => $saleId,
                        ':bid' => $businessId,
                    ]);

                    $pdo->prepare('DELETE FROM tbl_sale_items WHERE sale_id = :sid')->execute([':sid' => $saleId]);
                    $invoiceNumber = null;
                } else {
                    $countStmt = $pdo->prepare('SELECT COUNT(*) FROM tbl_sales WHERE business_id = :bid AND sale_type = "invoice"');
                    $countStmt->execute([':bid' => $businessId]);
                    $seq = ((int) $countStmt->fetchColumn()) + 1;
                    $invoiceNumber = 'INV-' . date('Ym') . '-' . str_pad((string) $seq, 4, '0', STR_PAD_LEFT);

                    $ins = $pdo->prepare(
                        'INSERT INTO tbl_sales
                            (business_id, sale_type, invoice_number, customer_id, customer_name, status, issue_date, due_date,
                             subtotal, discount, tax_rate, tax_amount, total_amount, amount_paid, notes, created_by)
                         VALUES (:bid, "invoice", :inv, :cid, :cname, "draft", :idate, :ddate,
                             :sub, :disc, :trate, :tamt, :tot, 0.00, :notes, :uid)'
                    );
                    $ins->execute([
                        ':bid' => $businessId,
                        ':inv' => $invoiceNumber,
                        ':cid' => $customerId > 0 ? $customerId : null,
                        ':cname' => $customerName,
                        ':idate' => $issueDate,
                        ':ddate' => $dueDate !== '' ? $dueDate : null,
                        ':sub' => $subtotal,
                        ':disc' => $discount,
                        ':trate' => $taxRate,
                        ':tamt' => $taxAmount,
                        ':tot' => $totalAmount,
                        ':notes' => $notes !== '' ? $notes : null,
                        ':uid' => $userId,
                    ]);
                    $saleId = (int) $pdo->lastInsertId();
                }

                $liStmt = $pdo->prepare(
                    'INSERT INTO tbl_sale_items (sale_id, item_id, item_name, quantity, unit_price, line_total, vat_applicable)
                     VALUES (:sid, :iid, :iname, :qty, :price, :ltot, :vat)'
                );
                foreach ($validatedItems as $v) {
                    $liStmt->execute([
                        ':sid' => $saleId,
                        ':iid' => $v['item_id'],
                        ':iname' => $v['item_name'],
                        ':qty' => $v['quantity'],
                        ':price' => $v['unit_price'],
                        ':ltot' => $v['line_total'],
                        ':vat' => $v['vat_applicable'],
                    ]);
                }

                $pdo->commit();
            } catch (Throwable $e) {
                $pdo->rollBack();
                throw $e;
            }

            respond($isEditing ? 200 : 201, [
                'ok' => true,
                'message' => $isEditing ? 'Invoice updated.' : "Invoice {$invoiceNumber} created.",
                'invoice_id' => $saleId,
            ]);
        }

        if ($action === 'create_pos_sale') {
            $customerId = (int) ($_POST['customer_id'] ?? 0);
            $customerNameInput = trim((string) ($_POST['customer_name'] ?? ''));
            $paymentMethod = trim((string) ($_POST['payment_method'] ?? 'cash'));
            $amountPaidInput = isset($_POST['amount_paid']) ? (float) $_POST['amount_paid'] : null;
            $itemsRaw = $_POST['items'] ?? '[]';
            $items = is_string($itemsRaw) ? json_decode($itemsRaw, true) : $itemsRaw;

            if (!is_array($items) || empty($items)) {
                respond(422, ['ok' => false, 'message' => 'Add at least one product to the cart.']);
            }

            // VAT is automatic from business settings.
            $vatEnabled = (int) ($biz['vat_enabled'] ?? 0) === 1;
            $taxRate = $vatEnabled ? (float) ($biz['tax_rate'] ?? 0) : 0.0;

            // Resolve customer (optional; POS defaults to walk-in)
            $customerName = $customerNameInput;
            if ($customerId > 0) {
                $cStmt = $pdo->prepare('SELECT id, full_name FROM tbl_customers WHERE id = :cid AND business_id = :bid LIMIT 1');
                $cStmt->execute([':cid' => $customerId, ':bid' => $businessId]);
                $cRow = $cStmt->fetch();
                if (!$cRow) {
                    respond(404, ['ok' => false, 'message' => 'Selected customer not found.']);
                }
                $customerName = (string) $cRow['full_name'];
            } else {
                $customerId = 0;
                if ($customerName === '') {
                    $customerName = 'Walk-in Customer';
                }
            }

            // Validate items, compute totals, and check stock for products.
            $subtotal = 0.0;
            $vatBase = 0.0;
            $validatedItems = [];
            foreach ($items as $item) {
                $itemId = (int) ($item['item_id'] ?? 0);
                $qty = (float) ($item['quantity'] ?? 0);
                $price = (float) ($item['unit_price'] ?? 0);
                if ($itemId <= 0 || $qty <= 0) {
                    continue;
                }

                $iStmt = $pdo->prepare('SELECT id, name, type, current_stock, cost_price, vat_applicable FROM tbl_items WHERE id = :id AND business_id = :bid LIMIT 1');
                $iStmt->execute([':id' => $itemId, ':bid' => $businessId]);
                $iRow = $iStmt->fetch();
                if (!$iRow) {
                    continue;
                }

                if ($iRow['type'] !== 'service' && (float) $iRow['current_stock'] < $qty) {
                    respond(422, ['ok' => false, 'message' => 'Not enough stock for "' . $iRow['name'] . '". Available: ' . rtrim(rtrim(number_format((float) $iRow['current_stock'], 2), '0'), '.') . '.']);
                }

                $lineTotal = round($qty * $price, 2);
                $subtotal += $lineTotal;
                $lineVat = ($vatEnabled && (int) ($iRow['vat_applicable'] ?? 1) === 1) ? 1 : 0;
                if ($lineVat === 1) {
                    $vatBase += $lineTotal;
                }

                $validatedItems[] = [
                    'item_id' => (int) $iRow['id'],
                    'item_name' => (string) $iRow['name'],
                    'type' => (string) $iRow['type'],
                    'cost_price' => (float) $iRow['cost_price'],
                    'quantity' => $qty,
                    'unit_price' => $price,
                    'line_total' => $lineTotal,
                    'vat_applicable' => $lineVat,
                ];
            }

            if (empty($validatedItems)) {
                respond(422, ['ok' => false, 'message' => 'No valid products in the cart.']);
            }

            $taxAmount = round(($vatBase * $taxRate) / 100, 2);
            $totalAmount = round($subtotal + $taxAmount, 2);
            // POS sales are settled immediately; default paid = total.
            $amountPaid = ($amountPaidInput !== null && $amountPaidInput >= $totalAmount) ? $amountPaidInput : $totalAmount;
            $changeDue = round($amountPaid - $totalAmount, 2);

            $pdo->beginTransaction();
            try {
                $countStmt = $pdo->prepare('SELECT COUNT(*) FROM tbl_sales WHERE business_id = :bid AND sale_type = "pos"');
                $countStmt->execute([':bid' => $businessId]);
                $seq = ((int) $countStmt->fetchColumn()) + 1;
                $receiptNumber = 'POS-' . date('Ym') . '-' . str_pad((string) $seq, 4, '0', STR_PAD_LEFT);
                $today = date('Y-m-d');

                $ins = $pdo->prepare(
                    'INSERT INTO tbl_sales
                        (business_id, sale_type, invoice_number, customer_id, customer_name, status, issue_date, due_date,
                         subtotal, discount, tax_rate, tax_amount, total_amount, amount_paid, notes, created_by)
                     VALUES (:bid, "pos", :inv, :cid, :cname, "paid", :idate, NULL,
                         :sub, 0.00, :trate, :tamt, :tot, :paid, :notes, :uid)'
                );
                $ins->execute([
                    ':bid' => $businessId,
                    ':inv' => $receiptNumber,
                    ':cid' => $customerId > 0 ? $customerId : null,
                    ':cname' => $customerName,
                    ':idate' => $today,
                    ':sub' => $subtotal,
                    ':trate' => $taxRate,
                    ':tamt' => $taxAmount,
                    ':tot' => $totalAmount,
                    ':paid' => min($amountPaid, $totalAmount), // store settled amount, not change
                    ':notes' => 'POS sale (' . $paymentMethod . ')',
                    ':uid' => $userId,
                ]);
                $saleId = (int) $pdo->lastInsertId();

                $liStmt = $pdo->prepare(
                    'INSERT INTO tbl_sale_items (sale_id, item_id, item_name, quantity, unit_price, line_total, vat_applicable)
                     VALUES (:sid, :iid, :iname, :qty, :price, :ltot, :vat)'
                );
                $updStock = $pdo->prepare('UPDATE tbl_items SET current_stock = current_stock - :qty WHERE id = :id AND business_id = :bid');
                $moveStmt = $pdo->prepare(
                    'INSERT INTO tbl_stock_movements
                     (business_id, item_id, movement_type, quantity, previous_stock, new_stock, unit_cost, reference_type, reference_id, notes, created_by)
                     VALUES (:bid, :item_id, "sale", :qty, :prev, :new, :cost, "POS", :ref, :notes, :uid)'
                );

                foreach ($validatedItems as $v) {
                    $liStmt->execute([
                        ':sid' => $saleId,
                        ':iid' => $v['item_id'],
                        ':iname' => $v['item_name'],
                        ':qty' => $v['quantity'],
                        ':price' => $v['unit_price'],
                        ':ltot' => $v['line_total'],
                        ':vat' => $v['vat_applicable'],
                    ]);

                    if ($v['type'] !== 'service') {
                        $sStmt = $pdo->prepare('SELECT current_stock FROM tbl_items WHERE id = :id AND business_id = :bid LIMIT 1');
                        $sStmt->execute([':id' => $v['item_id'], ':bid' => $businessId]);
                        $prevStock = (float) ($sStmt->fetchColumn() ?: 0);
                        $newStock = $prevStock - $v['quantity'];

                        $updStock->execute([':qty' => $v['quantity'], ':id' => $v['item_id'], ':bid' => $businessId]);
                        $moveStmt->execute([
                            ':bid' => $businessId,
                            ':item_id' => $v['item_id'],
                            ':qty' => -1 * $v['quantity'],
                            ':prev' => $prevStock,
                            ':new' => $newStock,
                            ':cost' => $v['cost_price'],
                            ':ref' => $receiptNumber,
                            ':notes' => 'Sold via POS ' . $receiptNumber,
                            ':uid' => $userId,
                        ]);
                    }
                }

                // Auto-post the settled amount into a Bank & Cash account.
                $settled = min($amountPaid, $totalAmount);
                if ($settled > 0) {
                    $requestedAccountId = (int) ($_POST['account_id'] ?? 0);
                    $postAccountId = 0;
                    if ($requestedAccountId > 0) {
                        $accChk = $pdo->prepare('SELECT id FROM tbl_accounts WHERE id = :id AND business_id = :bid AND status = "active" LIMIT 1');
                        $accChk->execute([':id' => $requestedAccountId, ':bid' => $businessId]);
                        $postAccountId = (int) ($accChk->fetchColumn() ?: 0);
                    }
                    if ($postAccountId <= 0) {
                        $postAccountId = resolve_account_for_type($pdo, $businessId, account_type_for_payment_method($paymentMethod));
                    }
                    if ($postAccountId > 0) {
                        post_account_txn($pdo, $businessId, $postAccountId, 'in', 'sale', $settled, 'POS', $receiptNumber, 'POS sale ' . $receiptNumber . ' (' . $paymentMethod . ')', $userId);
                    }
                }

                $pdo->commit();
            } catch (Throwable $e) {
                $pdo->rollBack();
                throw $e;
            }

            respond(201, [
                'ok' => true,
                'message' => "Sale {$receiptNumber} completed.",
                'sale_id' => $saleId,
                'receipt_number' => $receiptNumber,
                'subtotal' => $subtotal,
                'tax_rate' => $taxRate,
                'tax_amount' => $taxAmount,
                'total_amount' => $totalAmount,
                'amount_paid' => $amountPaid,
                'change_due' => $changeDue,
            ]);
        }

        if ($action === 'update_status') {
            $saleId = (int) ($_POST['invoice_id'] ?? 0);
            $newStatus = trim((string) ($_POST['status'] ?? ''));
            $allowed = ['draft', 'sent', 'paid'];
            if (!in_array($newStatus, $allowed, true)) {
                respond(422, ['ok' => false, 'message' => 'Invalid status.']);
            }

            $chk = $pdo->prepare('SELECT id, status, total_amount FROM tbl_sales WHERE id = :id AND business_id = :bid LIMIT 1');
            $chk->execute([':id' => $saleId, ':bid' => $businessId]);
            $sale = $chk->fetch();
            if (!$sale) {
                respond(404, ['ok' => false, 'message' => 'Invoice not found.']);
            }
            if ($sale['status'] === 'cancelled') {
                respond(422, ['ok' => false, 'message' => 'Reactivate the invoice before changing its status.']);
            }

            if ($newStatus === 'paid') {
                $pdo->prepare('UPDATE tbl_sales SET status = "paid", amount_paid = total_amount WHERE id = :id')
                    ->execute([':id' => $saleId]);

                // Auto-post the invoice payment into the default account (once).
                if ($sale['status'] !== 'paid'
                    && !account_txn_exists($pdo, $businessId, 'INVOICE', (string) $saleId, 'invoice_payment')) {
                    $postAccountId = ensure_default_account($pdo, $businessId);
                    if ($postAccountId > 0) {
                        post_account_txn(
                            $pdo,
                            $businessId,
                            $postAccountId,
                            'in',
                            'invoice_payment',
                            (float) $sale['total_amount'],
                            'INVOICE',
                            (string) $saleId,
                            'Invoice payment (#' . $saleId . ')',
                            $userId
                        );
                    }
                }
            } else {
                $pdo->prepare('UPDATE tbl_sales SET status = :st WHERE id = :id')
                    ->execute([':st' => $newStatus, ':id' => $saleId]);
            }

            respond(200, ['ok' => true, 'message' => 'Invoice status updated.']);
        }

        if ($action === 'cancel_invoice' || $action === 'uncancel_invoice') {
            $saleId = (int) ($_POST['invoice_id'] ?? 0);
            $chk = $pdo->prepare('SELECT id, status FROM tbl_sales WHERE id = :id AND business_id = :bid LIMIT 1');
            $chk->execute([':id' => $saleId, ':bid' => $businessId]);
            $sale = $chk->fetch();
            if (!$sale) {
                respond(404, ['ok' => false, 'message' => 'Invoice not found.']);
            }

            if ($action === 'cancel_invoice') {
                if ($sale['status'] === 'paid') {
                    respond(422, ['ok' => false, 'message' => 'A paid invoice cannot be cancelled.']);
                }
                $pdo->prepare('UPDATE tbl_sales SET status = "cancelled" WHERE id = :id')->execute([':id' => $saleId]);
                respond(200, ['ok' => true, 'message' => 'Invoice cancelled.']);
            }

            if ($sale['status'] !== 'cancelled') {
                respond(422, ['ok' => false, 'message' => 'Only a cancelled invoice can be reactivated.']);
            }
            $pdo->prepare('UPDATE tbl_sales SET status = "draft" WHERE id = :id')->execute([':id' => $saleId]);
            respond(200, ['ok' => true, 'message' => 'Invoice reactivated.']);
        }

        if ($action === 'delete_invoice') {
            $saleId = (int) ($_POST['invoice_id'] ?? 0);
            $chk = $pdo->prepare('SELECT id FROM tbl_sales WHERE id = :id AND business_id = :bid LIMIT 1');
            $chk->execute([':id' => $saleId, ':bid' => $businessId]);
            if (!$chk->fetch()) {
                respond(404, ['ok' => false, 'message' => 'Invoice not found.']);
            }
            $pdo->beginTransaction();
            try {
                $pdo->prepare('DELETE FROM tbl_sale_items WHERE sale_id = :sid')->execute([':sid' => $saleId]);
                $pdo->prepare('DELETE FROM tbl_sales WHERE id = :id AND business_id = :bid')->execute([':id' => $saleId, ':bid' => $businessId]);
                $pdo->commit();
            } catch (Throwable $e) {
                $pdo->rollBack();
                throw $e;
            }
            respond(200, ['ok' => true, 'message' => 'Invoice deleted.']);
        }

        respond(422, ['ok' => false, 'message' => 'Unknown sales action.']);
    }

    respond(405, ['ok' => false, 'message' => 'Method not allowed.']);
} catch (Throwable $e) {
    respond(500, ['ok' => false, 'message' => 'Unable to process sales: ' . $e->getMessage()]);
}
