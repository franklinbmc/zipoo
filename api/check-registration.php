<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function respond(int $status, array $payload): void
{
    http_response_code($status);
    echo json_encode($payload);
    exit;
}

$phone = trim((string) ($_GET['phone'] ?? ''));
$email = strtolower(trim((string) ($_GET['email'] ?? '')));

if ($phone === '' && $email === '') {
    respond(422, ['ok' => false, 'message' => 'Nothing to check.']);
}

try {
    $pdo = db();

    $checks = [];
    if ($phone !== '') {
        $phoneCheck = $pdo->prepare('SELECT COUNT(*) FROM tbl_users WHERE phone = :phone');
        $phoneCheck->execute([':phone' => $phone]);
        $checks['phoneExists'] = (int) $phoneCheck->fetchColumn() > 0;
    }

    if ($email !== '') {
        $emailCheck = $pdo->prepare('SELECT COUNT(*) FROM tbl_users WHERE email = :email');
        $emailCheck->execute([':email' => $email]);
        $checks['emailExists'] = (int) $emailCheck->fetchColumn() > 0;
    }

    respond(200, ['ok' => true] + $checks);
} catch (Throwable $error) {
    respond(500, ['ok' => false, 'message' => 'Unable to check account details.']);
}
