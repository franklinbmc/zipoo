<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/billing_lib.php';

session_start();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function respond(int $status, array $payload): void
{
    http_response_code($status);
    echo json_encode($payload);
    exit;
}

if (empty($_SESSION['saas_admin_id'])) {
    respond(401, ['ok' => false, 'message' => 'Admin login required.']);
}

function load_billing_admin(PDO $pdo): array
{
    $accounts = $pdo->query(
        'SELECT a.*, u.full_name AS owner_name, u.phone AS owner_phone,
                s.status AS subscription_status, p.plan_name,
                COALESCE(w.balance, 0) AS sms_balance,
                (SELECT COUNT(*) FROM tbl_account_businesses ab WHERE ab.account_id = a.id) AS business_count,
                (SELECT COUNT(*) FROM tbl_account_subscription_invoices i WHERE i.account_id = a.id AND i.status IN ("pending_payment","pending_review")) AS open_invoices
         FROM tbl_billing_accounts a
         LEFT JOIN tbl_users u ON u.id = a.owner_user_id
         LEFT JOIN tbl_account_subscriptions s ON s.account_id = a.id
         LEFT JOIN tbl_saas_plans p ON p.id = s.plan_id
         LEFT JOIN tbl_account_sms_wallets w ON w.account_id = a.id
         ORDER BY a.id DESC'
    )->fetchAll(PDO::FETCH_ASSOC);

    $transactions = $pdo->query(
        'SELECT * FROM tbl_lipa_transactions ORDER BY id DESC LIMIT 100'
    )->fetchAll(PDO::FETCH_ASSOC);

    $invoices = $pdo->query(
        'SELECT i.*, a.account_name, u.full_name AS owner_name, u.phone AS owner_phone
         FROM tbl_account_subscription_invoices i
         LEFT JOIN tbl_billing_accounts a ON a.id = i.account_id
         LEFT JOIN tbl_users u ON u.id = a.owner_user_id
         ORDER BY i.id DESC LIMIT 100'
    )->fetchAll(PDO::FETCH_ASSOC);

    $settings = [];
    $stmt = $pdo->prepare('SELECT setting_key, setting_value FROM tbl_saas_settings WHERE setting_group = "lipa"');
    $stmt->execute();
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $settings[$row['setting_key']] = $row['setting_value'];
    }

    $devices = $pdo->query(
        'SELECT id, device_uuid, device_name, status, last_seen_at, created_at
         FROM tbl_lipa_devices
         ORDER BY id DESC'
    )->fetchAll(PDO::FETCH_ASSOC);

    return [
        'accounts' => $accounts,
        'invoices' => $invoices,
        'lipa_transactions' => $transactions,
        'lipa_settings' => $settings,
        'lipa_devices' => $devices,
    ];
}

function save_lipa_setting(PDO $pdo, string $key, string $value): void
{
    $stmt = $pdo->prepare(
        'INSERT INTO tbl_saas_settings (setting_group, setting_key, setting_value, updated_at)
         VALUES ("lipa", :setting_key, :setting_value, NOW())
         ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_at = NOW()'
    );
    $stmt->execute([':setting_key' => $key, ':setting_value' => $value]);
}

function parse_lipa_numbers(string $value): string
{
    $numbers = [];
    foreach (preg_split('/\R/', $value) ?: [] as $line) {
        $line = trim($line);
        if ($line === '') {
            continue;
        }
        $parts = array_map('trim', explode('|', $line));
        $numbers[] = [
            'network' => $parts[0] ?? '',
            'number' => $parts[1] ?? ($parts[0] ?? ''),
            'name' => $parts[2] ?? '',
        ];
    }
    return json_encode($numbers, JSON_UNESCAPED_SLASHES);
}

try {
    $pdo = db();
    ensure_billing_tables($pdo);

    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        respond(200, ['ok' => true] + load_billing_admin($pdo));
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        respond(405, ['ok' => false, 'message' => 'Method not allowed.']);
    }

    $action = trim((string) ($_POST['action'] ?? ''));
    if ($action === 'create_invoice') {
        $accountId = (int) ($_POST['account_id'] ?? 0);
        if ($accountId <= 0) {
            respond(422, ['ok' => false, 'message' => 'Choose an account.']);
        }
        ensure_account_subscription($pdo, $accountId);
        $stmt = $pdo->prepare(
            'SELECT s.id AS subscription_id, p.monthly_price, p.business_limit, p.extra_business_price
             FROM tbl_account_subscriptions s
             LEFT JOIN tbl_saas_plans p ON p.id = s.plan_id
             WHERE s.account_id = :aid LIMIT 1'
        );
        $stmt->execute([':aid' => $accountId]);
        $sub = $stmt->fetch(PDO::FETCH_ASSOC);
        $countStmt = $pdo->prepare('SELECT COUNT(*) FROM tbl_account_businesses WHERE account_id = :aid');
        $countStmt->execute([':aid' => $accountId]);
        $businessCount = (int) $countStmt->fetchColumn();
        $included = max(1, (int) ($sub['business_limit'] ?? 1));
        $extra = max(0, $businessCount - $included);
        $amount = (float) ($sub['monthly_price'] ?? 0) + ($extra * (float) ($sub['extra_business_price'] ?? 0));
        $pdo->prepare(
            'INSERT INTO tbl_account_subscription_invoices
                (account_id, subscription_id, invoice_number, amount, businesses_count, extra_businesses_count, status, due_date)
             VALUES
                (:aid, :sid, :invoice_number, :amount, :businesses_count, :extra_businesses_count, "pending_payment", CURDATE())'
        )->execute([
            ':aid' => $accountId,
            ':sid' => $sub['subscription_id'] ?? null,
            ':invoice_number' => billing_generate_invoice_number($accountId),
            ':amount' => $amount,
            ':businesses_count' => $businessCount,
            ':extra_businesses_count' => $extra,
        ]);
        respond(201, ['ok' => true, 'message' => 'Invoice created.'] + load_billing_admin($pdo));
    }

    if ($action === 'confirm_invoice') {
        $invoiceId = (int) ($_POST['invoice_id'] ?? 0);
        if ($invoiceId <= 0) {
            respond(422, ['ok' => false, 'message' => 'Choose an invoice.']);
        }
        $stmt = $pdo->prepare('SELECT * FROM tbl_account_subscription_invoices WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $invoiceId]);
        $invoice = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$invoice) {
            respond(404, ['ok' => false, 'message' => 'Invoice not found.']);
        }
        $pdo->prepare('UPDATE tbl_account_subscription_invoices SET status = "paid", paid_at = NOW(), updated_at = NOW() WHERE id = :id')
            ->execute([':id' => $invoiceId]);
        $pdo->prepare(
            'INSERT INTO tbl_account_payments (account_id, invoice_id, amount, currency, payment_method, reference, status, received_at, recorded_by)
             VALUES (:aid, :iid, :amount, :currency, COALESCE(:method, "manual"), :ref, "confirmed", NOW(), :admin)'
        )->execute([
            ':aid' => $invoice['account_id'],
            ':iid' => $invoiceId,
            ':amount' => $invoice['amount'],
            ':currency' => $invoice['currency'],
            ':method' => $invoice['payment_method'] ?: 'manual',
            ':ref' => $invoice['payment_reference'],
            ':admin' => (int) $_SESSION['saas_admin_id'],
        ]);
        $pdo->prepare('UPDATE tbl_account_subscriptions SET status = "active", current_period_start = CURDATE(), current_period_end = DATE_ADD(CURDATE(), INTERVAL 30 DAY), next_due_date = DATE_ADD(CURDATE(), INTERVAL 30 DAY), grace_until = DATE_ADD(CURDATE(), INTERVAL 37 DAY), updated_at = NOW() WHERE account_id = :aid')
            ->execute([':aid' => $invoice['account_id']]);
        respond(200, ['ok' => true, 'message' => 'Invoice confirmed.'] + load_billing_admin($pdo));
    }

    if ($action === 'adjust_sms') {
        $accountId = (int) ($_POST['account_id'] ?? 0);
        $count = (int) ($_POST['sms_count'] ?? 0);
        $reference = trim((string) ($_POST['reference'] ?? 'SaaS adjustment'));
        if ($accountId <= 0 || $count === 0) {
            respond(422, ['ok' => false, 'message' => 'Choose account and SMS count.']);
        }
        $pdo->prepare('INSERT INTO tbl_account_sms_wallets (account_id, balance) VALUES (:aid, 0) ON DUPLICATE KEY UPDATE balance = balance')
            ->execute([':aid' => $accountId]);
        $pdo->prepare('UPDATE tbl_account_sms_wallets SET balance = balance + :count WHERE account_id = :aid')
            ->execute([':count' => $count, ':aid' => $accountId]);
        $balanceStmt = $pdo->prepare('SELECT balance FROM tbl_account_sms_wallets WHERE account_id = :aid');
        $balanceStmt->execute([':aid' => $accountId]);
        $balance = (int) $balanceStmt->fetchColumn();
        $pdo->prepare('INSERT INTO tbl_account_sms_transactions (account_id, type, sms_count, balance_after, reference) VALUES (:aid, "adjustment", :count, :balance, :ref)')
            ->execute([':aid' => $accountId, ':count' => $count, ':balance' => $balance, ':ref' => $reference]);
        respond(200, ['ok' => true, 'message' => 'SMS wallet adjusted.'] + load_billing_admin($pdo));
    }

    if ($action === 'save_lipa_settings') {
        save_lipa_setting($pdo, 'enabled', !empty($_POST['enabled']) ? '1' : '0');
        save_lipa_setting($pdo, 'auto_approve', !empty($_POST['auto_approve']) ? '1' : '0');
        save_lipa_setting($pdo, 'auto_approve_max', (string) max(0, (float) ($_POST['auto_approve_max'] ?? 0)));
        save_lipa_setting($pdo, 'allowed_senders', trim((string) ($_POST['allowed_senders'] ?? '')));
        save_lipa_setting($pdo, 'numbers', parse_lipa_numbers((string) ($_POST['numbers'] ?? '')));
        respond(200, ['ok' => true, 'message' => 'Lipa settings saved.'] + load_billing_admin($pdo));
    }

    if ($action === 'create_lipa_device') {
        $name = trim((string) ($_POST['device_name'] ?? 'Lipa SMS device'));
        $uuid = bin2hex(random_bytes(16));
        $token = bin2hex(random_bytes(32));
        $pdo->prepare(
            'INSERT INTO tbl_lipa_devices (device_uuid, device_name, token_hash, status)
             VALUES (:uuid, :name, :token_hash, "active")'
        )->execute([
            ':uuid' => $uuid,
            ':name' => $name !== '' ? $name : 'Lipa SMS device',
            ':token_hash' => password_hash($token, PASSWORD_DEFAULT),
        ]);
        respond(201, ['ok' => true, 'message' => 'Device created. Copy this token now; it will not be shown again.', 'device_token' => $token] + load_billing_admin($pdo));
    }

    if ($action === 'revoke_lipa_device') {
        $deviceId = (int) ($_POST['device_id'] ?? 0);
        if ($deviceId <= 0) {
            respond(422, ['ok' => false, 'message' => 'Choose a device.']);
        }
        $pdo->prepare('UPDATE tbl_lipa_devices SET status = "revoked", updated_at = NOW() WHERE id = :id')
            ->execute([':id' => $deviceId]);
        respond(200, ['ok' => true, 'message' => 'Device revoked.'] + load_billing_admin($pdo));
    }

    if ($action === 'ignore_lipa_transaction') {
        $transactionId = (int) ($_POST['transaction_id'] ?? 0);
        if ($transactionId <= 0) {
            respond(422, ['ok' => false, 'message' => 'Choose a transaction.']);
        }
        $pdo->prepare('UPDATE tbl_lipa_transactions SET status = "ignored", notes = COALESCE(NULLIF(notes, ""), "Ignored by SaaS admin"), updated_at = NOW() WHERE id = :id')
            ->execute([':id' => $transactionId]);
        respond(200, ['ok' => true, 'message' => 'Transaction ignored.'] + load_billing_admin($pdo));
    }

    respond(400, ['ok' => false, 'message' => 'Unsupported billing action.']);
} catch (Throwable $e) {
    respond(500, ['ok' => false, 'message' => $e->getMessage()]);
}

