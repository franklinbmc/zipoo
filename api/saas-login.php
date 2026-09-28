<?php
declare(strict_types=1);

session_start();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function respond(int $status, array $payload): void
{
    http_response_code($status);
    echo json_encode($payload);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond(405, ['ok' => false, 'message' => 'Method not allowed.']);
}

$email = strtolower(trim((string) ($_POST['email'] ?? '')));
$password = (string) ($_POST['password'] ?? '');

if ($email === '' || $password === '') {
    respond(422, ['ok' => false, 'message' => 'Email and password are required.']);
}

try {
    $pdo = new PDO('mysql:host=localhost;dbname=zipoo;charset=utf8mb4', 'root', '', [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $stmt = $pdo->prepare('SELECT id, full_name, email, password_hash FROM tbl_saas_admins WHERE email = :email AND is_active = 1 LIMIT 1');
    $stmt->execute([':email' => $email]);
    $admin = $stmt->fetch();

    if (!$admin || !password_verify($password, $admin['password_hash'])) {
        respond(401, ['ok' => false, 'message' => 'Invalid admin credentials.']);
    }

    $_SESSION['saas_admin_id'] = (int) $admin['id'];
    $_SESSION['saas_admin_name'] = $admin['full_name'];
    $pdo->prepare('UPDATE tbl_saas_admins SET last_login_at = NOW() WHERE id = :id')->execute([':id' => $admin['id']]);

    respond(200, ['ok' => true, 'name' => $admin['full_name']]);
} catch (Throwable $error) {
    respond(500, ['ok' => false, 'message' => 'Unable to login right now.']);
}
