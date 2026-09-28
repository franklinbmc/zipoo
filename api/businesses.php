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
    ];
}

function load_businesses(PDO $pdo, int $userId, ?int $businessId): array
{
    $stmt = $pdo->prepare(
        'SELECT id, business_name, business_type
         FROM tbl_businesses
         WHERE owner_user_id = :user_id OR id = :business_id
         ORDER BY id ASC'
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

    if ($action === 'switch') {
        $businessId = (int) ($_POST['business_id'] ?? 0);
        if ($businessId <= 0) {
            respond(422, ['ok' => false, 'message' => 'Choose a business.']);
        }

        $check = $pdo->prepare(
            'SELECT id, business_name, business_type
             FROM tbl_businesses
             WHERE id = :business_id AND (owner_user_id = :user_id OR id = :current_business_id)
             LIMIT 1'
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

        respond(200, ['ok' => true, 'business' => business_payload($business)]);
    }

    if ($action === 'create') {
        $businessName = trim((string) ($_POST['business_name'] ?? ''));
        $businessType = trim((string) ($_POST['business_type'] ?? 'service'));

        if ($businessName === '') {
            respond(422, ['ok' => false, 'message' => 'Business name is required.']);
        }

        $defaults = null;
        if ($currentBusinessId !== null) {
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
        ];

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