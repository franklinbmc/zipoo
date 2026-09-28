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
    $pdo = db();

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

    $userStmt = $pdo->prepare(
        'SELECT id, business_id, full_name, phone, email, business_name
         FROM tbl_users
         WHERE id = :id
         LIMIT 1'
    );
    $userStmt->execute([':id' => $userId]);
    $user = $userStmt->fetch();

    $businessId = $user && $user['business_id'] ? (int) $user['business_id'] : null;
    if ($businessId !== null) {
        $_SESSION['zipoo_business_id'] = $businessId;
    }

    $businesses = [];
    if ($businessId !== null) {
        $businessesStmt = $pdo->prepare(
            'SELECT id, business_name, business_type
             FROM tbl_businesses
             WHERE owner_user_id = :user_id OR id = :business_id
             ORDER BY id ASC'
        );
        $businessesStmt->execute([':user_id' => $userId, ':business_id' => $businessId]);
        $businesses = array_map(static function (array $b): array {
            return [
                'id' => (int) $b['id'],
                'business_name' => $b['business_name'],
                'business_type' => $b['business_type'],
            ];
        }, $businessesStmt->fetchAll());
    }

    respond(200, [
        'ok' => true,
        'message' => 'OTP verified.',
        'user' => $user ? [
            'id' => (int) $user['id'],
            'business_id' => $businessId,
            'business_name' => $user['business_name'] ?? null,
            'full_name' => $user['full_name'],
            'phone' => $user['phone'],
            'email' => $user['email'],
        ] : null,
        'businesses' => $businesses,
    ]);
} catch (Throwable) {
    respond(500, ['ok' => false, 'message' => 'Unable to verify OTP right now.']);
}
