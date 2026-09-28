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
    $sql = 'SELECT u.id, u.full_name, u.phone, u.email, u.created_at, b.business_name, b.account_status
            FROM tbl_users u
            LEFT JOIN tbl_businesses b ON b.id = u.business_id';
    $params = [];
    if ($query !== '') {
        $sql .= ' WHERE u.full_name LIKE :q OR u.phone LIKE :q OR u.email LIKE :q OR b.business_name LIKE :q';
        $params[':q'] = '%' . $query . '%';
    }
    $sql .= ' ORDER BY u.created_at DESC LIMIT 100';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    echo json_encode(['ok' => true, 'users' => $stmt->fetchAll()]);
} catch (Throwable $error) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'message' => 'Unable to load users.']);
}
