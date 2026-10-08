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

function require_user(): int
{
    $userId = (int) ($_SESSION['zipoo_user_id'] ?? 0);
    if ($userId <= 0) {
        respond(401, ['ok' => false, 'message' => 'Please login first.']);
    }
    return $userId;
}

function current_business_id(PDO $pdo, int $userId): int
{
    $businessId = (int) ($_SESSION['zipoo_business_id'] ?? 0);
    if ($businessId > 0) {
        return $businessId;
    }
    $stmt = $pdo->prepare('SELECT business_id FROM tbl_users WHERE id = :uid LIMIT 1');
    $stmt->execute([':uid' => $userId]);
    $businessId = (int) ($stmt->fetchColumn() ?: 0);
    if ($businessId > 0) {
        $_SESSION['zipoo_business_id'] = $businessId;
    }
    return $businessId;
}

function lipa_numbers(PDO $pdo): array
{
    $raw = saas_setting($pdo, 'lipa', 'lipa_numbers', '[]');
    $rows = json_decode($raw, true);
    if (!is_array($rows)) {
        $rows = [];
        foreach (preg_split('/\R/', $raw) ?: [] as $line) {
            $parts = array_map('trim', explode('|', $line));
            if (($parts[0] ?? '') !== '' || ($parts[1] ?? '') !== '') {
                $rows[] = ['network' => $parts[0] ?? '', 'number' => $parts[1] ?? '', 'name' => $parts[2] ?? ''];
            }
        }
    }
    return array_values(array_filter(array_map(static function ($row): array {
        return [
            'network' => trim((string) ($row['network'] ?? '')),
            'number' => trim((string) ($row['number'] ?? '')),
            'name' => trim((string) ($row['name'] ?? '')),
            'qr_url' => trim((string) ($row['qr_url'] ?? '')),
        ];
    }, $rows), static fn(array $row): bool => $row['network'] !== '' && $row['number'] !== ''));
}

function billing_payload(PDO $pdo, int $accountId): array
{
    $subStmt = $pdo->prepare(
        'SELECT s.*, p.plan_name, p.monthly_price, p.business_limit, p.extra_business_price, p.included_sms
         FROM tbl_account_subscriptions s
         LEFT JOIN tbl_saas_plans p ON p.id = s.plan_id
         WHERE s.account_id = :aid
         LIMIT 1'
    );
    $subStmt->execute([':aid' => $accountId]);
    $subscription = $subStmt->fetch(PDO::FETCH_ASSOC) ?: [];

    $businessCountStmt = $pdo->prepare('SELECT COUNT(*) FROM tbl_account_businesses WHERE account_id = :aid');
    $businessCountStmt->execute([':aid' => $accountId]);
    $businessCount = (int) $businessCountStmt->fetchColumn();
    $includedBusinesses = max(1, (int) ($subscription['business_limit'] ?? 1));
    $extraBusinesses = max(0, $businessCount - $includedBusinesses);
    $monthlyPrice = (float) ($subscription['monthly_price'] ?? 0);
    $extraPrice = (float) ($subscription['extra_business_price'] ?? 0);
    $estimatedTotal = $monthlyPrice + ($extraBusinesses * $extraPrice);

    $invoiceStmt = $pdo->prepare(
        'SELECT * FROM tbl_account_subscription_invoices
         WHERE account_id = :aid
         ORDER BY id DESC
         LIMIT 12'
    );
    $invoiceStmt->execute([':aid' => $accountId]);
    $invoices = $invoiceStmt->fetchAll(PDO::FETCH_ASSOC);

    $walletStmt = $pdo->prepare('SELECT balance FROM tbl_account_sms_wallets WHERE account_id = :aid LIMIT 1');
    $walletStmt->execute([':aid' => $accountId]);
    $smsBalance = (int) ($walletStmt->fetchColumn() ?: 0);

    $bundles = $pdo->query('SELECT id, name, sms_count, price FROM tbl_sms_bundles WHERE status = "active" ORDER BY price ASC, sms_count ASC')->fetchAll(PDO::FETCH_ASSOC);
    $plans = $pdo->query(
        'SELECT id, plan_name, monthly_price, user_limit, business_limit, extra_business_price, included_sms, notes
         FROM tbl_saas_plans
         WHERE status = "active"
         ORDER BY monthly_price ASC, id ASC'
    )->fetchAll(PDO::FETCH_ASSOC);

    return [
        'subscription' => $subscription,
        'plans' => $plans,
        'business_count' => $businessCount,
        'included_businesses' => $includedBusinesses,
        'extra_businesses' => $extraBusinesses,
        'estimated_monthly_total' => $estimatedTotal,
        'invoices' => $invoices,
        'sms_balance' => $smsBalance,
        'sms_bundles' => $bundles,
        'lipa_enabled' => saas_setting($pdo, 'lipa', 'lipa_payment_enabled', '1') === '1',
        'lipa_numbers' => lipa_numbers($pdo),
    ];
}

try {
    $userId = require_user();
    $pdo = db();
    ensure_billing_tables($pdo);
    $businessId = current_business_id($pdo, $userId);
    if ($businessId <= 0) {
        respond(400, ['ok' => false, 'message' => 'No active business selected.']);
    }

    $accountId = ensure_business_account($pdo, $businessId);
    if ($accountId <= 0) {
        respond(400, ['ok' => false, 'message' => 'No billing account found.']);
    }

    $ownerStmt = $pdo->prepare('SELECT owner_user_id FROM tbl_billing_accounts WHERE id = :aid LIMIT 1');
    $ownerStmt->execute([':aid' => $accountId]);
    $ownerId = (int) ($ownerStmt->fetchColumn() ?: 0);
    if ($ownerId !== $userId) {
        respond(403, ['ok' => false, 'message' => 'Only the account owner can manage billing.']);
    }

    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        respond(200, ['ok' => true] + billing_payload($pdo, $accountId));
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        respond(405, ['ok' => false, 'message' => 'Method not allowed.']);
    }

    $action = trim((string) ($_POST['action'] ?? ''));
    if ($action === 'choose_plan') {
        $planId = (int) ($_POST['plan_id'] ?? 0);
        if ($planId <= 0) {
            respond(422, ['ok' => false, 'message' => 'Choose a package plan.']);
        }
        $planStmt = $pdo->prepare('SELECT * FROM tbl_saas_plans WHERE id = :id AND status = "active" LIMIT 1');
        $planStmt->execute([':id' => $planId]);
        $plan = $planStmt->fetch(PDO::FETCH_ASSOC);
        if (!$plan) {
            respond(404, ['ok' => false, 'message' => 'Package plan not found.']);
        }

        $subscriptionId = ensure_account_subscription($pdo, $accountId);
        $countStmt = $pdo->prepare('SELECT COUNT(*) FROM tbl_account_businesses WHERE account_id = :aid');
        $countStmt->execute([':aid' => $accountId]);
        $businessCount = (int) $countStmt->fetchColumn();
        $included = max(1, (int) ($plan['business_limit'] ?? 1));
        $extra = max(0, $businessCount - $included);
        $amount = (float) ($plan['monthly_price'] ?? 0) + ($extra * (float) ($plan['extra_business_price'] ?? 0));

        $pdo->beginTransaction();
        try {
            $pdo->prepare(
                'UPDATE tbl_account_subscriptions
                 SET plan_id = :plan_id, updated_at = NOW()
                 WHERE account_id = :aid'
            )->execute([':plan_id' => $planId, ':aid' => $accountId]);

            $pdo->prepare(
                'INSERT INTO tbl_account_subscription_invoices
                    (account_id, subscription_id, invoice_number, amount, businesses_count, extra_businesses_count, status, due_date, notes)
                 VALUES
                    (:aid, :sid, :invoice_number, :amount, :businesses_count, :extra_businesses_count, "pending_payment", CURDATE(), :notes)'
            )->execute([
                ':aid' => $accountId,
                ':sid' => $subscriptionId,
                ':invoice_number' => billing_generate_invoice_number($accountId),
                ':amount' => $amount,
                ':businesses_count' => $businessCount,
                ':extra_businesses_count' => $extra,
                ':notes' => 'Plan selected: ' . (string) $plan['plan_name'],
            ]);
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        respond(200, ['ok' => true, 'message' => 'Plan selected and invoice issued.'] + billing_payload($pdo, $accountId));
    }

    if ($action === 'submit_lipa_reference') {
        $invoiceId = (int) ($_POST['invoice_id'] ?? 0);
        $reference = lipa_normalize_reference((string) ($_POST['reference'] ?? ''));
        if ($invoiceId <= 0 || $reference === '') {
            respond(422, ['ok' => false, 'message' => 'Choose an invoice and enter the payment reference.']);
        }
        $stmt = $pdo->prepare('SELECT id FROM tbl_account_subscription_invoices WHERE id = :id AND account_id = :aid LIMIT 1');
        $stmt->execute([':id' => $invoiceId, ':aid' => $accountId]);
        if (!$stmt->fetchColumn()) {
            respond(404, ['ok' => false, 'message' => 'Invoice not found.']);
        }
        $pdo->prepare('UPDATE tbl_account_subscription_invoices SET payment_method = "lipa_namba", payment_reference = :ref, status = "pending_payment", updated_at = NOW() WHERE id = :id AND account_id = :aid')
            ->execute([':ref' => $reference, ':id' => $invoiceId, ':aid' => $accountId]);
        $outcome = lipa_reconcile_reference($pdo, $reference);
        respond(200, ['ok' => true, 'message' => $outcome['message'], 'outcome' => $outcome] + billing_payload($pdo, $accountId));
    }

    respond(400, ['ok' => false, 'message' => 'Unsupported billing action.']);
} catch (Throwable $e) {
    respond(500, ['ok' => false, 'message' => $e->getMessage()]);
}

