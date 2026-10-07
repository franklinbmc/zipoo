<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once dirname(__DIR__) . '/vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use Dompdf\Dompdf;
use Dompdf\Options;
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception as MailException;

function load_business_group_settings(PDO $pdo, int $businessId, string $group): array
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
    } catch (Throwable $e) {
        return [];
    }

    $settings = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $settings[(string) $row['setting_key']] = $row['setting_value'];
    }
    return $settings;
}

function get_expenses_export_data(PDO $pdo, int $businessId, string $level, string $year, string $month, string $date): array
{
    // Fetch business info
    $bStmt = $pdo->prepare('SELECT * FROM tbl_businesses WHERE id = :id LIMIT 1');
    $bStmt->execute([':id' => $businessId]);
    $biz = $bStmt->fetch(PDO::FETCH_ASSOC) ?: [];
    $currency = (string) ($biz['currency'] ?? 'TZS');
    $bizName = (string) ($biz['business_name'] ?? $biz['name'] ?? 'Zipoo Business');

    // Fetch all expenses for business
    $stmt = $pdo->prepare(
        'SELECT t.*, COALESCE(a.name, "Default Account") AS account_name, COALESCE(a.type, "cash") AS account_type,
                c.name AS category_name, c.status AS category_status
         FROM tbl_account_transactions t
         LEFT JOIN tbl_accounts a ON a.id = t.account_id
         LEFT JOIN tbl_expense_categories c ON c.id = t.expense_category_id AND c.business_id = t.business_id
         WHERE t.business_id = :bid AND t.type = "expense"
         ORDER BY t.created_at DESC, t.id DESC'
    );
    $stmt->execute([':bid' => $businessId]);
    $allExpenses = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $level = in_array($level, ['years', 'months', 'days', 'items'], true) ? $level : 'years';

    $title = 'Expenses Report';
    $subtitle = '';
    $headers = [];
    $rows = [];
    $totalAmount = 0.0;
    $totalCount = 0;

    $monthNames = [
        '01' => 'January', '02' => 'February', '03' => 'March', '04' => 'April',
        '05' => 'May', '06' => 'June', '07' => 'July', '08' => 'August',
        '09' => 'September', '10' => 'October', '11' => 'November', '12' => 'December'
    ];

    $formatMonth = static function (string $mKey) use ($monthNames): string {
        $parts = explode('-', $mKey);
        if (count($parts) >= 2) {
            $mNum = $parts[1];
            $name = $monthNames[$mNum] ?? $mNum;
            return $name . ' ' . $parts[0];
        }
        return $mKey;
    };

    $formatDate = static function (string $dKey): string {
        $ts = strtotime($dKey);
        return $ts ? date('D, M j, Y', $ts) : $dKey;
    };

    if ($level === 'years') {
        $title = 'Annual Expenses Summary';
        $subtitle = 'Expenses grouped by year';
        $headers = ['Year', 'Expenses Count', "Total Amount ({$currency})"];

        $byYear = [];
        foreach ($allExpenses as $r) {
            $y = substr((string) ($r['created_at'] ?? ''), 0, 4) ?: 'Unknown';
            if (!isset($byYear[$y])) {
                $byYear[$y] = ['key' => $y, 'count' => 0, 'total' => 0.0];
            }
            $byYear[$y]['count']++;
            $byYear[$y]['total'] += (float) ($r['amount'] ?? 0);
            $totalAmount += (float) ($r['amount'] ?? 0);
            $totalCount++;
        }
        krsort($byYear);
        foreach ($byYear as $y) {
            $rows[] = [$y['key'], $y['count'], $y['total']];
        }
    } elseif ($level === 'months') {
        $targetYear = $year ?: date('Y');
        $title = "Expenses Summary - Year {$targetYear}";
        $subtitle = "Monthly breakdown for {$targetYear}";
        $headers = ['Month', 'Expenses Count', "Total Amount ({$currency})"];

        $byMonth = [];
        foreach ($allExpenses as $r) {
            $created = (string) ($r['created_at'] ?? '');
            if (substr($created, 0, 4) !== $targetYear) {
                continue;
            }
            $m = substr($created, 0, 7) ?: 'Unknown';
            if (!isset($byMonth[$m])) {
                $byMonth[$m] = ['key' => $m, 'count' => 0, 'total' => 0.0];
            }
            $byMonth[$m]['count']++;
            $byMonth[$m]['total'] += (float) ($r['amount'] ?? 0);
            $totalAmount += (float) ($r['amount'] ?? 0);
            $totalCount++;
        }
        krsort($byMonth);
        foreach ($byMonth as $m) {
            $rows[] = [$formatMonth($m['key']), $m['count'], $m['total']];
        }
    } elseif ($level === 'days') {
        $targetMonth = $month ?: date('Y-m');
        $mTitle = $formatMonth($targetMonth);
        $title = "Expenses Summary - {$mTitle}";
        $subtitle = "Daily breakdown for {$mTitle}";
        $headers = ['Date', 'Expenses Count', "Total Amount ({$currency})"];

        $byDay = [];
        foreach ($allExpenses as $r) {
            $created = (string) ($r['created_at'] ?? '');
            if (substr($created, 0, 7) !== $targetMonth) {
                continue;
            }
            $d = substr($created, 0, 10) ?: 'Unknown';
            if (!isset($byDay[$d])) {
                $byDay[$d] = ['key' => $d, 'count' => 0, 'total' => 0.0];
            }
            $byDay[$d]['count']++;
            $byDay[$d]['total'] += (float) ($r['amount'] ?? 0);
            $totalAmount += (float) ($r['amount'] ?? 0);
            $totalCount++;
        }
        krsort($byDay);
        foreach ($byDay as $d) {
            $rows[] = [$formatDate($d['key']), $d['count'], $d['total']];
        }
    } else { // items
        $targetDate = $date ?: date('Y-m-d');
        $dTitle = $formatDate($targetDate);
        $title = "Detailed Expenses - {$dTitle}";
        $subtitle = "Itemized transactions for {$dTitle}";
        $headers = ['#', 'Time', 'Description', 'Category', 'Paid From', "Amount ({$currency})"];

        $i = 1;
        foreach ($allExpenses as $r) {
            $created = (string) ($r['created_at'] ?? '');
            if (substr($created, 0, 10) !== $targetDate) {
                continue;
            }
            $time = '';
            if (strlen($created) >= 16) {
                $ts = strtotime($created);
                $time = $ts ? date('h:i A', $ts) : substr($created, 11, 5);
            }
            $desc = !empty($r['notes']) ? (string) $r['notes'] : 'Expense';
            $cat = !empty($r['category_name']) ? (string) $r['category_name'] : 'Uncategorized';
            $acc = !empty($r['account_name']) ? (string) $r['account_name'] : 'Default Account';
            $amt = (float) ($r['amount'] ?? 0);

            $rows[] = [$i++, $time, $desc, $cat, $acc, $amt];
            $totalAmount += $amt;
            $totalCount++;
        }
    }

    return [
        'biz' => $biz,
        'biz_name' => $bizName,
        'currency' => $currency,
        'level' => $level,
        'year' => $year,
        'month' => $month,
        'date' => $date,
        'title' => $title,
        'subtitle' => $subtitle,
        'headers' => $headers,
        'rows' => $rows,
        'total_amount' => $totalAmount,
        'total_count' => $totalCount,
        'generated_at' => date('Y-m-d H:i:s'),
    ];
}

function export_expenses_excel(PDO $pdo, int $businessId, string $level, string $year, string $month, string $date): void
{
    $data = get_expenses_export_data($pdo, $businessId, $level, $year, $month, $date);

    $spreadsheet = new Spreadsheet();
    $sheet = $spreadsheet->getActiveSheet();
    $sheet->setTitle('Expenses Report');

    // Title & Header info
    $sheet->setCellValue('A1', $data['biz_name']);
    $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(16)->getColor()->setARGB('FF0D2B5B');

    $sheet->setCellValue('A2', $data['title']);
    $sheet->getStyle('A2')->getFont()->setBold(true)->setSize(12);

    $sheet->setCellValue('A3', 'Generated on: ' . date('M j, Y h:i A') . ' | Currency: ' . $data['currency']);
    $sheet->getStyle('A3')->getFont()->setItalic(true)->setSize(9)->getColor()->setARGB('FF6B7280');

    // Table Headers starting at row 5
    $colCount = count($data['headers']);
    $lastColLetter = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($colCount);

    $colIndex = 1;
    foreach ($data['headers'] as $h) {
        $letter = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($colIndex);
        $sheet->setCellValue($letter . '5', $h);
        $colIndex++;
    }

    $headerRange = "A5:{$lastColLetter}5";
    $sheet->getStyle($headerRange)->getFont()->setBold(true)->getColor()->setARGB('FFFFFFFF');
    $sheet->getStyle($headerRange)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FF0D2B5B');
    $sheet->getStyle($headerRange)->getAlignment()->setVertical(Alignment::VERTICAL_CENTER);

    // Data rows
    $rowNum = 6;
    if (empty($data['rows'])) {
        $sheet->setCellValue('A6', 'No expenses found for this selection.');
        $sheet->mergeCells("A6:{$lastColLetter}6");
        $rowNum = 7;
    } else {
        foreach ($data['rows'] as $r) {
            $cIdx = 1;
            foreach ($r as $val) {
                $letter = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($cIdx);
                $sheet->setCellValue($letter . $rowNum, $val);
                $cIdx++;
            }

            // Format amount in last column
            $sheet->getStyle($lastColLetter . $rowNum)->getNumberFormat()->setFormatCode('#,##0.00');
            $rowNum++;
        }

        // Summary Total row
        $sheet->setCellValue('A' . $rowNum, 'TOTAL');
        $sheet->getStyle('A' . $rowNum)->getFont()->setBold(true);

        if ($colCount > 2) {
            // Count total in column 2 if applicable
            $secondCol = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex(2);
            if ($data['level'] !== 'items') {
                $sheet->setCellValue($secondCol . $rowNum, "=SUM({$secondCol}6:{$secondCol}" . ($rowNum - 1) . ')');
                $sheet->getStyle($secondCol . $rowNum)->getNumberFormat()->setFormatCode('#,##0');
                $sheet->getStyle($secondCol . $rowNum)->getFont()->setBold(true);
            }
        }

        $sheet->setCellValue($lastColLetter . $rowNum, "=SUM({$lastColLetter}6:{$lastColLetter}" . ($rowNum - 1) . ')');
        $sheet->getStyle($lastColLetter . $rowNum)->getNumberFormat()->setFormatCode('#,##0.00');
        $sheet->getStyle("A{$rowNum}:{$lastColLetter}{$rowNum}")->getFont()->setBold(true);
        $sheet->getStyle("A{$rowNum}:{$lastColLetter}{$rowNum}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFF1F5F9');
        $rowNum++;
    }

    // Auto-size columns
    for ($i = 1; $i <= $colCount; $i++) {
        $letter = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($i);
        $sheet->getColumnDimension($letter)->setAutoSize(true);
    }

    $sanitized = preg_replace('/[^a-zA-Z0-9_\-]/', '_', $data['title']);
    $filename = "{$sanitized}.xlsx";

    if (ob_get_length()) {
        ob_end_clean();
    }

    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: max-age=0');

    $writer = new Xlsx($spreadsheet);
    $writer->save('php://output');
    exit;
}

function render_expenses_pdf(PDO $pdo, int $businessId, string $level, string $year, string $month, string $date): string
{
    $data = get_expenses_export_data($pdo, $businessId, $level, $year, $month, $date);

    $bizName = htmlspecialchars($data['biz_name'], ENT_QUOTES, 'UTF-8');
    $title = htmlspecialchars($data['title'], ENT_QUOTES, 'UTF-8');
    $subtitle = htmlspecialchars($data['subtitle'], ENT_QUOTES, 'UTF-8');
    $currency = htmlspecialchars($data['currency'], ENT_QUOTES, 'UTF-8');
    $genDate = date('M j, Y h:i A');

    $rowsHtml = '';
    $lastIdx = count($data['headers']) - 1;

    if (empty($data['rows'])) {
        $rowsHtml = '<tr><td colspan="' . count($data['headers']) . '" style="text-align:center;padding:24px;color:#64748b;">No expenses recorded for this selection.</td></tr>';
    } else {
        foreach ($data['rows'] as $idx => $r) {
            $bg = $idx % 2 === 1 ? '#f8fafc' : '#ffffff';
            $rowsHtml .= "<tr style='background: {$bg};'>";
            foreach ($r as $cIdx => $val) {
                if ($cIdx === $lastIdx) {
                    $formatted = number_format((float) $val, 2) . ' ' . $currency;
                    $rowsHtml .= "<td style='padding:9px 12px;text-align:right;font-weight:700;color:#ef4444;'>{$formatted}</td>";
                } elseif (is_numeric($val) && $cIdx > 0 && $data['level'] !== 'items') {
                    $rowsHtml .= "<td style='padding:9px 12px;text-align:center;'>{$val}</td>";
                } else {
                    $escVal = htmlspecialchars((string) $val, ENT_QUOTES, 'UTF-8');
                    $rowsHtml .= "<td style='padding:9px 12px;color:#1e293b;'>{$escVal}</td>";
                }
            }
            $rowsHtml .= '</tr>';
        }
    }

    $headersHtml = '';
    foreach ($data['headers'] as $cIdx => $h) {
        $align = $cIdx === $lastIdx ? 'right' : ($cIdx === 1 && $data['level'] !== 'items' ? 'center' : 'left');
        $escH = htmlspecialchars($h, ENT_QUOTES, 'UTF-8');
        $headersHtml .= "<th style='padding:10px 12px;text-align:{$align};font-size:11px;text-transform:uppercase;letter-spacing:0.04em;'>{$escH}</th>";
    }

    $formattedTotal = number_format($data['total_amount'], 2) . ' ' . $currency;

    $html = <<<HTML
<!DOCTYPE html>
<html>
<head>
  <meta charset="utf-8">
  <title>{$title}</title>
  <style>
    body {
      font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
      margin: 0;
      padding: 24px;
      color: #1e293b;
      font-size: 12px;
      line-height: 1.4;
    }
    .header-table {
      width: 100%;
      border-bottom: 2px solid #0d2b5b;
      padding-bottom: 14px;
      margin-bottom: 18px;
    }
    .brand-title {
      font-size: 22px;
      font-weight: 900;
      color: #0d2b5b;
      margin: 0;
    }
    .report-title {
      font-size: 14px;
      font-weight: 800;
      color: #1477ff;
      margin: 4px 0 0;
    }
    .meta-text {
      font-size: 10px;
      color: #64748b;
      text-align: right;
    }
    .kpi-box {
      background: #f8fafc;
      border: 1px solid #e2e8f0;
      border-radius: 8px;
      padding: 12px 16px;
      margin-bottom: 18px;
    }
    .data-table {
      width: 100%;
      border-collapse: collapse;
      margin-top: 8px;
    }
    .data-table th {
      background: #0d2b5b;
      color: #ffffff;
      font-weight: 800;
      border: 1px solid #0d2b5b;
    }
    .data-table td {
      border: 1px solid #e2e8f0;
    }
    .total-row {
      background: #f1f5f9;
      font-weight: 800;
    }
    .total-row td {
      border: 1px solid #cbd5e1;
      padding: 10px 12px;
    }
    .footer {
      margin-top: 30px;
      border-top: 1px dashed #cbd5e1;
      padding-top: 10px;
      font-size: 9px;
      color: #94a3b8;
      text-align: center;
    }
  </style>
</head>
<body>
  <table class="header-table">
    <tr>
      <td>
        <h1 class="brand-title">{$bizName}</h1>
        <div class="report-title">{$title}</div>
        <div style="font-size:11px;color:#64748b;margin-top:2px;">{$subtitle}</div>
      </td>
      <td class="meta-text" style="vertical-align:top;">
        <div><strong>Date:</strong> {$genDate}</div>
        <div><strong>Currency:</strong> {$currency}</div>
        <div><strong>Total Records:</strong> {$data['total_count']}</div>
      </td>
    </tr>
  </table>

  <div class="kpi-box">
    <table style="width:100%;">
      <tr>
        <td style="width:50%;">
          <span style="font-size:10px;text-transform:uppercase;color:#64748b;font-weight:700;">Total Entries</span>
          <div style="font-size:18px;font-weight:900;color:#0d2b5b;">{$data['total_count']}</div>
        </td>
        <td style="width:50%;text-align:right;">
          <span style="font-size:10px;text-transform:uppercase;color:#64748b;font-weight:700;">Total Expenses Amount</span>
          <div style="font-size:18px;font-weight:900;color:#ef4444;">{$formattedTotal}</div>
        </td>
      </tr>
    </table>
  </div>

  <table class="data-table">
    <thead>
      <tr>
        {$headersHtml}
      </tr>
    </thead>
    <tbody>
      {$rowsHtml}
      <tr class="total-row">
        <td>TOTAL</td>
        <td colspan="{$lastIdx}" style="text-align:right;font-size:13px;color:#ef4444;">{$formattedTotal}</td>
      </tr>
    </tbody>
  </table>

  <div class="footer">
    Generated automatically by Zipoo Business Operating System &bull; {$bizName}
  </div>
</body>
</html>
HTML;

    $options = new Options();
    $options->set('isHtml5ParserEnabled', true);
    $options->set('isRemoteEnabled', true);

    $dompdf = new Dompdf($options);
    $dompdf->loadHtml($html);
    $dompdf->setPaper('A4', 'portrait');
    $dompdf->render();

    return (string) $dompdf->output();
}

function export_expenses_pdf_download(PDO $pdo, int $businessId, string $level, string $year, string $month, string $date): void
{
    $pdf = render_expenses_pdf($pdo, $businessId, $level, $year, $month, $date);
    $data = get_expenses_export_data($pdo, $businessId, $level, $year, $month, $date);

    $sanitized = preg_replace('/[^a-zA-Z0-9_\-]/', '_', $data['title']);
    $filename = "{$sanitized}.pdf";

    if (ob_get_length()) {
        ob_end_clean();
    }

    header('Content-Type: application/pdf');
    header('Content-Disposition: inline; filename="' . $filename . '"');
    header('Cache-Control: max-age=0');
    echo $pdf;
    exit;
}

function send_expenses_email(PDO $pdo, int $businessId, string $level, string $year, string $month, string $date, string $recipientEmail): array
{
    if (!filter_var($recipientEmail, FILTER_VALIDATE_EMAIL)) {
        return ['ok' => false, 'message' => 'Please provide a valid recipient email address.'];
    }

    $smtp = load_business_group_settings($pdo, $businessId, 'smtp');

    if (empty($smtp['smtp_host']) || empty($smtp['from_email'])) {
        return [
            'ok' => false,
            'message' => 'SMTP is not configured in Settings yet. Please configure SMTP in Settings to send emails directly.'
        ];
    }

    $data = get_expenses_export_data($pdo, $businessId, $level, $year, $month, $date);
    $bizName = $data['biz_name'];
    $title = $data['title'];
    $formattedTotal = number_format($data['total_amount'], 2) . ' ' . $data['currency'];

    try {
        $pdfContent = render_expenses_pdf($pdo, $businessId, $level, $year, $month, $date);

        $mail = new PHPMailer(true);
        $mail->isSMTP();
        $mail->Host = (string) $smtp['smtp_host'];
        $mail->Port = (int) ($smtp['smtp_port'] ?? 587);
        $mail->CharSet = 'UTF-8';
        $mail->Timeout = 15;

        $encryption = (string) ($smtp['smtp_encryption'] ?? 'tls');
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
            $mail->Username = (string) $smtp['smtp_username'];
            $mail->Password = (string) ($smtp['smtp_password'] ?? '');
        }

        $mail->setFrom((string) $smtp['from_email'], $bizName);
        $mail->addAddress($recipientEmail);
        $mail->Subject = "{$title} - {$bizName}";

        $sanitized = preg_replace('/[^a-zA-Z0-9_\-]/', '_', $title);
        $filename = "{$sanitized}.pdf";
        $mail->addStringAttachment($pdfContent, $filename, 'base64', 'application/pdf');

        $mail->isHTML(true);
        $mail->Body = "
        <div style='font-family:-apple-system,BlinkMacSystemFont,\"Segoe UI\",Roboto,sans-serif;color:#1e293b;max-width:600px;margin:0 auto;padding:24px;border:1px solid #e2e8f0;border-radius:12px;background:#ffffff;'>
          <h2 style='color:#0d2b5b;margin-top:0;font-size:20px;'>{$bizName}</h2>
          <h3 style='color:#1477ff;margin-top:4px;'>{$title}</h3>
          <p style='color:#475569;'>Hello,</p>
          <p style='color:#475569;'>Please find attached the official <strong>{$title}</strong> PDF report from {$bizName}.</p>
          
          <div style='background:#f8fafc;border:1px solid #cbd5e1;border-radius:8px;padding:16px;margin:18px 0;'>
            <table style='width:100%;font-size:13px;border-collapse:collapse;'>
              <tr><td style='padding:4px 0;color:#64748b;'>Total Entries:</td><td style='padding:4px 0;text-align:right;font-weight:700;'>{$data['total_count']}</td></tr>
              <tr><td style='padding:4px 0;color:#64748b;'>Total Amount:</td><td style='padding:4px 0;text-align:right;font-weight:900;color:#ef4444;'>{$formattedTotal}</td></tr>
              <tr><td style='padding:4px 0;color:#64748b;'>Generated:</td><td style='padding:4px 0;text-align:right;'>{$data['generated_at']}</td></tr>
            </table>
          </div>

          <p style='color:#64748b;font-size:11px;margin-top:24px;border-top:1px solid #f1f5f9;padding-top:12px;'>
            Sent securely via Zipoo Business Operating System.
          </p>
        </div>";

        $mail->send();
        return ['ok' => true, 'message' => "Expenses report has been sent successfully to {$recipientEmail}."];
    } catch (\Throwable $e) {
        return ['ok' => false, 'message' => 'Failed to send email: ' . $e->getMessage()];
    }
}

function get_payroll_export_data(PDO $pdo, int $businessId, string $month): array
{
    $bStmt = $pdo->prepare('SELECT * FROM tbl_businesses WHERE id = :id LIMIT 1');
    $bStmt->execute([':id' => $businessId]);
    $biz = $bStmt->fetch(PDO::FETCH_ASSOC) ?: [];
    $currency = (string) ($biz['currency'] ?? 'TZS');
    $bizName = (string) ($biz['business_name'] ?? $biz['name'] ?? 'Zipoo Business');

    $month = $month ?: date('Y-m');
    $runStmt = $pdo->prepare('SELECT * FROM tbl_payroll_runs WHERE business_id = :bid AND payroll_month = :month LIMIT 1');
    $runStmt->execute([':bid' => $businessId, ':month' => $month]);
    $run = $runStmt->fetch(PDO::FETCH_ASSOC);

    $items = [];
    if ($run) {
        $itemStmt = $pdo->prepare('SELECT * FROM tbl_payroll_items WHERE business_id = :bid AND run_id = :run ORDER BY staff_name ASC');
        $itemStmt->execute([':bid' => $businessId, ':run' => (int) $run['id']]);
        $items = $itemStmt->fetchAll(PDO::FETCH_ASSOC);
    }

    $total = 0.0;
    $paid = 0.0;
    $rows = [];
    $i = 1;
    foreach ($items as $item) {
        $amt = (float) ($item['salary_amount'] ?? 0);
        $total += $amt;
        if (($item['status'] ?? '') === 'paid') {
            $paid += $amt;
        }
        $rows[] = [
            $i++,
            (string) ($item['staff_name'] ?? 'Staff'),
            (string) ($item['role'] ?? 'Staff'),
            ucfirst((string) ($item['status'] ?? 'pending')),
            $amt,
        ];
    }

    $monthTs = strtotime($month . '-01');
    $monthLabel = $monthTs ? date('F Y', $monthTs) : $month;

    return [
        'biz_name' => $bizName,
        'currency' => $currency,
        'month' => $month,
        'month_label' => $monthLabel,
        'status' => (string) ($run['status'] ?? 'Draft'),
        'staff_count' => count($items),
        'total_amount' => $total,
        'paid_amount' => $paid,
        'pending_amount' => $total - $paid,
        'headers' => ['#', 'Staff Name', 'Role', 'Status', "Salary Amount ({$currency})"],
        'rows' => $rows,
        'generated_at' => date('Y-m-d H:i:s'),
    ];
}

function export_payroll_excel(PDO $pdo, int $businessId, string $month): void
{
    $data = get_payroll_export_data($pdo, $businessId, $month);

    $spreadsheet = new Spreadsheet();
    $sheet = $spreadsheet->getActiveSheet();
    $sheet->setTitle('Payroll ' . $data['month']);

    $sheet->setCellValue('A1', $data['biz_name']);
    $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(16)->getColor()->setARGB('FF0D2B5B');

    $sheet->setCellValue('A2', 'Payroll Salary Sheet - ' . $data['month_label']);
    $sheet->getStyle('A2')->getFont()->setBold(true)->setSize(12);

    $sheet->setCellValue('A3', 'Status: ' . ucfirst($data['status']) . ' | Staff: ' . $data['staff_count'] . ' | Currency: ' . $data['currency']);
    $sheet->getStyle('A3')->getFont()->setItalic(true)->setSize(9)->getColor()->setARGB('FF6B7280');

    $colCount = count($data['headers']);
    $lastCol = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($colCount);

    $colIdx = 1;
    foreach ($data['headers'] as $h) {
        $letter = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($colIdx);
        $sheet->setCellValue($letter . '5', $h);
        $colIdx++;
    }

    $headerRange = "A5:{$lastCol}5";
    $sheet->getStyle($headerRange)->getFont()->setBold(true)->getColor()->setARGB('FFFFFFFF');
    $sheet->getStyle($headerRange)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FF0D2B5B');
    $sheet->getStyle($headerRange)->getAlignment()->setVertical(Alignment::VERTICAL_CENTER);

    $rowNum = 6;
    if (empty($data['rows'])) {
        $sheet->setCellValue('A6', 'No staff salaries recorded for this month.');
        $sheet->mergeCells("A6:{$lastCol}6");
        $rowNum = 7;
    } else {
        foreach ($data['rows'] as $r) {
            $c = 1;
            foreach ($r as $val) {
                $letter = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($c);
                $sheet->setCellValue($letter . $rowNum, $val);
                $c++;
            }
            $sheet->getStyle($lastCol . $rowNum)->getNumberFormat()->setFormatCode('#,##0.00');
            $rowNum++;
        }

        $sheet->setCellValue('A' . $rowNum, 'TOTAL');
        $sheet->setCellValue($lastCol . $rowNum, "=SUM({$lastCol}6:{$lastCol}" . ($rowNum - 1) . ')');
        $sheet->getStyle($lastCol . $rowNum)->getNumberFormat()->setFormatCode('#,##0.00');
        $sheet->getStyle("A{$rowNum}:{$lastCol}{$rowNum}")->getFont()->setBold(true);
        $sheet->getStyle("A{$rowNum}:{$lastCol}{$rowNum}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFF1F5F9');
        $rowNum++;
    }

    for ($i = 1; $i <= $colCount; $i++) {
        $letter = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($i);
        $sheet->getColumnDimension($letter)->setAutoSize(true);
    }

    $filename = "Payroll_{$data['month']}.xlsx";
    if (ob_get_length()) {
        ob_end_clean();
    }

    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: max-age=0');

    $writer = new Xlsx($spreadsheet);
    $writer->save('php://output');
    exit;
}

function render_payroll_pdf(PDO $pdo, int $businessId, string $month): string
{
    $data = get_payroll_export_data($pdo, $businessId, $month);

    $bizName = htmlspecialchars($data['biz_name'], ENT_QUOTES, 'UTF-8');
    $monthLabel = htmlspecialchars($data['month_label'], ENT_QUOTES, 'UTF-8');
    $currency = htmlspecialchars($data['currency'], ENT_QUOTES, 'UTF-8');
    $status = htmlspecialchars(ucfirst($data['status']), ENT_QUOTES, 'UTF-8');
    $genDate = date('M j, Y h:i A');

    $rowsHtml = '';
    if (empty($data['rows'])) {
        $rowsHtml = '<tr><td colspan="5" style="text-align:center;padding:24px;color:#64748b;">No payroll prepared for this month.</td></tr>';
    } else {
        foreach ($data['rows'] as $idx => $r) {
            $bg = $idx % 2 === 1 ? '#f8fafc' : '#ffffff';
            $formattedAmt = number_format((float) $r[4], 2) . ' ' . $currency;
            $statusColor = strtolower($r[3]) === 'paid' ? '#16a34a' : '#d97706';
            $rowsHtml .= "<tr style='background: {$bg};'>
                <td style='padding:9px 12px;text-align:center;'>{$r[0]}</td>
                <td style='padding:9px 12px;font-weight:700;color:#0d2b5b;'>" . htmlspecialchars($r[1], ENT_QUOTES, 'UTF-8') . "</td>
                <td style='padding:9px 12px;color:#475569;'>" . htmlspecialchars($r[2], ENT_QUOTES, 'UTF-8') . "</td>
                <td style='padding:9px 12px;text-align:center;font-weight:700;color:{$statusColor};'>" . htmlspecialchars($r[3], ENT_QUOTES, 'UTF-8') . "</td>
                <td style='padding:9px 12px;text-align:right;font-weight:900;color:#0d2b5b;'>{$formattedAmt}</td>
            </tr>";
        }
    }

    $formattedTotal = number_format($data['total_amount'], 2) . ' ' . $currency;
    $formattedPaid = number_format($data['paid_amount'], 2) . ' ' . $currency;
    $formattedPending = number_format($data['pending_amount'], 2) . ' ' . $currency;

    $html = <<<HTML
<!DOCTYPE html>
<html>
<head>
  <meta charset="utf-8">
  <title>Payroll - {$monthLabel}</title>
  <style>
    body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif; margin: 0; padding: 24px; color: #1e293b; font-size: 12px; line-height: 1.4; }
    .header-table { width: 100%; border-bottom: 2px solid #0d2b5b; padding-bottom: 14px; margin-bottom: 18px; }
    .brand-title { font-size: 22px; font-weight: 900; color: #0d2b5b; margin: 0; }
    .report-title { font-size: 14px; font-weight: 800; color: #1477ff; margin: 4px 0 0; }
    .meta-text { font-size: 10px; color: #64748b; text-align: right; }
    .kpi-table { width: 100%; border-collapse: collapse; margin-bottom: 18px; }
    .kpi-card { background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 10px 14px; }
    .data-table { width: 100%; border-collapse: collapse; margin-top: 8px; }
    .data-table th { background: #0d2b5b; color: #ffffff; font-weight: 800; border: 1px solid #0d2b5b; padding: 10px 12px; text-transform: uppercase; font-size: 11px; letter-spacing: 0.04em; }
    .data-table td { border: 1px solid #e2e8f0; }
    .total-row td { background: #f1f5f9; font-weight: 900; border: 1px solid #cbd5e1; padding: 10px 12px; font-size: 13px; }
    .footer { margin-top: 30px; border-top: 1px dashed #cbd5e1; padding-top: 10px; font-size: 9px; color: #94a3b8; text-align: center; }
  </style>
</head>
<body>
  <table class="header-table">
    <tr>
      <td>
        <h1 class="brand-title">{$bizName}</h1>
        <div class="report-title">Payroll Salary Sheet - {$monthLabel}</div>
        <div style="font-size:11px;color:#64748b;margin-top:2px;">Status: {$status}</div>
      </td>
      <td class="meta-text" style="vertical-align:top;">
        <div><strong>Date:</strong> {$genDate}</div>
        <div><strong>Currency:</strong> {$currency}</div>
        <div><strong>Staff:</strong> {$data['staff_count']}</div>
      </td>
    </tr>
  </table>

  <table class="kpi-table">
    <tr>
      <td style="width:25%;padding-right:6px;">
        <div class="kpi-card">
          <div style="font-size:10px;text-transform:uppercase;color:#64748b;font-weight:700;">Staff</div>
          <div style="font-size:17px;font-weight:900;color:#0d2b5b;">{$data['staff_count']}</div>
        </div>
      </td>
      <td style="width:25%;padding:0 3px;">
        <div class="kpi-card">
          <div style="font-size:10px;text-transform:uppercase;color:#64748b;font-weight:700;">Total</div>
          <div style="font-size:17px;font-weight:900;color:#0d2b5b;">{$formattedTotal}</div>
        </div>
      </td>
      <td style="width:25%;padding:0 3px;">
        <div class="kpi-card">
          <div style="font-size:10px;text-transform:uppercase;color:#64748b;font-weight:700;">Paid</div>
          <div style="font-size:17px;font-weight:900;color:#16a34a;">{$formattedPaid}</div>
        </div>
      </td>
      <td style="width:25%;padding-left:6px;">
        <div class="kpi-card">
          <div style="font-size:10px;text-transform:uppercase;color:#64748b;font-weight:700;">Pending</div>
          <div style="font-size:17px;font-weight:900;color:#d97706;">{$formattedPending}</div>
        </div>
      </td>
    </tr>
  </table>

  <table class="data-table">
    <thead>
      <tr>
        <th style="width:40px;text-align:center;">#</th>
        <th style="text-align:left;">Staff Name</th>
        <th style="text-align:left;">Role</th>
        <th style="text-align:center;width:80px;">Status</th>
        <th style="text-align:right;">Salary Amount ({$currency})</th>
      </tr>
    </thead>
    <tbody>
      {$rowsHtml}
      <tr class="total-row">
        <td colspan="4" style="text-align:right;">TOTAL PAYROLL:</td>
        <td style="text-align:right;color:#0d2b5b;">{$formattedTotal}</td>
      </tr>
    </tbody>
  </table>

  <div class="footer">
    Generated automatically by Zipoo Business Operating System &bull; {$bizName}
  </div>
</body>
</html>
HTML;

    $options = new Options();
    $options->set('isHtml5ParserEnabled', true);
    $options->set('isRemoteEnabled', true);

    $dompdf = new Dompdf($options);
    $dompdf->loadHtml($html);
    $dompdf->setPaper('A4', 'portrait');
    $dompdf->render();

    return (string) $dompdf->output();
}

function export_payroll_pdf_download(PDO $pdo, int $businessId, string $month): void
{
    $pdf = render_payroll_pdf($pdo, $businessId, $month);
    $filename = "Payroll_{$month}.pdf";

    if (ob_get_length()) {
        ob_end_clean();
    }

    header('Content-Type: application/pdf');
    header('Content-Disposition: inline; filename="' . $filename . '"');
    header('Cache-Control: max-age=0');
    echo $pdf;
    exit;
}

function send_payroll_email(PDO $pdo, int $businessId, string $month, string $recipientEmail): array
{
    if (!filter_var($recipientEmail, FILTER_VALIDATE_EMAIL)) {
        return ['ok' => false, 'message' => 'Please provide a valid recipient email address.'];
    }

    $smtp = load_business_group_settings($pdo, $businessId, 'smtp');

    if (empty($smtp['smtp_host']) || empty($smtp['from_email'])) {
        return ['ok' => false, 'message' => 'SMTP is not configured in Settings yet.'];
    }

    $data = get_payroll_export_data($pdo, $businessId, $month);
    $bizName = $data['biz_name'];
    $monthLabel = $data['month_label'];
    $formattedTotal = number_format($data['total_amount'], 2) . ' ' . $data['currency'];

    try {
        $pdfContent = render_payroll_pdf($pdo, $businessId, $month);

        $mail = new PHPMailer(true);
        $mail->isSMTP();
        $mail->Host = (string) $smtp['smtp_host'];
        $mail->Port = (int) ($smtp['smtp_port'] ?? 587);
        $mail->CharSet = 'UTF-8';
        $mail->Timeout = 15;

        $encryption = (string) ($smtp['smtp_encryption'] ?? 'tls');
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
            $mail->Username = (string) $smtp['smtp_username'];
            $mail->Password = (string) ($smtp['smtp_password'] ?? '');
        }

        $mail->setFrom((string) $smtp['from_email'], $bizName);
        $mail->addAddress($recipientEmail);
        $mail->Subject = "Payroll Salary Sheet {$monthLabel} - {$bizName}";

        $filename = "Payroll_{$month}.pdf";
        $mail->addStringAttachment($pdfContent, $filename, 'base64', 'application/pdf');

        $mail->isHTML(true);
        $mail->Body = "
        <div style='font-family:-apple-system,BlinkMacSystemFont,\"Segoe UI\",Roboto,sans-serif;color:#1e293b;max-width:600px;margin:0 auto;padding:24px;border:1px solid #e2e8f0;border-radius:12px;background:#ffffff;'>
          <h2 style='color:#0d2b5b;margin-top:0;'>{$bizName}</h2>
          <h3 style='color:#1477ff;margin-top:4px;'>Payroll Salary Sheet - {$monthLabel}</h3>
          <p style='color:#475569;'>Please find attached the official payroll salary sheet for <strong>{$monthLabel}</strong>.</p>
          <div style='background:#f8fafc;border:1px solid #cbd5e1;border-radius:8px;padding:16px;margin:18px 0;'>
            <table style='width:100%;font-size:13px;'>
              <tr><td style='color:#64748b;'>Staff Count:</td><td style='text-align:right;font-weight:700;'>{$data['staff_count']}</td></tr>
              <tr><td style='color:#64748b;'>Total Salaries:</td><td style='text-align:right;font-weight:900;color:#0d2b5b;'>{$formattedTotal}</td></tr>
            </table>
          </div>
          <p style='color:#64748b;font-size:11px;margin-top:24px;border-top:1px solid #f1f5f9;padding-top:12px;'>Sent via Zipoo Business Operating System.</p>
        </div>";

        $mail->send();
        return ['ok' => true, 'message' => "Payroll sheet has been sent successfully to {$recipientEmail}."];
    } catch (\Throwable $e) {
        return ['ok' => false, 'message' => 'Failed to send email: ' . $e->getMessage()];
    }
}

function get_categories_export_data(PDO $pdo, int $businessId): array
{
    $bStmt = $pdo->prepare('SELECT * FROM tbl_businesses WHERE id = :id LIMIT 1');
    $bStmt->execute([':id' => $businessId]);
    $biz = $bStmt->fetch(PDO::FETCH_ASSOC) ?: [];
    $currency = (string) ($biz['currency'] ?? 'TZS');
    $bizName = (string) ($biz['business_name'] ?? $biz['name'] ?? 'Zipoo Business');

    $stmt = $pdo->prepare(
        'SELECT c.*,
                COALESCE((SELECT COUNT(*) FROM tbl_account_transactions t WHERE t.business_id = c.business_id AND t.expense_category_id = c.id AND t.type = "expense"), 0) AS used_count,
                COALESCE((SELECT SUM(t.amount) FROM tbl_account_transactions t WHERE t.business_id = c.business_id AND t.expense_category_id = c.id AND t.type = "expense"), 0) AS total_amount
         FROM tbl_expense_categories c
         WHERE c.business_id = :bid
         ORDER BY c.status ASC, c.name ASC'
    );
    $stmt->execute([':bid' => $businessId]);
    $categories = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $totalExpenses = 0.0;
    $totalCount = 0;
    $rows = [];
    $i = 1;
    foreach ($categories as $cat) {
        $amt = (float) ($cat['total_amount'] ?? 0);
        $cnt = (int) ($cat['used_count'] ?? 0);
        $totalExpenses += $amt;
        $totalCount += $cnt;
        $rows[] = [
            $i++,
            (string) $cat['name'],
            ucfirst((string) ($cat['status'] ?? 'active')),
            $cnt,
            $amt,
        ];
    }

    return [
        'biz_name' => $bizName,
        'currency' => $currency,
        'categories_count' => count($categories),
        'total_count' => $totalCount,
        'total_amount' => $totalExpenses,
        'headers' => ['#', 'Category Name', 'Status', 'Expenses Count', "Total Spent ({$currency})"],
        'rows' => $rows,
        'generated_at' => date('Y-m-d H:i:s'),
    ];
}

function export_categories_excel(PDO $pdo, int $businessId): void
{
    $data = get_categories_export_data($pdo, $businessId);

    $spreadsheet = new Spreadsheet();
    $sheet = $spreadsheet->getActiveSheet();
    $sheet->setTitle('Expense Categories');

    $sheet->setCellValue('A1', $data['biz_name']);
    $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(16)->getColor()->setARGB('FF0D2B5B');

    $sheet->setCellValue('A2', 'Expense Categories Breakdown');
    $sheet->getStyle('A2')->getFont()->setBold(true)->setSize(12);

    $sheet->setCellValue('A3', 'Total Categories: ' . $data['categories_count'] . ' | Currency: ' . $data['currency']);
    $sheet->getStyle('A3')->getFont()->setItalic(true)->setSize(9)->getColor()->setARGB('FF6B7280');

    $colCount = count($data['headers']);
    $lastCol = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($colCount);

    $colIdx = 1;
    foreach ($data['headers'] as $h) {
        $letter = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($colIdx);
        $sheet->setCellValue($letter . '5', $h);
        $colIdx++;
    }

    $headerRange = "A5:{$lastCol}5";
    $sheet->getStyle($headerRange)->getFont()->setBold(true)->getColor()->setARGB('FFFFFFFF');
    $sheet->getStyle($headerRange)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FF0D2B5B');

    $rowNum = 6;
    if (empty($data['rows'])) {
        $sheet->setCellValue('A6', 'No expense categories found.');
        $sheet->mergeCells("A6:{$lastCol}6");
    } else {
        foreach ($data['rows'] as $r) {
            $c = 1;
            foreach ($r as $val) {
                $letter = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($c);
                $sheet->setCellValue($letter . $rowNum, $val);
                $c++;
            }
            $sheet->getStyle($lastCol . $rowNum)->getNumberFormat()->setFormatCode('#,##0.00');
            $rowNum++;
        }

        $sheet->setCellValue('A' . $rowNum, 'TOTAL');
        $secondCol = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex(4);
        $sheet->setCellValue($secondCol . $rowNum, "=SUM({$secondCol}6:{$secondCol}" . ($rowNum - 1) . ')');
        $sheet->setCellValue($lastCol . $rowNum, "=SUM({$lastCol}6:{$lastCol}" . ($rowNum - 1) . ')');
        $sheet->getStyle($lastCol . $rowNum)->getNumberFormat()->setFormatCode('#,##0.00');
        $sheet->getStyle("A{$rowNum}:{$lastCol}{$rowNum}")->getFont()->setBold(true);
        $sheet->getStyle("A{$rowNum}:{$lastCol}{$rowNum}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFF1F5F9');
    }

    for ($i = 1; $i <= $colCount; $i++) {
        $letter = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($i);
        $sheet->getColumnDimension($letter)->setAutoSize(true);
    }

    $filename = "Expense_Categories_" . date('Y-m-d') . ".xlsx";
    if (ob_get_length()) {
        ob_end_clean();
    }

    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: max-age=0');

    $writer = new Xlsx($spreadsheet);
    $writer->save('php://output');
    exit;
}

function render_categories_pdf(PDO $pdo, int $businessId): string
{
    $data = get_categories_export_data($pdo, $businessId);

    $bizName = htmlspecialchars($data['biz_name'], ENT_QUOTES, 'UTF-8');
    $currency = htmlspecialchars($data['currency'], ENT_QUOTES, 'UTF-8');
    $genDate = date('M j, Y h:i A');

    $rowsHtml = '';
    if (empty($data['rows'])) {
        $rowsHtml = '<tr><td colspan="5" style="text-align:center;padding:24px;color:#64748b;">No expense categories recorded.</td></tr>';
    } else {
        foreach ($data['rows'] as $idx => $r) {
            $bg = $idx % 2 === 1 ? '#f8fafc' : '#ffffff';
            $formattedAmt = number_format((float) $r[4], 2) . ' ' . $currency;
            $statusColor = strtolower($r[2]) === 'active' ? '#16a34a' : '#64748b';
            $rowsHtml .= "<tr style='background: {$bg};'>
                <td style='padding:9px 12px;text-align:center;'>{$r[0]}</td>
                <td style='padding:9px 12px;font-weight:700;color:#0d2b5b;'>" . htmlspecialchars($r[1], ENT_QUOTES, 'UTF-8') . "</td>
                <td style='padding:9px 12px;text-align:center;font-weight:700;color:{$statusColor};'>" . htmlspecialchars($r[2], ENT_QUOTES, 'UTF-8') . "</td>
                <td style='padding:9px 12px;text-align:center;'>{$r[3]}</td>
                <td style='padding:9px 12px;text-align:right;font-weight:900;color:#ef4444;'>{$formattedAmt}</td>
            </tr>";
        }
    }

    $formattedTotal = number_format($data['total_amount'], 2) . ' ' . $currency;

    $html = <<<HTML
<!DOCTYPE html>
<html>
<head>
  <meta charset="utf-8">
  <title>Expense Categories - {$bizName}</title>
  <style>
    body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif; margin: 0; padding: 24px; color: #1e293b; font-size: 12px; line-height: 1.4; }
    .header-table { width: 100%; border-bottom: 2px solid #0d2b5b; padding-bottom: 14px; margin-bottom: 18px; }
    .brand-title { font-size: 22px; font-weight: 900; color: #0d2b5b; margin: 0; }
    .report-title { font-size: 14px; font-weight: 800; color: #1477ff; margin: 4px 0 0; }
    .meta-text { font-size: 10px; color: #64748b; text-align: right; }
    .data-table { width: 100%; border-collapse: collapse; margin-top: 8px; }
    .data-table th { background: #0d2b5b; color: #ffffff; font-weight: 800; border: 1px solid #0d2b5b; padding: 10px 12px; text-transform: uppercase; font-size: 11px; letter-spacing: 0.04em; }
    .data-table td { border: 1px solid #e2e8f0; }
    .total-row td { background: #f1f5f9; font-weight: 900; border: 1px solid #cbd5e1; padding: 10px 12px; font-size: 13px; }
    .footer { margin-top: 30px; border-top: 1px dashed #cbd5e1; padding-top: 10px; font-size: 9px; color: #94a3b8; text-align: center; }
  </style>
</head>
<body>
  <table class="header-table">
    <tr>
      <td>
        <h1 class="brand-title">{$bizName}</h1>
        <div class="report-title">Expense Categories Summary</div>
      </td>
      <td class="meta-text" style="vertical-align:top;">
        <div><strong>Date:</strong> {$genDate}</div>
        <div><strong>Currency:</strong> {$currency}</div>
        <div><strong>Total Categories:</strong> {$data['categories_count']}</div>
      </td>
    </tr>
  </table>

  <table class="data-table">
    <thead>
      <tr>
        <th style="width:40px;text-align:center;">#</th>
        <th style="text-align:left;">Category Name</th>
        <th style="text-align:center;width:80px;">Status</th>
        <th style="text-align:center;width:110px;">Expenses Count</th>
        <th style="text-align:right;">Total Spent ({$currency})</th>
      </tr>
    </thead>
    <tbody>
      {$rowsHtml}
      <tr class="total-row">
        <td colspan="3" style="text-align:right;">TOTAL:</td>
        <td style="text-align:center;">{$data['total_count']}</td>
        <td style="text-align:right;color:#ef4444;">{$formattedTotal}</td>
      </tr>
    </tbody>
  </table>

  <div class="footer">
    Generated automatically by Zipoo Business Operating System &bull; {$bizName}
  </div>
</body>
</html>
HTML;

    $options = new Options();
    $options->set('isHtml5ParserEnabled', true);
    $options->set('isRemoteEnabled', true);

    $dompdf = new Dompdf($options);
    $dompdf->loadHtml($html);
    $dompdf->setPaper('A4', 'portrait');
    $dompdf->render();

    return (string) $dompdf->output();
}

function export_categories_pdf_download(PDO $pdo, int $businessId): void
{
    $pdf = render_categories_pdf($pdo, $businessId);
    $filename = "Expense_Categories_" . date('Y-m-d') . ".pdf";

    if (ob_get_length()) {
        ob_end_clean();
    }

    header('Content-Type: application/pdf');
    header('Content-Disposition: inline; filename="' . $filename . '"');
    header('Cache-Control: max-age=0');
    echo $pdf;
    exit;
}

function send_categories_email(PDO $pdo, int $businessId, string $recipientEmail): array
{
    if (!filter_var($recipientEmail, FILTER_VALIDATE_EMAIL)) {
        return ['ok' => false, 'message' => 'Please provide a valid recipient email address.'];
    }

    $smtp = load_business_group_settings($pdo, $businessId, 'smtp');

    if (empty($smtp['smtp_host']) || empty($smtp['from_email'])) {
        return ['ok' => false, 'message' => 'SMTP is not configured in Settings yet.'];
    }

    $data = get_categories_export_data($pdo, $businessId);
    $bizName = $data['biz_name'];
    $formattedTotal = number_format($data['total_amount'], 2) . ' ' . $data['currency'];

    try {
        $pdfContent = render_categories_pdf($pdo, $businessId);

        $mail = new PHPMailer(true);
        $mail->isSMTP();
        $mail->Host = (string) $smtp['smtp_host'];
        $mail->Port = (int) ($smtp['smtp_port'] ?? 587);
        $mail->CharSet = 'UTF-8';
        $mail->Timeout = 15;

        $encryption = (string) ($smtp['smtp_encryption'] ?? 'tls');
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
            $mail->Username = (string) $smtp['smtp_username'];
            $mail->Password = (string) ($smtp['smtp_password'] ?? '');
        }

        $mail->setFrom((string) $smtp['from_email'], $bizName);
        $mail->addAddress($recipientEmail);
        $mail->Subject = "Expense Categories Summary - {$bizName}";

        $filename = "Expense_Categories_" . date('Y-m-d') . ".pdf";
        $mail->addStringAttachment($pdfContent, $filename, 'base64', 'application/pdf');

        $mail->isHTML(true);
        $mail->Body = "
        <div style='font-family:-apple-system,BlinkMacSystemFont,\"Segoe UI\",Roboto,sans-serif;color:#1e293b;max-width:600px;margin:0 auto;padding:24px;border:1px solid #e2e8f0;border-radius:12px;background:#ffffff;'>
          <h2 style='color:#0d2b5b;margin-top:0;'>{$bizName}</h2>
          <h3 style='color:#1477ff;margin-top:4px;'>Expense Categories Summary</h3>
          <p style='color:#475569;'>Please find attached the official expense categories breakdown report.</p>
          <div style='background:#f8fafc;border:1px solid #cbd5e1;border-radius:8px;padding:16px;margin:18px 0;'>
            <table style='width:100%;font-size:13px;'>
              <tr><td style='color:#64748b;'>Total Categories:</td><td style='text-align:right;font-weight:700;'>{$data['categories_count']}</td></tr>
              <tr><td style='color:#64748b;'>Total Expense Transactions:</td><td style='text-align:right;font-weight:700;'>{$data['total_count']}</td></tr>
              <tr><td style='color:#64748b;'>Total Spent:</td><td style='text-align:right;font-weight:900;color:#ef4444;'>{$formattedTotal}</td></tr>
            </table>
          </div>
          <p style='color:#64748b;font-size:11px;margin-top:24px;border-top:1px solid #f1f5f9;padding-top:12px;'>Sent via Zipoo Business Operating System.</p>
        </div>";

        $mail->send();
        return ['ok' => true, 'message' => "Categories report has been sent successfully to {$recipientEmail}."];
    } catch (\Throwable $e) {
        return ['ok' => false, 'message' => 'Failed to send email: ' . $e->getMessage()];
    }
}
