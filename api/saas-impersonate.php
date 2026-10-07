<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/audit_lib.php';

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
    respond(401, ['ok' => false, 'message' => 'SaaS admin login required.']);
}

try {
    $pdo = db();
    $action = trim((string) ($_POST['action'] ?? 'start'));

    if ($action === 'stop') {
        unset(
            $_SESSION['zipoo_user_id'],
            $_SESSION['zipoo_business_id'],
            $_SESSION['zipoo_impersonating'],
            $_SESSION['zipoo_impersonated_user_id'],
            $_SESSION['zipoo_impersonated_user_name'],
            $_SESSION['zipoo_impersonated_business_id'],
            $_SESSION['zipoo_impersonated_business_name']
        );
        respond(200, ['ok' => true, 'message' => 'Impersonation ended.', 'redirect' => '/saas/users']);
    }

    $userId = (int) ($_POST['user_id'] ?? 0);
    if ($userId <= 0) {
        respond(422, ['ok' => false, 'message' => 'Select a valid user.']);
    }

    $stmt = $pdo->prepare(
        'SELECT u.id, u.business_id, u.full_name, u.phone, u.email, u.status,
                COALESCE(b.business_name, u.business_name) AS business_name,
                b.owner_user_id
         FROM tbl_users u
         LEFT JOIN tbl_businesses b ON b.id = u.business_id
         WHERE u.id = :id
         LIMIT 1'
    );
    $stmt->execute([':id' => $userId]);
    $user = $stmt->fetch();
    if (!$user) {
        respond(404, ['ok' => false, 'message' => 'User not found.']);
    }
    if (isset($user['status']) && (string) $user['status'] !== '' && (string) $user['status'] !== 'active') {
        respond(422, ['ok' => false, 'message' => 'Only active users can be impersonated.']);
    }

    $businessId = $user['business_id'] !== null ? (int) $user['business_id'] : 0;
    if ($businessId <= 0) {
        $owned = $pdo->prepare('SELECT id, business_name FROM tbl_businesses WHERE owner_user_id = :uid ORDER BY id ASC LIMIT 1');
        $owned->execute([':uid' => $userId]);
        $business = $owned->fetch();
        if ($business) {
            $businessId = (int) $business['id'];
            $user['business_name'] = $business['business_name'];
        }
    }
    if ($businessId <= 0) {
        respond(422, ['ok' => false, 'message' => 'This user is not connected to a business.']);
    }

    session_regenerate_id(true);
    $_SESSION['zipoo_user_id'] = (int) $user['id'];
    $_SESSION['zipoo_business_id'] = $businessId;
    $_SESSION['zipoo_impersonating'] = true;
    $_SESSION['zipoo_impersonated_user_id'] = (int) $user['id'];
    $_SESSION['zipoo_impersonated_user_name'] = (string) $user['full_name'];
    $_SESSION['zipoo_impersonated_business_id'] = $businessId;
    $_SESSION['zipoo_impersonated_business_name'] = (string) ($user['business_name'] ?? 'Business');

    try {
        ensure_audit_logs_table($pdo);
        audit_log($pdo, $businessId, (int) $user['id'], 'saas_impersonation_started', 'user', (string) $user['id'], null, [
            'saas_admin_id' => (int) $_SESSION['saas_admin_id'],
            'saas_admin_name' => $_SESSION['saas_admin_name'] ?? null,
        ]);
    } catch (Throwable) {
        // Do not block support access if audit table creation is unavailable.
    }

    respond(200, [
        'ok' => true,
        'message' => 'Impersonation started.',
        'redirect' => '/dashboard',
        'user' => [
            'id' => (int) $user['id'],
            'business_id' => $businessId,
            'business_name' => $user['business_name'] ?? null,
            'full_name' => $user['full_name'],
            'phone' => $user['phone'],
            'email' => $user['email'],
        ],
        'businesses' => [[
            'id' => $businessId,
            'business_name' => $user['business_name'] ?? 'Business',
        ]],
        'impersonation' => [
            'active' => true,
            'admin_name' => $_SESSION['saas_admin_name'] ?? 'SaaS Admin',
            'user_name' => $user['full_name'],
            'business_name' => $user['business_name'] ?? 'Business',
        ],
    ]);
} catch (Throwable) {
    respond(500, ['ok' => false, 'message' => 'Unable to start impersonation right now.']);
}
