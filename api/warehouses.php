<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/warehouses_lib.php';

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
    $businessId = isset($_SESSION['zipoo_business_id']) ? (int) $_SESSION['zipoo_business_id'] : 0;
    if ($businessId > 0) {
        $stmt = $pdo->prepare('SELECT id FROM tbl_businesses WHERE id = :bid AND (owner_user_id = :uid OR id = :bid2) LIMIT 1');
        $stmt->execute([':bid' => $businessId, ':uid' => $userId, ':bid2' => $businessId]);
        if ((int) ($stmt->fetchColumn() ?: 0) > 0) {
            return $businessId;
        }
    }

    $stmt = $pdo->prepare('SELECT business_id FROM tbl_users WHERE id = :uid LIMIT 1');
    $stmt->execute([':uid' => $userId]);
    $userBid = (int) ($stmt->fetchColumn() ?: 0);
    if ($userBid > 0) {
        $_SESSION['zipoo_business_id'] = $userBid;
        return $userBid;
    }

    $stmt = $pdo->prepare('SELECT id FROM tbl_businesses WHERE owner_user_id = :uid ORDER BY id ASC LIMIT 1');
    $stmt->execute([':uid' => $userId]);
    $first = (int) ($stmt->fetchColumn() ?: 0);
    if ($first > 0) {
        $_SESSION['zipoo_business_id'] = $first;
    }
    return $first;
}

function warehouse_payload(array $row): array
{
    return [
        'id' => (int) $row['id'],
        'name' => (string) $row['name'],
        'code' => (string) ($row['code'] ?? ''),
        'location' => (string) ($row['location'] ?? ''),
        'is_default' => (int) ($row['is_default'] ?? 0) === 1,
        'status' => (string) ($row['status'] ?? 'active'),
        'created_at' => (string) ($row['created_at'] ?? ''),
    ];
}

function load_warehouses(PDO $pdo, int $businessId): array
{
    $stmt = $pdo->prepare('SELECT * FROM tbl_warehouses WHERE business_id = :bid ORDER BY is_default DESC, name ASC');
    $stmt->execute([':bid' => $businessId]);
    return array_map('warehouse_payload', $stmt->fetchAll());
}

try {
    $pdo = db();
    $userId = require_user();
    ensure_warehouses_table($pdo);

    $businessId = active_business_id($pdo, $userId);
    if ($businessId <= 0) {
        respond(422, ['ok' => false, 'message' => 'No active business selected.']);
    }

    // Lazy seed: guarantees a Main Warehouse for this (possibly pre-existing) business.
    ensure_default_warehouse($pdo, $businessId);

    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        respond(200, ['ok' => true, 'warehouses' => load_warehouses($pdo, $businessId)]);
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        respond(405, ['ok' => false, 'message' => 'Method not allowed.']);
    }

    $action = trim((string) ($_POST['action'] ?? ''));

    if ($action === 'create' || $action === 'update') {
        $name = trim((string) ($_POST['name'] ?? ''));
        $code = trim((string) ($_POST['code'] ?? ''));
        $location = trim((string) ($_POST['location'] ?? ''));
        $makeDefault = (int) ($_POST['is_default'] ?? 0) === 1;

        if ($name === '') {
            respond(422, ['ok' => false, 'message' => 'Warehouse name is required.']);
        }

        $pdo->beginTransaction();
        try {
            if ($action === 'update') {
                $warehouseId = (int) ($_POST['warehouse_id'] ?? 0);
                $chk = $pdo->prepare('SELECT id FROM tbl_warehouses WHERE id = :id AND business_id = :bid LIMIT 1');
                $chk->execute([':id' => $warehouseId, ':bid' => $businessId]);
                if (!$chk->fetch()) {
                    respond(404, ['ok' => false, 'message' => 'Warehouse not found.']);
                }
                $pdo->prepare(
                    'UPDATE tbl_warehouses SET name = :name, code = :code, location = :loc WHERE id = :id AND business_id = :bid'
                )->execute([
                    ':name' => $name,
                    ':code' => $code !== '' ? $code : null,
                    ':loc' => $location !== '' ? $location : null,
                    ':id' => $warehouseId,
                    ':bid' => $businessId,
                ]);
            } else {
                $ins = $pdo->prepare(
                    'INSERT INTO tbl_warehouses (business_id, name, code, location, is_default, status)
                     VALUES (:bid, :name, :code, :loc, 0, "active")'
                );
                $ins->execute([
                    ':bid' => $businessId,
                    ':name' => $name,
                    ':code' => $code !== '' ? $code : null,
                    ':loc' => $location !== '' ? $location : null,
                ]);
                $warehouseId = (int) $pdo->lastInsertId();
            }

            if ($makeDefault) {
                $pdo->prepare('UPDATE tbl_warehouses SET is_default = 0 WHERE business_id = :bid')->execute([':bid' => $businessId]);
                $pdo->prepare('UPDATE tbl_warehouses SET is_default = 1 WHERE id = :id AND business_id = :bid')
                    ->execute([':id' => $warehouseId, ':bid' => $businessId]);
            }

            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        respond($action === 'create' ? 201 : 200, [
            'ok' => true,
            'message' => $action === 'create' ? 'Warehouse created.' : 'Warehouse updated.',
            'warehouses' => load_warehouses($pdo, $businessId),
        ]);
    }

    if ($action === 'set_default') {
        $warehouseId = (int) ($_POST['warehouse_id'] ?? 0);
        $chk = $pdo->prepare('SELECT id FROM tbl_warehouses WHERE id = :id AND business_id = :bid LIMIT 1');
        $chk->execute([':id' => $warehouseId, ':bid' => $businessId]);
        if (!$chk->fetch()) {
            respond(404, ['ok' => false, 'message' => 'Warehouse not found.']);
        }
        $pdo->beginTransaction();
        try {
            $pdo->prepare('UPDATE tbl_warehouses SET is_default = 0 WHERE business_id = :bid')->execute([':bid' => $businessId]);
            $pdo->prepare('UPDATE tbl_warehouses SET is_default = 1 WHERE id = :id AND business_id = :bid')
                ->execute([':id' => $warehouseId, ':bid' => $businessId]);
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
        respond(200, ['ok' => true, 'message' => 'Default warehouse updated.', 'warehouses' => load_warehouses($pdo, $businessId)]);
    }

    if ($action === 'delete') {
        $warehouseId = (int) ($_POST['warehouse_id'] ?? 0);
        $chk = $pdo->prepare('SELECT id, is_default FROM tbl_warehouses WHERE id = :id AND business_id = :bid LIMIT 1');
        $chk->execute([':id' => $warehouseId, ':bid' => $businessId]);
        $row = $chk->fetch();
        if (!$row) {
            respond(404, ['ok' => false, 'message' => 'Warehouse not found.']);
        }
        if ((int) $row['is_default'] === 1) {
            respond(422, ['ok' => false, 'message' => 'Set another warehouse as default before deleting this one.']);
        }

        $countStmt = $pdo->prepare('SELECT COUNT(*) FROM tbl_warehouses WHERE business_id = :bid');
        $countStmt->execute([':bid' => $businessId]);
        if ((int) $countStmt->fetchColumn() <= 1) {
            respond(422, ['ok' => false, 'message' => 'A business must have at least one warehouse.']);
        }

        $pdo->prepare('DELETE FROM tbl_warehouses WHERE id = :id AND business_id = :bid')
            ->execute([':id' => $warehouseId, ':bid' => $businessId]);

        respond(200, ['ok' => true, 'message' => 'Warehouse deleted.', 'warehouses' => load_warehouses($pdo, $businessId)]);
    }

    respond(422, ['ok' => false, 'message' => 'Unknown warehouse action.']);
} catch (Throwable $error) {
    respond(500, ['ok' => false, 'message' => 'Unable to update warehouses right now.']);
}
