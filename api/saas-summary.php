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

try {
    $pdo = db();

    $summary = [
        'totalBusinesses' => (int) $pdo->query('SELECT COUNT(*) FROM tbl_businesses')->fetchColumn(),
        'activeBusinesses' => (int) $pdo->query('SELECT COUNT(*) FROM tbl_businesses WHERE account_status = "active"')->fetchColumn(),
        'inactiveBusinesses' => (int) $pdo->query('SELECT COUNT(*) FROM tbl_businesses WHERE account_status = "inactive"')->fetchColumn(),
        'totalUsers' => (int) $pdo->query('SELECT COUNT(*) FROM tbl_users')->fetchColumn(),
        'newRegistrations' => (int) $pdo->query('SELECT COUNT(*) FROM tbl_businesses WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)')->fetchColumn(),
    ];

    $businesses = $pdo->query(
        'SELECT b.id, b.business_name, b.plan_name, b.account_status, b.created_at, u.full_name AS owner_name, u.phone
         FROM tbl_businesses b
         LEFT JOIN tbl_users u ON u.id = b.owner_user_id
         ORDER BY b.created_at DESC
         LIMIT 8'
    )->fetchAll();

    echo json_encode([
        'ok' => true,
        'adminName' => $_SESSION['saas_admin_name'] ?? 'Admin',
        'summary' => $summary,
        'businesses' => $businesses,
    ]);
} catch (Throwable $error) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'message' => 'Unable to load SaaS dashboard.']);
}
