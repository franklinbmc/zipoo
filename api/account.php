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

function require_user(): int
{
    $userId = (int) ($_SESSION['zipoo_user_id'] ?? 0);
    if ($userId <= 0) {
        respond(401, ['ok' => false, 'message' => 'Please login first.']);
    }
    return $userId;
}

function input(string $key): string
{
    return trim((string) ($_POST[$key] ?? ''));
}

function user_payload(array $user): array
{
    return [
        'id' => (int) $user['id'],
        'business_id' => $user['business_id'] !== null ? (int) $user['business_id'] : null,
        'full_name' => $user['full_name'],
        'phone' => $user['phone'],
        'email' => $user['email'],
        'business_name' => $user['business_name'] ?? null,
    ];
}

function notify_email(string $to, string $subject, string $message): void
{
    if ($to !== '') {
        @mail($to, $subject, $message, 'From: no-reply@localhost');
    }
}

function make_otp(): string
{
    return (string) random_int(100000, 999999);
}

try {
    $userId = require_user();
    $pdo = db();

    $stmt = $pdo->prepare(
        'SELECT id, business_id, full_name, phone, email, business_name, business_type, region_code, district_code
         FROM tbl_users
         WHERE id = :id
         LIMIT 1'
    );
    $stmt->execute([':id' => $userId]);
    $user = $stmt->fetch();
    if (!$user) {
        respond(404, ['ok' => false, 'message' => 'Account not found.']);
    }

    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        respond(200, ['ok' => true, 'user' => user_payload($user)]);
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        respond(405, ['ok' => false, 'message' => 'Method not allowed.']);
    }

    $action = input('action');
    $field = input('field');
    $value = input('value');

    if ($action === 'request_change') {
        if (!in_array($field, ['full_name', 'email', 'phone'], true)) {
            respond(422, ['ok' => false, 'message' => 'Unknown account field.']);
        }
        if ($value === '') {
            respond(422, ['ok' => false, 'message' => 'Enter a value.']);
        }
        if ($field === 'email' && !filter_var($value, FILTER_VALIDATE_EMAIL)) {
            respond(422, ['ok' => false, 'message' => 'Enter a valid email address.']);
        }
        if ($field === 'phone' && !preg_match('/^0\d{1,9}$/', $value)) {
            respond(422, ['ok' => false, 'message' => 'Phone must start with 0 and be no more than 10 digits.']);
        }

        if ($field === 'full_name') {
            $pdo->prepare('UPDATE tbl_users SET full_name = :value WHERE id = :id')
                ->execute([':value' => $value, ':id' => $userId]);
            notify_email((string) ($user['email'] ?? ''), 'Zipoo account updated', 'Your Zipoo account name was updated.');
            $user['full_name'] = $value;
            respond(200, ['ok' => true, 'message' => 'Account updated.', 'user' => user_payload($user)]);
        }

        if ($field === 'email') {
            $duplicate = $pdo->prepare('SELECT COUNT(*) FROM tbl_users WHERE LOWER(email) = :email AND id <> :id');
            $duplicate->execute([':email' => strtolower($value), ':id' => $userId]);
            if ((int) $duplicate->fetchColumn() > 0) {
                respond(409, ['ok' => false, 'message' => 'That email is already used.']);
            }
        }

        if ($field === 'phone') {
            $duplicate = $pdo->prepare('SELECT COUNT(*) FROM tbl_users WHERE phone = :phone AND id <> :id');
            $duplicate->execute([':phone' => $value, ':id' => $userId]);
            if ((int) $duplicate->fetchColumn() > 0) {
                respond(409, ['ok' => false, 'message' => 'That phone number is already used.']);
            }
        }

        $oldOtp = make_otp();
        $newOtp = make_otp();
        $_SESSION['zipoo_account_change'] = [
            'field' => $field,
            'value' => $value,
            'old_otp_hash' => password_hash($oldOtp, PASSWORD_DEFAULT),
            'new_otp_hash' => password_hash($newOtp, PASSWORD_DEFAULT),
            'expires_at' => time() + 600,
        ];

        $oldEmail = (string) ($user['email'] ?? '');
        $newEmail = $field === 'email' ? $value : $oldEmail;
        notify_email($oldEmail, 'Confirm your Zipoo account change', "Your old verification code is {$oldOtp}. It expires in 10 minutes.");
        notify_email($newEmail, 'Confirm your Zipoo account change', "Your new verification code is {$newOtp}. It expires in 10 minutes.");
        notify_email($oldEmail, 'Zipoo account change requested', "A {$field} change was requested on your Zipoo account.");

        respond(200, ['ok' => true, 'message' => 'Verification codes were sent.']);
    }

    if ($action === 'verify_change') {
        $change = $_SESSION['zipoo_account_change'] ?? null;
        if (!is_array($change) || ($change['expires_at'] ?? 0) < time()) {
            respond(422, ['ok' => false, 'message' => 'Verification expired. Request new codes.']);
        }
        $oldOtp = input('old_otp');
        $newOtp = input('new_otp');
        if (!password_verify($oldOtp, (string) $change['old_otp_hash']) || !password_verify($newOtp, (string) $change['new_otp_hash'])) {
            respond(422, ['ok' => false, 'message' => 'Verification code is incorrect.']);
        }

        $field = (string) $change['field'];
        $value = (string) $change['value'];
        $column = $field === 'email' ? 'email' : 'phone';
        $pdo->prepare("UPDATE tbl_users SET {$column} = :value WHERE id = :id")
            ->execute([':value' => $value, ':id' => $userId]);
        unset($_SESSION['zipoo_account_change']);
        notify_email((string) ($user['email'] ?? ''), 'Zipoo account updated', "Your Zipoo {$field} was updated.");
        if ($field === 'email') {
            notify_email($value, 'Zipoo email connected', 'This email is now connected to your Zipoo account.');
        }

        $stmt->execute([':id' => $userId]);
        respond(200, ['ok' => true, 'message' => 'Account updated.', 'user' => user_payload($stmt->fetch())]);
    }

    respond(422, ['ok' => false, 'message' => 'Unknown account action.']);
} catch (Throwable) {
    respond(500, ['ok' => false, 'message' => 'Unable to update account right now.']);
}
