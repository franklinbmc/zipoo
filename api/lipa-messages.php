<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/billing_lib.php';

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

try {
    $pdo = db();
    ensure_billing_tables($pdo);

    $auth = (string) ($_SERVER['HTTP_AUTHORIZATION'] ?? '');
    $token = preg_match('/Bearer\s+(.+)/i', $auth, $m) ? trim($m[1]) : trim((string) ($_POST['token'] ?? ''));
    $device = null;
    if ($token !== '') {
        $hash = hash('sha256', $token);
        $stmt = $pdo->prepare('SELECT * FROM tbl_lipa_devices WHERE token_hash = :hash AND status = "active" LIMIT 1');
        $stmt->execute([':hash' => $hash]);
        $device = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }
    if (!$device) {
        respond(401, ['ok' => false, 'message' => 'Invalid Lipa device token.']);
    }

    $payload = json_decode((string) file_get_contents('php://input'), true);
    if (!is_array($payload)) {
        $payload = $_POST;
    }
    $sender = trim((string) ($payload['sender'] ?? ''));
    $body = trim((string) ($payload['body'] ?? $payload['message'] ?? ''));
    $receivedAt = trim((string) ($payload['received_at'] ?? '')) ?: null;
    if ($sender === '' || $body === '') {
        respond(422, ['ok' => false, 'message' => 'Sender and message body are required.']);
    }

    $result = lipa_ingest_message($pdo, (int) $device['id'], $sender, $body, $receivedAt);
    $pdo->prepare('UPDATE tbl_lipa_devices SET last_seen_at = NOW(), last_ip = :ip WHERE id = :id')
        ->execute([':ip' => $_SERVER['REMOTE_ADDR'] ?? null, ':id' => (int) $device['id']]);

    respond(200, ['ok' => true] + $result);
} catch (Throwable $e) {
    respond(500, ['ok' => false, 'message' => $e->getMessage()]);
}
