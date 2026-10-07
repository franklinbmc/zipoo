<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/idempotency_lib.php';
require_once __DIR__ . '/pos_lib.php';
require_once __DIR__ . '/accounts_lib.php';
require_once __DIR__ . '/shift_report_lib.php';

session_start();

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function respond(int $status, array $payload): void
{
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

function active_business_id(PDO $pdo, int $userId): int
{
    $businessId = isset($_SESSION['zipoo_business_id']) ? (int) $_SESSION['zipoo_business_id'] : 0;
    if ($businessId > 0) {
        $stmt = $pdo->prepare('SELECT id FROM tbl_businesses WHERE id = :bid AND (owner_user_id = :uid OR id = :bid2) LIMIT 1');
        $stmt->execute([':bid' => $businessId, ':uid' => $userId, ':bid2' => $businessId]);
        if ((int) ($stmt->fetchColumn() ?: 0) > 0) {
            return $businessId;
        }
    }
    $stmt = $pdo->prepare('SELECT business_id FROM tbl_users WHERE id = :uid LIMIT 1');
    $stmt->execute([':uid' => $userId]);
    $userBid = (int) ($stmt->fetchColumn() ?: 0);
    if ($userBid > 0) {
        $_SESSION['zipoo_business_id'] = $userBid;
        return $userBid;
    }
    $stmt = $pdo->prepare('SELECT id FROM tbl_businesses WHERE owner_user_id = :uid ORDER BY id ASC LIMIT 1');
    $stmt->execute([':uid' => $userId]);
    $first = (int) ($stmt->fetchColumn() ?: 0);
    if ($first > 0) {
        $_SESSION['zipoo_business_id'] = $first;
    }
    return $first;
}

/** Available funds across the business accounts, for the close-shift summary. */
function account_funds(PDO $pdo, int $businessId): array
{
    ensure_accounts_tables($pdo);
    $stmt = $pdo->prepare(
        'SELECT a.id, a.name, a.type,
                a.opening_balance
                + COALESCE((SELECT SUM(CASE WHEN t.direction = "in" THEN t.amount ELSE -t.amount END)
                            FROM tbl_account_transactions t WHERE t.account_id = a.id), 0) AS balance
         FROM tbl_accounts a WHERE a.business_id = :bid ORDER BY a.is_default DESC, a.name ASC'
    );
    $stmt->execute([':bid' => $businessId]);
    $accounts = [];
    $by = ['cash' => 0.0, 'bank' => 0.0, 'mobile' => 0.0];
    $net = 0.0;
    foreach ($stmt->fetchAll() as $a) {
        $bal = (float) $a['balance'];
        $accounts[] = ['id' => (int) $a['id'], 'name' => (string) $a['name'], 'type' => (string) $a['type'], 'balance' => $bal];
        $by[$a['type']] = ($by[$a['type']] ?? 0) + $bal;
        $net += $bal;
    }
    return [
        'accounts' => $accounts,
        'summary' => ['cash' => round($by['cash'], 2), 'bank' => round($by['bank'], 2), 'mobile' => round($by['mobile'], 2), 'net' => round($net, 2)],
    ];
}

function shift_payload(array $s, array $totals = null): array
{
    return [
        'id' => (int) $s['id'],
        'status' => (string) $s['status'],
        'opened_at' => (string) $s['opened_at'],
        'opening_balance' => (float) $s['opening_balance'],
        'closed_at' => $s['closed_at'] !== null ? (string) $s['closed_at'] : null,
        'closing_balance' => $s['closing_balance'] !== null ? (float) $s['closing_balance'] : null,
        'expected_cash' => $s['expected_cash'] !== null ? (float) $s['expected_cash'] : null,
        'cash_sales' => $totals !== null ? $totals['cash_sales'] : ($s['cash_sales'] !== null ? (float) $s['cash_sales'] : null),
        'sales_total' => $totals !== null ? $totals['sales_total'] : ($s['sales_total'] !== null ? (float) $s['sales_total'] : null),
        'sales_count' => $totals !== null ? $totals['sales_count'] : ($s['sales_count'] !== null ? (int) $s['sales_count'] : null),
        'variance' => $s['variance'] !== null ? (float) $s['variance'] : null,
        'notes' => (string) ($s['notes'] ?? ''),
    ];
}

try {
    $pdo = db();
    $userId = require_user();
    ensure_pos_shifts_table($pdo);
    ensure_sales_pos_columns($pdo);

    $businessId = active_business_id($pdo, $userId);
    if ($businessId <= 0) {
        respond(422, ['ok' => false, 'message' => 'No active business selected.']);
    }

    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $action = trim((string) ($_GET['action'] ?? 'current'));

        if ($action === 'current') {
            $shift = current_open_shift($pdo, $businessId, $userId);
            $funds = account_funds($pdo, $businessId);
            if (!$shift) {
                respond(200, ['ok' => true, 'shift' => null, 'accounts' => $funds['accounts'], 'summary' => $funds['summary']]);
            }
            $totals = shift_sales_totals($pdo, $businessId, (int) $shift['id']);
            $out = shift_payload($shift, $totals);
            $out['expected_cash'] = round((float) $shift['opening_balance'] + $totals['cash_sales'], 2);
            respond(200, ['ok' => true, 'shift' => $out, 'accounts' => $funds['accounts'], 'summary' => $funds['summary']]);
        }

        if ($action === 'list') {
            $from = trim((string) ($_GET['from'] ?? ''));
            $to = trim((string) ($_GET['to'] ?? ''));
            $conds = ['s.business_id = :bid'];
            $params = [':bid' => $businessId];
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) { $conds[] = 'DATE(s.opened_at) >= :from'; $params[':from'] = $from; }
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) { $conds[] = 'DATE(s.opened_at) <= :to'; $params[':to'] = $to; }
            $where = implode(' AND ', $conds);
            $stmt = $pdo->prepare(
                "SELECT s.*, u.full_name AS cashier FROM tbl_pos_shifts s
                 LEFT JOIN tbl_users u ON u.id = s.user_id
                 WHERE {$where} ORDER BY s.id DESC LIMIT 100"
            );
            $stmt->execute($params);
            $shifts = array_map(static function ($s) {
                $out = shift_payload($s);
                $out['cashier'] = (string) ($s['cashier'] ?? '');
                return $out;
            }, $stmt->fetchAll());
            respond(200, ['ok' => true, 'shifts' => $shifts]);
        }

        if ($action === 'report_details') {
            $shiftId = (int) ($_GET['id'] ?? 0);
            if ($shiftId <= 0) {
                $cur = current_open_shift($pdo, $businessId, $userId);
                $shiftId = (int) ($cur['id'] ?? 0);
            }
            if ($shiftId <= 0) {
                respond(404, ['ok' => false, 'message' => 'No shift specified or open.']);
            }
            $data = get_shift_report_data($pdo, $businessId, $shiftId);
            if (!$data) {
                respond(404, ['ok' => false, 'message' => 'Shift report not found.']);
            }
            respond(200, ['ok' => true, 'report' => $data]);
        }

        if ($action === 'export_pdf') {
            $shiftId = (int) ($_GET['id'] ?? 0);
            if ($shiftId <= 0) {
                $cur = current_open_shift($pdo, $businessId, $userId);
                $shiftId = (int) ($cur['id'] ?? 0);
            }
            if ($shiftId <= 0) {
                respond(404, ['ok' => false, 'message' => 'No shift specified.']);
            }
            $data = get_shift_report_data($pdo, $businessId, $shiftId);
            if (!$data) {
                respond(404, ['ok' => false, 'message' => 'Shift report data not found.']);
            }

            $pdf = render_shift_pdf($data);
            header('Content-Type: application/pdf');
            header('Content-Disposition: inline; filename="Shift_Report_' . $shiftId . '.pdf"');
            header('Cache-Control: private, max-age=0, must-revalidate');
            header('Pragma: public');
            echo $pdf;
            exit;
        }

        if ($action === 'export_excel') {
            $shiftId = (int) ($_GET['id'] ?? 0);
            if ($shiftId <= 0) {
                $cur = current_open_shift($pdo, $businessId, $userId);
                $shiftId = (int) ($cur['id'] ?? 0);
            }
            if ($shiftId <= 0) {
                respond(404, ['ok' => false, 'message' => 'No shift specified.']);
            }
            $data = get_shift_report_data($pdo, $businessId, $shiftId);
            if (!$data) {
                respond(404, ['ok' => false, 'message' => 'Shift report data not found.']);
            }

            export_shift_excel($data);
            exit;
        }

        respond(422, ['ok' => false, 'message' => 'Unknown action.']);
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        respond(405, ['ok' => false, 'message' => 'Method not allowed.']);
    }

    $action = trim((string) ($_POST['action'] ?? ''));

    if ($action === 'open') {
        if (current_open_shift($pdo, $businessId, $userId)) {
            respond(422, ['ok' => false, 'message' => 'You already have an open shift. Close it before starting a new one.']);
        }
        $opening = round((float) ($_POST['opening_balance'] ?? 0), 2);
        if ($opening < 0) {
            $opening = 0.0;
        }
        $ins = $pdo->prepare('INSERT INTO tbl_pos_shifts (business_id, user_id, opening_balance, status) VALUES (:bid, :uid, :open, "open")');
        $ins->execute([':bid' => $businessId, ':uid' => $userId, ':open' => $opening]);
        $shift = current_open_shift($pdo, $businessId, $userId);
        respond(201, ['ok' => true, 'message' => 'Shift started.', 'shift' => $shift ? shift_payload($shift, shift_sales_totals($pdo, $businessId, (int) $shift['id'])) : null]);
    }

    if ($action === 'close') {
        $shift = current_open_shift($pdo, $businessId, $userId);
        if (!$shift) {
            respond(422, ['ok' => false, 'message' => 'No open shift to close.']);
        }
        $closing = round((float) ($_POST['closing_balance'] ?? 0), 2);
        $notes = trim((string) ($_POST['notes'] ?? ''));
        $totals = shift_sales_totals($pdo, $businessId, (int) $shift['id']);
        $expectedCash = round((float) $shift['opening_balance'] + $totals['cash_sales'], 2);
        $variance = round($closing - $expectedCash, 2);

        $pdo->prepare(
            'UPDATE tbl_pos_shifts SET status = "closed", closed_at = CURRENT_TIMESTAMP, closing_balance = :close,
                expected_cash = :exp, cash_sales = :cash, sales_total = :stot, sales_count = :scnt, variance = :var, notes = :notes
             WHERE id = :id AND business_id = :bid'
        )->execute([
            ':close' => $closing, ':exp' => $expectedCash, ':cash' => $totals['cash_sales'], ':stot' => $totals['sales_total'],
            ':scnt' => $totals['sales_count'], ':var' => $variance, ':notes' => $notes !== '' ? $notes : null,
            ':id' => (int) $shift['id'], ':bid' => $businessId,
        ]);

        $funds = account_funds($pdo, $businessId);
        respond(200, ['ok' => true, 'message' => 'Shift closed.', 'summary' => [
            'shift_id' => (int) $shift['id'],
            'opening_balance' => (float) $shift['opening_balance'],
            'cash_sales' => $totals['cash_sales'],
            'expected_cash' => $expectedCash,
            'closing_balance' => $closing,
            'variance' => $variance,
            'sales_total' => $totals['sales_total'],
            'sales_count' => $totals['sales_count'],
        ], 'accounts' => $funds['accounts'], 'account_summary' => $funds['summary']]);
    }

    if ($action === 'email_report') {
        $shiftId = (int) ($_POST['id'] ?? 0);
        $email = trim((string) ($_POST['email'] ?? ''));
        if ($shiftId <= 0) {
            respond(422, ['ok' => false, 'message' => 'Invalid shift ID.']);
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            respond(422, ['ok' => false, 'message' => 'Please enter a valid recipient email address.']);
        }

        $data = get_shift_report_data($pdo, $businessId, $shiftId);
        if (!$data) {
            respond(404, ['ok' => false, 'message' => 'Shift report data not found.']);
        }

        $res = send_shift_email_report($pdo, $data, $email);
        respond($res['ok'] ? 200 : 422, $res);
    }

    respond(422, ['ok' => false, 'message' => 'Unknown action.']);
} catch (Throwable $error) {
    respond(500, ['ok' => false, 'message' => 'Unable to process shift right now: ' . $error->getMessage()]);
}
