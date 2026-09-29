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

try {
    $userId = require_user();
    $pdo = db();
    $businessId = get_active_business_id($pdo, $userId);

    if ($businessId <= 0) {
        respond(400, ['ok' => false, 'message' => 'No active business selected.']);
    }

    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $itemId = isset($_GET['item_id']) ? (int) $_GET['item_id'] : 0;
        $mType = trim((string) ($_GET['movement_type'] ?? ''));
        $search = trim((string) ($_GET['q'] ?? ''));

        $conditions = ['m.business_id = :bid'];
        $params = [':bid' => $businessId];

        if ($itemId > 0) {
            $conditions[] = 'm.item_id = :item_id';
            $params[':item_id'] = $itemId;
        }

        if ($mType !== '' && $mType !== 'all') {
            $conditions[] = 'm.movement_type = :m_type';
            $params[':m_type'] = $mType;
        }

        if ($search !== '') {
            $conditions[] = '(i.name LIKE :q OR m.reference_id LIKE :q OR m.notes LIKE :q)';
            $params[':q'] = '%' . $search . '%';
        }

        $whereSql = implode(' AND ', $conditions);
        $stmt = $pdo->prepare(
            "SELECT m.*, i.name as item_name, i.sku as item_sku, i.unit as item_unit, i.type as item_type,
                    u.full_name as user_name
             FROM tbl_stock_movements m
             LEFT JOIN tbl_items i ON i.id = m.item_id
             LEFT JOIN tbl_users u ON u.id = m.created_by
             WHERE {$whereSql}
             ORDER BY m.id DESC
             LIMIT 100"
        );
        $stmt->execute($params);
        $rows = $stmt->fetchAll();

        $statsStmt = $pdo->prepare(
            'SELECT 
                COUNT(*) as total_movements,
                SUM(CASE WHEN DATE(created_at) = CURDATE() THEN 1 ELSE 0 END) as today_movements
             FROM tbl_stock_movements
             WHERE business_id = :bid'
        );
        $statsStmt->execute([':bid' => $businessId]);
        $stats = $statsStmt->fetch() ?: [];

        respond(200, [
            'ok' => true,
            'movements' => $rows,
            'stats' => [
                'total_movements' => (int) ($stats['total_movements'] ?? 0),
                'today_movements' => (int) ($stats['today_movements'] ?? 0),
            ],
        ]);
    }

    respond(405, ['ok' => false, 'message' => 'Method not allowed.']);
} catch (Throwable $e) {
    respond(500, ['ok' => false, 'message' => 'Unable to load stock movements: ' . $e->getMessage()]);
}
