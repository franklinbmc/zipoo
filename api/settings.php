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

function require_user(): int
{
    $userId = (int) ($_SESSION['zipoo_user_id'] ?? 0);
    if ($userId <= 0) {
        respond(401, ['ok' => false, 'message' => 'Please login first.']);
    }

    return $userId;
}

function get_active_business_id(PDO $pdo, int $userId): int
{
    $businessId = isset($_SESSION['zipoo_business_id']) ? (int) $_SESSION['zipoo_business_id'] : 0;
    if ($businessId > 0) {
        $stmt = $pdo->prepare('SELECT id FROM tbl_businesses WHERE id = :bid AND (owner_user_id = :uid OR id = :check_bid) LIMIT 1');
        $stmt->execute([':bid' => $businessId, ':uid' => $userId, ':check_bid' => $businessId]);
        if ($stmt->fetchColumn()) {
            return $businessId;
        }
    }

    $stmt = $pdo->prepare('SELECT business_id FROM tbl_users WHERE id = :uid LIMIT 1');
    $stmt->execute([':uid' => $userId]);
    $userBid = (int) ($stmt->fetchColumn() ?: 0);
    if ($userBid > 0) {
        $_SESSION['zipoo_business_id'] = $userBid;
        return $userBid;
    }

    $stmt = $pdo->prepare('SELECT id FROM tbl_businesses WHERE owner_user_id = :uid ORDER BY id ASC LIMIT 1');
    $stmt->execute([':uid' => $userId]);
    $firstBid = (int) ($stmt->fetchColumn() ?: 0);
    if ($firstBid > 0) {
        $_SESSION['zipoo_business_id'] = $firstBid;
        return $firstBid;
    }

    return 0;
}

function ensure_settings_tables(PDO $pdo): void
{
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS tbl_business_settings (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            business_id BIGINT UNSIGNED NOT NULL,
            setting_group VARCHAR(50) NOT NULL,
            setting_key VARCHAR(100) NOT NULL,
            setting_value LONGTEXT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY unique_business_group_key (business_id, setting_group, setting_key),
            KEY idx_business_group (business_id, setting_group)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS tbl_sender_id_requests (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            business_id BIGINT UNSIGNED NULL,
            user_id BIGINT UNSIGNED NOT NULL,
            sender_id VARCHAR(11) NOT NULL,
            company_name VARCHAR(190) NOT NULL,
            purpose VARCHAR(255) NOT NULL,
            sample_message TEXT NULL,
            status ENUM("pending", "approved", "rejected") NOT NULL DEFAULT "pending",
            admin_notes TEXT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            KEY idx_user (user_id),
            KEY idx_business (business_id),
            KEY idx_status (status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );
}

function load_group_settings(PDO $pdo, int $businessId, string $group): array
{
    if ($businessId <= 0) {
        return [];
    }

    $stmt = $pdo->prepare(
        'SELECT setting_key, setting_value
         FROM tbl_business_settings
         WHERE business_id = :business_id AND setting_group = :setting_group'
    );
    $stmt->execute([':business_id' => $businessId, ':setting_group' => $group]);
    $result = [];
    foreach ($stmt->fetchAll() as $row) {
        $result[$row['setting_key']] = $row['setting_value'];
    }
    return $result;
}

function save_group_settings(PDO $pdo, int $businessId, string $group, array $settings): void
{
    if ($businessId <= 0) {
        respond(422, ['ok' => false, 'message' => 'Please select a business first.']);
    }

    $stmt = $pdo->prepare(
        'INSERT INTO tbl_business_settings (business_id, setting_group, setting_key, setting_value)
         VALUES (:business_id, :setting_group, :setting_key, :setting_value)
         ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)'
    );

    foreach ($settings as $key => $value) {
        $stmt->execute([
            ':business_id' => $businessId,
            ':setting_group' => $group,
            ':setting_key' => $key,
            ':setting_value' => $value,
        ]);
    }
}

try {
    $userId = require_user();
    $pdo = db();
    ensure_settings_tables($pdo);
    $businessId = get_active_business_id($pdo, $userId);

    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $smtp = load_group_settings($pdo, $businessId, 'smtp');
        $sms = load_group_settings($pdo, $businessId, 'sms');
        $general = load_group_settings($pdo, $businessId, 'general');

        // Fetch user's sender id requests
        $stmt = $pdo->prepare(
            'SELECT id, sender_id, company_name, purpose, sample_message, status, admin_notes, created_at 
             FROM tbl_sender_id_requests 
             WHERE user_id = :uid OR (business_id = :bid AND :bid > 0) 
             ORDER BY id DESC'
        );
        $stmt->execute([':uid' => $userId, ':bid' => $businessId]);
        $requests = $stmt->fetchAll();

        respond(200, [
            'ok' => true,
            'smtp' => [
                'smtp_host' => $smtp['smtp_host'] ?? '',
                'smtp_port' => $smtp['smtp_port'] ?? '587',
                'smtp_username' => $smtp['smtp_username'] ?? '',
                'has_password' => !empty($smtp['smtp_password']),
                'from_email' => $smtp['from_email'] ?? '',
                'smtp_encryption' => $smtp['smtp_encryption'] ?? 'tls',
            ],
            'sms' => [
                'provider_name' => $sms['provider_name'] ?? 'MegaSMS',
                'api_url' => 'https://megasms.co.tz/api/v1',
                'has_api_key' => !empty($sms['api_key']),
                'sender_id' => $sms['sender_id'] ?? 'MEGASMS',
            ],
            'general' => [
                'currency' => $general['currency'] ?? 'TZS',
                'timezone' => $general['timezone'] ?? 'Africa/Dar_es_Salaam',
                'tax_rate' => $general['tax_rate'] ?? '18',
                'receipt_footer' => $general['receipt_footer'] ?? 'Thank you for your business!',
            ],
            'sender_id_requests' => $requests,
        ]);
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        respond(405, ['ok' => false, 'message' => 'Method not allowed.']);
    }

    $action = trim((string) ($_POST['action'] ?? ''));

    if ($action === 'save_smtp') {
        $host = trim((string) ($_POST['smtp_host'] ?? ''));
        $port = trim((string) ($_POST['smtp_port'] ?? '587'));
        $username = trim((string) ($_POST['smtp_username'] ?? ''));
        $password = (string) ($_POST['smtp_password'] ?? '');
        $fromEmail = trim((string) ($_POST['from_email'] ?? ''));
        $encryption = trim((string) ($_POST['smtp_encryption'] ?? 'tls'));

        if ($fromEmail !== '' && !filter_var($fromEmail, FILTER_VALIDATE_EMAIL)) {
            respond(422, ['ok' => false, 'message' => 'Please enter a valid From Email.']);
        }

        $settings = [
            'smtp_host' => $host,
            'smtp_port' => $port,
            'smtp_username' => $username,
            'from_email' => $fromEmail,
            'smtp_encryption' => $encryption,
        ];

        if ($password !== '') {
            $settings['smtp_password'] = $password;
        }

        save_group_settings($pdo, $businessId, 'smtp', $settings);

        respond(200, ['ok' => true, 'message' => 'SMTP settings saved successfully.']);
    }

    if ($action === 'test_smtp') {
        $settings = load_group_settings($pdo, $businessId, 'smtp');
        $testEmail = trim((string) ($_POST['test_email'] ?? ''));
        if (!filter_var($testEmail, FILTER_VALIDATE_EMAIL)) {
            respond(422, ['ok' => false, 'message' => 'Please enter a valid email address to receive the test.']);
        }

        if (empty($settings['smtp_host']) || empty($settings['smtp_port']) || empty($settings['from_email'])) {
            respond(422, ['ok' => false, 'message' => 'Please configure SMTP host, port, and from email first.']);
        }

        $mail = new PHPMailer(true);
        try {
            $mail->isSMTP();
            $mail->Host = $settings['smtp_host'];
            $mail->Port = (int) $settings['smtp_port'];
            $mail->CharSet = 'UTF-8';
            $mail->Timeout = 15;
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

            $mail->setFrom($settings['from_email'], 'Zipoo System');
            $mail->addAddress($testEmail);
            $mail->Subject = 'Zipoo SMTP Test Message';
            $mail->Body = "Hello!\n\nThis is a test email sent from your Zipoo System Settings.\nSMTP connection is functioning properly.\n\nDate: " . date('Y-m-d H:i:s');
            $mail->send();

            respond(200, ['ok' => true, 'message' => 'Test email sent successfully to ' . $testEmail]);
        } catch (MailException $e) {
            respond(500, ['ok' => false, 'message' => 'SMTP test failed: ' . ($mail->ErrorInfo ?: $e->getMessage())]);
        }
    }

    if ($action === 'save_sms') {
        $apiUrl = 'https://megasms.co.tz/api/v1';
        $apiKey = (string) ($_POST['api_key'] ?? '');
        $senderId = strtoupper(trim((string) ($_POST['sender_id'] ?? 'MEGASMS')));

        if ($senderId === '') {
            $senderId = 'MEGASMS';
        }

        $settings = [
            'provider_name' => 'MegaSMS',
            'api_url' => $apiUrl,
            'sender_id' => $senderId,
        ];

        if ($apiKey !== '') {
            $settings['api_key'] = $apiKey;
        }

        save_group_settings($pdo, $businessId, 'sms', $settings);

        respond(200, ['ok' => true, 'message' => 'SMS settings saved successfully.']);
    }

    if ($action === 'test_sms') {
        $settings = load_group_settings($pdo, $businessId, 'sms');
        $phone = trim((string) ($_POST['test_phone'] ?? ''));
        if ($phone === '') {
            respond(422, ['ok' => false, 'message' => 'Please enter a test phone number (e.g. 255712345678).']);
        }

        if (empty($settings['api_key'])) {
            respond(422, ['ok' => false, 'message' => 'Please save the MegaSMS API Key first.']);
        }

        $senderId = $settings['sender_id'] ?? 'MEGASMS';
        $message = 'Zipoo test SMS. Your MegaSMS gateway integration is active and working!';
        $baseUrl = 'https://megasms.co.tz/api/v1';
        $url = $baseUrl . '/messages';

        $payload = json_encode([
            'to' => $phone,
            'sender_id' => $senderId,
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
                'content' => $payload,
                'timeout' => 20,
                'ignore_errors' => true,
            ],
        ]);

        $result = @file_get_contents($url, false, $context);
        $statusLine = $http_response_header[0] ?? '';

        if ($result === false) {
            respond(500, ['ok' => false, 'message' => 'Unable to reach MegaSMS server.', 'provider_response' => $statusLine]);
        }

        $success = preg_match('/HTTP\/\S+\s+2\d\d/', $statusLine) === 1;
        $respJson = json_decode((string) $result, true);

        if (!$success) {
            $errMsg = $respJson['message'] ?? $respJson['error'] ?? 'MegaSMS returned an error (' . $statusLine . ')';
            respond(400, ['ok' => false, 'message' => 'SMS failed: ' . $errMsg, 'raw' => $result]);
        }

        respond(200, [
            'ok' => true,
            'message' => 'Test SMS sent successfully to ' . $phone,
            'provider_response' => $respJson,
        ]);
    }

    if ($action === 'request_sender_id') {
        $senderId = strtoupper(trim((string) ($_POST['sender_id'] ?? '')));
        $companyName = trim((string) ($_POST['company_name'] ?? ''));
        $purpose = trim((string) ($_POST['purpose'] ?? ''));
        $sampleMessage = trim((string) ($_POST['sample_message'] ?? ''));

        if ($senderId === '') {
            respond(422, ['ok' => false, 'message' => 'Sender ID is required.']);
        }

        if (strlen($senderId) > 11) {
            respond(422, ['ok' => false, 'message' => 'Sender ID cannot exceed 11 characters.']);
        }

        if (!preg_match('/^[A-Z0-9]+$/', $senderId)) {
            respond(422, ['ok' => false, 'message' => 'Sender ID must contain only alphanumeric characters without spaces.']);
        }

        if ($companyName === '') {
            respond(422, ['ok' => false, 'message' => 'Registered business/company name is required.']);
        }

        if ($purpose === '') {
            respond(422, ['ok' => false, 'message' => 'Please provide the purpose for this Sender ID (e.g. customer receipts, OTP alerts).']);
        }

        $stmt = $pdo->prepare(
            'INSERT INTO tbl_sender_id_requests (business_id, user_id, sender_id, company_name, purpose, sample_message, status)
             VALUES (:bid, :uid, :sender_id, :company_name, :purpose, :sample_message, "pending")'
        );
        $stmt->execute([
            ':bid' => $businessId > 0 ? $businessId : null,
            ':uid' => $userId,
            ':sender_id' => $senderId,
            ':company_name' => $companyName,
            ':purpose' => $purpose,
            ':sample_message' => $sampleMessage !== '' ? $sampleMessage : null,
        ]);

        respond(201, [
            'ok' => true,
            'message' => 'Sender ID request for "' . $senderId . '" submitted to MegaSMS team for operator approval.',
        ]);
    }

    if ($action === 'save_general') {
        $currency = strtoupper(trim((string) ($_POST['currency'] ?? 'TZS')));
        $timezone = trim((string) ($_POST['timezone'] ?? 'Africa/Dar_es_Salaam'));
        $taxRate = trim((string) ($_POST['tax_rate'] ?? '18'));
        $receiptFooter = trim((string) ($_POST['receipt_footer'] ?? ''));

        save_group_settings($pdo, $businessId, 'general', [
            'currency' => $currency,
            'timezone' => $timezone,
            'tax_rate' => $taxRate,
            'receipt_footer' => $receiptFooter,
        ]);

        respond(200, ['ok' => true, 'message' => 'General preferences saved successfully.']);
    }

    respond(400, ['ok' => false, 'message' => 'Unsupported action.']);
} catch (Throwable $e) {
    respond(500, ['ok' => false, 'message' => $e->getMessage()]);
}
