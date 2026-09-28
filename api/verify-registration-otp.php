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

$userId = (int) ($_SESSION['zipoo_pending_user_id'] ?? 0);
$otp = preg_replace('/\D+/', '', (string) ($_POST['otp'] ?? ''));

if ($userId <= 0) {
    respond(401, ['ok' => false, 'message' => 'Registration session expired. Please register again.']);
}

if (strlen($otp) !== 6) {
    respond(422, ['ok' => false, 'message' => 'Enter the 6 digit OTP.']);
}

try {
    $pdo = new PDO('mysql:host=localhost;dbname=zipoo;charset=utf8mb4', 'root', '', [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);

    $stmt = $pdo->prepare(
        'SELECT id, otp_hash
         FROM tbl_user_otps
         WHERE user_id = :user_id
           AND verified_at IS NULL
           AND expires_at > NOW()
         ORDER BY id DESC
         LIMIT 1'
    );
    $stmt->execute([':user_id' => $userId]);
    $record = $stmt->fetch();

    if (!$record || !password_verify($otp, $record['otp_hash'])) {
        respond(422, ['ok' => false, 'message' => 'Invalid or expired OTP.']);
    }

    $pdo->prepare('UPDATE tbl_user_otps SET verified_at = NOW() WHERE id = :id')
        ->execute([':id' => $record['id']]);

    $_SESSION['zipoo_user_id'] = $userId;
    unset($_SESSION['zipoo_pending_user_id']);

    respond(200, ['ok' => true, 'message' => 'OTP verified.']);
} catch (Throwable) {
    respond(500, ['ok' => false, 'message' => 'Unable to verify OTP right now.']);
}
