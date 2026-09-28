<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';

session_start();
require_once dirname(__DIR__) . '/vendor/autoload.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception as MailException;

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function respond(int $status, array $payload): void
{
    http_response_code($status);
    echo json_encode($payload);
    exit;
}

function save_settings(PDO $pdo, string $group, array $settings): void
{
    $stmt = $pdo->prepare(
        'INSERT INTO tbl_saas_settings (setting_group, setting_key, setting_value)
         VALUES (:setting_group, :setting_key, :setting_value)
         ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)'
    );

    foreach ($settings as $key => $value) {
        $stmt->execute([
            ':setting_group' => $group,
            ':setting_key' => $key,
            ':setting_value' => $value,
        ]);
    }
}

function upload_asset(string $field): ?string
{
    if (empty($_FILES[$field]) || ($_FILES[$field]['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return null;
    }

    if ($_FILES[$field]['error'] !== UPLOAD_ERR_OK) {
        respond(422, ['ok' => false, 'message' => 'Upload failed.']);
    }

    $allowed = [
        'image/png' => 'png',
        'image/jpeg' => 'jpg',
        'image/svg+xml' => 'svg',
        'image/webp' => 'webp',
    ];
    $mime = mime_content_type($_FILES[$field]['tmp_name']);
    if (!isset($allowed[$mime])) {
        respond(422, ['ok' => false, 'message' => 'Only PNG, JPG, SVG, and WEBP files are allowed.']);
    }

    $directory = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'platform';
    if (!is_dir($directory)) {
        mkdir($directory, 0775, true);
    }

    $filename = $field . '-' . date('YmdHis') . '-' . bin2hex(random_bytes(4)) . '.' . $allowed[$mime];
    $target = $directory . DIRECTORY_SEPARATOR . $filename;
    if (!move_uploaded_file($_FILES[$field]['tmp_name'], $target)) {
        respond(500, ['ok' => false, 'message' => 'Unable to save uploaded file.']);
    }

    return '/uploads/platform/' . $filename;
}

function update_manifest(array $settings): void
{
    $manifestPath = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'manifest.json';
    if (!is_file($manifestPath)) {
        return;
    }

    $manifest = json_decode((string) file_get_contents($manifestPath), true);
    if (!is_array($manifest)) {
        return;
    }

    if (!empty($settings['platform_name'])) {
        $manifest['name'] = $settings['platform_name'];
        $manifest['short_name'] = $settings['platform_name'];
    }

    if (!empty($settings['platform_icon'])) {
        $icon = $settings['platform_icon'];
        $type = str_ends_with($icon, '.svg') ? 'image/svg+xml'
            : (str_ends_with($icon, '.webp') ? 'image/webp'
            : (str_ends_with($icon, '.png') ? 'image/png' : 'image/jpeg'));
        $manifest['icons'] = [
            [
                'src' => $icon,
                'sizes' => 'any',
                'type' => $type,
                'purpose' => 'any maskable',
            ],
        ];
    }

    file_put_contents($manifestPath, json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL);
}

function load_group_settings(PDO $pdo, string $group): array
{
    $stmt = $pdo->prepare('SELECT setting_key, setting_value FROM tbl_saas_settings WHERE setting_group = :setting_group');
    $stmt->execute([':setting_group' => $group]);
    $settings = [];
    foreach ($stmt->fetchAll() as $row) {
        $settings[$row['setting_key']] = $row['setting_value'];
    }
    return $settings;
}

function test_sms(PDO $pdo): void
{
    $settings = load_group_settings($pdo, 'sms');
    $phone = trim((string) ($_POST['test_phone'] ?? ''));
    if ($phone === '') {
        respond(422, ['ok' => false, 'message' => 'Enter a test phone number.']);
    }
    if (empty($settings['api_url']) || empty($settings['api_key'])) {
        respond(422, ['ok' => false, 'message' => 'Save SMS API URL and API key first.']);
    }

    $message = trim((string) ($_POST['test_message'] ?? ''));
    if ($message === '') {
        $message = 'Zipoo test SMS setup message.';
    }
    $baseUrl = rtrim($settings['api_url'], '/');
    $url = $baseUrl . '/messages';
    $content = json_encode([
        'to' => $phone,
        'sender_id' => $settings['sender_id'] ?? 'MEGASMS',
        'message' => $message,
    ]);
    $headers = [
        'Authorization: Bearer ' . $settings['api_key'],
        'Content-Type: application/json',
        'Accept: application/json',
    ];
    $context = stream_context_create([
        'http' => [
            'method' => 'POST',
            'header' => implode("\r\n", $headers),
            'content' => $content,
            'timeout' => 20,
            'ignore_errors' => true,
        ],
    ]);
    $result = @file_get_contents($url, false, $context);
    $statusLine = $http_response_header[0] ?? '';
    if ($result === false) {
        respond(500, ['ok' => false, 'message' => 'Unable to reach SMS provider.', 'provider_response' => $statusLine]);
    }

    $success = preg_match('/HTTP\/\S+\s+2\d\d/', $statusLine) === 1;
    respond($success ? 200 : 502, [
        'ok' => $success,
        'message' => $success ? 'Test SMS request sent.' : 'SMS provider returned an error.',
        'provider_response' => trim($statusLine . ' ' . mb_substr((string) $result, 0, 240)),
    ]);
}

function test_smtp(PDO $pdo): void
{
    $settings = load_group_settings($pdo, 'smtp');
    $email = trim((string) ($_POST['test_email'] ?? ''));
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        respond(422, ['ok' => false, 'message' => 'Enter a valid test email.']);
    }
    if (empty($settings['smtp_host']) || empty($settings['smtp_port']) || empty($settings['from_email'])) {
        respond(422, ['ok' => false, 'message' => 'Save SMTP host, port, and from email first.']);
    }

    $mail = new PHPMailer(true);
    try {
        $mail->isSMTP();
        $mail->Host = $settings['smtp_host'];
        $mail->Port = (int) $settings['smtp_port'];
        $mail->CharSet = 'UTF-8';
        $mail->Timeout = 20;
        $encryption = $settings['smtp_encryption'] ?? 'tls';
        if ($encryption === 'ssl') {
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
        } elseif ($encryption === 'tls') {
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        } else {
            $mail->SMTPSecure = false;
            $mail->SMTPAutoTLS = false;
        }
        if (!empty($settings['smtp_username'])) {
            $mail->SMTPAuth = true;
            $mail->Username = $settings['smtp_username'];
            $mail->Password = $settings['smtp_password'] ?? '';
        }
        $mail->setFrom($settings['from_email'], 'Zipoo');
        $mail->addAddress($email);
        $mail->Subject = 'Zipoo SMTP test';
        $mail->Body = "Your Zipoo SMTP setup can send email.\n\nSent by PHPMailer.";
        $mail->send();
    } catch (MailException $error) {
        respond(500, ['ok' => false, 'message' => 'SMTP test failed: ' . ($mail->ErrorInfo ?: $error->getMessage())]);
    }

    respond(200, ['ok' => true, 'message' => 'Test email sent.']);
}

if (empty($_SESSION['saas_admin_id'])) {
    respond(401, ['ok' => false, 'message' => 'Admin login required.']);
}

try {
    $pdo = db();

    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $rows = $pdo->query('SELECT setting_group, setting_key, setting_value FROM tbl_saas_settings')->fetchAll();
        $settings = [];
        foreach ($rows as $row) {
            $settings[$row['setting_group']][$row['setting_key']] = $row['setting_value'];
        }
        respond(200, ['ok' => true, 'settings' => $settings]);
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        respond(405, ['ok' => false, 'message' => 'Method not allowed.']);
    }

    $action = trim((string) ($_POST['action'] ?? ''));
    if ($action === 'test_sms') {
        test_sms($pdo);
    }
    if ($action === 'test_smtp') {
        test_smtp($pdo);
    }

    $group = trim((string) ($_POST['group'] ?? ''));
    if (!in_array($group, ['sms', 'smtp', 'platform'], true)) {
        respond(422, ['ok' => false, 'message' => 'Invalid settings group.']);
    }

    if ($group === 'sms') {
        save_settings($pdo, 'sms', [
            'provider_name' => trim((string) ($_POST['provider_name'] ?? '')),
            'sender_id' => trim((string) ($_POST['sender_id'] ?? '')),
            'api_url' => trim((string) ($_POST['api_url'] ?? '')),
            'api_key' => (string) ($_POST['api_key'] ?? ''),
        ]);
    }

    if ($group === 'smtp') {
        save_settings($pdo, 'smtp', [
            'smtp_host' => trim((string) ($_POST['smtp_host'] ?? '')),
            'smtp_port' => trim((string) ($_POST['smtp_port'] ?? '')),
            'smtp_username' => trim((string) ($_POST['smtp_username'] ?? '')),
            'smtp_password' => (string) ($_POST['smtp_password'] ?? ''),
            'from_email' => trim((string) ($_POST['from_email'] ?? '')),
            'smtp_encryption' => trim((string) ($_POST['smtp_encryption'] ?? 'tls')),
        ]);
    }

    if ($group === 'platform') {
        $settings = [
            'platform_name' => trim((string) ($_POST['platform_name'] ?? 'Zipoo')),
            'version' => trim((string) ($_POST['version'] ?? '1.0.0')),
            'support_email' => trim((string) ($_POST['support_email'] ?? '')),
            'default_timezone' => trim((string) ($_POST['default_timezone'] ?? 'Africa/Dar_es_Salaam')),
        ];

        $logo = upload_asset('platform_logo');
        $icon = upload_asset('platform_icon');
        if ($logo) {
            $settings['platform_logo'] = $logo;
        }
        if ($icon) {
            $settings['platform_icon'] = $icon;
        }

        save_settings($pdo, 'platform', $settings);
        update_manifest(array_merge(load_group_settings($pdo, 'platform'), $settings));
    }

    respond(200, ['ok' => true, 'message' => 'Settings saved.']);
} catch (Throwable $error) {
    respond(500, ['ok' => false, 'message' => 'Unable to save settings right now.']);
}
