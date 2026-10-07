<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once dirname(__DIR__) . '/vendor/autoload.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception as MailException;

session_start();
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
        'CREATE TABLE IF NOT EXISTS tbl_saas_password_resets (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            admin_id BIGINT UNSIGNED NOT NULL,
            token_hash VARCHAR(255) NOT NULL,
            expires_at DATETIME NOT NULL,
            used_at DATETIME NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_admin (admin_id),
            KEY idx_token (token_hash),
            KEY idx_expires (expires_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );
}

function load_settings(PDO $pdo, string $group): array
{
    if (!table_exists($pdo, 'tbl_saas_settings')) {
        return [];
    }
    $stmt = $pdo->prepare('SELECT setting_key, setting_value FROM tbl_saas_settings WHERE setting_group = :grp');
    $stmt->execute([':grp' => $group]);
    $out = [];
    foreach ($stmt->fetchAll() as $row) {
        $out[(string) $row['setting_key']] = (string) $row['setting_value'];
    }
    return $out;
}

function table_exists(PDO $pdo, string $table): bool
{
    $stmt = $pdo->prepare('SHOW TABLES LIKE :table_name');
    $stmt->execute([':table_name' => $table]);
    return (bool) $stmt->fetchColumn();
}

function column_exists(PDO $pdo, string $table, string $column): bool
{
    if (!table_exists($pdo, $table)) {
        return false;
    }
    $stmt = $pdo->prepare("SHOW COLUMNS FROM {$table} LIKE :column_name");
    $stmt->execute([':column_name' => $column]);
    return (bool) $stmt->fetch();
}

function saas_admin_active_sql(PDO $pdo, string $alias = ''): string
{
    $prefix = $alias !== '' ? $alias . '.' : '';
    if (column_exists($pdo, 'tbl_saas_admins', 'is_active')) {
        return $prefix . 'is_active = 1';
    }
    return $prefix . 'status = "active"';
}

function app_origin(): string
{
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = (string) ($_SERVER['HTTP_HOST'] ?? 'localhost');
    return $scheme . '://' . $host;
}

function send_reset_email(PDO $pdo, string $email, string $name, string $resetUrl): void
{
    $settings = load_settings($pdo, 'smtp');
    if (empty($settings['smtp_host']) || empty($settings['smtp_port']) || empty($settings['from_email'])) {
        return;
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
        $mail->setFrom($settings['from_email'], 'Zipoo SaaS Admin');
        $mail->addAddress($email, $name);
        $mail->Subject = 'Reset your Zipoo SaaS admin password';
        $mail->Body = "Hi {$name},\n\nUse this link to reset your SaaS admin password. It expires in 30 minutes:\n\n{$resetUrl}\n\nIf you did not request this, ignore this email.\n\nZipoo";
        $mail->send();
    } catch (MailException $error) {
        // Keep reset requests non-enumerating. Operators can verify SMTP from SaaS settings.
        return;
    }
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond(405, ['ok' => false, 'message' => 'Method not allowed.']);
}

try {
    $pdo = db();
    ensure_reset_table($pdo);
    $action = trim((string) ($_POST['action'] ?? 'request'));

    if ($action === 'request') {
        $email = strtolower(trim((string) ($_POST['email'] ?? '')));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            respond(422, ['ok' => false, 'message' => 'Enter a valid admin email.']);
        }

        $stmt = $pdo->prepare('SELECT id, full_name, email FROM tbl_saas_admins WHERE email = :email AND ' . saas_admin_active_sql($pdo) . ' LIMIT 1');
        $stmt->execute([':email' => $email]);
        $admin = $stmt->fetch();
        if ($admin) {
            $token = bin2hex(random_bytes(32));
            $hash = hash('sha256', $token);
            $pdo->prepare('UPDATE tbl_saas_password_resets SET used_at = NOW() WHERE admin_id = :admin AND used_at IS NULL')
                ->execute([':admin' => (int) $admin['id']]);
            $pdo->prepare(
                'INSERT INTO tbl_saas_password_resets (admin_id, token_hash, expires_at)
                 VALUES (:admin, :hash, DATE_ADD(NOW(), INTERVAL 30 MINUTE))'
            )->execute([':admin' => (int) $admin['id'], ':hash' => $hash]);
            $resetUrl = app_origin() . '/saas/reset-password?token=' . urlencode($token);
            send_reset_email($pdo, (string) $admin['email'], (string) $admin['full_name'], $resetUrl);
        }

        respond(200, ['ok' => true, 'message' => 'If that admin email exists, a reset link has been sent.']);
    }

    if ($action === 'reset') {
        $token = trim((string) ($_POST['token'] ?? ''));
        $password = (string) ($_POST['password'] ?? '');
        $confirm = (string) ($_POST['confirm_password'] ?? '');
        if ($token === '' || strlen($token) < 32) {
            respond(422, ['ok' => false, 'message' => 'Reset link is invalid.']);
        }
        if ($password !== $confirm) {
            respond(422, ['ok' => false, 'message' => 'Passwords must match.']);
        }
        if (!preg_match('/^(?=.*[A-Z])(?=.*\d)(?=.*[^A-Za-z0-9]).{8,}$/', $password)) {
            respond(422, ['ok' => false, 'message' => 'Password must be 8+ characters with uppercase, number, and symbol.']);
        }

        $hash = hash('sha256', $token);
        $stmt = $pdo->prepare(
            'SELECT r.id, r.admin_id
             FROM tbl_saas_password_resets r
             JOIN tbl_saas_admins a ON a.id = r.admin_id AND ' . saas_admin_active_sql($pdo, 'a') . '
             WHERE r.token_hash = :hash AND r.used_at IS NULL AND r.expires_at > NOW()
             ORDER BY r.id DESC LIMIT 1'
        );
        $stmt->execute([':hash' => $hash]);
        $reset = $stmt->fetch();
        if (!$reset) {
            respond(422, ['ok' => false, 'message' => 'Reset link has expired or was already used.']);
        }

        $pdo->beginTransaction();
        try {
            $pdo->prepare('UPDATE tbl_saas_admins SET password_hash = :pwd, updated_at = NOW() WHERE id = :id')
                ->execute([':pwd' => password_hash($password, PASSWORD_DEFAULT), ':id' => (int) $reset['admin_id']]);
            $pdo->prepare('UPDATE tbl_saas_password_resets SET used_at = NOW() WHERE admin_id = :admin AND used_at IS NULL')
                ->execute([':admin' => (int) $reset['admin_id']]);
            $pdo->commit();
        } catch (Throwable $error) {
            $pdo->rollBack();
            throw $error;
        }

        respond(200, ['ok' => true, 'message' => 'Password reset. You can now login.']);
    }

    respond(422, ['ok' => false, 'message' => 'Unsupported reset action.']);
} catch (Throwable $error) {
    respond(500, ['ok' => false, 'message' => 'Unable to process password reset right now.']);
}
