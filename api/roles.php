<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/permissions_lib.php';

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

try {
    $userId = require_user();
    $pdo = db();
    $businessId = active_business_id($pdo, $userId);
    if ($businessId <= 0) {
        respond(400, ['ok' => false, 'message' => 'No active business selected.']);
    }

    ensure_business_rbac($pdo, $businessId);
    require_permission($pdo, $businessId, $userId, 'settings.roles.manage');

    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        respond(200, [
            'ok' => true,
            'roles' => rbac_roles_payload($pdo, $businessId),
            'permissions' => rbac_permission_catalog(),
        ]);
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        respond(405, ['ok' => false, 'message' => 'Method not allowed.']);
    }

    $action = trim((string) ($_POST['action'] ?? 'save'));
    if ($action !== 'save') {
        respond(400, ['ok' => false, 'message' => 'Unsupported role action.']);
    }

    $roleId = (int) ($_POST['role_id'] ?? 0);
    $name = trim((string) ($_POST['name'] ?? ''));
    $permissions = $_POST['permissions'] ?? [];
    if (!is_array($permissions)) {
        $permissions = [];
    }
    $permissions = array_values(array_intersect(array_map('strval', $permissions), array_keys(rbac_permission_catalog())));

    if ($roleId <= 0 || $name === '') {
        respond(422, ['ok' => false, 'message' => 'Choose a role and provide a name.']);
    }

    $roleStmt = $pdo->prepare('SELECT id, slug, is_locked FROM tbl_roles WHERE id = :id AND business_id = :bid LIMIT 1');
    $roleStmt->execute([':id' => $roleId, ':bid' => $businessId]);
    $role = $roleStmt->fetch(PDO::FETCH_ASSOC);
    if (!$role) {
        respond(404, ['ok' => false, 'message' => 'Role not found.']);
    }
    if ((int) $role['is_locked'] === 1 || $role['slug'] === 'superAdmin') {
        respond(403, ['ok' => false, 'message' => 'Super Admin cannot be reduced or edited.']);
    }

    $pdo->beginTransaction();
    $pdo->prepare('UPDATE tbl_roles SET name = :name WHERE id = :id AND business_id = :bid')
        ->execute([':name' => $name, ':id' => $roleId, ':bid' => $businessId]);
    $pdo->prepare('DELETE FROM tbl_role_permissions WHERE role_id = :role_id')
        ->execute([':role_id' => $roleId]);

    $permIdStmt = $pdo->prepare('SELECT id FROM tbl_permissions WHERE permission_key = :permission_key LIMIT 1');
    $insertStmt = $pdo->prepare('INSERT IGNORE INTO tbl_role_permissions (role_id, permission_id) VALUES (:role_id, :permission_id)');
    foreach ($permissions as $permissionKey) {
        $permIdStmt->execute([':permission_key' => $permissionKey]);
        $permissionId = (int) ($permIdStmt->fetchColumn() ?: 0);
        if ($permissionId > 0) {
            $insertStmt->execute([':role_id' => $roleId, ':permission_id' => $permissionId]);
        }
    }
    $pdo->commit();

    respond(200, [
        'ok' => true,
        'message' => 'Role permissions updated successfully.',
        'roles' => rbac_roles_payload($pdo, $businessId),
    ]);
} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    respond(500, ['ok' => false, 'message' => $e->getMessage()]);
}
