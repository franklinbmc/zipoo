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
    $pdo = db();

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

    $businessesStmt = $pdo->prepare(
        'SELECT id, business_name, business_type
         FROM tbl_businesses
         WHERE owner_user_id = :user_id OR id = :business_id
         ORDER BY id ASC'
    );
    $businessesStmt->execute([
        ':user_id' => (int) $user['id'],
        ':business_id' => $user['business_id'] !== null ? (int) $user['business_id'] : 0,
    ]);
    $businesses = array_map(static function (array $business): array {
        return [
            'id' => (int) $business['id'],
            'business_name' => $business['business_name'],
            'business_type' => $business['business_type'],
        ];
    }, $businessesStmt->fetchAll());

    $currentBusiness = null;
    foreach ($businesses as $business) {
        if ($user['business_id'] !== null && (int) $business['id'] === (int) $user['business_id']) {
            $currentBusiness = $business;
            break;
        }
    }
    $currentBusiness ??= $businesses[0] ?? null;

    session_regenerate_id(true);
    $_SESSION['zipoo_user_id'] = (int) $user['id'];
    $_SESSION['zipoo_business_id'] = $currentBusiness !== null ? (int) $currentBusiness['id'] : null;

    respond(200, [
        'ok' => true,
        'message' => 'Login successful.',
        'user' => [
            'id' => (int) $user['id'],
            'business_id' => $currentBusiness !== null ? (int) $currentBusiness['id'] : null,
            'business_name' => $currentBusiness['business_name'] ?? null,
            'full_name' => $user['full_name'],
            'phone' => $user['phone'],
            'email' => $user['email'],
        ],
        'businesses' => $businesses,
    ]);
} catch (Throwable $error) {
    respond(500, [
        'ok' => false,
        'message' => 'Unable to login right now.',
    ]);
}
