<?php
declare(strict_types=1);

function user_role_for_business(PDO $pdo, int $businessId, int $userId): string
{
    $ownerStmt = $pdo->prepare('SELECT owner_user_id FROM tbl_businesses WHERE id = :bid LIMIT 1');
    $ownerStmt->execute([':bid' => $businessId]);
    if ((int) ($ownerStmt->fetchColumn() ?: 0) === $userId) {
        return 'owner';
    }

    $stmt = $pdo->prepare('SELECT role FROM tbl_users WHERE id = :uid AND business_id = :bid LIMIT 1');
    $stmt->execute([':uid' => $userId, ':bid' => $businessId]);
    return strtolower((string) ($stmt->fetchColumn() ?: 'staff'));
}

function role_can(string $role, string $permission): bool
{
    if ($role === 'owner' || $role === 'manager') {
        return true;
    }

    $cashierPermissions = [
        'sales.create',
        'sales.receive_payment',
        'sales.view',
    ];

    return $role === 'cashier' && in_array($permission, $cashierPermissions, true);
}

function require_permission(PDO $pdo, int $businessId, int $userId, string $permission): void
{
    if (!role_can(user_role_for_business($pdo, $businessId, $userId), $permission)) {
        respond(403, ['ok' => false, 'message' => 'You do not have permission to perform this action.']);
    }
}
