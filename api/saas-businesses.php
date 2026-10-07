<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';

session_start();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if (empty($_SESSION['saas_admin_id'])) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'message' => 'Admin login required.']);
    exit;
}

function respond(int $status, array $payload): void
{
    http_response_code($status);
    echo json_encode($payload);
    exit;
}

function business_snapshot(PDO $pdo, int $businessId): array
{
    $stmt = $pdo->prepare(
        'SELECT b.id, b.business_name, b.business_type, b.plan_name, b.account_status, b.created_at,
                u.full_name AS owner_name, u.phone, u.email, l.region_name, l.district_name
         FROM tbl_businesses b
         LEFT JOIN tbl_users u ON u.id = b.owner_user_id
         LEFT JOIN tbl_tanzania_locations l ON l.region_code = b.region_code AND l.district_code = b.district_code
         WHERE b.id = :id
         LIMIT 1'
    );
    $stmt->execute([':id' => $businessId]);
    $business = $stmt->fetch();
    if (!$business) {
        respond(404, ['ok' => false, 'message' => 'Business not found.']);
    }

    $count = static function (string $table, PDO $pdo, int $id): int {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM {$table} WHERE business_id = :id");
        $stmt->execute([':id' => $id]);
        return (int) $stmt->fetchColumn();
    };

    $sales = $pdo->prepare('SELECT COALESCE(SUM(total_amount), 0) FROM tbl_sales WHERE business_id = :id');
    $sales->execute([':id' => $businessId]);

    return [
        'business' => $business,
        'stats' => [
            'users' => $count('tbl_users', $pdo, $businessId),
            'items' => $count('tbl_items', $pdo, $businessId),
            'sales' => (float) $sales->fetchColumn(),
        ],
    ];
}

try {
    $pdo = db();

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $action = trim((string) ($_POST['action'] ?? ''));
        $businessId = (int) ($_POST['business_id'] ?? 0);
        if ($businessId <= 0) {
            respond(422, ['ok' => false, 'message' => 'Select a valid business.']);
        }

        if ($action === 'update_business') {
            $plan = trim((string) ($_POST['plan_name'] ?? 'Starter'));
            $status = trim((string) ($_POST['account_status'] ?? 'active'));
            if ($plan === '') {
                respond(422, ['ok' => false, 'message' => 'Plan name is required.']);
            }
            if (!in_array($status, ['active', 'inactive'], true)) {
                respond(422, ['ok' => false, 'message' => 'Invalid account status.']);
            }

            $stmt = $pdo->prepare('UPDATE tbl_businesses SET plan_name = :plan, account_status = :status WHERE id = :id');
            $stmt->execute([':plan' => $plan, ':status' => $status, ':id' => $businessId]);
            respond(200, ['ok' => true, 'message' => 'Business updated.', 'snapshot' => business_snapshot($pdo, $businessId)]);
        }

        respond(422, ['ok' => false, 'message' => 'Unsupported business action.']);
    }

    if (isset($_GET['id'])) {
        respond(200, ['ok' => true] + business_snapshot($pdo, (int) $_GET['id']));
    }

    $query = trim((string) ($_GET['q'] ?? ''));
    $sql = 'SELECT b.id, b.business_name, b.business_type, b.plan_name, b.account_status, b.created_at,
                   u.full_name AS owner_name, u.phone, u.email, l.region_name, l.district_name
            FROM tbl_businesses b
            LEFT JOIN tbl_users u ON u.id = b.owner_user_id
            LEFT JOIN tbl_tanzania_locations l ON l.region_code = b.region_code AND l.district_code = b.district_code';
    $params = [];
    if ($query !== '') {
        $sql .= ' WHERE b.business_name LIKE :q OR u.full_name LIKE :q OR u.phone LIKE :q OR u.email LIKE :q';
        $params[':q'] = '%' . $query . '%';
    }
    $sql .= ' ORDER BY b.created_at DESC LIMIT 100';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    echo json_encode(['ok' => true, 'businesses' => $stmt->fetchAll()]);
} catch (Throwable $error) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'message' => 'Unable to load businesses.']);
}
