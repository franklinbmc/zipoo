<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/accounts_lib.php';
require_once __DIR__ . '/vat_lib.php';
require_once __DIR__ . '/pos_lib.php';
require_once dirname(__DIR__) . '/vendor/autoload.php';

use Dompdf\Dompdf;
use Dompdf\Options;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Border;

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
        $price = number_format((float) ($item['unit_price'] ?? 0), 0);
        $total = number_format((float) ($item['line_total'] ?? 0), 0);
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
                            <td style='text-align: right;'>" . number_format($subtotal, 0) . " {$currency}</td>
                        </tr>
                        " . ($discount > 0 ? "
                        <tr>
                            <td>Discount:</td>
                            <td style='text-align: right;'>-" . number_format($discount, 0) . " {$currency}</td>
                        </tr>" : "") . "
                        " . ($taxRate > 0 ? "
                        <tr>
                            <td>VAT ({$taxRate}%):</td>
                            <td style='text-align: right;'>" . number_format($taxAmount, 0) . " {$currency}</td>
                        </tr>" : "") . "
                        <tr class='grand-total'>
                            <td>Total:</td>
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

function sales_report_dates(string $range): array
{
    $today = new DateTimeImmutable('today');
    if ($range === 'year') {
        $from = $today->setDate((int) $today->format('Y'), 1, 1);
    } elseif ($range === 'month') {
        $from = $today->modify('first day of this month');
    } elseif ($range === 'week') {
        $from = $today->modify('-6 days');
    } else {
        $from = $today;
        $range = 'today';
    }
    return [$from->format('Y-m-d'), $today->format('Y-m-d'), $range];
}

function load_sales_report_rows(PDO $pdo, int $businessId, string $range): array
{
    [$from, $to, $range] = sales_report_dates($range);
    $rows = [];

    $inv = $pdo->prepare(
        'SELECT s.id, s.invoice_number, s.customer_name, s.total_amount, s.issue_date, s.created_at,
                (SELECT COUNT(*) FROM tbl_sale_items si WHERE si.sale_id = s.id) AS items_count
         FROM tbl_sales s
         WHERE s.business_id = :bid AND s.sale_type = "invoice" AND s.status NOT IN ("cancelled", "draft")
           AND s.issue_date BETWEEN :from AND :to
         ORDER BY s.issue_date DESC, s.id DESC'
    );
    $inv->execute([':bid' => $businessId, ':from' => $from, ':to' => $to]);
    foreach ($inv->fetchAll() as $r) {
        $rows[] = [
            'type' => 'Invoice',
            'number' => (string) $r['invoice_number'],
            'customer' => (string) ($r['customer_name'] ?: 'Walk-in Customer'),
            'amount' => (float) $r['total_amount'],
            'items' => (float) ($r['items_count'] ?? 0),
            'date' => (string) ($r['issue_date'] ?: $r['created_at']),
        ];
    }

    $pos = $pdo->prepare(
        'SELECT s.id, s.invoice_number, s.customer_name, s.total_amount, s.created_at,
                (SELECT COALESCE(SUM(si.quantity), 0) FROM tbl_sale_items si WHERE si.sale_id = s.id) AS items_count
         FROM tbl_sales s
         WHERE s.business_id = :bid AND s.sale_type = "pos" AND s.status != "cancelled"
           AND DATE(s.created_at) BETWEEN :from AND :to
         ORDER BY s.created_at DESC, s.id DESC'
    );
    $pos->execute([':bid' => $businessId, ':from' => $from, ':to' => $to]);
    foreach ($pos->fetchAll() as $r) {
        $rows[] = [
            'type' => 'POS',
            'number' => (string) $r['invoice_number'],
            'customer' => (string) ($r['customer_name'] ?: 'Walk-in Customer'),
            'amount' => (float) $r['total_amount'],
            'items' => (float) ($r['items_count'] ?? 0),
            'date' => (string) $r['created_at'],
        ];
    }

    usort($rows, static fn($a, $b) => strcmp((string) $b['date'], (string) $a['date']));
    return ['rows' => $rows, 'from' => $from, 'to' => $to, 'range' => $range];
}

function export_sales_report_excel(PDO $pdo, int $businessId, string $range): void
{
    $report = load_sales_report_rows($pdo, $businessId, $range);
    $rows = $report['rows'];
    $spreadsheet = new Spreadsheet();
    $sheet = $spreadsheet->getActiveSheet();
    $sheet->setTitle('Sales Report');
    $sheet->fromArray(['Type', 'Number', 'Customer', 'Date', 'Items', 'Amount'], null, 'A1');
    $sheet->getStyle('A1:F1')->getFont()->setBold(true)->getColor()->setRGB('FFFFFF');
    $sheet->getStyle('A1:F1')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('0D2B5B');
    $r = 2;
    foreach ($rows as $row) {
        $sheet->fromArray([$row['type'], $row['number'], $row['customer'], $row['date'], $row['items'], $row['amount']], null, "A{$r}");
        $r++;
    }
    $sheet->setCellValue("E{$r}", 'Total');
    $sheet->setCellValue("F{$r}", array_sum(array_column($rows, 'amount')));
    $sheet->getStyle("E{$r}:F{$r}")->getFont()->setBold(true);
    foreach (range('A', 'F') as $col) { $sheet->getColumnDimension($col)->setAutoSize(true); }
    $sheet->getStyle("A1:F{$r}")->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN)->getColor()->setRGB('DBE3EF');
    $filename = 'Sales_Report_' . $report['range'] . '_' . $report['to'] . '.xlsx';
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: max-age=0');
    (new Xlsx($spreadsheet))->save('php://output');
    exit;
}

function export_sales_report_pdf(PDO $pdo, int $businessId, string $range): void
{
    $report = load_sales_report_rows($pdo, $businessId, $range);
    $rows = $report['rows'];
    $total = array_sum(array_column($rows, 'amount'));
    $htmlRows = '';
    foreach ($rows as $row) {
        $htmlRows .= '<tr><td>' . htmlspecialchars($row['type']) . '</td><td>' . htmlspecialchars($row['number']) . '</td><td>' . htmlspecialchars($row['customer']) . '</td><td>' . htmlspecialchars($row['date']) . '</td><td style="text-align:right;">' . number_format((float) $row['items'], 0) . '</td><td style="text-align:right;">' . number_format((float) $row['amount'], 0) . '</td></tr>';
    }
    if ($htmlRows === '') {
        $htmlRows = '<tr><td colspan="6" style="text-align:center;color:#64748b;">No sales in this period.</td></tr>';
    }
    $html = '<html><head><style>body{font-family:DejaVu Sans,sans-serif;color:#0d2b5b;}h1{font-size:20px;}small{color:#64748b;}table{width:100%;border-collapse:collapse;margin-top:16px;}th{background:#0d2b5b;color:#fff;text-align:left;}th,td{border:1px solid #dbe3ef;padding:8px;font-size:11px;}tfoot td{font-weight:bold;}</style></head><body><h1>Sales Report</h1><small>' . htmlspecialchars($report['from'] . ' to ' . $report['to']) . '</small><table><thead><tr><th>Type</th><th>Number</th><th>Customer</th><th>Date</th><th>Items</th><th>Amount</th></tr></thead><tbody>' . $htmlRows . '</tbody><tfoot><tr><td colspan="5" style="text-align:right;">Total</td><td style="text-align:right;">' . number_format($total, 0) . '</td></tr></tfoot></table></body></html>';
    $options = new Options();
    $options->set('isRemoteEnabled', true);
    $dompdf = new Dompdf($options);
    $dompdf->loadHtml($html);
    $dompdf->setPaper('A4', 'portrait');
    $dompdf->render();
    $filename = 'Sales_Report_' . $report['range'] . '_' . $report['to'] . '.pdf';
    header('Content-Type: application/pdf');
    header('Content-Disposition: inline; filename="' . $filename . '"');
    echo $dompdf->output();
    exit;
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
    ensure_party_tax_ids($pdo);
    ensure_sales_pos_columns($pdo);

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
                    'tax_inclusive' => (int) ($it['tax_inclusive'] ?? 0),
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

        // POS sales history (dated list of counter sales).
        if (($_GET['history'] ?? '') === 'pos') {
            $from = trim((string) ($_GET['from'] ?? ''));
            $to = trim((string) ($_GET['to'] ?? ''));
            $hq = trim((string) ($_GET['q'] ?? ''));
            $debtOnly = trim((string) ($_GET['debt'] ?? '')) === 'pay_later';
            $conds = ['s.business_id = :bid', 's.sale_type = "pos"'];
            $hp = [':bid' => $businessId];
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) { $conds[] = 'DATE(s.created_at) >= :from'; $hp[':from'] = $from; }
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) { $conds[] = 'DATE(s.created_at) <= :to'; $hp[':to'] = $to; }
            if ($hq !== '') { $conds[] = '(s.invoice_number LIKE :q OR s.customer_name LIKE :q)'; $hp[':q'] = '%' . $hq . '%'; }
            if ($debtOnly) { $conds[] = 's.payment_method = "pay_later" AND s.status != "cancelled" AND s.amount_paid < s.total_amount'; }
            $hwhere = implode(' AND ', $conds);
            $stmt = $pdo->prepare(
                "SELECT s.id, s.invoice_number, s.customer_name, s.total_amount, s.amount_paid, s.payment_method, s.status, s.created_at,
                        u.full_name AS cashier,
                        (SELECT COALESCE(SUM(si.quantity), 0) FROM tbl_sale_items si WHERE si.sale_id = s.id) AS items_count
                 FROM tbl_sales s LEFT JOIN tbl_users u ON u.id = s.created_by
                 WHERE {$hwhere} ORDER BY s.id DESC LIMIT 300"
            );
            $stmt->execute($hp);
            $rows = $stmt->fetchAll();
            $total = 0.0;
            $sales = array_map(static function ($r) use (&$total, $debtOnly) {
                $amountDue = max(0, (float) $r['total_amount'] - (float) $r['amount_paid']);
                if ($r['status'] !== 'cancelled') { $total += $debtOnly ? $amountDue : (float) $r['total_amount']; }
                return [
                    'id' => (int) $r['id'],
                    'receipt_number' => (string) $r['invoice_number'],
                    'customer_name' => (string) ($r['customer_name'] ?? ''),
                    'total_amount' => (float) $r['total_amount'],
                    'amount_paid' => (float) $r['amount_paid'],
                    'amount_due' => $amountDue,
                    'payment_method' => (string) ($r['payment_method'] ?? ''),
                    'status' => (string) $r['status'],
                    'cashier' => (string) ($r['cashier'] ?? ''),
                    'items_count' => (float) ($r['items_count'] ?? 0),
                    'created_at' => (string) $r['created_at'],
                ];
            }, $rows);
            respond(200, ['ok' => true, 'sales' => $sales, 'stats' => ['count' => count($sales), 'total' => round($total, 2)]]);
        }

        if (($_GET['action'] ?? '') === 'export_report_excel') {
            export_sales_report_excel($pdo, $businessId, trim((string) ($_GET['range'] ?? 'today')));
        }

        if (($_GET['action'] ?? '') === 'export_report_pdf') {
            export_sales_report_pdf($pdo, $businessId, trim((string) ($_GET['range'] ?? 'today')));
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
                COUNT(DISTINCT CASE WHEN status = "sent" AND total_amount > amount_paid THEN COALESCE(NULLIF(customer_name, ""), invoice_number) ELSE NULL END) AS due_customers,
                SUM(CASE WHEN status IN ("sent") THEN (total_amount - amount_paid) ELSE 0 END) AS amount_due,
                SUM(CASE WHEN status NOT IN ("cancelled", "draft") AND issue_date = CURDATE() THEN total_amount ELSE 0 END) AS sales_today,
                SUM(CASE WHEN status NOT IN ("cancelled", "draft") AND issue_date = CURDATE() THEN 1 ELSE 0 END) AS txn_today,
                SUM(CASE WHEN status NOT IN ("cancelled", "draft") AND YEAR(issue_date) = YEAR(CURDATE()) AND MONTH(issue_date) = MONTH(CURDATE()) THEN total_amount ELSE 0 END) AS sales_month
             FROM tbl_sales WHERE business_id = :bid AND sale_type = "invoice"'
        );
        $statStmt->execute([':bid' => $businessId]);
        $stats = $statStmt->fetch() ?: [];
        $posDebtStmt = $pdo->prepare(
            'SELECT
                COUNT(*) AS pay_later_count,
                COUNT(DISTINCT COALESCE(NULLIF(customer_name, ""), invoice_number)) AS pay_later_customers,
                COALESCE(SUM(total_amount - amount_paid), 0) AS pay_later_due
             FROM tbl_sales
             WHERE business_id = :bid AND sale_type = "pos" AND payment_method = "pay_later"
               AND status != "cancelled" AND amount_paid < total_amount'
        );
        $posDebtStmt->execute([':bid' => $businessId]);
        $posDebt = $posDebtStmt->fetch() ?: [];
        $invoiceDue = (float) ($stats['amount_due'] ?? 0);
        $payLaterDue = (float) ($posDebt['pay_later_due'] ?? 0);
        $invoiceDueCount = (int) ($stats['due_customers'] ?? 0);
        $payLaterCustomers = (int) ($posDebt['pay_later_customers'] ?? 0);

        respond(200, [
            'ok' => true,
            'invoices' => $invoices,
            'stats' => [
                'total' => (int) ($stats['total_count'] ?? 0),
                'draft' => (int) ($stats['draft_count'] ?? 0),
                'sent' => (int) ($stats['sent_count'] ?? 0),
                'paid' => (int) ($stats['paid_count'] ?? 0),
                'overdue' => $invoiceDueCount + $payLaterCustomers,
                'amount_due' => $invoiceDue + $payLaterDue,
                'pay_later_due' => $payLaterDue,
                'pay_later_count' => (int) ($posDebt['pay_later_count'] ?? 0),
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

            // Validate line items. Prices may be tax-inclusive per item; convert each
            // taxable line to its net amount so VAT = net * rate for both pricing modes.
            $subtotal = 0.0;   // net subtotal
            $taxableNet = 0.0; // net of taxable lines
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
                $itemIncl = 0; // free-text lines are treated as tax-exclusive
                if ($itemId > 0) {
                    $iStmt = $pdo->prepare('SELECT id, name, vat_applicable, tax_inclusive FROM tbl_items WHERE id = :id AND business_id = :bid LIMIT 1');
                    $iStmt->execute([':id' => $itemId, ':bid' => $businessId]);
                    $iRow = $iStmt->fetch();
                    if ($iRow) {
                        $resolvedItemId = (int) $iRow['id'];
                        $itemName = (string) $iRow['name'];
                        $itemVat = (int) ($iRow['vat_applicable'] ?? 1);
                        $itemIncl = (int) ($iRow['tax_inclusive'] ?? 0);
                    }
                }

                if ($itemName === '') {
                    continue;
                }

                $gross = round($qty * $price, 2);
                $lineVat = ($vatEnabled && $itemVat === 1) ? 1 : 0;
                $lineIncl = ($lineVat === 1 && $itemIncl === 1) ? 1 : 0;
                $net = ($lineIncl === 1 && $taxRate > 0) ? ($gross / (1 + $taxRate / 100)) : $gross;
                $subtotal += $net;
                if ($lineVat === 1) {
                    $taxableNet += $net;
                }
                $validatedItems[] = [
                    'item_id' => $resolvedItemId,
                    'item_name' => $itemName,
                    'quantity' => $qty,
                    'unit_price' => $price,
                    'line_total' => $gross, // the price as entered (inclusive price when inclusive)
                    'vat_applicable' => $lineVat,
                    'tax_inclusive' => $lineIncl,
                ];
            }

            if (empty($validatedItems)) {
                respond(422, ['ok' => false, 'message' => 'No valid line items provided.']);
            }

            $subtotal = round($subtotal, 2);
            $taxableNet = round($taxableNet, 2);
            if ($discount < 0) {
                $discount = 0.0;
            }
            if ($discount > $subtotal) {
                $discount = $subtotal;
            }
            // Discount reduces the taxable net proportionally so tax stays consistent.
            $discountRatio = $subtotal > 0 ? ($discount / $subtotal) : 0.0;
            $effectiveTaxableNet = $taxableNet * (1 - $discountRatio);
            $taxAmount = round(($effectiveTaxableNet * $taxRate) / 100, 2);
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
                    'INSERT INTO tbl_sale_items (sale_id, item_id, item_name, quantity, unit_price, line_total, vat_applicable, tax_inclusive)
                     VALUES (:sid, :iid, :iname, :qty, :price, :ltot, :vat, :taxincl)'
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
                        ':taxincl' => $v['tax_inclusive'],
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
            $paymentKey = strtolower($paymentMethod);
            $isPayLater = in_array($paymentKey, ['pay_later', 'paylater', 'credit'], true);
            if ($isPayLater) {
                $paymentMethod = 'pay_later';
            }
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

            // Validate items, compute totals (net-based, honouring tax-inclusive prices),
            // and check stock for products.
            $subtotal = 0.0;   // net subtotal
            $taxableNet = 0.0; // net of taxable lines
            $validatedItems = [];
            foreach ($items as $item) {
                $itemId = (int) ($item['item_id'] ?? 0);
                $qty = (float) ($item['quantity'] ?? 0);
                $price = (float) ($item['unit_price'] ?? 0);
                if ($itemId <= 0 || $qty <= 0) {
                    continue;
                }

                $iStmt = $pdo->prepare('SELECT id, name, type, current_stock, cost_price, vat_applicable, tax_inclusive FROM tbl_items WHERE id = :id AND business_id = :bid LIMIT 1');
                $iStmt->execute([':id' => $itemId, ':bid' => $businessId]);
                $iRow = $iStmt->fetch();
                if (!$iRow) {
                    continue;
                }

                if ($iRow['type'] !== 'service' && (float) $iRow['current_stock'] < $qty) {
                    respond(422, ['ok' => false, 'message' => 'Not enough stock for "' . $iRow['name'] . '". Available: ' . rtrim(rtrim(number_format((float) $iRow['current_stock'], 2), '0'), '.') . '.']);
                }

                $gross = round($qty * $price, 2);
                $lineVat = ($vatEnabled && (int) ($iRow['vat_applicable'] ?? 1) === 1) ? 1 : 0;
                $lineIncl = ($lineVat === 1 && (int) ($iRow['tax_inclusive'] ?? 0) === 1) ? 1 : 0;
                $net = ($lineIncl === 1 && $taxRate > 0) ? ($gross / (1 + $taxRate / 100)) : $gross;
                $subtotal += $net;
                if ($lineVat === 1) {
                    $taxableNet += $net;
                }

                $validatedItems[] = [
                    'item_id' => (int) $iRow['id'],
                    'item_name' => (string) $iRow['name'],
                    'type' => (string) $iRow['type'],
                    'cost_price' => (float) $iRow['cost_price'],
                    'quantity' => $qty,
                    'unit_price' => $price,
                    'line_total' => $gross,
                    'vat_applicable' => $lineVat,
                    'tax_inclusive' => $lineIncl,
                ];
            }

            if (empty($validatedItems)) {
                respond(422, ['ok' => false, 'message' => 'No valid products in the cart.']);
            }

            $subtotal = round($subtotal, 2);
            $taxAmount = round(($taxableNet * $taxRate) / 100, 2);
            $totalAmount = round($subtotal + $taxAmount, 2);
            // POS sales are settled immediately, except Pay Later which becomes outstanding credit.
            $amountPaid = $isPayLater ? 0.0 : (($amountPaidInput !== null && $amountPaidInput >= $totalAmount) ? $amountPaidInput : $totalAmount);
            $changeDue = round($amountPaid - $totalAmount, 2);
            if ($changeDue < 0) {
                $changeDue = 0.0;
            }

            // A shift (till) must be open before any counter sale can be made.
            $openShift = current_open_shift($pdo, $businessId, $userId);
            if (!$openShift) {
                respond(422, ['ok' => false, 'message' => 'Start a shift before making sales.']);
            }
            $shiftId = (int) $openShift['id'];

            // Resolve which Bank & Cash account this payment lands in, before touching
            // the database. Cash uses/creates the default; Card and Lipa kwa simu require
            // an account of their own type so the money is tracked correctly.
            $payType = account_type_for_payment_method($paymentMethod);
            $requestedAccountId = (int) ($_POST['account_id'] ?? 0);
            $postAccountId = 0;
            if (!$isPayLater && $requestedAccountId > 0) {
                $accChk = $pdo->prepare('SELECT id FROM tbl_accounts WHERE id = :id AND business_id = :bid AND status = "active" LIMIT 1');
                $accChk->execute([':id' => $requestedAccountId, ':bid' => $businessId]);
                $postAccountId = (int) ($accChk->fetchColumn() ?: 0);
            }
            if (!$isPayLater && $postAccountId <= 0) {
                if ($payType === 'cash') {
                    $postAccountId = ensure_default_account($pdo, $businessId);
                } else {
                    $accStmt = $pdo->prepare('SELECT id FROM tbl_accounts WHERE business_id = :bid AND type = :type AND status = "active" ORDER BY is_default DESC, id ASC LIMIT 1');
                    $accStmt->execute([':bid' => $businessId, ':type' => $payType]);
                    $postAccountId = (int) ($accStmt->fetchColumn() ?: 0);
                    if ($postAccountId <= 0) {
                        $label = $payType === 'bank' ? 'Bank' : 'Lipa kwa simu';
                        respond(422, ['ok' => false, 'message' => "No {$label} account is set up yet. Open Bank & Cash, add a {$label} account, then complete this sale."]);
                    }
                }
            }

            $pdo->beginTransaction();
            try {
                $countStmt = $pdo->prepare('SELECT COUNT(*) FROM tbl_sales WHERE business_id = :bid AND sale_type = "pos"');
                $countStmt->execute([':bid' => $businessId]);
                $seq = ((int) $countStmt->fetchColumn()) + 1;
                $receiptNumber = 'POS-' . date('Ym') . '-' . str_pad((string) $seq, 4, '0', STR_PAD_LEFT);
                $today = date('Y-m-d');
                $saleStatus = $isPayLater ? 'sent' : 'paid';

                $ins = $pdo->prepare(
                    'INSERT INTO tbl_sales
                        (business_id, sale_type, invoice_number, customer_id, customer_name, status, issue_date, due_date,
                         subtotal, discount, tax_rate, tax_amount, total_amount, amount_paid, notes, created_by, shift_id, payment_method)
                     VALUES (:bid, "pos", :inv, :cid, :cname, :status, :idate, NULL,
                         :sub, 0.00, :trate, :tamt, :tot, :paid, :notes, :uid, :shift, :pm)'
                );
                $ins->execute([
                    ':bid' => $businessId,
                    ':inv' => $receiptNumber,
                    ':cid' => $customerId > 0 ? $customerId : null,
                    ':cname' => $customerName,
                    ':status' => $saleStatus,
                    ':idate' => $today,
                    ':sub' => $subtotal,
                    ':trate' => $taxRate,
                    ':tamt' => $taxAmount,
                    ':tot' => $totalAmount,
                    ':paid' => min($amountPaid, $totalAmount), // store settled amount, not change
                    ':notes' => $isPayLater ? 'POS sale (pay later)' : 'POS sale (' . $paymentMethod . ')',
                    ':uid' => $userId,
                    ':shift' => $shiftId,
                    ':pm' => $paymentMethod,
                ]);
                $saleId = (int) $pdo->lastInsertId();

                $liStmt = $pdo->prepare(
                    'INSERT INTO tbl_sale_items (sale_id, item_id, item_name, quantity, unit_price, line_total, vat_applicable, tax_inclusive)
                     VALUES (:sid, :iid, :iname, :qty, :price, :ltot, :vat, :taxincl)'
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
                        ':taxincl' => $v['tax_inclusive'],
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

                // Auto-post the settled amount into the resolved Bank & Cash account.
                $settled = min($amountPaid, $totalAmount);
                if ($settled > 0 && $postAccountId > 0) {
                    post_account_txn($pdo, $businessId, $postAccountId, 'in', 'sale', $settled, 'POS', $receiptNumber, 'POS sale ' . $receiptNumber . ' (' . $paymentMethod . ')', $userId);
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

            $chk = $pdo->prepare('SELECT id, status, total_amount, amount_paid FROM tbl_sales WHERE id = :id AND business_id = :bid LIMIT 1');
            $chk->execute([':id' => $saleId, ':bid' => $businessId]);
            $sale = $chk->fetch();
            if (!$sale) {
                respond(404, ['ok' => false, 'message' => 'Invoice not found.']);
            }
            if ($sale['status'] === 'cancelled') {
                respond(422, ['ok' => false, 'message' => 'Reactivate the invoice before changing its status.']);
            }

            if ($newStatus === 'paid') {
                $currentPaid = (float) ($sale['amount_paid'] ?? 0);
                $totalAmount = (float) $sale['total_amount'];
                $paymentAmount = isset($_POST['amount_paid']) ? round((float) $_POST['amount_paid'], 2) : round($totalAmount - $currentPaid, 2);
                if ($paymentAmount <= 0) {
                    respond(422, ['ok' => false, 'message' => 'Enter a payment amount.']);
                }
                $balanceDue = round($totalAmount - $currentPaid, 2);
                if ($paymentAmount > $balanceDue) {
                    respond(422, ['ok' => false, 'message' => 'Payment cannot exceed the invoice balance.']);
                }

                $postAccountId = (int) ($_POST['account_id'] ?? 0);
                if ($postAccountId <= 0) {
                    $postAccountId = ensure_default_account($pdo, $businessId);
                } else {
                    $accChk = $pdo->prepare('SELECT id FROM tbl_accounts WHERE id = :id AND business_id = :bid AND status = "active" AND type IN ("cash", "mobile", "bank") LIMIT 1');
                    $accChk->execute([':id' => $postAccountId, ':bid' => $businessId]);
                    $postAccountId = (int) ($accChk->fetchColumn() ?: 0);
                    if ($postAccountId <= 0) {
                        respond(422, ['ok' => false, 'message' => 'Choose an active Cash, Lipa kwa simu or Bank account.']);
                    }
                }

                $newPaid = round($currentPaid + $paymentAmount, 2);
                $finalStatus = $newPaid >= $totalAmount ? 'paid' : 'sent';
                $pdo->beginTransaction();
                try {
                    $pdo->prepare('UPDATE tbl_sales SET status = :status, amount_paid = :paid WHERE id = :id')
                        ->execute([':status' => $finalStatus, ':paid' => min($newPaid, $totalAmount), ':id' => $saleId]);
                    post_account_txn(
                        $pdo,
                        $businessId,
                        $postAccountId,
                        'in',
                        'invoice_payment',
                        $paymentAmount,
                        'INVOICE',
                        (string) $saleId,
                        'Invoice payment (#' . $saleId . ')',
                        $userId
                    );
                    $pdo->commit();
                } catch (Throwable $e) {
                    $pdo->rollBack();
                    throw $e;
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
