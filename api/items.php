<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/vat_lib.php';

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

function get_active_business_id(PDO $pdo, int $userId): int
{
    $businessId = isset($_SESSION['zipoo_business_id']) ? (int) $_SESSION['zipoo_business_id'] : 0;
    if ($businessId > 0) {
        $stmt = $pdo->prepare('SELECT id FROM tbl_businesses WHERE id = :bid AND (owner_user_id = :uid OR id = :check_bid) LIMIT 1');
        $stmt->execute([':bid' => $businessId, ':uid' => $userId, ':check_bid' => $businessId]);
        if ($stmt->fetchColumn()) {
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
    $firstBid = (int) ($stmt->fetchColumn() ?: 0);
    if ($firstBid > 0) {
        $_SESSION['zipoo_business_id'] = $firstBid;
        return $firstBid;
    }

    return 0;
}

function item_payload(array $row): array
{
    $type = (string) ($row['type'] ?? 'product');
    $currentStock = (float) ($row['current_stock'] ?? 0);
    $minStockAlert = (float) ($row['min_stock_alert'] ?? 5);
    $costPrice = (float) ($row['cost_price'] ?? 0);
    $sellingPrice = (float) ($row['selling_price'] ?? 0);
    $margin = $sellingPrice > 0 ? round((($sellingPrice - $costPrice) / $sellingPrice) * 100, 1) : 0.0;

    return [
        'id' => (int) $row['id'],
        'business_id' => (int) $row['business_id'],
        'type' => $type,
        'name' => (string) $row['name'],
        'sku' => (string) ($row['sku'] ?? ''),
        'barcode' => (string) ($row['barcode'] ?? ''),
        'category' => (string) ($row['category'] ?? 'General'),
        'unit' => (string) ($row['unit'] ?? 'pcs'),
        'cost_price' => $costPrice,
        'selling_price' => $sellingPrice,
        'vat_applicable' => (int) ($row['vat_applicable'] ?? 1),
        'margin_percent' => $margin,
        'current_stock' => $type === 'service' ? 0.0 : $currentStock,
        'min_stock_alert' => $minStockAlert,
        'is_low_stock' => ($type === 'product' && $currentStock <= $minStockAlert),
        'description' => (string) ($row['description'] ?? ''),
        'status' => (string) ($row['status'] ?? 'active'),
        'created_at' => (string) ($row['created_at'] ?? ''),
        'updated_at' => (string) ($row['updated_at'] ?? ''),
    ];
}

try {
    $userId = require_user();
    $pdo = db();
    $businessId = get_active_business_id($pdo, $userId);

    if ($businessId <= 0) {
        respond(400, ['ok' => false, 'message' => 'No active business selected.']);
    }

    ensure_vat_columns($pdo);

    // VAT config for the active business (toggle + rate reuse tbl_businesses).
    $vatStmt = $pdo->prepare('SELECT vat_enabled, tax_rate FROM tbl_businesses WHERE id = :bid LIMIT 1');
    $vatStmt->execute([':bid' => $businessId]);
    $vatRow = $vatStmt->fetch() ?: [];
    $vatConfig = [
        'enabled' => (int) ($vatRow['vat_enabled'] ?? 0) === 1,
        'rate' => (float) ($vatRow['tax_rate'] ?? 0),
    ];

    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $itemId = isset($_GET['id']) ? (int) $_GET['id'] : 0;
        if ($itemId > 0) {
            $stmt = $pdo->prepare('SELECT * FROM tbl_items WHERE id = :id AND business_id = :bid LIMIT 1');
            $stmt->execute([':id' => $itemId, ':bid' => $businessId]);
            $row = $stmt->fetch();
            if (!$row) {
                respond(404, ['ok' => false, 'message' => 'Item not found.']);
            }

            // Get recent stock movements for this item
            $mStmt = $pdo->prepare(
                'SELECT m.*, u.full_name as user_name
                 FROM tbl_stock_movements m
                 LEFT JOIN tbl_users u ON u.id = m.created_by
                 WHERE m.item_id = :item_id AND m.business_id = :bid
                 ORDER BY m.id DESC LIMIT 20'
            );
            $mStmt->execute([':item_id' => $itemId, ':bid' => $businessId]);
            $movements = $mStmt->fetchAll();

            respond(200, [
                'ok' => true,
                'item' => item_payload($row),
                'movements' => $movements,
                'vat' => $vatConfig,
            ]);
        }

        $type = trim((string) ($_GET['type'] ?? ''));
        $category = trim((string) ($_GET['category'] ?? ''));
        $lowStock = trim((string) ($_GET['low_stock'] ?? ''));
        $search = trim((string) ($_GET['q'] ?? ''));

        $conditions = ['business_id = :bid'];
        $params = [':bid' => $businessId];

        if ($type === 'product' || $type === 'service') {
            $conditions[] = 'type = :type';
            $params[':type'] = $type;
        }

        if ($category !== '') {
            $conditions[] = 'category = :category';
            $params[':category'] = $category;
        }

        if ($lowStock === '1' || $lowStock === 'true') {
            $conditions[] = "type = 'product' AND current_stock <= min_stock_alert";
        }

        if ($search !== '') {
            $conditions[] = '(name LIKE :q OR sku LIKE :q OR barcode LIKE :q OR category LIKE :q)';
            $params[':q'] = '%' . $search . '%';
        }

        $whereSql = implode(' AND ', $conditions);
        $stmt = $pdo->prepare("SELECT * FROM tbl_items WHERE {$whereSql} ORDER BY name ASC");
        $stmt->execute($params);
        $rows = $stmt->fetchAll();

        // Get aggregate statistics for badges
        $statsStmt = $pdo->prepare(
            "SELECT 
                COUNT(*) as total_count,
                SUM(CASE WHEN type = 'product' THEN 1 ELSE 0 END) as products_count,
                SUM(CASE WHEN type = 'service' THEN 1 ELSE 0 END) as services_count,
                SUM(CASE WHEN type = 'product' AND current_stock <= min_stock_alert THEN 1 ELSE 0 END) as low_stock_count
             FROM tbl_items
             WHERE business_id = :bid AND status = 'active'"
        );
        $statsStmt->execute([':bid' => $businessId]);
        $stats = $statsStmt->fetch() ?: [];

        // Get list of distinct categories
        $catStmt = $pdo->prepare('SELECT DISTINCT category FROM tbl_items WHERE business_id = :bid AND category != "" ORDER BY category ASC');
        $catStmt->execute([':bid' => $businessId]);
        $categories = $catStmt->fetchAll(PDO::FETCH_COLUMN);

        respond(200, [
            'ok' => true,
            'items' => array_map('item_payload', $rows),
            'stats' => [
                'total' => (int) ($stats['total_count'] ?? 0),
                'products' => (int) ($stats['products_count'] ?? 0),
                'services' => (int) ($stats['services_count'] ?? 0),
                'low_stock' => (int) ($stats['low_stock_count'] ?? 0),
            ],
            'categories' => $categories,
            'vat' => $vatConfig,
        ]);
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $action = trim((string) ($_POST['action'] ?? 'create'));

        if ($action === 'create') {
            $type = trim((string) ($_POST['type'] ?? 'product'));
            if ($type !== 'service') {
                $type = 'product';
            }
            $name = trim((string) ($_POST['name'] ?? ''));
            $sku = trim((string) ($_POST['sku'] ?? ''));
            $barcode = trim((string) ($_POST['barcode'] ?? ''));
            $category = trim((string) ($_POST['category'] ?? 'General'));
            $unit = trim((string) ($_POST['unit'] ?? ($type === 'service' ? 'service' : 'pcs')));
            $costPrice = (float) ($_POST['cost_price'] ?? 0);
            $sellingPrice = (float) ($_POST['selling_price'] ?? 0);
            $vatApplicable = (!isset($_POST['vat_applicable']) || $_POST['vat_applicable'] === '0') ? 0 : 1;
            $initialStock = (float) ($_POST['initial_stock'] ?? 0);
            $minStockAlert = (float) ($_POST['min_stock_alert'] ?? 5);
            $description = trim((string) ($_POST['description'] ?? ''));
            $status = trim((string) ($_POST['status'] ?? 'active'));
            if ($status !== 'inactive') {
                $status = 'active';
            }

            if ($name === '') {
                respond(422, ['ok' => false, 'message' => 'Please enter the item name.']);
            }

            $currentStock = ($type === 'product' && $initialStock > 0) ? $initialStock : 0.0;

            $stmt = $pdo->prepare(
                'INSERT INTO tbl_items
                 (business_id, type, name, sku, barcode, category, unit, cost_price, selling_price, vat_applicable, current_stock, min_stock_alert, description, status)
                 VALUES (:bid, :type, :name, :sku, :barcode, :category, :unit, :cost, :selling, :vat, :stock, :min_alert, :desc, :status)'
            );
            $stmt->execute([
                ':bid' => $businessId,
                ':type' => $type,
                ':name' => $name,
                ':sku' => $sku !== '' ? $sku : null,
                ':barcode' => $barcode !== '' ? $barcode : null,
                ':category' => $category !== '' ? $category : 'General',
                ':unit' => $unit !== '' ? $unit : 'pcs',
                ':cost' => $costPrice,
                ':selling' => $sellingPrice,
                ':vat' => $vatApplicable,
                ':stock' => $currentStock,
                ':min_alert' => $minStockAlert,
                ':desc' => $description !== '' ? $description : null,
                ':status' => $status,
            ]);

            $newItemId = (int) $pdo->lastInsertId();

            // If product and initial stock > 0, record opening stock movement
            if ($type === 'product' && $initialStock > 0) {
                $mStmt = $pdo->prepare(
                    'INSERT INTO tbl_stock_movements
                     (business_id, item_id, movement_type, quantity, previous_stock, new_stock, unit_cost, reference_type, reference_id, notes, created_by)
                     VALUES (:bid, :item_id, "adjustment_add", :qty, 0, :new_stock, :cost, "INITIAL", "INIT", "Initial opening stock", :uid)'
                );
                $mStmt->execute([
                    ':bid' => $businessId,
                    ':item_id' => $newItemId,
                    ':qty' => $initialStock,
                    ':new_stock' => $initialStock,
                    ':cost' => $costPrice,
                    ':uid' => $userId,
                ]);
            }

            $getStmt = $pdo->prepare('SELECT * FROM tbl_items WHERE id = :id LIMIT 1');
            $getStmt->execute([':id' => $newItemId]);
            $created = $getStmt->fetch();

            respond(201, [
                'ok' => true,
                'message' => ucfirst($type) . ' created successfully.',
                'item' => item_payload($created),
            ]);
        }

        if ($action === 'update') {
            $itemId = (int) ($_POST['item_id'] ?? 0);
            if ($itemId <= 0) {
                respond(422, ['ok' => false, 'message' => 'Invalid item ID.']);
            }

            $name = trim((string) ($_POST['name'] ?? ''));
            $sku = trim((string) ($_POST['sku'] ?? ''));
            $barcode = trim((string) ($_POST['barcode'] ?? ''));
            $category = trim((string) ($_POST['category'] ?? 'General'));
            $unit = trim((string) ($_POST['unit'] ?? 'pcs'));
            $costPrice = (float) ($_POST['cost_price'] ?? 0);
            $sellingPrice = (float) ($_POST['selling_price'] ?? 0);
            $vatApplicable = (!isset($_POST['vat_applicable']) || $_POST['vat_applicable'] === '0') ? 0 : 1;
            $minStockAlert = (float) ($_POST['min_stock_alert'] ?? 5);
            $description = trim((string) ($_POST['description'] ?? ''));
            $status = trim((string) ($_POST['status'] ?? 'active'));
            if ($status !== 'inactive') {
                $status = 'active';
            }

            if ($name === '') {
                respond(422, ['ok' => false, 'message' => 'Item name is required.']);
            }

            $check = $pdo->prepare('SELECT id, type FROM tbl_items WHERE id = :id AND business_id = :bid LIMIT 1');
            $check->execute([':id' => $itemId, ':bid' => $businessId]);
            $existing = $check->fetch();
            if (!$existing) {
                respond(404, ['ok' => false, 'message' => 'Item not found in this business.']);
            }

            $stmt = $pdo->prepare(
                'UPDATE tbl_items
                 SET name = :name, sku = :sku, barcode = :barcode, category = :category, unit = :unit,
                     cost_price = :cost, selling_price = :selling, vat_applicable = :vat, min_stock_alert = :min_alert,
                     description = :desc, status = :status
                 WHERE id = :id AND business_id = :bid'
            );
            $stmt->execute([
                ':name' => $name,
                ':sku' => $sku !== '' ? $sku : null,
                ':barcode' => $barcode !== '' ? $barcode : null,
                ':category' => $category !== '' ? $category : 'General',
                ':unit' => $unit !== '' ? $unit : 'pcs',
                ':cost' => $costPrice,
                ':selling' => $sellingPrice,
                ':vat' => $vatApplicable,
                ':min_alert' => $minStockAlert,
                ':desc' => $description !== '' ? $description : null,
                ':status' => $status,
                ':id' => $itemId,
                ':bid' => $businessId,
            ]);

            $getStmt = $pdo->prepare('SELECT * FROM tbl_items WHERE id = :id LIMIT 1');
            $getStmt->execute([':id' => $itemId]);
            $updated = $getStmt->fetch();

            respond(200, [
                'ok' => true,
                'message' => 'Item updated successfully.',
                'item' => item_payload($updated),
            ]);
        }

        if ($action === 'delete') {
            $itemId = (int) ($_POST['item_id'] ?? 0);
            if ($itemId <= 0) {
                respond(422, ['ok' => false, 'message' => 'Invalid item ID.']);
            }

            $check = $pdo->prepare('SELECT id, name FROM tbl_items WHERE id = :id AND business_id = :bid LIMIT 1');
            $check->execute([':id' => $itemId, ':bid' => $businessId]);
            $existing = $check->fetch();
            if (!$existing) {
                respond(404, ['ok' => false, 'message' => 'Item not found in this business.']);
            }

            // Check if item has been referenced in POs
            $poCheck = $pdo->prepare('SELECT id FROM tbl_purchase_order_items WHERE item_id = :id LIMIT 1');
            $poCheck->execute([':id' => $itemId]);
            if ($poCheck->fetch()) {
                // Soft delete by setting status = inactive
                $pdo->prepare('UPDATE tbl_items SET status = "inactive" WHERE id = :id')->execute([':id' => $itemId]);
                respond(200, ['ok' => true, 'message' => 'Item has purchase history; it has been deactivated instead of deleted.']);
            }

            // Delete item and its movements
            $pdo->prepare('DELETE FROM tbl_stock_movements WHERE item_id = :id AND business_id = :bid')->execute([':id' => $itemId, ':bid' => $businessId]);
            $pdo->prepare('DELETE FROM tbl_items WHERE id = :id AND business_id = :bid')->execute([':id' => $itemId, ':bid' => $businessId]);

            respond(200, ['ok' => true, 'message' => 'Item deleted successfully.']);
        }

        if ($action === 'adjust_stock') {
            $itemId = (int) ($_POST['item_id'] ?? 0);
            $adjType = trim((string) ($_POST['adjustment_type'] ?? 'add')); // 'add' or 'subtract'
            $qty = abs((float) ($_POST['quantity'] ?? 0));
            $reason = trim((string) ($_POST['reason'] ?? 'Manual adjustment'));

            if ($itemId <= 0 || $qty <= 0) {
                respond(422, ['ok' => false, 'message' => 'Please provide a valid item and quantity greater than 0.']);
            }

            $check = $pdo->prepare('SELECT id, name, type, current_stock, cost_price FROM tbl_items WHERE id = :id AND business_id = :bid LIMIT 1');
            $check->execute([':id' => $itemId, ':bid' => $businessId]);
            $item = $check->fetch();
            if (!$item) {
                respond(404, ['ok' => false, 'message' => 'Item not found in this business.']);
            }

            if ($item['type'] === 'service') {
                respond(422, ['ok' => false, 'message' => 'Services do not carry physical inventory stock.']);
            }

            $prevStock = (float) $item['current_stock'];
            if ($adjType === 'subtract') {
                $newStock = max(0.0, $prevStock - $qty);
                $deltaQty = -1 * $qty;
                $movementType = 'adjustment_subtract';
            } else {
                $newStock = $prevStock + $qty;
                $deltaQty = $qty;
                $movementType = 'adjustment_add';
            }

            // Update item stock
            $uStmt = $pdo->prepare('UPDATE tbl_items SET current_stock = :stock WHERE id = :id AND business_id = :bid');
            $uStmt->execute([':stock' => $newStock, ':id' => $itemId, ':bid' => $businessId]);

            // Log movement
            $mStmt = $pdo->prepare(
                'INSERT INTO tbl_stock_movements
                 (business_id, item_id, movement_type, quantity, previous_stock, new_stock, unit_cost, reference_type, reference_id, notes, created_by)
                 VALUES (:bid, :item_id, :m_type, :qty, :prev_stock, :new_stock, :cost, "MANUAL", "ADJ", :notes, :uid)'
            );
            $mStmt->execute([
                ':bid' => $businessId,
                ':item_id' => $itemId,
                ':m_type' => $movementType,
                ':qty' => $deltaQty,
                ':prev_stock' => $prevStock,
                ':new_stock' => $newStock,
                ':cost' => (float) $item['cost_price'],
                ':notes' => $reason !== '' ? $reason : 'Manual inventory adjustment',
                ':uid' => $userId,
            ]);

            $getStmt = $pdo->prepare('SELECT * FROM tbl_items WHERE id = :id LIMIT 1');
            $getStmt->execute([':id' => $itemId]);
            $updated = $getStmt->fetch();

            respond(200, [
                'ok' => true,
                'message' => 'Stock adjusted successfully. New stock: ' . number_format($newStock, 2),
                'item' => item_payload($updated),
            ]);
        }

        respond(422, ['ok' => false, 'message' => 'Unknown items action.']);
    }
} catch (Throwable $e) {
    respond(500, ['ok' => false, 'message' => 'Unable to process items: ' . $e->getMessage()]);
}
