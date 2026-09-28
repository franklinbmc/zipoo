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

function input(string $key): string
{
    return trim((string) ($_POST[$key] ?? ''));
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond(405, ['ok' => false, 'message' => 'Method not allowed.']);
}

$login = input('login');
$password = (string) ($_POST['password'] ?? '');

if ($login === '' || $password === '') {
    respond(422, ['ok' => false, 'message' => 'Phone/email and password are required.']);
}

try {
    $pdo = new PDO(
        'mysql:host=localhost;dbname=zipoo;charset=utf8mb4',
        'root',
        '',
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]
    );

    $normalizedLogin = strtolower($login);
    $stmt = $pdo->prepare(
        'SELECT id, business_id, full_name, phone, email, password_hash
         FROM tbl_users
         WHERE phone = :login OR LOWER(email) = :login
         LIMIT 1'
    );
    $stmt->execute([':login' => $normalizedLogin]);
    $user = $stmt->fetch();

    if (!$user || !password_verify($password, $user['password_hash'])) {
        respond(401, ['ok' => false, 'message' => 'Invalid phone/email or password.']);
    }

    session_regenerate_id(true);
    $_SESSION['zipoo_user_id'] = (int) $user['id'];
    $_SESSION['zipoo_business_id'] = $user['business_id'] !== null ? (int) $user['business_id'] : null;

    respond(200, [
        'ok' => true,
        'message' => 'Login successful.',
        'user' => [
            'id' => (int) $user['id'],
            'business_id' => $user['business_id'] !== null ? (int) $user['business_id'] : null,
            'full_name' => $user['full_name'],
            'phone' => $user['phone'],
            'email' => $user['email'],
        ],
    ]);
} catch (Throwable $error) {
    respond(500, [
        'ok' => false,
        'message' => 'Unable to login right now.',
    ]);
}
