<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';

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

function ensure_business_preference_columns(PDO $pdo): void
{
    $cols = $pdo->query("SHOW COLUMNS FROM tbl_businesses LIKE 'currency'")->fetchAll();
    if (empty($cols)) {
        $pdo->exec("ALTER TABLE tbl_businesses ADD COLUMN currency VARCHAR(10) NOT NULL DEFAULT 'TZS' AFTER plan_name");
    }

    $cols = $pdo->query("SHOW COLUMNS FROM tbl_businesses LIKE 'timezone'")->fetchAll();
    if (empty($cols)) {
        $pdo->exec("ALTER TABLE tbl_businesses ADD COLUMN timezone VARCHAR(100) NOT NULL DEFAULT 'Africa/Dar_es_Salaam' AFTER currency");
    }

    $cols = $pdo->query("SHOW COLUMNS FROM tbl_businesses LIKE 'tax_rate'")->fetchAll();
    if (empty($cols)) {
        $pdo->exec("ALTER TABLE tbl_businesses ADD COLUMN tax_rate DECIMAL(5, 2) NOT NULL DEFAULT 18.00 AFTER timezone");
    }

    $cols = $pdo->query("SHOW COLUMNS FROM tbl_businesses LIKE 'receipt_footer'")->fetchAll();
    if (empty($cols)) {
        $pdo->exec("ALTER TABLE tbl_businesses ADD COLUMN receipt_footer TEXT NULL AFTER tax_rate");
    }

    $cols = $pdo->query("SHOW COLUMNS FROM tbl_businesses LIKE 'vat_enabled'")->fetchAll();
    if (empty($cols)) {
        $pdo->exec("ALTER TABLE tbl_businesses ADD COLUMN vat_enabled TINYINT(1) NOT NULL DEFAULT 0 AFTER tax_rate");
    }

    // TIN = Taxpayer Identification Number, VRN = VAT Registration Number (see migration 014).
    $cols = $pdo->query("SHOW COLUMNS FROM tbl_businesses LIKE 'tin'")->fetchAll();
    if (empty($cols)) {
        $pdo->exec("ALTER TABLE tbl_businesses ADD COLUMN tin VARCHAR(50) NULL AFTER vat_enabled");
    }

    $cols = $pdo->query("SHOW COLUMNS FROM tbl_businesses LIKE 'vrn'")->fetchAll();
    if (empty($cols)) {
        $pdo->exec("ALTER TABLE tbl_businesses ADD COLUMN vrn VARCHAR(50) NULL AFTER tin");
    }
}

function business_payload(array $business): array
{
    return [
        'id' => (int) $business['id'],
        'business_name' => $business['business_name'],
        'business_type' => $business['business_type'],
        'region_code' => $business['region_code'] ?? null,
        'region_name' => $business['region_name'] ?? null,
        'district_code' => $business['district_code'] ?? null,
        'district_name' => $business['district_name'] ?? null,
        'plan_name' => $business['plan_name'] ?? null,
        'account_status' => $business['account_status'] ?? null,
        'currency' => (string) ($business['currency'] ?? 'TZS'),
        'timezone' => (string) ($business['timezone'] ?? 'Africa/Dar_es_Salaam'),
        'tax_rate' => (float) ($business['tax_rate'] ?? 18.00),
        'vat_enabled' => (int) ($business['vat_enabled'] ?? 0),
        'tin' => (string) ($business['tin'] ?? ''),
        'vrn' => (string) ($business['vrn'] ?? ''),
        'receipt_footer' => (string) ($business['receipt_footer'] ?? ''),
        'created_at' => $business['created_at'] ?? null,
    ];
}

function business_select_sql(string $where): string
{
    return "SELECT b.id, b.business_name, b.business_type, b.region_code, l.region_name, b.district_code, l.district_name,
                   b.plan_name, b.account_status, b.currency, b.timezone, b.tax_rate, b.vat_enabled, b.tin, b.vrn, b.receipt_footer, b.created_at
            FROM tbl_businesses b
            LEFT JOIN tbl_tanzania_locations l ON l.region_code = b.region_code AND l.district_code = b.district_code
            WHERE {$where}";
}

function notify_user(PDO $pdo, int $userId, string $subject, string $message): void
{
    try {
        $stmt = $pdo->prepare('SELECT email FROM tbl_users WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $userId]);
        $email = (string) ($stmt->fetchColumn() ?: '');
        if ($email !== '') {
            @mail($email, $subject, $message, 'From: no-reply@localhost');
        }
    } catch (Throwable) {
    }
}

function load_businesses(PDO $pdo, int $userId, ?int $businessId): array
{
    $stmt = $pdo->prepare(
        business_select_sql('b.owner_user_id = :user_id OR b.id = :business_id') . ' ORDER BY b.id ASC'
    );
    $stmt->execute([
        ':user_id' => $userId,
        ':business_id' => $businessId ?? 0,
    ]);

    return array_map('business_payload', $stmt->fetchAll());
}

try {
    $userId = require_user();
    $pdo = db();
    ensure_business_preference_columns($pdo);
    $currentBusinessId = isset($_SESSION['zipoo_business_id']) ? (int) $_SESSION['zipoo_business_id'] : null;

    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        respond(200, [
            'ok' => true,
            'businesses' => load_businesses($pdo, $userId, $currentBusinessId),
            'current_business_id' => $currentBusinessId,
        ]);
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        respond(405, ['ok' => false, 'message' => 'Method not allowed.']);
    }

    $action = trim((string) ($_POST['action'] ?? ''));

    if ($action === 'set_default') {
        $action = 'switch';
    }

    if ($action === 'delete') {
        $businessId = (int) ($_POST['business_id'] ?? 0);
        if ($businessId <= 0) {
            respond(422, ['ok' => false, 'message' => 'Choose a business.']);
        }
        if ($currentBusinessId !== null && $businessId === $currentBusinessId) {
            respond(422, ['ok' => false, 'message' => 'Switch to another business before deleting this one.']);
        }
        $delete = $pdo->prepare('DELETE FROM tbl_businesses WHERE id = :business_id AND owner_user_id = :user_id');
        $delete->execute([':business_id' => $businessId, ':user_id' => $userId]);
        if ($delete->rowCount() === 0) {
            respond(403, ['ok' => false, 'message' => 'You cannot delete that business.']);
        }
        notify_user($pdo, $userId, 'Zipoo business deleted', 'A business was deleted from your Zipoo account.');
        respond(200, ['ok' => true, 'businesses' => load_businesses($pdo, $userId, $currentBusinessId)]);
    }

    if ($action === 'switch') {
        $businessId = (int) ($_POST['business_id'] ?? 0);
        if ($businessId <= 0) {
            respond(422, ['ok' => false, 'message' => 'Choose a business.']);
        }

        $check = $pdo->prepare(
            business_select_sql('b.id = :business_id AND (b.owner_user_id = :user_id OR b.id = :current_business_id)') . ' LIMIT 1'
        );
        $check->execute([
            ':business_id' => $businessId,
            ':user_id' => $userId,
            ':current_business_id' => $currentBusinessId ?? 0,
        ]);
        $business = $check->fetch();

        if (!$business) {
            respond(403, ['ok' => false, 'message' => 'You cannot access that business.']);
        }

        $_SESSION['zipoo_business_id'] = (int) $business['id'];
        $pdo->prepare('UPDATE tbl_users SET business_id = :business_id WHERE id = :user_id')
            ->execute([':business_id' => (int) $business['id'], ':user_id' => $userId]);

        notify_user($pdo, $userId, 'Zipoo serving business changed', 'Your current serving business was changed.');
        respond(200, ['ok' => true, 'business' => business_payload($business)]);
    }

    if ($action === 'create') {
        $businessName = trim((string) ($_POST['business_name'] ?? ''));
        $businessType = trim((string) ($_POST['business_type'] ?? 'service'));
        $regionCode = trim((string) ($_POST['region'] ?? $_POST['region_code'] ?? ''));
        $districtCode = trim((string) ($_POST['district'] ?? $_POST['district_code'] ?? ''));

        if ($businessName === '') {
            respond(422, ['ok' => false, 'message' => 'Business name is required.']);
        }

        $defaults = null;
        if ($regionCode !== '' && $districtCode !== '') {
            $defaults = ['region_code' => $regionCode, 'district_code' => $districtCode];
        }

        if (!$defaults && $currentBusinessId !== null) {
            $defaultsStmt = $pdo->prepare('SELECT region_code, district_code FROM tbl_businesses WHERE id = :business_id LIMIT 1');
            $defaultsStmt->execute([':business_id' => $currentBusinessId]);
            $defaults = $defaultsStmt->fetch();
        }

        if (!$defaults) {
            $defaultsStmt = $pdo->prepare('SELECT region_code, district_code FROM tbl_users WHERE id = :user_id LIMIT 1');
            $defaultsStmt->execute([':user_id' => $userId]);
            $defaults = $defaultsStmt->fetch();
        }

        if (!$defaults || empty($defaults['region_code']) || empty($defaults['district_code'])) {
            respond(422, ['ok' => false, 'message' => 'Your current business location is required before creating another business.']);
        }

        $create = $pdo->prepare(
            'INSERT INTO tbl_businesses
                (owner_user_id, business_name, business_type, region_code, district_code)
             VALUES
                (:owner_user_id, :business_name, :business_type, :region_code, :district_code)'
        );
        $create->execute([
            ':owner_user_id' => $userId,
            ':business_name' => $businessName,
            ':business_type' => $businessType !== '' ? $businessType : 'service',
            ':region_code' => $defaults['region_code'],
            ':district_code' => $defaults['district_code'],
        ]);

        $businessId = (int) $pdo->lastInsertId();
        $_SESSION['zipoo_business_id'] = $businessId;
        $pdo->prepare(
            'UPDATE tbl_users
             SET business_id = :business_id,
                 business_name = :business_name,
                 business_type = :business_type,
                 region_code = :region_code,
                 district_code = :district_code
             WHERE id = :user_id'
        )->execute([
            ':business_id' => $businessId,
            ':business_name' => $businessName,
            ':business_type' => $businessType !== '' ? $businessType : 'service',
            ':region_code' => $defaults['region_code'],
            ':district_code' => $defaults['district_code'],
            ':user_id' => $userId,
        ]);

        $business = [
            'id' => $businessId,
            'business_name' => $businessName,
            'business_type' => $businessType !== '' ? $businessType : 'service',
            'region_code' => $defaults['region_code'],
            'district_code' => $defaults['district_code'],
        ];

        notify_user($pdo, $userId, 'Zipoo business created', 'A new business was created on your Zipoo account.');

        respond(201, [
            'ok' => true,
            'business' => $business,
            'businesses' => load_businesses($pdo, $userId, $businessId),
        ]);
    }

    if ($action === 'update') {
        $businessId = (int) ($_POST['business_id'] ?? 0);
        $businessName = trim((string) ($_POST['business_name'] ?? ''));
        $businessType = trim((string) ($_POST['business_type'] ?? 'service'));
        $regionCode = trim((string) ($_POST['region'] ?? $_POST['region_code'] ?? ''));
        $districtCode = trim((string) ($_POST['district'] ?? $_POST['district_code'] ?? ''));

        if ($businessId <= 0) {
            respond(422, ['ok' => false, 'message' => 'Choose a business to edit.']);
        }
        if ($businessName === '') {
            respond(422, ['ok' => false, 'message' => 'Business name is required.']);
        }
        if ($regionCode === '' || $districtCode === '') {
            respond(422, ['ok' => false, 'message' => 'Region and district are required.']);
        }

        $check = $pdo->prepare('SELECT id FROM tbl_businesses WHERE id = :business_id AND owner_user_id = :user_id LIMIT 1');
        $check->execute([':business_id' => $businessId, ':user_id' => $userId]);
        if (!$check->fetch()) {
            respond(403, ['ok' => false, 'message' => 'You cannot edit that business.']);
        }

        $update = $pdo->prepare(
            'UPDATE tbl_businesses
             SET business_name = :business_name,
                 business_type = :business_type,
                 region_code = :region_code,
                 district_code = :district_code
             WHERE id = :business_id AND owner_user_id = :user_id'
        );
        $update->execute([
            ':business_name' => $businessName,
            ':business_type' => $businessType !== '' ? $businessType : 'service',
            ':region_code' => $regionCode,
            ':district_code' => $districtCode,
            ':business_id' => $businessId,
            ':user_id' => $userId,
        ]);

        if ($currentBusinessId !== null && $businessId === $currentBusinessId) {
            $pdo->prepare(
                'UPDATE tbl_users
                 SET business_name = :business_name,
                     business_type = :business_type,
                     region_code = :region_code,
                     district_code = :district_code
                 WHERE id = :user_id'
            )->execute([
                ':business_name' => $businessName,
                ':business_type' => $businessType !== '' ? $businessType : 'service',
                ':region_code' => $regionCode,
                ':district_code' => $districtCode,
                ':user_id' => $userId,
            ]);
        }

        notify_user($pdo, $userId, 'Zipoo business updated', 'Business details were updated for ' . $businessName . '.');

        $businesses = load_businesses($pdo, $userId, $currentBusinessId);
        $updatedBusiness = null;
        foreach ($businesses as $b) {
            if ($b['id'] === $businessId) {
                $updatedBusiness = $b;
                break;
            }
        }

        respond(200, [
            'ok' => true,
            'message' => 'Business updated successfully.',
            'business' => $updatedBusiness,
            'businesses' => $businesses,
        ]);
    }

    if ($action === 'save_preferences') {
        $businessId = (int) ($_POST['business_id'] ?? 0);
        $currency = strtoupper(trim((string) ($_POST['currency'] ?? 'TZS')));
        $timezone = trim((string) ($_POST['timezone'] ?? 'Africa/Dar_es_Salaam'));
        $taxRate = (float) ($_POST['tax_rate'] ?? 18.00);
        $vatEnabled = !empty($_POST['vat_enabled']) && $_POST['vat_enabled'] !== '0' ? 1 : 0;
        $tin = trim((string) ($_POST['tin'] ?? ''));
        $vrn = trim((string) ($_POST['vrn'] ?? ''));
        $receiptFooter = trim((string) ($_POST['receipt_footer'] ?? ''));

        if ($businessId <= 0) {
            respond(422, ['ok' => false, 'message' => 'Choose a business.']);
        }

        $check = $pdo->prepare('SELECT id FROM tbl_businesses WHERE id = :bid AND owner_user_id = :uid LIMIT 1');
        $check->execute([':bid' => $businessId, ':uid' => $userId]);
        if (!$check->fetch()) {
            respond(403, ['ok' => false, 'message' => 'You cannot edit that business.']);
        }

        $stmt = $pdo->prepare(
            'UPDATE tbl_businesses
             SET currency = :currency, timezone = :timezone, tax_rate = :tax_rate, vat_enabled = :vat_enabled,
                 tin = :tin, vrn = :vrn, receipt_footer = :receipt_footer
             WHERE id = :bid AND owner_user_id = :uid'
        );
        $stmt->execute([
            ':bid' => $businessId,
            ':uid' => $userId,
            ':currency' => $currency !== '' ? $currency : 'TZS',
            ':timezone' => $timezone !== '' ? $timezone : 'Africa/Dar_es_Salaam',
            ':tax_rate' => $taxRate,
            ':vat_enabled' => $vatEnabled,
            ':tin' => $tin !== '' ? $tin : null,
            ':vrn' => $vrn !== '' ? $vrn : null,
            ':receipt_footer' => $receiptFooter !== '' ? $receiptFooter : null,
        ]);

        $businesses = load_businesses($pdo, $userId, $currentBusinessId);
        $updatedBusiness = null;
        foreach ($businesses as $b) {
            if ($b['id'] === $businessId) {
                $updatedBusiness = $b;
                break;
            }
        }

        respond(200, [
            'ok' => true,
            'message' => 'Preferences updated successfully.',
            'business' => $updatedBusiness,
            'businesses' => $businesses,
        ]);
    }

    respond(422, ['ok' => false, 'message' => 'Unknown business action.']);
} catch (Throwable $error) {
    respond(500, ['ok' => false, 'message' => 'Unable to update businesses right now.']);
}
