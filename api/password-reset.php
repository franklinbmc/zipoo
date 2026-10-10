<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once dirname(__DIR__) . '/vendor/autoload.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception as MailException;

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function respond(int $status, array $payload): void
{
    http_response_code($status);
    echo json_encode($payload);
    exit;
}

function ensure_reset_table(PDO $pdo): void
{
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS tbl_user_password_resets (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id BIGINT UNSIGNED NOT NULL,
            otp_hash VARCHAR(255) NULL,
            token_hash VARCHAR(255) NULL,
            expires_at DATETIME NOT NULL,
            used_at DATETIME NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_user (user_id),
            KEY idx_token (token_hash),
            KEY idx_expires (expires_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );
}

function load_group_settings(PDO $pdo, string $group): array
{
    try {
        $stmt = $pdo->prepare('SELECT setting_key, setting_value FROM tbl_saas_settings WHERE setting_group = :setting_group');
        $stmt->execute([':setting_group' => $group]);
    } catch (Throwable) {
        return [];
    }

    $settings = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $settings[$row['setting_key']] = $row['setting_value'];
    }
    return $settings;
}

function app_origin(): string
{
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = (string) ($_SERVER['HTTP_HOST'] ?? 'localhost');
    return $scheme . '://' . $host;
}

function is_local_env(): bool
{
    $host = (string) ($_SERVER['HTTP_HOST'] ?? '');
    return str_contains($host, 'localhost') || str_contains($host, '127.0.0.1');
}

function mask_target(string $val): string
{
    if (filter_var($val, FILTER_VALIDATE_EMAIL)) {
        $parts = explode('@', $val, 2);
        $name = $parts[0];
        $domain = $parts[1] ?? '';
        $maskedName = strlen($name) <= 2 ? $name[0] . '*' : substr($name, 0, 2) . str_repeat('*', max(1, strlen($name) - 3)) . substr($name, -1);
        return $maskedName . '@' . $domain;
    }

    if (preg_match('/^0\d+$/', $val)) {
        if (strlen($val) <= 5) return $val;
        return substr($val, 0, 3) . '****' . substr($val, -2);
    }

    return $val;
}

function send_reset_email(PDO $pdo, string $email, string $name, string $otp, string $resetUrl): bool
{
    if ($email === '') {
        return false;
    }

    $settings = load_group_settings($pdo, 'smtp');
    if (empty($settings['smtp_host']) || empty($settings['smtp_port']) || empty($settings['from_email'])) {
        $body = "Hi {$name},\n\nYour Zipoo password reset code is: {$otp}\n\nAlternatively, use this direct link (valid for 15 minutes):\n{$resetUrl}\n\nIf you did not request this, please ignore this email.\n\nZipoo";
        return @mail($email, 'Reset your Zipoo password', $body, 'From: no-reply@localhost');
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
        $mail->addAddress($email, $name);
        $mail->Subject = 'Reset your Zipoo password';
        $mail->Body = "Hi {$name},\n\nYour Zipoo password reset verification code is: {$otp}\n\nAlternatively, you can open this link to set a new password:\n{$resetUrl}\n\nThis code and link will expire in 15 minutes.\n\nZipoo Support";
        $mail->send();
        return true;
    } catch (MailException) {
        return false;
    }
}

function send_reset_sms(PDO $pdo, string $phone, string $otp): bool
{
    $settings = load_group_settings($pdo, 'sms');
    if (empty($settings['api_url']) || empty($settings['api_key'])) {
        return false;
    }

    $baseUrl = rtrim($settings['api_url'], '/');
    $content = json_encode([
        'to' => $phone,
        'sender_id' => $settings['sender_id'] ?? 'MEGASMS',
        'message' => "Your Zipoo password reset code is {$otp}. It expires in 15 minutes.",
    ]);
    $context = stream_context_create([
        'http' => [
            'method' => 'POST',
            'header' => implode("\r\n", [
                'Authorization: Bearer ' . $settings['api_key'],
                'Content-Type: application/json',
                'Accept: application/json',
            ]),
            'content' => $content,
            'timeout' => 20,
            'ignore_errors' => true,
        ],
    ]);

    $result = @file_get_contents($baseUrl . '/messages', false, $context);
    $statusLine = $http_response_header[0] ?? '';
    return $result !== false && preg_match('/HTTP\/\S+\s+2\d\d/', $statusLine) === 1;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond(405, ['ok' => false, 'message' => 'Method not allowed.']);
}

try {
    $pdo = db();
    ensure_reset_table($pdo);

    $action = trim((string) ($_POST['action'] ?? 'request'));

    if ($action === 'request') {
        $login = trim((string) ($_POST['login'] ?? ''));
        if ($login === '') {
            respond(422, ['ok' => false, 'message' => 'Please enter your phone number or email address.']);
        }

        $normalized = strtolower($login);
        $stmt = $pdo->prepare(
            'SELECT id, full_name, phone, email
             FROM tbl_users
             WHERE phone = :login OR LOWER(email) = :login
             LIMIT 1'
        );
        $stmt->execute([':login' => $normalized]);
        $user = $stmt->fetch();

        if (!$user) {
            // Keep user privacy, but give helpful response
            respond(200, [
                'ok' => true,
                'message' => 'If an account exists with that phone number or email, a reset code has been sent.',
                'target' => mask_target($login),
            ]);
        }

        $userId = (int) $user['id'];
        $otp = sprintf('%06d', random_int(100000, 999999));
        $token = bin2hex(random_bytes(32));

        $pdo->prepare('UPDATE tbl_user_password_resets SET used_at = NOW() WHERE user_id = :uid AND used_at IS NULL')
            ->execute([':uid' => $userId]);

        $insertStmt = $pdo->prepare(
            'INSERT INTO tbl_user_password_resets (user_id, otp_hash, token_hash, expires_at)
             VALUES (:uid, :otp_hash, :token_hash, DATE_ADD(NOW(), INTERVAL 15 MINUTE))'
        );
        $insertStmt->execute([
            ':uid' => $userId,
            ':otp_hash' => password_hash($otp, PASSWORD_DEFAULT),
            ':token_hash' => hash('sha256', $token),
        ]);

        $resetUrl = app_origin() . '/reset-password?token=' . urlencode($token);

        $emailSent = false;
        $smsSent = false;

        if (!empty($user['email'])) {
            $emailSent = send_reset_email($pdo, (string) $user['email'], (string) $user['full_name'], $otp, $resetUrl);
        }
        if (!empty($user['phone'])) {
            $smsSent = send_reset_sms($pdo, (string) $user['phone'], $otp);
        }

        $targetDesc = !empty($user['phone']) ? mask_target($user['phone']) : mask_target((string) $user['email']);
        $payload = [
            'ok' => true,
            'message' => ($emailSent || $smsSent)
                ? 'Verification code sent to ' . $targetDesc . '. Please check and enter the 6-digit code.'
                : 'Reset code generated. Enter the code to continue.',
            'target' => $targetDesc,
            'delivery' => [
                'email' => $emailSent,
                'sms' => $smsSent,
            ],
        ];

        // If local environment or delivery not configured, provide dev info so local testing is friction-free
        if (is_local_env() || (!$emailSent && !$smsSent)) {
            $payload['dev_otp'] = $otp;
            $payload['dev_token'] = $token;
            $payload['dev_reset_url'] = $resetUrl;
        }

        respond(200, $payload);
    }

    if ($action === 'verify_otp') {
        $login = trim((string) ($_POST['login'] ?? ''));
        $otp = preg_replace('/\D+/', '', (string) ($_POST['otp'] ?? ''));

        if ($login === '' || strlen($otp) !== 6) {
            respond(422, ['ok' => false, 'message' => 'Enter a valid 6-digit verification code.']);
        }

        $normalized = strtolower($login);
        $stmt = $pdo->prepare(
            'SELECT id FROM tbl_users WHERE phone = :login OR LOWER(email) = :login LIMIT 1'
        );
        $stmt->execute([':login' => $normalized]);
        $user = $stmt->fetch();
        if (!$user) {
            respond(422, ['ok' => false, 'message' => 'Invalid or expired verification code.']);
        }

        $resetStmt = $pdo->prepare(
            'SELECT id, otp_hash, token_hash
             FROM tbl_user_password_resets
             WHERE user_id = :uid AND used_at IS NULL AND expires_at > NOW()
             ORDER BY id DESC LIMIT 1'
        );
        $resetStmt->execute([':uid' => (int) $user['id']]);
        $record = $resetStmt->fetch();

        if (!$record || !password_verify($otp, (string) $record['otp_hash'])) {
            respond(422, ['ok' => false, 'message' => 'Invalid or expired verification code.']);
        }

        respond(200, [
            'ok' => true,
            'message' => 'Code verified successfully. Enter your new password.',
        ]);
    }

    if ($action === 'reset') {
        $token = trim((string) ($_POST['token'] ?? ''));
        $login = trim((string) ($_POST['login'] ?? ''));
        $otp = preg_replace('/\D+/', '', (string) ($_POST['otp'] ?? ''));
        $password = (string) ($_POST['password'] ?? '');
        $confirm = (string) ($_POST['confirm_password'] ?? '');

        if ($password !== $confirm) {
            respond(422, ['ok' => false, 'message' => 'Passwords do not match.']);
        }

        if (!preg_match('/^(?=.*[A-Z])(?=.*\d)(?=.*[^A-Za-z0-9]).{8,}$/', $password)) {
            respond(422, [
                'ok' => false,
                'message' => 'Password must be at least 8 characters and include a capital letter, a number, and a symbol.',
            ]);
        }

        $resetRecord = null;

        if ($token !== '' && strlen($token) >= 32) {
            $tokenHash = hash('sha256', $token);
            $stmt = $pdo->prepare(
                'SELECT id, user_id
                 FROM tbl_user_password_resets
                 WHERE token_hash = :hash AND used_at IS NULL AND expires_at > NOW()
                 ORDER BY id DESC LIMIT 1'
            );
            $stmt->execute([':hash' => $tokenHash]);
            $resetRecord = $stmt->fetch();
        } elseif ($login !== '' && strlen($otp) === 6) {
            $normalized = strtolower($login);
            $userStmt = $pdo->prepare(
                'SELECT id FROM tbl_users WHERE phone = :login OR LOWER(email) = :login LIMIT 1'
            );
            $userStmt->execute([':login' => $normalized]);
            $user = $userStmt->fetch();

            if ($user) {
                $stmt = $pdo->prepare(
                    'SELECT id, user_id, otp_hash
                     FROM tbl_user_password_resets
                     WHERE user_id = :uid AND used_at IS NULL AND expires_at > NOW()
                     ORDER BY id DESC LIMIT 1'
                );
                $stmt->execute([':uid' => (int) $user['id']]);
                $rec = $stmt->fetch();
                if ($rec && password_verify($otp, (string) $rec['otp_hash'])) {
                    $resetRecord = $rec;
                }
            }
        }

        if (!$resetRecord) {
            respond(422, ['ok' => false, 'message' => 'Reset request is invalid or has expired. Please request a new code.']);
        }

        $userId = (int) $resetRecord['user_id'];
        $newHash = password_hash($password, PASSWORD_DEFAULT);

        $pdo->beginTransaction();
        try {
            $pdo->prepare('UPDATE tbl_users SET password_hash = :pwd, updated_at = NOW() WHERE id = :uid')
                ->execute([':pwd' => $newHash, ':uid' => $userId]);

            $pdo->prepare('UPDATE tbl_user_password_resets SET used_at = NOW() WHERE user_id = :uid AND used_at IS NULL')
                ->execute([':uid' => $userId]);

            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        respond(200, [
            'ok' => true,
            'message' => 'Password reset successfully. You can now login with your new password.',
        ]);
    }

    respond(422, ['ok' => false, 'message' => 'Unsupported reset action.']);
} catch (Throwable $error) {
    respond(500, [
        'ok' => false,
        'message' => 'Unable to process password reset right now. Please try again later.',
    ]);
}
