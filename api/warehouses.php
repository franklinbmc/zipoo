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

    ensure_warehouse_stock_tables($pdo);

    // Lazy seed: guarantees a Main Warehouse for this (possibly pre-existing) business.
    $defaultWarehouseId = ensure_default_warehouse($pdo, $businessId);

    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        // Products and their stock held in one warehouse.
        if (isset($_GET['stock_warehouse_id'])) {
            $warehouseId = (int) $_GET['stock_warehouse_id'];
            $wh = $pdo->prepare('SELECT id, name, is_default FROM tbl_warehouses WHERE id = :id AND business_id = :bid LIMIT 1');
            $wh->execute([':id' => $warehouseId, ':bid' => $businessId]);
            $warehouse = $wh->fetch();
            if (!$warehouse) {
                respond(404, ['ok' => false, 'message' => 'Warehouse not found.']);
            }
            $isDefault = (int) $warehouse['is_default'] === 1;
            $q = trim((string) ($_GET['q'] ?? ''));

            $params = [':bid' => $businessId, ':wid' => $warehouseId, ':bid2' => $businessId];
            $where = "i.business_id = :bid3 AND i.type = 'product'";
            $params[':bid3'] = $businessId;
            if ($q !== '') {
                $where .= ' AND (i.name LIKE :q OR i.sku LIKE :q OR i.category LIKE :q)';
                $params[':q'] = '%' . $q . '%';
            }

            $stmt = $pdo->prepare(
                "SELECT i.id, i.name, i.sku, i.category, i.unit, i.current_stock, i.min_stock_alert,
                        COALESCE((SELECT quantity FROM tbl_warehouse_stock ws WHERE ws.business_id = :bid AND ws.warehouse_id = :wid AND ws.item_id = i.id), 0) AS alloc,
                        COALESCE((SELECT SUM(quantity) FROM tbl_warehouse_stock ws2 WHERE ws2.business_id = :bid2 AND ws2.item_id = i.id), 0) AS total_alloc
                 FROM tbl_items i
                 WHERE {$where}
                 ORDER BY i.name ASC"
            );
            $stmt->execute($params);

            $products = array_map(static function ($r) use ($isDefault) {
                $current = (float) $r['current_stock'];
                $alloc = (float) $r['alloc'];
                $totalAlloc = (float) $r['total_alloc'];
                // Default warehouse holds the residual; others hold their explicit allocation.
                $qty = $isDefault ? ($current - $totalAlloc) : $alloc;
                $min = (float) $r['min_stock_alert'];
                return [
                    'id' => (int) $r['id'],
                    'name' => (string) $r['name'],
                    'sku' => (string) ($r['sku'] ?? ''),
                    'category' => (string) ($r['category'] ?? 'General'),
                    'unit' => (string) ($r['unit'] ?? ''),
                    'warehouse_qty' => round($qty, 2),
                    'current_stock' => $current,
                    'min_stock_alert' => $min,
                    'is_low_stock' => $qty > 0 && $qty <= $min,
                ];
            }, $stmt->fetchAll());

            respond(200, [
                'ok' => true,
                'warehouse' => ['id' => (int) $warehouse['id'], 'name' => (string) $warehouse['name'], 'is_default' => $isDefault],
                'products' => $products,
            ]);
        }

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
            // The default warehouse holds each item's residual stock. When the default
            // changes, freeze the outgoing default's residual into explicit rows so its
            // stock stays put, then clear the incoming default's rows (it becomes residual).
            if ($defaultWarehouseId !== $warehouseId) {
                $rows = $pdo->prepare(
                    'SELECT i.id AS item_id,
                            (i.current_stock - COALESCE((SELECT SUM(quantity) FROM tbl_warehouse_stock ws WHERE ws.business_id = :b1 AND ws.item_id = i.id), 0)) AS residual
                     FROM tbl_items i WHERE i.business_id = :b2 AND i.type = "product"'
                );
                $rows->execute([':b1' => $businessId, ':b2' => $businessId]);
                foreach ($rows->fetchAll() as $r) {
                    set_warehouse_allocation($pdo, $businessId, $defaultWarehouseId, (int) $r['item_id'], (float) $r['residual']);
                }
            }
            $pdo->prepare('UPDATE tbl_warehouses SET is_default = 0 WHERE business_id = :bid')->execute([':bid' => $businessId]);
            $pdo->prepare('UPDATE tbl_warehouses SET is_default = 1 WHERE id = :id AND business_id = :bid')
                ->execute([':id' => $warehouseId, ':bid' => $businessId]);
            $pdo->prepare('DELETE FROM tbl_warehouse_stock WHERE business_id = :bid AND warehouse_id = :wid')
                ->execute([':bid' => $businessId, ':wid' => $warehouseId]);
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

        $pdo->beginTransaction();
        try {
            // Removing a warehouse returns its allocated stock to the default (residual) warehouse.
            $pdo->prepare('DELETE FROM tbl_warehouse_stock WHERE business_id = :bid AND warehouse_id = :wid')
                ->execute([':bid' => $businessId, ':wid' => $warehouseId]);
            $pdo->prepare('DELETE FROM tbl_warehouses WHERE id = :id AND business_id = :bid')
                ->execute([':id' => $warehouseId, ':bid' => $businessId]);
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        respond(200, ['ok' => true, 'message' => 'Warehouse deleted.', 'warehouses' => load_warehouses($pdo, $businessId)]);
    }

    if ($action === 'transfer_stock') {
        $fromId = (int) ($_POST['from_warehouse_id'] ?? 0);
        $toId = (int) ($_POST['to_warehouse_id'] ?? 0);
        $itemId = (int) ($_POST['item_id'] ?? 0);
        $qty = round((float) ($_POST['quantity'] ?? 0), 2);
        $notes = trim((string) ($_POST['notes'] ?? ''));

        if ($fromId <= 0 || $toId <= 0 || $fromId === $toId) {
            respond(422, ['ok' => false, 'message' => 'Choose two different warehouses.']);
        }
        if ($qty <= 0) {
            respond(422, ['ok' => false, 'message' => 'Enter a quantity greater than zero.']);
        }

        $whStmt = $pdo->prepare('SELECT id, is_default FROM tbl_warehouses WHERE id = :id AND business_id = :bid LIMIT 1');
        $whStmt->execute([':id' => $fromId, ':bid' => $businessId]);
        $from = $whStmt->fetch();
        $whStmt->execute([':id' => $toId, ':bid' => $businessId]);
        $to = $whStmt->fetch();
        if (!$from || !$to) {
            respond(404, ['ok' => false, 'message' => 'Warehouse not found.']);
        }

        $itStmt = $pdo->prepare('SELECT id, name, current_stock, type FROM tbl_items WHERE id = :id AND business_id = :bid LIMIT 1');
        $itStmt->execute([':id' => $itemId, ':bid' => $businessId]);
        $item = $itStmt->fetch();
        if (!$item || $item['type'] !== 'product') {
            respond(404, ['ok' => false, 'message' => 'Product not found.']);
        }

        $fromDefault = (int) $from['is_default'] === 1;
        $toDefault = (int) $to['is_default'] === 1;

        $allocStmt = $pdo->prepare('SELECT warehouse_id, quantity FROM tbl_warehouse_stock WHERE business_id = :bid AND item_id = :iid');
        $allocStmt->execute([':bid' => $businessId, ':iid' => $itemId]);
        $alloc = [];
        $totalAlloc = 0.0;
        foreach ($allocStmt->fetchAll() as $a) {
            $alloc[(int) $a['warehouse_id']] = (float) $a['quantity'];
            $totalAlloc += (float) $a['quantity'];
        }
        $current = (float) $item['current_stock'];
        $availFrom = $fromDefault ? ($current - $totalAlloc) : ($alloc[$fromId] ?? 0.0);

        if ($qty > $availFrom + 0.00001) {
            $avail = rtrim(rtrim(number_format($availFrom, 2), '0'), '.');
            respond(422, ['ok' => false, 'message' => 'Not enough stock in the source warehouse. Available: ' . $avail . '.']);
        }

        $pdo->beginTransaction();
        try {
            if (!$fromDefault) {
                set_warehouse_allocation($pdo, $businessId, $fromId, $itemId, ($alloc[$fromId] ?? 0.0) - $qty);
            }
            if (!$toDefault) {
                set_warehouse_allocation($pdo, $businessId, $toId, $itemId, ($alloc[$toId] ?? 0.0) + $qty);
            }
            $pdo->prepare(
                'INSERT INTO tbl_stock_transfers (business_id, item_id, from_warehouse_id, to_warehouse_id, quantity, notes, created_by)
                 VALUES (:bid, :iid, :from, :to, :qty, :notes, :by)'
            )->execute([
                ':bid' => $businessId, ':iid' => $itemId, ':from' => $fromId, ':to' => $toId,
                ':qty' => $qty, ':notes' => $notes !== '' ? $notes : null, ':by' => $userId,
            ]);
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        $moved = rtrim(rtrim(number_format($qty, 2), '0'), '.');
        respond(200, ['ok' => true, 'message' => 'Transferred ' . $moved . ' of ' . $item['name'] . '.']);
    }

    respond(422, ['ok' => false, 'message' => 'Unknown warehouse action.']);
} catch (Throwable $error) {
    respond(500, ['ok' => false, 'message' => 'Unable to update warehouses right now.']);
}
