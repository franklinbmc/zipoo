<?php
declare(strict_types=1);

return function (PDO $pdo): void {
    require_once dirname(__DIR__, 2) . '/api/permissions_lib.php';

    ensure_rbac_tables($pdo);
    seed_permissions($pdo);

    $businessIds = $pdo->query('SELECT id FROM tbl_businesses')->fetchAll(PDO::FETCH_COLUMN);
    foreach ($businessIds as $businessId) {
        ensure_business_rbac($pdo, (int) $businessId);
    }

    $stmt = $pdo->query('SELECT id, business_id, role FROM tbl_users WHERE business_id IS NOT NULL AND business_id > 0');
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $user) {
        $role = (string) ($user['role'] ?? 'viewer');
        $slug = match (strtolower($role)) {
            'owner' => 'superAdmin',
            'manager' => 'manager',
            'cashier' => 'cashier',
            'staff' => 'viewer',
            default => $role !== '' ? $role : 'viewer',
        };
        assign_user_role($pdo, (int) $user['business_id'], (int) $user['id'], $slug);
    }
};
