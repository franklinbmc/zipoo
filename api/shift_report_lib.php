<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once dirname(__DIR__) . '/vendor/autoload.php';

use Dompdf\Dompdf;
use Dompdf\Options;
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception as MailException;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;

/**
 * Retrieves comprehensive report data for a specific shift including
 * cash reconciliation, payment method totals, and all sold items.
 */
function get_shift_report_data(PDO $pdo, int $businessId, int $shiftId): ?array
{
    $stmt = $pdo->prepare(
        'SELECT s.*, u.full_name AS cashier, u.email AS cashier_email 
         FROM tbl_pos_shifts s
         LEFT JOIN tbl_users u ON u.id = s.user_id
         WHERE s.id = :sid AND s.business_id = :bid
         LIMIT 1'
    );
    $stmt->execute([':sid' => $shiftId, ':bid' => $businessId]);
    $shift = $stmt->fetch();
    if (!$shift) {
        return null;
    }

    $bStmt = $pdo->prepare('SELECT * FROM tbl_businesses WHERE id = :bid LIMIT 1');
    $bStmt->execute([':bid' => $businessId]);
    $bRow = $bStmt->fetch() ?: [];
    $business = [
        'name' => (string) ($bRow['business_name'] ?? $bRow['name'] ?? 'Zipoo Business'),
        'phone' => (string) ($bRow['phone'] ?? ''),
        'email' => (string) ($bRow['email'] ?? ''),
        'tin_number' => (string) ($bRow['tin'] ?? $bRow['tin_number'] ?? ''),
        'vrn_number' => (string) ($bRow['vrn'] ?? $bRow['vrn_number'] ?? ''),
        'address' => (string) ($bRow['address'] ?? ''),
    ];

    // Totals by payment method
    $pStmt = $pdo->prepare(
        'SELECT 
            COUNT(*) AS tx_count,
            COALESCE(SUM(total_amount), 0) AS total_sales,
            COALESCE(SUM(amount_paid), 0) AS total_paid,
            COALESCE(SUM(CASE WHEN payment_method = "cash" THEN amount_paid ELSE 0 END), 0) AS cash_sales,
            COALESCE(SUM(CASE WHEN payment_method = "bank" THEN amount_paid ELSE 0 END), 0) AS bank_sales,
            COALESCE(SUM(CASE WHEN payment_method = "mobile" THEN amount_paid ELSE 0 END), 0) AS mobile_sales
         FROM tbl_sales
         WHERE shift_id = :sid AND business_id = :bid AND status != "cancelled"'
    );
    $pStmt->execute([':sid' => $shiftId, ':bid' => $businessId]);
    $salesSummary = $pStmt->fetch() ?: [];

    // Grouped sold items
    $iStmt = $pdo->prepare(
        'SELECT 
            si.item_name,
            SUM(si.quantity) AS quantity,
            CASE WHEN SUM(si.quantity) > 0 THEN ROUND(SUM(si.line_total) / SUM(si.quantity), 2) ELSE ROUND(AVG(si.unit_price), 2) END AS unit_price,
            ROUND(SUM(si.line_total), 2) AS line_total
         FROM tbl_sale_items si
         JOIN tbl_sales s ON s.id = si.sale_id
         WHERE s.shift_id = :sid AND s.business_id = :bid AND s.status != "cancelled"
         GROUP BY si.item_id, si.item_name
         ORDER BY line_total DESC, quantity DESC'
    );
    $iStmt->execute([':sid' => $shiftId, ':bid' => $businessId]);
    $soldItems = $iStmt->fetchAll() ?: [];

    // Individual sales transactions
    $tStmt = $pdo->prepare(
        'SELECT 
            s.id, s.invoice_number, s.issue_date, s.created_at, s.payment_method, s.total_amount,
            COALESCE(c.full_name, "Walk-in Customer") AS customer_name
         FROM tbl_sales s
         LEFT JOIN tbl_customers c ON c.id = s.customer_id
         WHERE s.shift_id = :sid AND s.business_id = :bid AND s.status != "cancelled"
         ORDER BY s.id ASC'
    );
    $tStmt->execute([':sid' => $shiftId, ':bid' => $businessId]);
    $transactions = $tStmt->fetchAll() ?: [];

    $openingBalance = (float) $shift['opening_balance'];
    $cashSales = (float) ($salesSummary['cash_sales'] ?? 0);
    $expectedCash = $shift['expected_cash'] !== null 
        ? (float) $shift['expected_cash'] 
        : round($openingBalance + $cashSales, 2);
    
    $closingBalance = $shift['closing_balance'] !== null ? (float) $shift['closing_balance'] : null;
    $variance = $shift['variance'] !== null 
        ? (float) $shift['variance'] 
        : ($closingBalance !== null ? round($closingBalance - $expectedCash, 2) : null);

    return [
        'shift' => [
            'id' => (int) $shift['id'],
            'status' => (string) $shift['status'],
            'opened_at' => (string) $shift['opened_at'],
            'closed_at' => $shift['closed_at'] !== null ? (string) $shift['closed_at'] : null,
            'opening_balance' => $openingBalance,
            'closing_balance' => $closingBalance,
            'expected_cash' => $expectedCash,
            'variance' => $variance,
            'notes' => (string) ($shift['notes'] ?? ''),
            'cashier' => (string) ($shift['cashier'] ?? 'Cashier'),
            'cashier_email' => (string) ($shift['cashier_email'] ?? ''),
        ],
        'business' => $business,
        'sales_summary' => [
            'tx_count' => (int) ($salesSummary['tx_count'] ?? 0),
            'total_sales' => (float) ($salesSummary['total_sales'] ?? 0),
            'total_paid' => (float) ($salesSummary['total_paid'] ?? 0),
            'cash_sales' => $cashSales,
            'bank_sales' => (float) ($salesSummary['bank_sales'] ?? 0),
            'mobile_sales' => (float) ($salesSummary['mobile_sales'] ?? 0),
        ],
        'sold_items' => $soldItems,
        'transactions' => $transactions,
    ];
}

/**
 * Generates clean HTML template for Dompdf.
 */
function generate_shift_pdf_html(array $data): string
{
    $shift = $data['shift'];
    $biz = $data['business'];
    $summary = $data['sales_summary'];
    $items = $data['sold_items'];
    $txns = $data['transactions'];

    $bizName = htmlspecialchars($biz['name'] ?? 'Zipoo Business');
    $bizPhone = htmlspecialchars($biz['phone'] ?? '');
    $bizEmail = htmlspecialchars($biz['email'] ?? '');
    $bizTin = htmlspecialchars($biz['tin_number'] ?? '');
    $cashier = htmlspecialchars($shift['cashier'] ?? 'Cashier');

    $openedAt = htmlspecialchars($shift['opened_at'] ?? '');
    $closedAt = $shift['closed_at'] ? htmlspecialchars($shift['closed_at']) : 'In Progress (Open)';
    $statusText = strtoupper($shift['status'] ?? 'OPEN');
    $badgeClass = $shift['status'] === 'closed' ? 'badge-closed' : 'badge-open';

    $openingBal = number_format($shift['opening_balance'], 2);
    $cashSales = number_format($summary['cash_sales'], 2);
    $expectedCash = number_format($shift['expected_cash'], 2);
    $closingBal = $shift['closing_balance'] !== null ? number_format($shift['closing_balance'], 2) : '—';

    $varVal = $shift['variance'] ?? 0.0;
    $varFormatted = number_format($varVal, 2);
    if ($shift['closing_balance'] === null) {
        $varHtml = '—';
    } elseif ($varVal == 0) {
        $varHtml = "<span style='color: #166534; font-weight: bold;'>TZS {$varFormatted} (Balanced)</span>";
    } elseif ($varVal < 0) {
        $varHtml = "<span style='color: #dc2626; font-weight: bold;'>TZS {$varFormatted} (Shortage)</span>";
    } else {
        $varHtml = "<span style='color: #2563eb; font-weight: bold;'>+TZS {$varFormatted} (Surplus)</span>";
    }

    $totalSales = number_format($summary['total_sales'], 2);
    $bankSales = number_format($summary['bank_sales'], 2);
    $mobileSales = number_format($summary['mobile_sales'], 2);
    $txCount = (int) $summary['tx_count'];

    $itemsHtml = '';
    $totalQty = 0;
    $totalItemsAmount = 0.0;
    if (empty($items)) {
        $itemsHtml = '<tr><td colspan="5" style="text-align: center; color: #64748b; padding: 12px;">No items sold during this shift.</td></tr>';
    } else {
        $idx = 1;
        foreach ($items as $it) {
            $qty = (float) $it['quantity'];
            $price = number_format((float) $it['unit_price'], 2);
            $total = (float) $it['line_total'];
            $totalQty += $qty;
            $totalItemsAmount += $total;

            $itemsHtml .= '<tr>
                <td class="text-center">' . $idx++ . '</td>
                <td>' . htmlspecialchars((string) $it['item_name']) . '</td>
                <td class="text-center">' . number_format($qty, 0) . '</td>
                <td class="text-right">TZS ' . $price . '</td>
                <td class="text-right"><strong>TZS ' . number_format($total, 2) . '</strong></td>
            </tr>';
        }
    }

    $txnsHtml = '';
    if (!empty($txns)) {
        $tIdx = 1;
        foreach ($txns as $tx) {
            $txnsHtml .= '<tr>
                <td class="text-center">' . $tIdx++ . '</td>
                <td>' . htmlspecialchars((string) $tx['invoice_number']) . '</td>
                <td>' . htmlspecialchars((string) $tx['created_at']) . '</td>
                <td>' . htmlspecialchars((string) $tx['customer_name']) . '</td>
                <td class="text-center" style="text-transform: uppercase;">' . htmlspecialchars((string) $tx['payment_method']) . '</td>
                <td class="text-right">TZS ' . number_format((float) $tx['total_amount'], 2) . '</td>
            </tr>';
        }
    }

    return "
    <!doctype html>
    <html lang='en'>
    <head>
      <meta charset='utf-8'>
      <title>POS Shift Report #{$shift['id']}</title>
      <style>
        body { font-family: Helvetica, Arial, sans-serif; font-size: 11px; color: #1e293b; line-height: 1.4; margin: 16px 20px; }
        .header-table { width: 100%; border-bottom: 2.5px solid #0d2b5b; padding-bottom: 12px; margin-bottom: 16px; }
        .biz-title { font-size: 19px; font-weight: bold; color: #0d2b5b; }
        .biz-meta { font-size: 10px; color: #64748b; margin-top: 3px; }
        .report-title { font-size: 15px; font-weight: bold; color: #0d2b5b; text-align: right; text-transform: uppercase; }
        .report-sub { font-size: 10px; color: #64748b; text-align: right; margin-top: 2px; }
        
        .badge { display: inline-block; padding: 3px 8px; border-radius: 4px; font-size: 9px; font-weight: bold; }
        .badge-open { background: #dcfce7; color: #166534; }
        .badge-closed { background: #f1f5f9; color: #334155; }

        .meta-grid { width: 100%; margin-bottom: 14px; border-collapse: collapse; }
        .meta-grid td { padding: 4px 8px; font-size: 10.5px; vertical-align: top; }
        .meta-label { color: #64748b; font-weight: bold; width: 18%; }
        .meta-val { width: 32%; }

        .section-heading {
          font-size: 11px;
          font-weight: bold;
          text-transform: uppercase;
          color: #0d2b5b;
          border-bottom: 1.5px solid #cbd5e1;
          padding-bottom: 4px;
          margin-top: 14px;
          margin-bottom: 8px;
          letter-spacing: 0.04em;
        }

        .summary-table { width: 100%; border-collapse: collapse; margin-bottom: 14px; }
        .summary-table td { padding: 6px 10px; font-size: 10.5px; border-bottom: 1px solid #f1f5f9; }
        .summary-table tr:nth-child(even) td { background: #f8fafc; }
        .summary-label { color: #475569; width: 45%; }
        .summary-val { text-align: right; font-weight: bold; color: #0f172a; }

        .highlight-box {
          background: #f8fafc;
          border: 1.5px solid #0d2b5b;
          border-radius: 6px;
          padding: 10px 14px;
          margin-bottom: 14px;
        }
        .highlight-grid { width: 100%; border-collapse: collapse; }
        .highlight-grid td { padding: 3px 0; font-size: 11px; }

        .data-table { width: 100%; border-collapse: collapse; margin-top: 6px; margin-bottom: 16px; }
        .data-table th { background: #0d2b5b; color: #ffffff; text-align: left; padding: 6px 8px; font-size: 10px; text-transform: uppercase; }
        .data-table td { padding: 6px 8px; border-bottom: 1px solid #e2e8f0; font-size: 10px; }
        .data-table tr:nth-child(even) td { background: #f8fafc; }
        .total-row td { background: #e2e8f0 !important; font-weight: bold; font-size: 10.5px; border-top: 1.5px solid #94a3b8; }

        .text-right { text-align: right; }
        .text-center { text-align: center; }

        .footer {
          margin-top: 24px;
          padding-top: 10px;
          border-top: 1px solid #cbd5e1;
          font-size: 9px;
          color: #94a3b8;
          text-align: center;
        }
      </style>
    </head>
    <body>
      <table class='header-table'>
        <tr>
          <td>
            <div class='biz-title'>{$bizName}</div>
            <div class='biz-meta'>
              " . ($bizPhone ? "Phone: {$bizPhone} &bull; " : "") . "
              " . ($bizEmail ? "Email: {$bizEmail} &bull; " : "") . "
              " . ($bizTin ? "TIN: {$bizTin}" : "") . "
            </div>
          </td>
          <td style='text-align: right;'>
            <div class='report-title'>POS Shift Sales Report</div>
            <div class='report-sub'>Shift #{$shift['id']} &bull; <span class='badge {$badgeClass}'>{$statusText}</span></div>
          </td>
        </tr>
      </table>

      <!-- Shift Metadata -->
      <table class='meta-grid'>
        <tr>
          <td class='meta-label'>Cashier:</td>
          <td class='meta-val'><strong>{$cashier}</strong></td>
          <td class='meta-label'>Opened At:</td>
          <td class='meta-val'>{$openedAt}</td>
        </tr>
        <tr>
          <td class='meta-label'>Shift Status:</td>
          <td class='meta-val'>{$statusText}</td>
          <td class='meta-label'>Closed At:</td>
          <td class='meta-val'>{$closedAt}</td>
        </tr>
      </table>

      <!-- Cash Reconciliation Highlight Box -->
      <div class='section-heading'>1. Cash &amp; Drawer Reconciliation</div>
      <div class='highlight-box'>
        <table class='highlight-grid'>
          <tr>
            <td style='color: #475569;'>Opening Cash Balance (Start of Shift):</td>
            <td class='text-right'><strong>TZS {$openingBal}</strong></td>
          </tr>
          <tr>
            <td style='color: #475569;'>Cash Sales (Collected in Drawer):</td>
            <td class='text-right'><strong>+ TZS {$cashSales}</strong></td>
          </tr>
          <tr style='border-top: 1px solid #cbd5e1;'>
            <td style='padding-top: 5px; font-weight: bold; color: #0d2b5b;'>Expected Cash in Drawer:</td>
            <td class='text-right' style='padding-top: 5px; font-weight: bold; color: #0d2b5b;'>TZS {$expectedCash}</td>
          </tr>
          <tr>
            <td style='color: #475569;'>Actual Counted Closing Cash:</td>
            <td class='text-right'><strong>TZS {$closingBal}</strong></td>
          </tr>
          <tr style='border-top: 1.5px dashed #cbd5e1;'>
            <td style='padding-top: 5px; font-weight: bold;'>Variance (Counted vs Expected):</td>
            <td class='text-right' style='padding-top: 5px;'>{$varHtml}</td>
          </tr>
        </table>
      </div>

      <!-- Sales by Payment Method -->
      <div class='section-heading'>2. Shift Sales Summary</div>
      <table class='summary-table'>
        <tr>
          <td class='summary-label'>Cash Sales</td>
          <td class='summary-val'>TZS {$cashSales}</td>
        </tr>
        <tr>
          <td class='summary-label'>Bank Transfer / POS Card</td>
          <td class='summary-val'>TZS {$bankSales}</td>
        </tr>
        <tr>
          <td class='summary-label'>Mobile Money (M-Pesa / Tigo / Airtel)</td>
          <td class='summary-val'>TZS {$mobileSales}</td>
        </tr>
        <tr style='border-top: 2px solid #0d2b5b;'>
          <td class='summary-label' style='font-weight: bold; color: #0d2b5b;'>Grand Total Sales</td>
          <td class='summary-val' style='font-size: 12px; color: #0d2b5b;'>TZS {$totalSales}</td>
        </tr>
        <tr>
          <td class='summary-label'>Completed Transactions</td>
          <td class='summary-val'>{$txCount} sales</td>
        </tr>
      </table>

      <!-- Sold Items Table -->
      <div class='section-heading'>3. Items Sold During Shift</div>
      <table class='data-table'>
        <thead>
          <tr>
            <th class='text-center' style='width: 30px;'>#</th>
            <th>Item / Product Name</th>
            <th class='text-center' style='width: 60px;'>Qty Sold</th>
            <th class='text-right' style='width: 100px;'>Avg. Unit Price</th>
            <th class='text-right' style='width: 110px;'>Total Amount</th>
          </tr>
        </thead>
        <tbody>
          {$itemsHtml}
          <tr class='total-row'>
            <td colspan='2'>TOTAL ITEMS SOLD</td>
            <td class='text-center'>" . number_format($totalQty, 0) . "</td>
            <td></td>
            <td class='text-right'>TZS " . number_format($totalItemsAmount, 2) . "</td>
          </tr>
        </tbody>
      </table>

      " . (!empty($txnsHtml) ? "
      <!-- Transactions Breakdown -->
      <div class='section-heading' style='page-break-before: auto;'>4. Transactions List</div>
      <table class='data-table'>
        <thead>
          <tr>
            <th class='text-center' style='width: 30px;'>#</th>
            <th>Receipt #</th>
            <th>Date / Time</th>
            <th>Customer</th>
            <th class='text-center'>Payment</th>
            <th class='text-right'>Total</th>
          </tr>
        </thead>
        <tbody>
          {$txnsHtml}
        </tbody>
      </table>
      " : "") . "

      <div class='footer'>
        Generated by Zipoo Business Anywhere &bull; " . date('Y-m-d H:i:s') . " &bull; Shift #{$shift['id']} Report
      </div>
    </body>
    </html>
    ";
}

/**
 * Renders shift report as a PDF binary string.
 */
function render_shift_pdf(array $data): string
{
    $html = generate_shift_pdf_html($data);
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

/**
 * Generates and streams an Excel (.xlsx) file with shift sales and sold items.
 */
function export_shift_excel(array $data): void
{
    $shift = $data['shift'];
    $biz = $data['business'];
    $summary = $data['sales_summary'];
    $items = $data['sold_items'];
    $txns = $data['transactions'];

    $spreadsheet = new Spreadsheet();
    $sheet = $spreadsheet->getActiveSheet();
    $sheet->setTitle('Shift #' . $shift['id']);

    // Set document properties
    $spreadsheet->getProperties()
        ->setCreator('Zipoo Business Anywhere')
        ->setTitle('Shift Sales Report #' . $shift['id']);

    // Title Block
    $sheet->setCellValue('A1', ($biz['name'] ?? 'Zipoo Business') . ' - POS Shift Report #' . $shift['id']);
    $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14)->getColor()->setARGB('FF0D2B5B');

    $sheet->setCellValue('A2', 'Generated: ' . date('Y-m-d H:i:s') . ' | Status: ' . strtoupper($shift['status']));
    $sheet->getStyle('A2')->getFont()->setItalic(true)->setSize(10)->getColor()->setARGB('FF64748B');

    // Section 1: Shift & Cash Reconciliation
    $sheet->setCellValue('A4', '1. SHIFT & CASH RECONCILIATION');
    $sheet->getStyle('A4')->getFont()->setBold(true)->setSize(11)->getColor()->setARGB('FF0D2B5B');

    $sheet->setCellValue('A5', 'Cashier');
    $sheet->setCellValue('B5', $shift['cashier']);
    $sheet->setCellValue('A6', 'Opened At');
    $sheet->setCellValue('B6', $shift['opened_at']);
    $sheet->setCellValue('A7', 'Closed At');
    $sheet->setCellValue('B7', $shift['closed_at'] ?: 'In Progress (Open)');

    $sheet->setCellValue('D5', 'Opening Cash');
    $sheet->setCellValue('E5', $shift['opening_balance']);
    $sheet->setCellValue('D6', 'Cash Sales');
    $sheet->setCellValue('E6', $summary['cash_sales']);
    $sheet->setCellValue('D7', 'Expected Cash');
    $sheet->setCellValue('E7', $shift['expected_cash']);
    $sheet->setCellValue('D8', 'Counted Closing Cash');
    $sheet->setCellValue('E8', $shift['closing_balance'] !== null ? $shift['closing_balance'] : '—');
    $sheet->setCellValue('D9', 'Variance');
    $sheet->setCellValue('E9', $shift['variance'] !== null ? $shift['variance'] : '—');

    $sheet->getStyle('D5:D9')->getFont()->setBold(true);
    $sheet->getStyle('E5:E7')->getNumberFormat()->setFormatCode('#,##0.00');
    if ($shift['closing_balance'] !== null) {
        $sheet->getStyle('E8:E9')->getNumberFormat()->setFormatCode('#,##0.00');
    }

    // Section 2: Payment Method Breakdown
    $sheet->setCellValue('A11', '2. PAYMENT METHODS');
    $sheet->getStyle('A11')->getFont()->setBold(true)->setSize(11)->getColor()->setARGB('FF0D2B5B');

    $sheet->setCellValue('A12', 'Payment Method');
    $sheet->setCellValue('B12', 'Amount (TZS)');
    $sheet->getStyle('A12:B12')->getFont()->setBold(true)->getColor()->setARGB('FFFFFFFF');
    $sheet->getStyle('A12:B12')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FF0D2B5B');

    $sheet->setCellValue('A13', 'Cash');
    $sheet->setCellValue('B13', $summary['cash_sales']);
    $sheet->setCellValue('A14', 'Bank');
    $sheet->setCellValue('B14', $summary['bank_sales']);
    $sheet->setCellValue('A15', 'Mobile Money');
    $sheet->setCellValue('B15', $summary['mobile_sales']);
    $sheet->setCellValue('A16', 'TOTAL SALES');
    $sheet->setCellValue('B16', '=SUM(B13:B15)');
    $sheet->getStyle('A16:B16')->getFont()->setBold(true);
    $sheet->getStyle('B13:B16')->getNumberFormat()->setFormatCode('#,##0.00');

    // Section 3: Sold Items Table
    $sheet->setCellValue('A18', '3. ITEMS SOLD LIST');
    $sheet->getStyle('A18')->getFont()->setBold(true)->setSize(11)->getColor()->setARGB('FF0D2B5B');

    $itemHeaders = ['#', 'Item Name', 'Quantity Sold', 'Avg. Unit Price (TZS)', 'Total Amount (TZS)'];
    $colLetter = 'A';
    foreach ($itemHeaders as $hdr) {
        $sheet->setCellValue($colLetter . '19', $hdr);
        $colLetter++;
    }
    $sheet->getStyle('A19:E19')->getFont()->setBold(true)->getColor()->setARGB('FFFFFFFF');
    $sheet->getStyle('A19:E19')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FF0D2B5B');

    $row = 20;
    if (empty($items)) {
        $sheet->setCellValue('A20', 'No items sold during this shift.');
        $sheet->mergeCells('A20:E20');
        $row = 21;
    } else {
        $i = 1;
        foreach ($items as $it) {
            $sheet->setCellValue('A' . $row, $i++);
            $sheet->setCellValue('B' . $row, $it['item_name']);
            $sheet->setCellValue('C' . $row, (float) $it['quantity']);
            $sheet->setCellValue('D' . $row, (float) $it['unit_price']);
            $sheet->setCellValue('E' . $row, (float) $it['line_total']);

            $sheet->getStyle('C' . $row)->getNumberFormat()->setFormatCode('#,##0');
            $sheet->getStyle('D' . $row . ':E' . $row)->getNumberFormat()->setFormatCode('#,##0.00');
            $row++;
        }
        // Total row
        $sheet->setCellValue('B' . $row, 'TOTAL');
        $sheet->setCellValue('C' . $row, '=SUM(C20:C' . ($row - 1) . ')');
        $sheet->setCellValue('E' . $row, '=SUM(E20:E' . ($row - 1) . ')');
        $sheet->getStyle('B' . $row . ':E' . $row)->getFont()->setBold(true);
        $sheet->getStyle('C' . $row)->getNumberFormat()->setFormatCode('#,##0');
        $sheet->getStyle('E' . $row)->getNumberFormat()->setFormatCode('#,##0.00');
        $row++;
    }

    // Auto-fit columns
    foreach (range('A', 'E') as $col) {
        $sheet->getColumnDimension($col)->setAutoSize(true);
    }

    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="Shift_Report_' . $shift['id'] . '.xlsx"');
    header('Cache-Control: max-age=0');

    $writer = new Xlsx($spreadsheet);
    $writer->save('php://output');
    exit;
}

/**
 * Emails the Shift Sales PDF report as an attachment via PHPMailer.
 */
function send_shift_email_report(PDO $pdo, array $data, string $recipientEmail): array
{
    if (!filter_var($recipientEmail, FILTER_VALIDATE_EMAIL)) {
        return ['ok' => false, 'message' => 'Please provide a valid recipient email address.'];
    }

    // Load SMTP settings from tbl_saas_settings
    $stmt = $pdo->prepare('SELECT setting_key, setting_value FROM tbl_saas_settings WHERE setting_group = "smtp"');
    $stmt->execute();
    $smtp = [];
    foreach ($stmt->fetchAll() as $row) {
        $smtp[$row['setting_key']] = $row['setting_value'];
    }

    if (empty($smtp['smtp_host']) || empty($smtp['from_email'])) {
        return [
            'ok' => false,
            'message' => 'SMTP settings are not configured yet. Please configure SMTP in Settings to send emails.'
        ];
    }

    $shift = $data['shift'];
    $biz = $data['business'];
    $summary = $data['sales_summary'];
    $bizName = $biz['name'] ?? 'Zipoo Business';

    try {
        $pdfContent = render_shift_pdf($data);

        $mail = new PHPMailer(true);
        $mail->isSMTP();
        $mail->Host = $smtp['smtp_host'];
        $mail->Port = (int) ($smtp['smtp_port'] ?? 587);
        $mail->CharSet = 'UTF-8';
        $mail->Timeout = 15;

        $encryption = $smtp['smtp_encryption'] ?? 'tls';
        if ($encryption === 'ssl') {
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
        } elseif ($encryption === 'tls') {
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        } else {
            $mail->SMTPSecure = false;
            $mail->SMTPAutoTLS = false;
        }

        if (!empty($smtp['smtp_username'])) {
            $mail->SMTPAuth = true;
            $mail->Username = $smtp['smtp_username'];
            $mail->Password = $smtp['smtp_password'] ?? '';
        }

        $mail->setFrom($smtp['from_email'], $bizName);
        $mail->addAddress($recipientEmail);
        $mail->Subject = "POS Shift Sales Report #{$shift['id']} - {$bizName}";

        // Attach PDF
        $mail->addStringAttachment($pdfContent, "Shift_Report_{$shift['id']}.pdf", 'base64', 'application/pdf');

        $opening = number_format($shift['opening_balance'], 2);
        $cashSales = number_format($summary['cash_sales'], 2);
        $expected = number_format($shift['expected_cash'], 2);
        $counted = $shift['closing_balance'] !== null ? number_format($shift['closing_balance'], 2) : '—';
        $var = $shift['variance'] !== null ? number_format($shift['variance'], 2) : '—';
        $salesTot = number_format($summary['total_sales'], 2);
        $soldCount = count($data['sold_items']);

        $mail->isHTML(true);
        $mail->Body = "
        <div style='font-family: sans-serif; color: #1e293b; max-width: 600px; margin: 0 auto; padding: 20px; border: 1px solid #e2e8f0; border-radius: 8px;'>
          <h2 style='color: #0d2b5b; margin-top: 0;'>{$bizName} - POS Shift Report #{$shift['id']}</h2>
          <p>Hello,</p>
          <p>Please find attached the detailed POS Shift Sales Report for <strong>Shift #{$shift['id']}</strong>.</p>
          
          <div style='background: #f8fafc; border: 1px solid #cbd5e1; border-radius: 6px; padding: 14px; margin: 16px 0;'>
            <table style='width: 100%; font-size: 13px;'>
              <tr><td><strong>Cashier:</strong></td><td style='text-align: right;'>{$shift['cashier']}</td></tr>
              <tr><td><strong>Shift Opened:</strong></td><td style='text-align: right;'>{$shift['opened_at']}</td></tr>
              <tr><td><strong>Shift Closed:</strong></td><td style='text-align: right;'>{$shift['closed_at']}</td></tr>
              <tr><td style='padding-top: 8px;'><strong>Total Sales:</strong></td><td style='text-align: right; padding-top: 8px; font-weight: bold; color: #0d2b5b;'>TZS {$salesTot}</td></tr>
              <tr><td><strong>Cash Sales:</strong></td><td style='text-align: right;'>TZS {$cashSales}</td></tr>
              <tr><td><strong>Expected Cash:</strong></td><td style='text-align: right;'>TZS {$expected}</td></tr>
              <tr><td><strong>Counted Closing Cash:</strong></td><td style='text-align: right;'>TZS {$counted}</td></tr>
              <tr><td><strong>Variance:</strong></td><td style='text-align: right; font-weight: bold;'>TZS {$var}</td></tr>
              <tr><td><strong>Unique Items Sold:</strong></td><td style='text-align: right;'>{$soldCount} products</td></tr>
            </table>
          </div>

          <p style='font-size: 12px; color: #64748b;'>The full report with sold items breakdown has been attached as a PDF document.</p>
          <hr style='border: none; border-top: 1px solid #e2e8f0; margin: 18px 0;'>
          <p style='font-size: 11px; color: #94a3b8;'>Sent automatically by Zipoo Business Anywhere.</p>
        </div>
        ";
        $mail->AltBody = "POS Shift Report #{$shift['id']}\nTotal Sales: TZS {$salesTot}\nExpected Cash: TZS {$expected}\nCounted Cash: TZS {$counted}\nVariance: TZS {$var}\nPlease see the attached PDF for the full breakdown.";

        $mail->send();
        return ['ok' => true, 'message' => "Shift report sent successfully to {$recipientEmail}"];
    } catch (MailException $e) {
        return ['ok' => false, 'message' => 'Email sending failed: ' . ($mail->ErrorInfo ?: $e->getMessage())];
    } catch (Throwable $t) {
        return ['ok' => false, 'message' => 'Failed to generate report email: ' . $t->getMessage()];
    }
}
