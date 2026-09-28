<?php
declare(strict_types=1);

session_start();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if (empty($_SESSION['saas_admin_id'])) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'message' => 'Admin login required.']);
    exit;
}

try {
    $pdo = new PDO('mysql:host=localhost;dbname=zipoo;charset=utf8mb4', 'root', '', [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
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
