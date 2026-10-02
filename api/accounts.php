<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/accounts_lib.php';

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

const ACCOUNT_TYPE_LABELS = [
    'cash' => 'Cash Account',
    'bank' => 'Bank Account',
    'mobile' => 'Lipa kwa simu Account',
];

const TXN_TYPE_LABELS = [
    'sale' => 'POS sale',
    'invoice_payment' => 'Invoice payment',
    'rent' => 'Rent payment',
    'deposit' => 'Deposit',
    'withdrawal' => 'Withdrawal',
    'transfer_in' => 'Transfer in',
    'transfer_out' => 'Transfer out',
    'expense' => 'Expense',
    'opening' => 'Opening balance',
    'adjustment' => 'Adjustment',
];

function account_payload(array $row): array
{
    $type = (string) ($row['type'] ?? 'cash');
    return [
        'id' => (int) $row['id'],
        'name' => (string) $row['name'],
        'type' => $type,
        'type_label' => ACCOUNT_TYPE_LABELS[$type] ?? $type,
        'bank_name' => (string) ($row['bank_name'] ?? ''),
        'account_number' => (string) ($row['account_number'] ?? ''),
        'opening_balance' => (float) ($row['opening_balance'] ?? 0),
        'balance' => (float) ($row['balance'] ?? $row['opening_balance'] ?? 0),
        'is_default' => (int) ($row['is_default'] ?? 0) === 1,
        'status' => (string) ($row['status'] ?? 'active'),
        'created_at' => (string) ($row['created_at'] ?? ''),
    ];
}

function load_accounts(PDO $pdo, int $businessId): array
{
    $stmt = $pdo->prepare(
        'SELECT a.*,
                a.opening_balance
                + COALESCE((SELECT SUM(CASE WHEN t.direction = "in" THEN t.amount ELSE -t.amount END)
                            FROM tbl_account_transactions t WHERE t.account_id = a.id), 0) AS balance
         FROM tbl_accounts a
         WHERE a.business_id = :bid
         ORDER BY a.is_default DESC, a.name ASC'
    );
    $stmt->execute([':bid' => $businessId]);
    return array_map('account_payload', $stmt->fetchAll());
}

function account_summary(array $accounts): array
{
    $byType = ['cash' => 0.0, 'bank' => 0.0, 'mobile' => 0.0];
    $net = 0.0;
    foreach ($accounts as $a) {
        if (($a['status'] ?? 'active') === 'inactive') {
            continue;
        }
        $byType[$a['type']] = ($byType[$a['type']] ?? 0) + (float) $a['balance'];
        $net += (float) $a['balance'];
    }
    return [
        'cash' => round($byType['cash'], 2),
        'bank' => round($byType['bank'], 2),
        'mobile' => round($byType['mobile'], 2),
        'net' => round($net, 2),
        'accounts_count' => count($accounts),
    ];
}

function fetch_account_or_404(PDO $pdo, int $businessId, int $accountId): array
{
    $stmt = $pdo->prepare('SELECT * FROM tbl_accounts WHERE id = :id AND business_id = :bid LIMIT 1');
    $stmt->execute([':id' => $accountId, ':bid' => $businessId]);
    $row = $stmt->fetch();
    if (!$row) {
        respond(404, ['ok' => false, 'message' => 'Account not found.']);
    }
    return $row;
}

try {
    $pdo = db();
    $userId = require_user();
    ensure_accounts_tables($pdo);

    $businessId = active_business_id($pdo, $userId);
    if ($businessId <= 0) {
        respond(422, ['ok' => false, 'message' => 'No active business selected.']);
    }

    // Lazy seed: guarantees a default Cash account for this (possibly pre-existing) business.
    ensure_default_account($pdo, $businessId);

    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        // Single account detail + its ledger.
        if (isset($_GET['id'])) {
            $accountId = (int) $_GET['id'];
            $row = fetch_account_or_404($pdo, $businessId, $accountId);
            $row['balance'] = account_balance($pdo, $businessId, $accountId);

            $tStmt = $pdo->prepare(
                'SELECT * FROM tbl_account_transactions WHERE account_id = :id AND business_id = :bid ORDER BY id DESC LIMIT 200'
            );
            $tStmt->execute([':id' => $accountId, ':bid' => $businessId]);
            $txns = array_map(static function ($t) {
                $type = (string) $t['type'];
                return [
                    'id' => (int) $t['id'],
                    'direction' => (string) $t['direction'],
                    'type' => $type,
                    'type_label' => TXN_TYPE_LABELS[$type] ?? $type,
                    'amount' => (float) $t['amount'],
                    'reference_type' => (string) ($t['reference_type'] ?? ''),
                    'reference_id' => (string) ($t['reference_id'] ?? ''),
                    'notes' => (string) ($t['notes'] ?? ''),
                    'created_at' => (string) ($t['created_at'] ?? ''),
                ];
            }, $tStmt->fetchAll());

            $out = account_payload($row);
            $out['transactions'] = $txns;
            respond(200, ['ok' => true, 'account' => $out]);
        }

        // Recent activity across all accounts.
        if (($_GET['action'] ?? '') === 'activity') {
            $stmt = $pdo->prepare(
                'SELECT t.*, a.name AS account_name, a.type AS account_type
                 FROM tbl_account_transactions t
                 JOIN tbl_accounts a ON a.id = t.account_id
                 WHERE t.business_id = :bid
                 ORDER BY t.id DESC LIMIT 100'
            );
            $stmt->execute([':bid' => $businessId]);
            $activity = array_map(static function ($t) {
                $type = (string) $t['type'];
                return [
                    'id' => (int) $t['id'],
                    'account_id' => (int) $t['account_id'],
                    'account_name' => (string) $t['account_name'],
                    'account_type' => (string) $t['account_type'],
                    'direction' => (string) $t['direction'],
                    'type' => $type,
                    'type_label' => TXN_TYPE_LABELS[$type] ?? $type,
                    'amount' => (float) $t['amount'],
                    'reference_type' => (string) ($t['reference_type'] ?? ''),
                    'reference_id' => (string) ($t['reference_id'] ?? ''),
                    'notes' => (string) ($t['notes'] ?? ''),
                    'created_at' => (string) ($t['created_at'] ?? ''),
                ];
            }, $stmt->fetchAll());
            respond(200, ['ok' => true, 'activity' => $activity]);
        }

        $accounts = load_accounts($pdo, $businessId);
        respond(200, [
            'ok' => true,
            'accounts' => $accounts,
            'summary' => account_summary($accounts),
        ]);
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        respond(405, ['ok' => false, 'message' => 'Method not allowed.']);
    }

    $action = trim((string) ($_POST['action'] ?? ''));

    if ($action === 'create' || $action === 'update') {
        $name = trim((string) ($_POST['name'] ?? ''));
        $type = strtolower(trim((string) ($_POST['type'] ?? 'cash')));
        if (!in_array($type, ['cash', 'bank', 'mobile'], true)) {
            $type = 'cash';
        }
        $bankName = trim((string) ($_POST['bank_name'] ?? ''));
        $accountNumber = trim((string) ($_POST['account_number'] ?? ''));
        $openingBalance = (float) ($_POST['opening_balance'] ?? 0);
        $makeDefault = (int) ($_POST['is_default'] ?? 0) === 1;

        if ($name === '') {
            respond(422, ['ok' => false, 'message' => 'Account name is required.']);
        }

        $pdo->beginTransaction();
        try {
            if ($action === 'update') {
                $accountId = (int) ($_POST['account_id'] ?? 0);
                fetch_account_or_404($pdo, $businessId, $accountId);
                // Opening balance is immutable after creation to keep the ledger honest.
                $pdo->prepare(
                    'UPDATE tbl_accounts SET name = :name, type = :type, bank_name = :bank, account_number = :num
                     WHERE id = :id AND business_id = :bid'
                )->execute([
                    ':name' => $name,
                    ':type' => $type,
                    ':bank' => $bankName !== '' ? $bankName : null,
                    ':num' => $accountNumber !== '' ? $accountNumber : null,
                    ':id' => $accountId,
                    ':bid' => $businessId,
                ]);
            } else {
                $ins = $pdo->prepare(
                    'INSERT INTO tbl_accounts (business_id, name, type, bank_name, account_number, opening_balance, is_default, status)
                     VALUES (:bid, :name, :type, :bank, :num, :open, 0, "active")'
                );
                $ins->execute([
                    ':bid' => $businessId,
                    ':name' => $name,
                    ':type' => $type,
                    ':bank' => $bankName !== '' ? $bankName : null,
                    ':num' => $accountNumber !== '' ? $accountNumber : null,
                    ':open' => round($openingBalance, 2),
                ]);
                $accountId = (int) $pdo->lastInsertId();
            }

            if ($makeDefault) {
                $pdo->prepare('UPDATE tbl_accounts SET is_default = 0 WHERE business_id = :bid')->execute([':bid' => $businessId]);
                $pdo->prepare('UPDATE tbl_accounts SET is_default = 1 WHERE id = :id AND business_id = :bid')
                    ->execute([':id' => $accountId, ':bid' => $businessId]);
            }

            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        $accounts = load_accounts($pdo, $businessId);
        respond($action === 'create' ? 201 : 200, [
            'ok' => true,
            'message' => $action === 'create' ? 'Account created.' : 'Account updated.',
            'accounts' => $accounts,
            'summary' => account_summary($accounts),
        ]);
    }

    if ($action === 'set_default') {
        $accountId = (int) ($_POST['account_id'] ?? 0);
        fetch_account_or_404($pdo, $businessId, $accountId);
        $pdo->beginTransaction();
        try {
            $pdo->prepare('UPDATE tbl_accounts SET is_default = 0 WHERE business_id = :bid')->execute([':bid' => $businessId]);
            $pdo->prepare('UPDATE tbl_accounts SET is_default = 1 WHERE id = :id AND business_id = :bid')
                ->execute([':id' => $accountId, ':bid' => $businessId]);
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
        $accounts = load_accounts($pdo, $businessId);
        respond(200, ['ok' => true, 'message' => 'Default account updated.', 'accounts' => $accounts, 'summary' => account_summary($accounts)]);
    }

    if ($action === 'delete') {
        $accountId = (int) ($_POST['account_id'] ?? 0);
        $row = fetch_account_or_404($pdo, $businessId, $accountId);
        if ((int) $row['is_default'] === 1) {
            respond(422, ['ok' => false, 'message' => 'Set another account as default before deleting this one.']);
        }
        $countStmt = $pdo->prepare('SELECT COUNT(*) FROM tbl_accounts WHERE business_id = :bid');
        $countStmt->execute([':bid' => $businessId]);
        if ((int) $countStmt->fetchColumn() <= 1) {
            respond(422, ['ok' => false, 'message' => 'A business must have at least one account.']);
        }

        $pdo->beginTransaction();
        try {
            $pdo->prepare('DELETE FROM tbl_account_transactions WHERE account_id = :id AND business_id = :bid')
                ->execute([':id' => $accountId, ':bid' => $businessId]);
            $pdo->prepare('DELETE FROM tbl_accounts WHERE id = :id AND business_id = :bid')
                ->execute([':id' => $accountId, ':bid' => $businessId]);
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        $accounts = load_accounts($pdo, $businessId);
        respond(200, ['ok' => true, 'message' => 'Account deleted.', 'accounts' => $accounts, 'summary' => account_summary($accounts)]);
    }

    if ($action === 'deposit' || $action === 'withdraw' || $action === 'expense') {
        $accountId = (int) ($_POST['account_id'] ?? 0);
        $amount = round((float) ($_POST['amount'] ?? 0), 2);
        $notes = trim((string) ($_POST['notes'] ?? ''));
        fetch_account_or_404($pdo, $businessId, $accountId);

        if ($amount <= 0) {
            respond(422, ['ok' => false, 'message' => 'Enter an amount greater than zero.']);
        }

        if ($action === 'deposit') {
            $direction = 'in';
            $type = 'deposit';
        } elseif ($action === 'withdraw') {
            $direction = 'out';
            $type = 'withdrawal';
        } else {
            $direction = 'out';
            $type = 'expense';
        }

        if ($direction === 'out') {
            $balance = account_balance($pdo, $businessId, $accountId);
            if ($amount > $balance) {
                respond(422, ['ok' => false, 'message' => 'Insufficient balance. Available: ' . number_format($balance, 2) . '.']);
            }
        }

        post_account_txn($pdo, $businessId, $accountId, $direction, $type, $amount, 'manual', null, $notes !== '' ? $notes : null, $userId);

        $accounts = load_accounts($pdo, $businessId);
        respond(200, ['ok' => true, 'message' => 'Transaction recorded.', 'accounts' => $accounts, 'summary' => account_summary($accounts)]);
    }

    if ($action === 'transfer') {
        $fromId = (int) ($_POST['account_id'] ?? 0);
        $toId = (int) ($_POST['to_account_id'] ?? 0);
        $amount = round((float) ($_POST['amount'] ?? 0), 2);
        $notes = trim((string) ($_POST['notes'] ?? ''));

        if ($fromId <= 0 || $toId <= 0 || $fromId === $toId) {
            respond(422, ['ok' => false, 'message' => 'Choose two different accounts.']);
        }
        $from = fetch_account_or_404($pdo, $businessId, $fromId);
        $to = fetch_account_or_404($pdo, $businessId, $toId);
        if ($amount <= 0) {
            respond(422, ['ok' => false, 'message' => 'Enter an amount greater than zero.']);
        }

        $balance = account_balance($pdo, $businessId, $fromId);
        if ($amount > $balance) {
            respond(422, ['ok' => false, 'message' => 'Insufficient balance in "' . $from['name'] . '". Available: ' . number_format($balance, 2) . '.']);
        }

        $pdo->beginTransaction();
        try {
            post_account_txn($pdo, $businessId, $fromId, 'out', 'transfer_out', $amount, 'transfer', null, ('Transfer to ' . $to['name'] . ($notes !== '' ? ' — ' . $notes : '')), $userId, $toId);
            post_account_txn($pdo, $businessId, $toId, 'in', 'transfer_in', $amount, 'transfer', null, ('Transfer from ' . $from['name'] . ($notes !== '' ? ' — ' . $notes : '')), $userId, $fromId);
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        $accounts = load_accounts($pdo, $businessId);
        respond(200, ['ok' => true, 'message' => 'Transfer completed.', 'accounts' => $accounts, 'summary' => account_summary($accounts)]);
    }

    respond(422, ['ok' => false, 'message' => 'Unknown account action.']);
} catch (Throwable $error) {
    respond(500, ['ok' => false, 'message' => 'Unable to process accounts right now.']);
}
