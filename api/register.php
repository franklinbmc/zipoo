<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/permissions_lib.php';

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

function input(string $key): string
{
    return trim((string) ($_POST[$key] ?? ''));
}

function ensure_otp_table(PDO $pdo): void
{
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS tbl_user_otps (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id BIGINT UNSIGNED NOT NULL,
            otp_hash VARCHAR(255) NOT NULL,
            expires_at DATETIME NOT NULL,
            verified_at DATETIME NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY user_id (user_id),
            KEY expires_at (expires_at)
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

function send_otp_email(PDO $pdo, string $email, string $name, string $otp): bool
{
    if ($email === '') {
        return false;
    }

    $settings = load_group_settings($pdo, 'smtp');
    if (empty($settings['smtp_host']) || empty($settings['smtp_port']) || empty($settings['from_email'])) {
        return @mail(
            $email,
            'Your Zipoo verification code',
            "Hi {$name},\n\nYour Zipoo verification code is {$otp}. It expires in 10 minutes.\n\nZipoo",
            'From: no-reply@localhost'
        );
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
        $mail->Subject = 'Your Zipoo verification code';
        $mail->Body = "Hi {$name},\n\nYour Zipoo verification code is {$otp}. It expires in 10 minutes.\n\nZipoo";
        $mail->send();
        return true;
    } catch (MailException) {
        return false;
    }
}

function send_otp_sms(PDO $pdo, string $phone, string $otp): bool
{
    $settings = load_group_settings($pdo, 'sms');
    if (empty($settings['api_url']) || empty($settings['api_key'])) {
        return false;
    }

    $baseUrl = rtrim($settings['api_url'], '/');
    $content = json_encode([
        'to' => $phone,
        'sender_id' => $settings['sender_id'] ?? 'MEGASMS',
        'message' => "Your Zipoo verification code is {$otp}. It expires in 10 minutes.",
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

$fullName = input('full_name');
$phone = input('phone');
$email = strtolower(input('email'));
$businessName = input('business_name');
$businessType = input('business_type');
$region = input('region');
$district = input('district');
$password = (string) ($_POST['password'] ?? '');
$confirmPassword = (string) ($_POST['confirm_password'] ?? '');

if ($fullName === '' || $phone === '' || $businessName === '' || $businessType === '' || $region === '' || $district === '' || $password === '') {
    respond(422, ['ok' => false, 'code' => 'required', 'message' => 'Please complete all required fields.']);
}

if (!preg_match('/^0\d{1,9}$/', $phone)) {
    respond(422, ['ok' => false, 'code' => 'phone', 'message' => 'Phone must start with 0 and be no more than 10 digits.']);
}

if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    respond(422, ['ok' => false, 'code' => 'email', 'message' => 'Enter a valid email address.']);
}

if ($password !== $confirmPassword) {
    respond(422, ['ok' => false, 'code' => 'password_match', 'message' => 'Passwords must match.']);
}

if (!preg_match('/^(?=.*[A-Z])(?=.*\d)(?=.*[^A-Za-z0-9]).{8,}$/', $password)) {
    respond(422, [
        'ok' => false,
        'code' => 'password',
        'message' => 'Password must be at least 8 characters and include a capital letter, a number, and a symbol.',
    ]);
}

try {
    $pdo = db();
    ensure_otp_table($pdo);

    $location = $pdo->prepare(
        'SELECT COUNT(*) FROM tbl_tanzania_locations WHERE region_code = :region AND district_code = :district'
    );
    $location->execute([':region' => $region, ':district' => $district]);
    if ((int) $location->fetchColumn() === 0) {
        respond(422, ['ok' => false, 'code' => 'location', 'message' => 'Choose a valid region and district.']);
    }

    $duplicate = $pdo->prepare(
        'SELECT id, full_name, email FROM tbl_users WHERE phone = :phone OR (:email <> "" AND email = :email) LIMIT 1'
    );
    $duplicate->execute([':phone' => $phone, ':email' => $email]);
    $existing = $duplicate->fetch();

    if ($existing) {
        if (!empty($existing['email'])) {
            $subject = 'Zipoo account notice';
            $body = "Hi {$existing['full_name']},\n\nSomeone tried to create a Zipoo account using your phone number or email. If this was you, please use the login page or reset your password.\n\nZipoo";
            @mail($existing['email'], $subject, $body, 'From: no-reply@localhost');
        }

        respond(409, [
            'ok' => false,
            'code' => 'existing',
            'message' => 'This account already exists. We sent an email if one is saved on the account.',
        ]);
    }

    $pdo->beginTransaction();

    $business = $pdo->prepare(
        'INSERT INTO tbl_businesses
            (business_name, business_type, region_code, district_code)
         VALUES
            (:business_name, :business_type, :region_code, :district_code)'
    );
    $business->execute([
        ':business_name' => $businessName,
        ':business_type' => $businessType,
        ':region_code' => $region,
        ':district_code' => $district,
    ]);
    $businessId = (int) $pdo->lastInsertId();

    $insert = $pdo->prepare(
        'INSERT INTO tbl_users
            (business_id, full_name, phone, email, business_name, business_type, region_code, district_code, password_hash)
         VALUES
            (:business_id, :full_name, :phone, :email, :business_name, :business_type, :region_code, :district_code, :password_hash)'
    );
    $insert->execute([
        ':business_id' => $businessId,
        ':full_name' => $fullName,
        ':phone' => $phone,
        ':email' => $email !== '' ? $email : null,
        ':business_name' => $businessName,
        ':business_type' => $businessType,
        ':region_code' => $region,
        ':district_code' => $district,
        ':password_hash' => password_hash($password, PASSWORD_DEFAULT),
    ]);
    $userId = (int) $pdo->lastInsertId();
    $pdo->prepare('UPDATE tbl_businesses SET owner_user_id = :owner_user_id WHERE id = :id')
        ->execute([':owner_user_id' => $userId, ':id' => $businessId]);
    ensure_business_rbac($pdo, $businessId);

    $otp = (string) random_int(100000, 999999);
    $otpStmt = $pdo->prepare(
        'INSERT INTO tbl_user_otps (user_id, otp_hash, expires_at)
         VALUES (:user_id, :otp_hash, DATE_ADD(NOW(), INTERVAL 10 MINUTE))'
    );
    $otpStmt->execute([
        ':user_id' => $userId,
        ':otp_hash' => password_hash($otp, PASSWORD_DEFAULT),
    ]);
    $pdo->commit();

    $_SESSION['zipoo_pending_user_id'] = $userId;

    $emailSent = false;
    $smsSent = false;
    try {
        $emailSent = send_otp_email($pdo, $email, $fullName, $otp);
        $smsSent = send_otp_sms($pdo, $phone, $otp);
    } catch (Throwable) {
        $emailSent = false;
        $smsSent = false;
    }

    respond(201, [
        'ok' => true,
        'requires_otp' => true,
        'message' => ($emailSent || $smsSent)
            ? 'Account created successfully. Enter the OTP sent to your phone or email.'
            : 'Account created successfully, but OTP delivery is not configured. Ask the admin to set SMS or SMTP.',
        'delivery' => [
            'email' => $emailSent,
            'sms' => $smsSent,
        ],
    ]);
} catch (Throwable $error) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    respond(500, [
        'ok' => false,
        'code' => 'server',
        'message' => 'Unable to create the account right now.',
    ]);
}
