<?php
declare(strict_types=1);

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
    $pdo = new PDO(
        'mysql:host=localhost;dbname=zipoo;charset=utf8mb4',
        'root',
        '',
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]
    );

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
