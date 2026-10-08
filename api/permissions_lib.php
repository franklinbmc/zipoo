<?php
declare(strict_types=1);

function rbac_permission_catalog(): array
{
    return [
        'dashboard.view' => ['Dashboard', 'View dashboard'],
        'sales.view' => ['Sales', 'View sales'],
        'sales.create' => ['Sales', 'Create sales'],
        'sales.edit' => ['Sales', 'Edit sales'],
        'sales.delete' => ['Sales', 'Delete/cancel sales'],
        'sales.receive_payment' => ['Sales', 'Receive payments'],
        'sales.reports.view' => ['Sales', 'View sales reports'],
        'sales.debts.view' => ['Sales', 'View debts'],
        'sales.debts.manage' => ['Sales', 'Manage debts'],
        'sales.shifts.view' => ['Sales', 'View shifts'],
        'sales.shifts.manage' => ['Sales', 'Manage shifts'],
        'stock.view' => ['Stock', 'View stock'],
        'products.view' => ['Stock', 'View products/services'],
        'products.create' => ['Stock', 'Create products/services'],
        'products.edit' => ['Stock', 'Edit products/services'],
        'products.delete' => ['Stock', 'Delete products/services'],
        'stock.movements.view' => ['Stock', 'View stock movement'],
        'stock.movements.manage' => ['Stock', 'Manage stock movement'],
        'warehouses.view' => ['Stock', 'View warehouses'],
        'warehouses.manage' => ['Stock', 'Manage warehouses'],
        'purchasing.view' => ['Purchasing', 'View purchasing'],
        'purchasing.manage' => ['Purchasing', 'Manage purchasing'],
        'customers.view' => ['Customers', 'View customers'],
        'customers.manage' => ['Customers', 'Manage customers'],
        'suppliers.view' => ['Suppliers', 'View suppliers'],
        'suppliers.manage' => ['Suppliers', 'Manage suppliers'],
        'expenses.view' => ['Expenses', 'View expenses'],
        'expenses.manage' => ['Expenses', 'Manage expenses'],
        'bank_cash.view' => ['Bank & Cash', 'View bank and cash'],
        'bank_cash.manage' => ['Bank & Cash', 'Manage bank and cash'],
        'real_estate.view' => ['Real Estate', 'View real estate'],
        'real_estate.manage' => ['Real Estate', 'Manage real estate'],
        'settings.view' => ['Settings', 'View settings'],
        'settings.business.manage' => ['Settings', 'Manage business settings'],
        'settings.smtp.manage' => ['Settings', 'Manage SMTP settings'],
        'settings.sms.manage' => ['Settings', 'Manage SMS settings'],
        'settings.users.manage' => ['Settings', 'Manage users'],
        'settings.roles.manage' => ['Settings', 'Manage roles and permissions'],
    ];
}

function rbac_default_roles(): array
{
    $all = array_keys(rbac_permission_catalog());

    return [
        'superAdmin' => [
            'name' => 'Super Admin',
            'description' => 'Business owner with full control.',
            'permissions' => $all,
            'locked' => true,
        ],
        'admin' => [
            'name' => 'Admin',
            'description' => 'Broad business administration without owner-only controls.',
            'permissions' => array_values(array_diff($all, ['settings.roles.manage'])),
        ],
        'manager' => [
            'name' => 'Manager',
            'description' => 'Daily operations, reports, stock, customers, and staff work.',
            'permissions' => [
                'dashboard.view', 'sales.view', 'sales.create', 'sales.edit', 'sales.receive_payment',
                'sales.reports.view', 'sales.debts.view', 'sales.debts.manage', 'sales.shifts.view',
                'sales.shifts.manage', 'stock.view', 'products.view', 'products.create', 'products.edit',
                'stock.movements.view', 'stock.movements.manage', 'warehouses.view', 'warehouses.manage',
                'purchasing.view', 'purchasing.manage', 'customers.view', 'customers.manage',
                'suppliers.view', 'suppliers.manage', 'expenses.view', 'expenses.manage',
                'bank_cash.view', 'real_estate.view', 'real_estate.manage', 'settings.view',
            ],
        ],
        'cashier' => [
            'name' => 'Cashier',
            'description' => 'POS sales, payments, shift work, and customer lookup.',
            'permissions' => [
                'dashboard.view', 'sales.view', 'sales.create', 'sales.receive_payment',
                'sales.debts.view', 'sales.shifts.view', 'sales.shifts.manage',
                'customers.view', 'products.view',
            ],
        ],
        'stock_keeper' => [
            'name' => 'Stock Keeper',
            'description' => 'Products, purchasing, warehouses, and stock movement.',
            'permissions' => [
                'dashboard.view', 'stock.view', 'products.view', 'products.create', 'products.edit',
                'stock.movements.view', 'stock.movements.manage', 'warehouses.view', 'warehouses.manage',
                'purchasing.view', 'purchasing.manage', 'suppliers.view',
            ],
        ],
        'accountant' => [
            'name' => 'Accountant',
            'description' => 'Reports, debts, expenses, bank/cash, and balances.',
            'permissions' => [
                'dashboard.view', 'sales.view', 'sales.reports.view', 'sales.debts.view',
                'sales.debts.manage', 'customers.view', 'suppliers.view', 'expenses.view',
                'expenses.manage', 'bank_cash.view', 'bank_cash.manage',
            ],
        ],
        'viewer' => [
            'name' => 'Viewer',
            'description' => 'Read-only access to selected business areas.',
            'permissions' => [
                'dashboard.view', 'sales.view', 'sales.reports.view', 'stock.view', 'products.view',
                'stock.movements.view', 'warehouses.view', 'customers.view', 'suppliers.view',
                'expenses.view', 'bank_cash.view', 'real_estate.view', 'settings.view',
            ],
        ],
    ];
}

function ensure_rbac_tables(PDO $pdo): void
{
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS tbl_permissions (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            permission_key VARCHAR(120) NOT NULL UNIQUE,
            label VARCHAR(160) NOT NULL,
            group_name VARCHAR(80) NOT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS tbl_roles (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            business_id BIGINT UNSIGNED NOT NULL,
            name VARCHAR(120) NOT NULL,
            slug VARCHAR(80) NOT NULL,
            description VARCHAR(255) NULL,
            is_system TINYINT(1) NOT NULL DEFAULT 0,
            is_locked TINYINT(1) NOT NULL DEFAULT 0,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY unique_business_role_slug (business_id, slug),
            KEY idx_business (business_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS tbl_role_permissions (
            role_id BIGINT UNSIGNED NOT NULL,
            permission_id BIGINT UNSIGNED NOT NULL,
            PRIMARY KEY (role_id, permission_id),
            KEY idx_permission (permission_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS tbl_user_roles (
            user_id BIGINT UNSIGNED NOT NULL,
            business_id BIGINT UNSIGNED NOT NULL,
            role_id BIGINT UNSIGNED NOT NULL,
            assigned_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (user_id, business_id),
            KEY idx_role (role_id),
            KEY idx_business (business_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );
}

function seed_permissions(PDO $pdo): void
{
    $stmt = $pdo->prepare(
        'INSERT INTO tbl_permissions (permission_key, label, group_name)
         VALUES (:permission_key, :label, :group_name)
         ON DUPLICATE KEY UPDATE label = VALUES(label), group_name = VALUES(group_name)'
    );

    foreach (rbac_permission_catalog() as $key => [$group, $label]) {
        $stmt->execute([
            ':permission_key' => $key,
            ':label' => $label,
            ':group_name' => $group,
        ]);
    }
}

function role_id_by_slug(PDO $pdo, int $businessId, string $slug): int
{
    $stmt = $pdo->prepare('SELECT id FROM tbl_roles WHERE business_id = :bid AND slug = :slug LIMIT 1');
    $stmt->execute([':bid' => $businessId, ':slug' => $slug]);
    return (int) ($stmt->fetchColumn() ?: 0);
}

function seed_business_roles(PDO $pdo, int $businessId): void
{
    if ($businessId <= 0) {
        return;
    }

    ensure_rbac_tables($pdo);
    seed_permissions($pdo);

    $existingRoleStmt = $pdo->prepare('SELECT id FROM tbl_roles WHERE business_id = :bid AND slug = :slug LIMIT 1');
    $roleStmt = $pdo->prepare(
        'INSERT INTO tbl_roles (business_id, name, slug, description, is_system, is_locked)
         VALUES (:bid, :name, :slug, :description, 1, :locked)
         ON DUPLICATE KEY UPDATE name = VALUES(name), description = VALUES(description), is_system = 1, is_locked = VALUES(is_locked)'
    );
    $permIdStmt = $pdo->prepare('SELECT id FROM tbl_permissions WHERE permission_key = :permission_key LIMIT 1');
    $deleteStmt = $pdo->prepare('DELETE FROM tbl_role_permissions WHERE role_id = :role_id');
    $insertPermStmt = $pdo->prepare(
        'INSERT IGNORE INTO tbl_role_permissions (role_id, permission_id)
         VALUES (:role_id, :permission_id)'
    );

    foreach (rbac_default_roles() as $slug => $role) {
        $existingRoleStmt->execute([':bid' => $businessId, ':slug' => $slug]);
        $roleExisted = (bool) $existingRoleStmt->fetchColumn();
        $roleStmt->execute([
            ':bid' => $businessId,
            ':name' => $role['name'],
            ':slug' => $slug,
            ':description' => $role['description'],
            ':locked' => !empty($role['locked']) ? 1 : 0,
        ]);

        $roleId = role_id_by_slug($pdo, $businessId, $slug);
        if ($roleId <= 0) {
            continue;
        }

        if ($roleExisted && $slug !== 'superAdmin') {
            continue;
        }

        $deleteStmt->execute([':role_id' => $roleId]);
        foreach ($role['permissions'] as $permissionKey) {
            $permIdStmt->execute([':permission_key' => $permissionKey]);
            $permissionId = (int) ($permIdStmt->fetchColumn() ?: 0);
            if ($permissionId > 0) {
                $insertPermStmt->execute([':role_id' => $roleId, ':permission_id' => $permissionId]);
            }
        }
    }
}

function assign_user_role(PDO $pdo, int $businessId, int $userId, string $roleSlug): void
{
    seed_business_roles($pdo, $businessId);
    $roleId = role_id_by_slug($pdo, $businessId, $roleSlug);
    if ($roleId <= 0) {
        $roleId = role_id_by_slug($pdo, $businessId, 'viewer');
    }
    if ($roleId <= 0) {
        return;
    }

    $stmt = $pdo->prepare(
        'INSERT INTO tbl_user_roles (user_id, business_id, role_id)
         VALUES (:uid, :bid, :role_id)
         ON DUPLICATE KEY UPDATE role_id = VALUES(role_id)'
    );
    $stmt->execute([':uid' => $userId, ':bid' => $businessId, ':role_id' => $roleId]);

    try {
        $pdo->prepare('UPDATE tbl_users SET role = :role WHERE id = :uid AND business_id = :bid')
            ->execute([':role' => $roleSlug, ':uid' => $userId, ':bid' => $businessId]);
    } catch (Throwable) {
    }
}

function ensure_business_owner_role(PDO $pdo, int $businessId): void
{
    if ($businessId <= 0) {
        return;
    }

    $stmt = $pdo->prepare('SELECT owner_user_id FROM tbl_businesses WHERE id = :bid LIMIT 1');
    $stmt->execute([':bid' => $businessId]);
    $ownerId = (int) ($stmt->fetchColumn() ?: 0);
    if ($ownerId > 0) {
        assign_user_role($pdo, $businessId, $ownerId, 'superAdmin');
    }
}

function ensure_business_rbac(PDO $pdo, int $businessId): void
{
    seed_business_roles($pdo, $businessId);
    ensure_business_owner_role($pdo, $businessId);
}

function user_role_for_business(PDO $pdo, int $businessId, int $userId): string
{
    ensure_business_rbac($pdo, $businessId);

    $ownerStmt = $pdo->prepare('SELECT owner_user_id FROM tbl_businesses WHERE id = :bid LIMIT 1');
    $ownerStmt->execute([':bid' => $businessId]);
    if ((int) ($ownerStmt->fetchColumn() ?: 0) === $userId) {
        return 'superAdmin';
    }

    $rbacStmt = $pdo->prepare(
        'SELECT r.slug
         FROM tbl_user_roles ur
         INNER JOIN tbl_roles r ON r.id = ur.role_id
         WHERE ur.user_id = :uid AND ur.business_id = :bid
         LIMIT 1'
    );
    $rbacStmt->execute([':uid' => $userId, ':bid' => $businessId]);
    $rbacRole = (string) ($rbacStmt->fetchColumn() ?: '');
    if ($rbacRole !== '') {
        return $rbacRole;
    }

    $stmt = $pdo->prepare('SELECT role FROM tbl_users WHERE id = :uid AND business_id = :bid LIMIT 1');
    $stmt->execute([':uid' => $userId, ':bid' => $businessId]);
    $legacy = (string) ($stmt->fetchColumn() ?: 'viewer');
    $mapped = match (strtolower($legacy)) {
        'owner' => 'superAdmin',
        'manager' => 'manager',
        'cashier' => 'cashier',
        'staff' => 'viewer',
        default => $legacy,
    };
    assign_user_role($pdo, $businessId, $userId, $mapped);
    return $mapped;
}

function role_can(PDO|string $pdoOrRole, string $roleOrPermission, ?string $permission = null, ?int $businessId = null): bool
{
    if (!$pdoOrRole instanceof PDO) {
        $role = (string) $pdoOrRole;
        $permissionKey = $roleOrPermission;
        if ($role === 'owner' || $role === 'superAdmin' || $role === 'manager') {
            return true;
        }

        $legacy = rbac_default_roles()[$role]['permissions'] ?? [];
        return in_array($permissionKey, $legacy, true);
    }

    $pdo = $pdoOrRole;
    $role = $roleOrPermission;
    $permissionKey = (string) $permission;

    if ($role === 'superAdmin' || $role === 'owner') {
        return true;
    }

    if (($businessId ?? 0) <= 0) {
        return role_can($role, $permissionKey);
    }

    ensure_business_rbac($pdo, (int) $businessId);
    $stmt = $pdo->prepare(
        'SELECT 1
         FROM tbl_roles r
         INNER JOIN tbl_role_permissions rp ON rp.role_id = r.id
         INNER JOIN tbl_permissions p ON p.id = rp.permission_id
         WHERE r.business_id = :bid AND r.slug = :slug AND p.permission_key = :permission_key
         LIMIT 1'
    );
    $stmt->execute([':bid' => $businessId, ':slug' => $role, ':permission_key' => $permissionKey]);
    return (bool) $stmt->fetchColumn();
}

function user_permissions_for_business(PDO $pdo, int $businessId, int $userId): array
{
    $role = user_role_for_business($pdo, $businessId, $userId);
    if ($role === 'superAdmin' || $role === 'owner') {
        return array_keys(rbac_permission_catalog());
    }

    $stmt = $pdo->prepare(
        'SELECT p.permission_key
         FROM tbl_user_roles ur
         INNER JOIN tbl_role_permissions rp ON rp.role_id = ur.role_id
         INNER JOIN tbl_permissions p ON p.id = rp.permission_id
         WHERE ur.user_id = :uid AND ur.business_id = :bid
         ORDER BY p.permission_key ASC'
    );
    $stmt->execute([':uid' => $userId, ':bid' => $businessId]);
    return array_values(array_unique(array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN))));
}

function rbac_roles_payload(PDO $pdo, int $businessId): array
{
    ensure_business_rbac($pdo, $businessId);
    $stmt = $pdo->prepare(
        'SELECT r.id, r.name, r.slug, r.description, r.is_system, r.is_locked,
                GROUP_CONCAT(p.permission_key ORDER BY p.permission_key SEPARATOR ",") AS permissions
         FROM tbl_roles r
         LEFT JOIN tbl_role_permissions rp ON rp.role_id = r.id
         LEFT JOIN tbl_permissions p ON p.id = rp.permission_id
         WHERE r.business_id = :bid
         GROUP BY r.id
         ORDER BY FIELD(r.slug, "superAdmin", "admin", "manager", "cashier", "stock_keeper", "accountant", "viewer"), r.name'
    );
    $stmt->execute([':bid' => $businessId]);

    return array_map(static function (array $row): array {
        return [
            'id' => (int) $row['id'],
            'name' => (string) $row['name'],
            'slug' => (string) $row['slug'],
            'description' => (string) ($row['description'] ?? ''),
            'is_system' => (int) $row['is_system'],
            'is_locked' => (int) $row['is_locked'],
            'permissions' => $row['permissions'] !== null && $row['permissions'] !== ''
                ? explode(',', (string) $row['permissions'])
                : [],
        ];
    }, $stmt->fetchAll(PDO::FETCH_ASSOC));
}

function require_permission(PDO $pdo, int $businessId, int $userId, string $permission): void
{
    if (!role_can($pdo, user_role_for_business($pdo, $businessId, $userId), $permission, $businessId)) {
        respond(403, ['ok' => false, 'message' => 'You do not have permission to perform this action.']);
    }
}
