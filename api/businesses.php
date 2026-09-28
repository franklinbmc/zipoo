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
        'created_at' => $business['created_at'] ?? null,
    ];
}

function business_select_sql(string $where): string
{
    return "SELECT b.id, b.business_name, b.business_type, b.region_code, l.region_name, b.district_code, l.district_name,
                   b.plan_name, b.account_status, b.created_at
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
        $pdo->prepare('UPDATE tbl_users SET business_id = :business_id WHERE id = :user_id')
            ->execute([':business_id' => $businessId, ':user_id' => $userId]);

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

    respond(422, ['ok' => false, 'message' => 'Unknown business action.']);
} catch (Throwable $error) {
    respond(500, ['ok' => false, 'message' => 'Unable to update businesses right now.']);
}
