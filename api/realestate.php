<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/idempotency_lib.php';
require_once __DIR__ . '/realestate_lib.php';
require_once __DIR__ . '/accounts_lib.php';
require_once __DIR__ . '/billing_lib.php';

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

function active_business_id(PDO $pdo, int $userId): int
{
    $businessId = isset($_SESSION['zipoo_business_id']) ? (int) $_SESSION['zipoo_business_id'] : 0;
    if ($businessId > 0) {
        $stmt = $pdo->prepare('SELECT id FROM tbl_businesses WHERE id = :bid AND (owner_user_id = :uid OR id = :bid2) LIMIT 1');
        $stmt->execute([':bid' => $businessId, ':uid' => $userId, ':bid2' => $businessId]);
        if ((int) ($stmt->fetchColumn() ?: 0) > 0) {
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
    $first = (int) ($stmt->fetchColumn() ?: 0);
    if ($first > 0) {
        $_SESSION['zipoo_business_id'] = $first;
    }
    return $first;
}

function property_payload(array $row): array
{
    return [
        'id' => (int) $row['id'],
        'name' => (string) $row['name'],
        'type' => (string) ($row['type'] ?? 'residential'),
        'location' => (string) ($row['location'] ?? ''),
        'notes' => (string) ($row['notes'] ?? ''),
        'status' => (string) ($row['status'] ?? 'active'),
        'units_count' => (int) ($row['units_count'] ?? 0),
        'occupied_count' => (int) ($row['occupied_count'] ?? 0),
        'created_at' => (string) ($row['created_at'] ?? ''),
    ];
}

function parse_json_or_default($val, $default = [])
{
    if (is_array($val)) return $val;
    if (!is_string($val) || trim($val) === '') return $default;
    $d = json_decode($val, true);
    return is_array($d) ? $d : $default;
}

function unit_payload(array $row): array
{
    return [
        'id' => (int) $row['id'],
        'property_id' => (int) $row['property_id'],
        'property_name' => (string) ($row['property_name'] ?? ''),
        'name' => (string) $row['name'],
        'charge_type' => (string) ($row['charge_type'] ?? 'monthly'),
        'rate' => (float) ($row['rate'] ?? 0),
        'status' => (string) ($row['status'] ?? 'vacant'),
        'notes' => (string) ($row['notes'] ?? ''),
        'utilities' => parse_json_or_default($row['utilities'] ?? null, []),
        'amenities' => parse_json_or_default($row['amenities'] ?? null, []),
        'tenant_id' => isset($row['tenant_id']) && $row['tenant_id'] !== null ? (int) $row['tenant_id'] : null,
        'tenant_name' => (string) ($row['tenant_name'] ?? ''),
        'tenancy_id' => isset($row['tenancy_id']) && $row['tenancy_id'] !== null ? (int) $row['tenancy_id'] : null,
        'start_date' => isset($row['start_date']) && $row['start_date'] !== null ? (string) $row['start_date'] : null,
        'end_date' => isset($row['end_date']) && $row['end_date'] !== null ? (string) $row['end_date'] : null,
        'total_paid' => isset($row['total_paid']) ? (float) $row['total_paid'] : 0.0,
        'paid_until' => isset($row['paid_until']) && $row['paid_until'] !== null ? (string) $row['paid_until'] : null,
    ];
}

function load_properties(PDO $pdo, int $businessId): array
{
    $stmt = $pdo->prepare(
        'SELECT p.*,
                (SELECT COUNT(*) FROM tbl_property_units u WHERE u.property_id = p.id) AS units_count,
                (SELECT COUNT(*) FROM tbl_property_units u WHERE u.property_id = p.id AND u.status = "occupied") AS occupied_count
         FROM tbl_properties p
         WHERE p.business_id = :bid
         ORDER BY p.name ASC'
    );
    $stmt->execute([':bid' => $businessId]);
    return array_map('property_payload', $stmt->fetchAll());
}

/** Units with their property name and current (active) tenant, if any. */
function load_units(PDO $pdo, int $businessId, ?int $propertyId = null): array
{
    $where = 'u.business_id = :bid';
    $params = [':bid' => $businessId];
    if ($propertyId !== null && $propertyId > 0) {
        $where .= ' AND u.property_id = :pid';
        $params[':pid'] = $propertyId;
    }
    $stmt = $pdo->prepare(
        "SELECT u.*, p.name AS property_name,
                t.id AS tenancy_id, t.customer_id AS tenant_id, c.full_name AS tenant_name,
                t.start_date, t.end_date,
                (SELECT COALESCE(SUM(amount), 0) FROM tbl_rent_payments r WHERE r.tenancy_id = t.id) AS total_paid,
                (SELECT MAX(period_end) FROM tbl_rent_payments r WHERE r.tenancy_id = t.id AND r.period_end IS NOT NULL) AS paid_until
         FROM tbl_property_units u
         JOIN tbl_properties p ON p.id = u.property_id
         LEFT JOIN tbl_tenancies t ON t.unit_id = u.id AND t.status = 'active'
         LEFT JOIN tbl_customers c ON c.id = t.customer_id
         WHERE {$where}
         ORDER BY p.name ASC, u.name ASC"
    );
    $stmt->execute($params);
    return array_map('unit_payload', $stmt->fetchAll());
}

function load_summary(PDO $pdo, int $businessId): array
{
    $propCount = (int) $pdo->query('SELECT COUNT(*) FROM tbl_properties WHERE business_id = ' . $businessId)->fetchColumn();
    $unitStmt = $pdo->prepare('SELECT COUNT(*) AS total, SUM(status = "occupied") AS occupied FROM tbl_property_units WHERE business_id = :bid');
    $unitStmt->execute([':bid' => $businessId]);
    $u = $unitStmt->fetch() ?: [];
    $activeTenancies = (int) $pdo->query('SELECT COUNT(*) FROM tbl_tenancies WHERE business_id = ' . $businessId . ' AND status = "active"')->fetchColumn();
    $rentStmt = $pdo->prepare(
        'SELECT COALESCE(SUM(amount), 0) FROM tbl_rent_payments
         WHERE business_id = :bid AND YEAR(paid_date) = YEAR(CURDATE()) AND MONTH(paid_date) = MONTH(CURDATE())'
    );
    $rentStmt->execute([':bid' => $businessId]);
    return [
        'properties' => $propCount,
        'units_total' => (int) ($u['total'] ?? 0),
        'units_occupied' => (int) ($u['occupied'] ?? 0),
        'active_tenancies' => $activeTenancies,
        'rent_collected_month' => (float) $rentStmt->fetchColumn(),
    ];
}

function load_tenancies(PDO $pdo, int $businessId): array
{
    $stmt = $pdo->prepare(
        'SELECT t.*, u.name AS unit_name, p.name AS property_name, c.full_name AS tenant_name, c.phone AS tenant_phone
         FROM tbl_tenancies t
         JOIN tbl_property_units u ON u.id = t.unit_id
         JOIN tbl_properties p ON p.id = t.property_id
         JOIN tbl_customers c ON c.id = t.customer_id
         WHERE t.business_id = :bid
         ORDER BY (t.status = "active") DESC, t.id DESC'
    );
    $stmt->execute([':bid' => $businessId]);
    return array_map(static function ($t) {
        return [
            'id' => (int) $t['id'],
            'property_id' => (int) $t['property_id'],
            'unit_id' => (int) $t['unit_id'],
            'customer_id' => (int) $t['customer_id'],
            'property_name' => (string) $t['property_name'],
            'unit_name' => (string) $t['unit_name'],
            'tenant_name' => (string) $t['tenant_name'],
            'tenant_phone' => (string) ($t['tenant_phone'] ?? ''),
            'charge_type' => (string) $t['charge_type'],
            'rate' => (float) $t['rate'],
            'start_date' => (string) $t['start_date'],
            'end_date' => $t['end_date'] !== null ? (string) $t['end_date'] : null,
            'deposit' => (float) $t['deposit'],
            'status' => (string) $t['status'],
            'notes' => (string) ($t['notes'] ?? ''),
        ];
    }, $stmt->fetchAll());
}

function load_payments(PDO $pdo, int $businessId): array
{
    $stmt = $pdo->prepare(
        'SELECT r.*, u.name AS unit_name, p.name AS property_name, c.full_name AS tenant_name, a.name AS account_name
         FROM tbl_rent_payments r
         JOIN tbl_property_units u ON u.id = r.unit_id
         JOIN tbl_properties p ON p.id = r.property_id
         JOIN tbl_customers c ON c.id = r.customer_id
         LEFT JOIN tbl_accounts a ON a.id = r.account_id
         WHERE r.business_id = :bid
         ORDER BY r.id DESC LIMIT 200'
    );
    $stmt->execute([':bid' => $businessId]);
    return array_map(static function ($r) {
        return [
            'id' => (int) $r['id'],
            'tenancy_id' => (int) $r['tenancy_id'],
            'property_name' => (string) $r['property_name'],
            'unit_name' => (string) $r['unit_name'],
            'tenant_name' => (string) $r['tenant_name'],
            'amount' => (float) $r['amount'],
            'period_label' => (string) ($r['period_label'] ?? ''),
            'paid_date' => (string) $r['paid_date'],
            'account_name' => (string) ($r['account_name'] ?? ''),
            'notes' => (string) ($r['notes'] ?? ''),
        ];
    }, $stmt->fetchAll());
}

function compute_tenancy_rent_math(string $startDate, float $rate, string $chargeType, float $totalPaid, ?string $recordedPaidUntil = null): array
{
    $start = new DateTimeImmutable($startDate);
    $today = new DateTimeImmutable('today');

    $stayDiff = $start->diff($today);
    if ($start > $today) {
        $daysUntil = (int) $today->diff($start)->format('%a');
        $stayText = 'Starts in ' . $daysUntil . ' day' . ($daysUntil !== 1 ? 's' : '');
    } else {
        $parts = [];
        if ($stayDiff->y > 0) $parts[] = $stayDiff->y . ' yr' . ($stayDiff->y > 1 ? 's' : '');
        if ($stayDiff->m > 0) $parts[] = $stayDiff->m . ' mo' . ($stayDiff->m > 1 ? 's' : '');
        if ($stayDiff->d > 0 || empty($parts)) $parts[] = $stayDiff->d . ' day' . ($stayDiff->d !== 1 ? 's' : '');
        $stayText = implode(' ', $parts);
    }

    $periodsCovered = 0;
    if ($rate > 0) {
        $periodsCovered = (int) floor($totalPaid / $rate);
    }

    $expectedDate = $start;
    if ($periodsCovered > 0) {
        if ($chargeType === 'daily') {
            $expectedDate = $start->modify("+{$periodsCovered} days");
        } elseif ($chargeType === 'yearly') {
            $expectedDate = $start->modify("+{$periodsCovered} years");
        } else {
            $expectedDate = $start->modify("+{$periodsCovered} months");
        }
    }

    if ($recordedPaidUntil !== null && $recordedPaidUntil !== '') {
        $recEnd = new DateTimeImmutable($recordedPaidUntil);
        $nextDay = $recEnd->modify('+1 day');
        if ($nextDay > $expectedDate) {
            $expectedDate = $nextDay;
        }
    }

    $paidUntilDate = null;
    if ($periodsCovered > 0) {
        $paidUntilDate = $expectedDate->modify('-1 day')->format('Y-m-d');
    } elseif ($recordedPaidUntil !== null && $recordedPaidUntil !== '') {
        $paidUntilDate = $recordedPaidUntil;
    }

    $daysDiff = (int) $today->diff($expectedDate)->format('%r%a');

    return [
        'stay_duration' => $stayText,
        'periods_covered' => $periodsCovered,
        'expected_date' => $expectedDate->format('Y-m-d'),
        'paid_until' => $paidUntilDate,
        'days_diff' => $daysDiff,
    ];
}

function get_business_sms_balance(PDO $pdo, int $businessId): int
{
    require_once __DIR__ . '/billing_lib.php';
    ensure_billing_tables($pdo);
    $accountId = ensure_business_account($pdo, $businessId);
    if ($accountId <= 0) {
        return 0;
    }
    $stmt = $pdo->prepare('SELECT balance FROM tbl_account_sms_wallets WHERE account_id = :aid LIMIT 1');
    $stmt->execute([':aid' => $accountId]);
    $val = $stmt->fetchColumn();
    return $val !== false ? (int) $val : 0;
}

function get_sms_gateway_config(PDO $pdo, int $businessId): array
{
    $config = [
        'api_url' => 'https://megasms.co.tz/api/v1',
        'api_key' => '',
        'sender_id' => 'MEGASMS',
    ];
    $stmt = $pdo->prepare('SELECT setting_key, setting_value FROM tbl_business_settings WHERE business_id = :bid AND setting_group = "sms"');
    $stmt->execute([':bid' => $businessId]);
    $rows = $stmt->fetchAll(PDO::FETCH_KEY_PAIR) ?: [];
    if (!empty($rows['api_key'])) {
        $config['api_key'] = (string) $rows['api_key'];
        if (!empty($rows['api_url'])) $config['api_url'] = (string) $rows['api_url'];
        if (!empty($rows['sender_id'])) $config['sender_id'] = (string) $rows['sender_id'];
        return $config;
    }
    $sStmt = $pdo->query('SELECT setting_key, setting_value FROM tbl_saas_settings WHERE setting_group = "sms"');
    $sRows = $sStmt ? ($sStmt->fetchAll(PDO::FETCH_KEY_PAIR) ?: []) : [];
    if (!empty($sRows['api_key'])) {
        $config['api_key'] = (string) $sRows['api_key'];
        if (!empty($sRows['api_url'])) $config['api_url'] = (string) $sRows['api_url'];
        if (!empty($sRows['sender_id'])) $config['sender_id'] = (string) $sRows['sender_id'];
    }
    return $config;
}

function send_megasms(array $config, string $phone, string $message): array
{
    $cleanPhone = preg_replace('/[^\d]/', '', $phone);
    if (str_starts_with($cleanPhone, '0')) {
        $cleanPhone = '255' . substr($cleanPhone, 1);
    } elseif (str_starts_with($cleanPhone, '7') || str_starts_with($cleanPhone, '6')) {
        $cleanPhone = '255' . $cleanPhone;
    }

    $baseUrl = rtrim($config['api_url'] ?: 'https://megasms.co.tz/api/v1', '/');
    $url = $baseUrl . '/messages';
    $payload = json_encode([
        'to' => $cleanPhone,
        'sender_id' => $config['sender_id'] ?: 'MEGASMS',
        'message' => $message,
    ]);
    $headers = [
        'Authorization: Bearer ' . $config['api_key'],
        'Content-Type: application/json',
        'Accept: application/json',
    ];
    $context = stream_context_create([
        'http' => [
            'method' => 'POST',
            'header' => implode("\r\n", $headers),
            'content' => $payload,
            'timeout' => 15,
            'ignore_errors' => true,
        ],
    ]);
    $result = @file_get_contents($url, false, $context);
    $statusLine = $http_response_header[0] ?? '';
    $success = preg_match('/HTTP\/\S+\s+2\d\d/', $statusLine) === 1;
    $respJson = json_decode((string) $result, true);
    return [
        'ok' => $success,
        'phone' => $cleanPhone,
        'response' => $respJson,
        'status_line' => $statusLine,
    ];
}

function deduct_sms_wallet(PDO $pdo, int $accountId, int $businessId, int $count, string $reference): int
{
    $pdo->prepare('UPDATE tbl_account_sms_wallets SET balance = GREATEST(0, balance - :count) WHERE account_id = :aid')
        ->execute([':count' => $count, ':aid' => $accountId]);
    $stmt = $pdo->prepare('SELECT balance FROM tbl_account_sms_wallets WHERE account_id = :aid LIMIT 1');
    $stmt->execute([':aid' => $accountId]);
    $balanceAfter = (int) ($stmt->fetchColumn() ?: 0);
    $pdo->prepare('INSERT INTO tbl_account_sms_transactions (account_id, business_id, type, sms_count, balance_after, reference) VALUES (:aid, :bid, "send", :count, :bal, :ref)')
        ->execute([
            ':aid' => $accountId,
            ':bid' => $businessId,
            ':count' => -$count,
            ':bal' => $balanceAfter,
            ':ref' => $reference,
        ]);
    return $balanceAfter;
}

function get_smtp_config(PDO $pdo, int $businessId): array
{
    $stmt = $pdo->prepare('SELECT setting_key, setting_value FROM tbl_business_settings WHERE business_id = :bid AND setting_group = "smtp"');
    $stmt->execute([':bid' => $businessId]);
    $rows = $stmt->fetchAll(PDO::FETCH_KEY_PAIR) ?: [];
    if (!empty($rows['smtp_host'])) {
        return $rows;
    }
    $sStmt = $pdo->query('SELECT setting_key, setting_value FROM tbl_saas_settings WHERE setting_group = "smtp"');
    return $sStmt ? ($sStmt->fetchAll(PDO::FETCH_KEY_PAIR) ?: []) : [];
}

function send_rent_email(array $smtp, string $toEmail, string $recipientName, string $subject, string $bodyHtml, string $bodyText, string $senderBusinessName = ''): array
{
    if (empty($smtp['smtp_host']) || empty($smtp['from_email'])) {
        return ['ok' => false, 'message' => 'SMTP server is not configured.'];
    }
    require_once dirname(__DIR__) . '/vendor/autoload.php';
    $mail = new \PHPMailer\PHPMailer\PHPMailer(true);
    try {
        $mail->isSMTP();
        $mail->Host = $smtp['smtp_host'];
        $mail->Port = (int) ($smtp['smtp_port'] ?? 587);
        $mail->CharSet = 'UTF-8';
        $mail->Timeout = 15;
        $encryption = $smtp['smtp_encryption'] ?? 'tls';
        if ($encryption === 'ssl') {
            $mail->SMTPSecure = \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS;
        } elseif ($encryption === 'tls') {
            $mail->SMTPSecure = \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
        } else {
            $mail->SMTPSecure = false;
            $mail->SMTPAutoTLS = false;
        }
        if (!empty($smtp['smtp_username'])) {
            $mail->SMTPAuth = true;
            $mail->Username = $smtp['smtp_username'];
            $mail->Password = $smtp['smtp_password'] ?? '';
        }
        $fromName = !empty($senderBusinessName)
            ? $senderBusinessName
            : (!empty($smtp['from_name']) ? $smtp['from_name'] : 'Zipoo Property Management');
        $mail->setFrom($smtp['from_email'], $fromName);
        $mail->addAddress($toEmail, $recipientName);
        $mail->Subject = $subject;
        $mail->isHTML(true);
        $mail->Body = $bodyHtml;
        $mail->AltBody = $bodyText;
        $mail->send();
        return ['ok' => true];
    } catch (\Throwable $e) {
        return ['ok' => false, 'message' => 'Email sending failed: ' . ($mail->ErrorInfo ?: $e->getMessage())];
    }
}

function get_business_landlord_contact(PDO $pdo, int $businessId, int $userId): array
{
    $stmt = $pdo->prepare(
        'SELECT u.full_name, u.phone, u.email, b.business_name 
         FROM tbl_businesses b
         LEFT JOIN tbl_users u ON u.id = b.owner_user_id
         WHERE b.id = :bid LIMIT 1'
    );
    $stmt->execute([':bid' => $businessId]);
    $bRow = $stmt->fetch(PDO::FETCH_ASSOC);

    $phone = trim((string) ($bRow['phone'] ?? ''));
    $email = trim((string) ($bRow['email'] ?? ''));
    $name = trim((string) ($bRow['full_name'] ?? 'Landlord'));
    $businessName = trim((string) ($bRow['business_name'] ?? ''));

    if ($phone === '' || $email === '') {
        $uStmt = $pdo->prepare('SELECT full_name, phone, email FROM tbl_users WHERE id = :uid LIMIT 1');
        $uStmt->execute([':uid' => $userId]);
        $uRow = $uStmt->fetch(PDO::FETCH_ASSOC);
        if ($phone === '') $phone = trim((string) ($uRow['phone'] ?? ''));
        if ($email === '') $email = trim((string) ($uRow['email'] ?? ''));
        if ($name === 'Landlord' && !empty($uRow['full_name'])) $name = trim((string) $uRow['full_name']);
    }

    return [
        'name' => $name,
        'business_name' => $businessName,
        'phone' => $phone,
        'email' => $email,
    ];
}

function load_rent_overview(PDO $pdo, int $businessId): array
{
    $stmt = $pdo->prepare(
        'SELECT t.id, t.status, t.rate, t.charge_type, t.start_date, t.end_date, t.deposit,
                u.name AS unit_name, p.name AS property_name, c.full_name AS tenant_name, c.phone AS tenant_phone, c.email AS tenant_email,
                (SELECT COUNT(*) FROM tbl_rent_payments r WHERE r.tenancy_id = t.id) AS payments_count,
                (SELECT COALESCE(SUM(amount), 0) FROM tbl_rent_payments r WHERE r.tenancy_id = t.id) AS total_paid,
                (SELECT MAX(paid_date) FROM tbl_rent_payments r WHERE r.tenancy_id = t.id) AS last_paid_date,
                (SELECT amount FROM tbl_rent_payments r WHERE r.tenancy_id = t.id ORDER BY r.id DESC LIMIT 1) AS last_amount,
                (SELECT MAX(period_end) FROM tbl_rent_payments r WHERE r.tenancy_id = t.id AND r.period_end IS NOT NULL) AS recorded_paid_until
         FROM tbl_tenancies t
         JOIN tbl_property_units u ON u.id = t.unit_id
         JOIN tbl_properties p ON p.id = t.property_id
         JOIN tbl_customers c ON c.id = t.customer_id
         WHERE t.business_id = :bid
         ORDER BY (t.status = "active") DESC, t.id DESC'
    );
    $stmt->execute([':bid' => $businessId]);
    return array_map(static function ($r) {
        $math = compute_tenancy_rent_math(
            (string) $r['start_date'],
            (float) $r['rate'],
            (string) $r['charge_type'],
            (float) $r['total_paid'],
            $r['recorded_paid_until'] !== null ? (string) $r['recorded_paid_until'] : null
        );
        return [
            'tenancy_id' => (int) $r['id'],
            'status' => (string) $r['status'],
            'rate' => (float) $r['rate'],
            'charge_type' => (string) $r['charge_type'],
            'start_date' => (string) $r['start_date'],
            'end_date' => $r['end_date'] !== null ? (string) $r['end_date'] : null,
            'deposit' => (float) $r['deposit'],
            'property_name' => (string) $r['property_name'],
            'unit_name' => (string) $r['unit_name'],
            'tenant_name' => (string) $r['tenant_name'],
            'tenant_phone' => (string) ($r['tenant_phone'] ?? ''),
            'tenant_email' => (string) ($r['tenant_email'] ?? ''),
            'payments_count' => (int) $r['payments_count'],
            'total_paid' => (float) $r['total_paid'],
            'last_paid_date' => $r['last_paid_date'] !== null ? (string) $r['last_paid_date'] : null,
            'last_amount' => $r['last_amount'] !== null ? (float) $r['last_amount'] : null,
            'paid_until' => $math['paid_until'],
            'expected_date' => $math['expected_date'],
            'stay_duration' => $math['stay_duration'],
            'periods_covered' => $math['periods_covered'],
            'days_diff' => $math['days_diff'],
        ];
    }, $stmt->fetchAll());
}

function load_staff(PDO $pdo, int $businessId): array
{
    $stmt = $pdo->prepare(
        'SELECT s.*, p.name AS property_name FROM tbl_realestate_staff s
         LEFT JOIN tbl_properties p ON p.id = s.property_id
         WHERE s.business_id = :bid ORDER BY s.name ASC'
    );
    $stmt->execute([':bid' => $businessId]);
    return array_map(static function ($s) {
        return [
            'id' => (int) $s['id'],
            'name' => (string) $s['name'],
            'phone' => (string) ($s['phone'] ?? ''),
            'role' => (string) ($s['role'] ?? 'caretaker'),
            'property_id' => $s['property_id'] !== null ? (int) $s['property_id'] : null,
            'property_name' => (string) ($s['property_name'] ?? ''),
            'notes' => (string) ($s['notes'] ?? ''),
            'status' => (string) ($s['status'] ?? 'active'),
        ];
    }, $stmt->fetchAll());
}

function load_all_bundle(PDO $pdo, int $businessId, int $userId = 0): array
{
    return [
        'summary' => load_summary($pdo, $businessId),
        'properties' => load_properties($pdo, $businessId),
        'units' => load_units($pdo, $businessId),
        'tenancies' => load_tenancies($pdo, $businessId),
        'tenants' => load_rent_overview($pdo, $businessId),
        'payments' => load_payments($pdo, $businessId),
        'staff' => load_staff($pdo, $businessId),
        'sms_balance' => get_business_sms_balance($pdo, $businessId),
        'landlord' => get_business_landlord_contact($pdo, $businessId, $userId),
    ];
}

function fetch_or_404(PDO $pdo, string $table, int $businessId, int $id, string $label): array
{
    $stmt = $pdo->prepare("SELECT * FROM {$table} WHERE id = :id AND business_id = :bid LIMIT 1");
    $stmt->execute([':id' => $id, ':bid' => $businessId]);
    $row = $stmt->fetch();
    if (!$row) {
        respond(404, ['ok' => false, 'message' => $label . ' not found.']);
    }
    return $row;
}

try {
    $pdo = db();
    $userId = require_user();
    ensure_realestate_tables($pdo);
    ensure_rent_period_columns($pdo);
    ensure_property_units_extended_columns($pdo);
    ensure_accounts_tables($pdo);
    ensure_rent_txn_type($pdo);

    $businessId = active_business_id($pdo, $userId);
    if ($businessId <= 0) {
        respond(422, ['ok' => false, 'message' => 'No active business selected.']);
    }

    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $resource = trim((string) ($_GET['resource'] ?? ''));

        // Property detail (property + its units). The `tenancy` resource also uses `id`.
        if (isset($_GET['id']) && $resource !== 'tenancy') {
            $propertyId = (int) $_GET['id'];
            $property = fetch_or_404($pdo, 'tbl_properties', $businessId, $propertyId, 'Property');
            $units = load_units($pdo, $businessId, $propertyId);
            $out = property_payload($property);
            $out['units'] = $units;
            respond(200, ['ok' => true, 'property' => $out]);
        }

        if ($resource === '' || $resource === 'all' || $resource === 'bundle') {
            respond(200, array_merge(['ok' => true], load_all_bundle($pdo, $businessId, $userId)));
        }

        if ($resource === 'summary') {
            respond(200, ['ok' => true, 'summary' => load_summary($pdo, $businessId)]);
        }

        if ($resource === 'properties') {
            respond(200, ['ok' => true, 'properties' => load_properties($pdo, $businessId)]);
        }

        if ($resource === 'units') {
            $propertyId = isset($_GET['property_id']) ? (int) $_GET['property_id'] : null;
            respond(200, ['ok' => true, 'units' => load_units($pdo, $businessId, $propertyId)]);
        }

        if ($resource === 'tenancies') {
            respond(200, ['ok' => true, 'tenancies' => load_tenancies($pdo, $businessId)]);
        }

        if ($resource === 'payments') {
            respond(200, ['ok' => true, 'payments' => load_payments($pdo, $businessId)]);
        }

        // Who rented + rent-status summary per tenancy.
        if ($resource === 'rent_overview') {
            respond(200, [
                'ok' => true,
                'tenants' => load_rent_overview($pdo, $businessId),
                'sms_balance' => get_business_sms_balance($pdo, $businessId),
                'landlord' => get_business_landlord_contact($pdo, $businessId, $userId),
            ]);
        }

        // Full detail for one tenancy, including payment history.
        if ($resource === 'tenancy') {
            $tenancyId = (int) ($_GET['id'] ?? 0);
            $stmt = $pdo->prepare(
                'SELECT t.*, u.name AS unit_name, u.utilities AS unit_utilities, u.amenities AS unit_amenities, p.name AS property_name, c.full_name AS tenant_name, c.phone AS tenant_phone, c.email AS tenant_email
                 FROM tbl_tenancies t
                 JOIN tbl_property_units u ON u.id = t.unit_id
                 JOIN tbl_properties p ON p.id = t.property_id
                 JOIN tbl_customers c ON c.id = t.customer_id
                 WHERE t.id = :id AND t.business_id = :bid LIMIT 1'
            );
            $stmt->execute([':id' => $tenancyId, ':bid' => $businessId]);
            $t = $stmt->fetch();
            if (!$t) {
                respond(404, ['ok' => false, 'message' => 'Tenancy not found.']);
            }

            $pStmt = $pdo->prepare(
                'SELECT r.*, a.name AS account_name FROM tbl_rent_payments r
                 LEFT JOIN tbl_accounts a ON a.id = r.account_id
                 WHERE r.tenancy_id = :id AND r.business_id = :bid ORDER BY r.id DESC'
            );
            $pStmt->execute([':id' => $tenancyId, ':bid' => $businessId]);
            $history = array_map(static function ($r) {
                return [
                    'id' => (int) $r['id'],
                    'amount' => (float) $r['amount'],
                    'period_label' => (string) ($r['period_label'] ?? ''),
                    'period_start' => $r['period_start'] !== null ? (string) $r['period_start'] : null,
                    'period_end' => $r['period_end'] !== null ? (string) $r['period_end'] : null,
                    'paid_date' => (string) $r['paid_date'],
                    'account_name' => (string) ($r['account_name'] ?? ''),
                    'notes' => (string) ($r['notes'] ?? ''),
                ];
            }, $pStmt->fetchAll());
            $totalPaid = 0.0;
            $paidUntil = null;
            foreach ($history as $h) {
                $totalPaid += $h['amount'];
                if ($h['period_end'] !== null && ($paidUntil === null || $h['period_end'] > $paidUntil)) {
                    $paidUntil = $h['period_end'];
                }
            }

            $math = compute_tenancy_rent_math(
                (string) $t['start_date'],
                (float) $t['rate'],
                (string) $t['charge_type'],
                (float) $totalPaid,
                $paidUntil
            );

            respond(200, ['ok' => true, 'tenancy' => [
                'id' => (int) $t['id'],
                'status' => (string) $t['status'],
                'property_name' => (string) $t['property_name'],
                'unit_name' => (string) $t['unit_name'],
                'unit_utilities' => parse_json_or_default($t['unit_utilities'] ?? null, []),
                'unit_amenities' => parse_json_or_default($t['unit_amenities'] ?? null, []),
                'tenant_name' => (string) $t['tenant_name'],
                'tenant_phone' => (string) ($t['tenant_phone'] ?? ''),
                'tenant_email' => (string) ($t['tenant_email'] ?? ''),
                'charge_type' => (string) $t['charge_type'],
                'rate' => (float) $t['rate'],
                'start_date' => (string) $t['start_date'],
                'end_date' => $t['end_date'] !== null ? (string) $t['end_date'] : null,
                'deposit' => (float) $t['deposit'],
                'notes' => (string) ($t['notes'] ?? ''),
                'total_paid' => round($totalPaid, 2),
                'paid_until' => $math['paid_until'],
                'expected_date' => $math['expected_date'],
                'stay_duration' => $math['stay_duration'],
                'periods_covered' => $math['periods_covered'],
                'days_diff' => $math['days_diff'],
                'payments' => $history,
            ]]);
        }

        if ($resource === 'staff') {
            respond(200, ['ok' => true, 'staff' => load_staff($pdo, $businessId)]);
        }

        respond(422, ['ok' => false, 'message' => 'Unknown resource.']);
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        respond(405, ['ok' => false, 'message' => 'Method not allowed.']);
    }

    $action = trim((string) ($_POST['action'] ?? ''));

    // ---------- Properties ----------
    if ($action === 'property_create' || $action === 'property_update') {
        $name = trim((string) ($_POST['name'] ?? ''));
        $type = trim((string) ($_POST['type'] ?? 'residential'));
        $location = trim((string) ($_POST['location'] ?? ''));
        $notes = trim((string) ($_POST['notes'] ?? ''));
        if ($name === '') {
            respond(422, ['ok' => false, 'message' => 'Property name is required.']);
        }
        if ($action === 'property_update') {
            $id = (int) ($_POST['property_id'] ?? 0);
            fetch_or_404($pdo, 'tbl_properties', $businessId, $id, 'Property');
            $pdo->prepare('UPDATE tbl_properties SET name = :name, type = :type, location = :loc, notes = :notes WHERE id = :id AND business_id = :bid')
                ->execute([':name' => $name, ':type' => $type !== '' ? $type : 'residential', ':loc' => $location !== '' ? $location : null, ':notes' => $notes !== '' ? $notes : null, ':id' => $id, ':bid' => $businessId]);
        } else {
            $ins = $pdo->prepare('INSERT INTO tbl_properties (business_id, name, type, location, notes, status) VALUES (:bid, :name, :type, :loc, :notes, "active")');
            $ins->execute([':bid' => $businessId, ':name' => $name, ':type' => $type !== '' ? $type : 'residential', ':loc' => $location !== '' ? $location : null, ':notes' => $notes !== '' ? $notes : null]);
        }
        respond($action === 'property_create' ? 201 : 200, ['ok' => true, 'message' => $action === 'property_create' ? 'Property created.' : 'Property updated.', 'properties' => load_properties($pdo, $businessId)]);
    }

    if ($action === 'property_delete') {
        $id = (int) ($_POST['property_id'] ?? 0);
        fetch_or_404($pdo, 'tbl_properties', $businessId, $id, 'Property');
        $active = $pdo->prepare('SELECT COUNT(*) FROM tbl_tenancies WHERE property_id = :id AND business_id = :bid AND status = "active"');
        $active->execute([':id' => $id, ':bid' => $businessId]);
        if ((int) $active->fetchColumn() > 0) {
            respond(422, ['ok' => false, 'message' => 'End the active tenancies in this property before deleting it.']);
        }
        $pdo->beginTransaction();
        try {
            $pdo->prepare('DELETE FROM tbl_property_units WHERE property_id = :id AND business_id = :bid')->execute([':id' => $id, ':bid' => $businessId]);
            $pdo->prepare('DELETE FROM tbl_properties WHERE id = :id AND business_id = :bid')->execute([':id' => $id, ':bid' => $businessId]);
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
        respond(200, ['ok' => true, 'message' => 'Property deleted.', 'properties' => load_properties($pdo, $businessId)]);
    }

    // ---------- Units ----------
    if ($action === 'unit_create' || $action === 'unit_update') {
        $propertyId = (int) ($_POST['property_id'] ?? 0);
        $name = trim((string) ($_POST['name'] ?? ''));
        $chargeType = (string) ($_POST['charge_type'] ?? 'monthly');
        $chargeType = in_array($chargeType, ['daily', 'monthly'], true) ? $chargeType : 'monthly';
        $rate = round((float) ($_POST['rate'] ?? 0), 2);
        $notes = trim((string) ($_POST['notes'] ?? ''));
        $rawUtilities = $_POST['utilities'] ?? null;
        $utilitiesJson = null;
        if (is_string($rawUtilities)) {
            $dec = json_decode($rawUtilities, true);
            if (is_array($dec)) {
                $utilitiesJson = json_encode(array_values(array_filter($dec, static function ($item) {
                    return is_array($item) && trim((string) ($item['name'] ?? '')) !== '';
                })));
            }
        } elseif (is_array($rawUtilities)) {
            $utilitiesJson = json_encode(array_values(array_filter($rawUtilities, static function ($item) {
                return is_array($item) && trim((string) ($item['name'] ?? '')) !== '';
            })));
        }

        $rawAmenities = $_POST['amenities'] ?? null;
        $amenitiesJson = null;
        if (is_string($rawAmenities)) {
            $dec = json_decode($rawAmenities, true);
            if (is_array($dec)) {
                $amenitiesJson = json_encode(array_values(array_filter(array_map('trim', $dec), static function ($val) {
                    return $val !== '';
                })));
            }
        } elseif (is_array($rawAmenities)) {
            $amenitiesJson = json_encode(array_values(array_filter(array_map('trim', $rawAmenities), static function ($val) {
                return $val !== '';
            })));
        }

        fetch_or_404($pdo, 'tbl_properties', $businessId, $propertyId, 'Property');
        if ($name === '') {
            respond(422, ['ok' => false, 'message' => 'Unit name is required.']);
        }
        if ($action === 'unit_update') {
            $id = (int) ($_POST['unit_id'] ?? 0);
            fetch_or_404($pdo, 'tbl_property_units', $businessId, $id, 'Unit');
            $pdo->prepare('UPDATE tbl_property_units SET name = :name, charge_type = :ct, rate = :rate, notes = :notes, utilities = :util, amenities = :amen WHERE id = :id AND business_id = :bid')
                ->execute([':name' => $name, ':ct' => $chargeType, ':rate' => $rate, ':notes' => $notes !== '' ? $notes : null, ':util' => $utilitiesJson, ':amen' => $amenitiesJson, ':id' => $id, ':bid' => $businessId]);
        } else {
            $pdo->prepare('INSERT INTO tbl_property_units (business_id, property_id, name, charge_type, rate, status, notes, utilities, amenities) VALUES (:bid, :pid, :name, :ct, :rate, "vacant", :notes, :util, :amen)')
                ->execute([':bid' => $businessId, ':pid' => $propertyId, ':name' => $name, ':ct' => $chargeType, ':rate' => $rate, ':notes' => $notes !== '' ? $notes : null, ':util' => $utilitiesJson, ':amen' => $amenitiesJson]);
        }
        respond($action === 'unit_create' ? 201 : 200, ['ok' => true, 'message' => $action === 'unit_create' ? 'Unit added.' : 'Unit updated.', 'units' => load_units($pdo, $businessId, $propertyId)]);
    }

    if ($action === 'unit_delete') {
        $id = (int) ($_POST['unit_id'] ?? 0);
        $unit = fetch_or_404($pdo, 'tbl_property_units', $businessId, $id, 'Unit');
        $active = $pdo->prepare('SELECT COUNT(*) FROM tbl_tenancies WHERE unit_id = :id AND business_id = :bid AND status = "active"');
        $active->execute([':id' => $id, ':bid' => $businessId]);
        if ((int) $active->fetchColumn() > 0) {
            respond(422, ['ok' => false, 'message' => 'End the active tenancy on this unit before deleting it.']);
        }
        $pdo->prepare('DELETE FROM tbl_property_units WHERE id = :id AND business_id = :bid')->execute([':id' => $id, ':bid' => $businessId]);
        respond(200, ['ok' => true, 'message' => 'Unit deleted.', 'units' => load_units($pdo, $businessId, (int) $unit['property_id'])]);
    }

    // ---------- Tenancies ----------
    if ($action === 'tenancy_create') {
        $unitId = (int) ($_POST['unit_id'] ?? 0);
        $customerId = (int) ($_POST['customer_id'] ?? 0);
        $startDate = trim((string) ($_POST['start_date'] ?? date('Y-m-d')));
        $deposit = round((float) ($_POST['deposit'] ?? 0), 2);
        $notes = trim((string) ($_POST['notes'] ?? ''));

        $unit = fetch_or_404($pdo, 'tbl_property_units', $businessId, $unitId, 'Unit');
        if ($unit['status'] === 'occupied') {
            respond(422, ['ok' => false, 'message' => 'That unit already has an active tenant.']);
        }
        $cust = $pdo->prepare('SELECT id FROM tbl_customers WHERE id = :cid AND business_id = :bid LIMIT 1');
        $cust->execute([':cid' => $customerId, ':bid' => $businessId]);
        if (!$cust->fetch()) {
            respond(404, ['ok' => false, 'message' => 'Select a tenant.']);
        }

        $rate = round((float) ($_POST['rate'] ?? $unit['rate']), 2);
        $chargeType = (string) ($_POST['charge_type'] ?? $unit['charge_type']);
        $chargeType = in_array($chargeType, ['daily', 'monthly'], true) ? $chargeType : (string) $unit['charge_type'];

        $pdo->beginTransaction();
        try {
            $ins = $pdo->prepare(
                'INSERT INTO tbl_tenancies (business_id, property_id, unit_id, customer_id, charge_type, rate, start_date, deposit, status, notes)
                 VALUES (:bid, :pid, :uid, :cid, :ct, :rate, :start, :dep, "active", :notes)'
            );
            $ins->execute([
                ':bid' => $businessId, ':pid' => (int) $unit['property_id'], ':uid' => $unitId, ':cid' => $customerId,
                ':ct' => $chargeType, ':rate' => $rate, ':start' => $startDate, ':dep' => $deposit, ':notes' => $notes !== '' ? $notes : null,
            ]);
            $pdo->prepare('UPDATE tbl_property_units SET status = "occupied" WHERE id = :id AND business_id = :bid')->execute([':id' => $unitId, ':bid' => $businessId]);
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
        respond(201, [
            'ok' => true,
            'message' => 'Tenancy created.',
            'tenancies' => load_tenancies($pdo, $businessId),
            'tenants' => load_rent_overview($pdo, $businessId),
            'units' => load_units($pdo, $businessId),
            'summary' => load_summary($pdo, $businessId),
        ]);
    }

    if ($action === 'tenancy_end') {
        $id = (int) ($_POST['tenancy_id'] ?? 0);
        $tenancy = fetch_or_404($pdo, 'tbl_tenancies', $businessId, $id, 'Tenancy');
        if ($tenancy['status'] !== 'active') {
            respond(422, ['ok' => false, 'message' => 'This tenancy is already ended.']);
        }
        $pdo->beginTransaction();
        try {
            $pdo->prepare('UPDATE tbl_tenancies SET status = "ended", end_date = :end WHERE id = :id AND business_id = :bid')
                ->execute([':end' => date('Y-m-d'), ':id' => $id, ':bid' => $businessId]);
            $pdo->prepare('UPDATE tbl_property_units SET status = "vacant" WHERE id = :id AND business_id = :bid')
                ->execute([':id' => (int) $tenancy['unit_id'], ':bid' => $businessId]);
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
        respond(200, [
            'ok' => true,
            'message' => 'Tenancy ended.',
            'tenancies' => load_tenancies($pdo, $businessId),
            'tenants' => load_rent_overview($pdo, $businessId),
            'units' => load_units($pdo, $businessId),
            'summary' => load_summary($pdo, $businessId),
        ]);
    }

    // ---------- Rent payments (auto-post into Bank & Cash) ----------
    if ($action === 'rent_payment_create') {
        $tenancyId = (int) ($_POST['tenancy_id'] ?? 0);
        $amount = round((float) ($_POST['amount'] ?? 0), 2);
        $periodLabel = trim((string) ($_POST['period_label'] ?? ''));
        $periodStart = trim((string) ($_POST['period_start'] ?? ''));
        $periodEnd = trim((string) ($_POST['period_end'] ?? ''));
        $isDate = static fn ($s) => (bool) preg_match('/^\d{4}-\d{2}-\d{2}$/', $s);
        $periodStart = $isDate($periodStart) ? $periodStart : null;
        $periodEnd = $isDate($periodEnd) ? $periodEnd : null;
        $paidDate = trim((string) ($_POST['paid_date'] ?? date('Y-m-d')));
        $notes = trim((string) ($_POST['notes'] ?? ''));
        $requestedAccountId = (int) ($_POST['account_id'] ?? 0);

        $tenancy = fetch_or_404($pdo, 'tbl_tenancies', $businessId, $tenancyId, 'Tenancy');
        if ($amount <= 0) {
            respond(422, ['ok' => false, 'message' => 'Enter an amount greater than zero.']);
        }

        // Resolve the destination account.
        $accountId = 0;
        if ($requestedAccountId > 0) {
            $accChk = $pdo->prepare('SELECT id FROM tbl_accounts WHERE id = :id AND business_id = :bid AND status = "active" LIMIT 1');
            $accChk->execute([':id' => $requestedAccountId, ':bid' => $businessId]);
            $accountId = (int) ($accChk->fetchColumn() ?: 0);
        }
        if ($accountId <= 0) {
            $accountId = ensure_default_account($pdo, $businessId);
        }

        $pdo->beginTransaction();
        try {
            $txnId = post_account_txn(
                $pdo, $businessId, $accountId, 'in', 'rent', $amount, 'RENT', (string) $tenancyId,
                ('Rent' . ($periodLabel !== '' ? ' ' . $periodLabel : '') . ($notes !== '' ? ' — ' . $notes : '')), $userId
            );
            $ins = $pdo->prepare(
                'INSERT INTO tbl_rent_payments (business_id, tenancy_id, unit_id, property_id, customer_id, amount, period_label, period_start, period_end, paid_date, account_id, account_txn_id, notes, created_by)
                 VALUES (:bid, :ten, :uid, :pid, :cid, :amt, :period, :pstart, :pend, :paid, :acc, :txn, :notes, :by)'
            );
            $ins->execute([
                ':bid' => $businessId, ':ten' => $tenancyId, ':uid' => (int) $tenancy['unit_id'], ':pid' => (int) $tenancy['property_id'],
                ':cid' => (int) $tenancy['customer_id'], ':amt' => $amount, ':period' => $periodLabel !== '' ? $periodLabel : null,
                ':pstart' => $periodStart, ':pend' => $periodEnd,
                ':paid' => $paidDate, ':acc' => $accountId, ':txn' => $txnId, ':notes' => $notes !== '' ? $notes : null, ':by' => $userId,
            ]);
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
        respond(201, [
            'ok' => true,
            'message' => 'Rent payment recorded.',
            'payments' => load_payments($pdo, $businessId),
            'tenants' => load_rent_overview($pdo, $businessId),
            'summary' => load_summary($pdo, $businessId),
        ]);
    }

    if ($action === 'rent_payment_delete') {
        $id = (int) ($_POST['payment_id'] ?? 0);
        $payment = fetch_or_404($pdo, 'tbl_rent_payments', $businessId, $id, 'Payment');
        $pdo->beginTransaction();
        try {
            if ($payment['account_txn_id'] !== null) {
                $pdo->prepare('DELETE FROM tbl_account_transactions WHERE id = :id AND business_id = :bid')
                    ->execute([':id' => (int) $payment['account_txn_id'], ':bid' => $businessId]);
            }
            $pdo->prepare('DELETE FROM tbl_rent_payments WHERE id = :id AND business_id = :bid')->execute([':id' => $id, ':bid' => $businessId]);
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
        respond(200, [
            'ok' => true,
            'message' => 'Rent payment deleted.',
            'payments' => load_payments($pdo, $businessId),
            'tenants' => load_rent_overview($pdo, $businessId),
            'summary' => load_summary($pdo, $businessId),
        ]);
    }

    // ---------- Tenant quick-create (into tbl_customers) ----------
    if ($action === 'tenant_quick_create') {
        $name = trim((string) ($_POST['full_name'] ?? ''));
        $phone = trim((string) ($_POST['phone'] ?? ''));
        if ($name === '') {
            respond(422, ['ok' => false, 'message' => 'Tenant name is required.']);
        }
        $ins = $pdo->prepare('INSERT INTO tbl_customers (business_id, full_name, phone, total_sales, sales_count) VALUES (:bid, :name, :phone, 0.00, 0)');
        $ins->execute([':bid' => $businessId, ':name' => $name, ':phone' => $phone !== '' ? $phone : null]);
        respond(201, ['ok' => true, 'message' => 'Tenant added.', 'customer' => ['id' => (int) $pdo->lastInsertId(), 'full_name' => $name, 'phone' => $phone]]);
    }

    // ---------- Staff ----------
    if ($action === 'staff_create' || $action === 'staff_update') {
        $name = trim((string) ($_POST['name'] ?? ''));
        $phone = trim((string) ($_POST['phone'] ?? ''));
        $role = trim((string) ($_POST['role'] ?? 'caretaker'));
        $propertyId = (int) ($_POST['property_id'] ?? 0);
        $notes = trim((string) ($_POST['notes'] ?? ''));
        if ($name === '') {
            respond(422, ['ok' => false, 'message' => 'Staff name is required.']);
        }
        if ($propertyId > 0) {
            fetch_or_404($pdo, 'tbl_properties', $businessId, $propertyId, 'Property');
        }
        if ($action === 'staff_update') {
            $id = (int) ($_POST['staff_id'] ?? 0);
            fetch_or_404($pdo, 'tbl_realestate_staff', $businessId, $id, 'Staff member');
            $pdo->prepare('UPDATE tbl_realestate_staff SET name = :name, phone = :phone, role = :role, property_id = :pid, notes = :notes WHERE id = :id AND business_id = :bid')
                ->execute([':name' => $name, ':phone' => $phone !== '' ? $phone : null, ':role' => $role !== '' ? $role : 'caretaker', ':pid' => $propertyId > 0 ? $propertyId : null, ':notes' => $notes !== '' ? $notes : null, ':id' => $id, ':bid' => $businessId]);
        } else {
            $pdo->prepare('INSERT INTO tbl_realestate_staff (business_id, name, phone, role, property_id, notes, status) VALUES (:bid, :name, :phone, :role, :pid, :notes, "active")')
                ->execute([':bid' => $businessId, ':name' => $name, ':phone' => $phone !== '' ? $phone : null, ':role' => $role !== '' ? $role : 'caretaker', ':pid' => $propertyId > 0 ? $propertyId : null, ':notes' => $notes !== '' ? $notes : null]);
        }
        respond($action === 'staff_create' ? 201 : 200, ['ok' => true, 'message' => $action === 'staff_create' ? 'Staff added.' : 'Staff updated.']);
    }

    if ($action === 'staff_delete') {
        $id = (int) ($_POST['staff_id'] ?? 0);
        fetch_or_404($pdo, 'tbl_realestate_staff', $businessId, $id, 'Staff member');
        $pdo->prepare('DELETE FROM tbl_realestate_staff WHERE id = :id AND business_id = :bid')->execute([':id' => $id, ':bid' => $businessId]);
        respond(200, ['ok' => true, 'message' => 'Staff removed.']);
    }

    if ($action === 'send_rent_reminder') {
        $tenancyId = (int) ($_POST['tenancy_id'] ?? 0);
        if ($tenancyId <= 0) {
            respond(422, ['ok' => false, 'message' => 'Valid tenancy ID required.']);
        }
        $tStmt = $pdo->prepare(
            'SELECT t.*, u.name AS unit_name, p.name AS property_name, c.full_name AS tenant_name, c.phone AS tenant_phone, c.email AS tenant_email,
                    (SELECT COALESCE(SUM(amount), 0) FROM tbl_rent_payments r WHERE r.tenancy_id = t.id) AS total_paid,
                    (SELECT MAX(period_end) FROM tbl_rent_payments r WHERE r.tenancy_id = t.id AND r.period_end IS NOT NULL) AS recorded_paid_until
             FROM tbl_tenancies t
             JOIN tbl_property_units u ON u.id = t.unit_id
             JOIN tbl_properties p ON p.id = t.property_id
             JOIN tbl_customers c ON c.id = t.customer_id
             WHERE t.id = :id AND t.business_id = :bid LIMIT 1'
        );
        $tStmt->execute([':id' => $tenancyId, ':bid' => $businessId]);
        $t = $tStmt->fetch(PDO::FETCH_ASSOC);
        if (!$t) {
            respond(404, ['ok' => false, 'message' => 'Tenancy not found.']);
        }

        $sendSms = !empty($_POST['send_sms']) && $_POST['send_sms'] !== 'false' && $_POST['send_sms'] !== '0';
        $sendEmail = !empty($_POST['send_email']) && $_POST['send_email'] !== 'false' && $_POST['send_email'] !== '0';
        $toTenant = !empty($_POST['to_tenant']) && $_POST['to_tenant'] !== 'false' && $_POST['to_tenant'] !== '0';
        $toLandlord = !empty($_POST['to_landlord']) && $_POST['to_landlord'] !== 'false' && $_POST['to_landlord'] !== '0';

        if (!$sendSms && !$sendEmail) {
            respond(422, ['ok' => false, 'message' => 'Select at least one delivery channel (SMS or Email).']);
        }
        if (!$toTenant && !$toLandlord) {
            respond(422, ['ok' => false, 'message' => 'Select at least one recipient (Tenant or Landlord).']);
        }

        $math = compute_tenancy_rent_math((string) $t['start_date'], (float) $t['rate'], (string) $t['charge_type'], (float) $t['total_paid'], $t['recorded_paid_until']);

        $tenantPhone = trim((string) ($_POST['tenant_phone'] ?? $t['tenant_phone'] ?? ''));
        $tenantEmail = trim((string) ($_POST['tenant_email'] ?? $t['tenant_email'] ?? ''));

        $landlordContact = get_business_landlord_contact($pdo, $businessId, $userId);
        $landlordPhone = trim((string) ($_POST['landlord_phone'] ?? $landlordContact['phone'] ?? ''));
        $landlordEmail = trim((string) ($_POST['landlord_email'] ?? $landlordContact['email'] ?? ''));

        $smsMessage = trim((string) ($_POST['sms_message'] ?? ''));
        if ($smsMessage === '') {
            $formattedRate = number_format((float) $t['rate'], 0) . ' TZS';
            $smsMessage = "Dear {$t['tenant_name']}, rent reminder for {$t['property_name']} ({$t['unit_name']}). Rent {$formattedRate} is expected by {$math['expected_date']}. Please make payment on time. Thank you!";
        }

        $emailSubject = trim((string) ($_POST['email_subject'] ?? ''));
        if ($emailSubject === '') {
            $emailSubject = "Rent Reminder: {$t['property_name']} ({$t['unit_name']})";
        }

        $smsRecipients = [];
        if ($sendSms) {
            if ($toTenant && $tenantPhone !== '') {
                $smsRecipients[] = ['role' => 'tenant', 'phone' => $tenantPhone, 'name' => (string) $t['tenant_name']];
            }
            if ($toLandlord && $landlordPhone !== '') {
                $smsRecipients[] = ['role' => 'landlord', 'phone' => $landlordPhone, 'name' => $landlordContact['name']];
            }
        }

        $emailRecipients = [];
        if ($sendEmail) {
            if ($toTenant && filter_var($tenantEmail, FILTER_VALIDATE_EMAIL)) {
                $emailRecipients[] = ['role' => 'tenant', 'email' => $tenantEmail, 'name' => (string) $t['tenant_name']];
            }
            if ($toLandlord && filter_var($landlordEmail, FILTER_VALIDATE_EMAIL)) {
                $emailRecipients[] = ['role' => 'landlord', 'email' => $landlordEmail, 'name' => $landlordContact['name']];
            }
        }

        if ($sendSms && empty($smsRecipients)) {
            respond(422, ['ok' => false, 'message' => 'No valid phone numbers found for SMS delivery.']);
        }
        if ($sendEmail && empty($emailRecipients)) {
            respond(422, ['ok' => false, 'message' => 'No valid email addresses found for Email delivery.']);
        }

        $accountId = ensure_business_account($pdo, $businessId);
        $smsBalance = get_business_sms_balance($pdo, $businessId);

        if ($sendSms) {
            $neededSms = count($smsRecipients);
            if ($smsBalance < $neededSms) {
                respond(422, [
                    'ok' => false,
                    'message' => "Insufficient SMS balance ({$smsBalance} SMS available, {$neededSms} needed). Please top up SMS bundle in Billing or uncheck SMS to send email only.",
                    'sms_balance' => $smsBalance,
                ]);
            }
        }

        $smsSentCount = 0;
        $smsErrors = [];
        if ($sendSms && !empty($smsRecipients)) {
            $smsConfig = get_sms_gateway_config($pdo, $businessId);
            if (empty($smsConfig['api_key'])) {
                respond(422, ['ok' => false, 'message' => 'MegaSMS API key is not configured. Please save SMS gateway settings.']);
            }
            foreach ($smsRecipients as $rcpt) {
                $res = send_megasms($smsConfig, $rcpt['phone'], $smsMessage);
                if ($res['ok']) {
                    deduct_sms_wallet($pdo, $accountId, $businessId, 1, "Rent reminder for {$t['unit_name']} to {$rcpt['role']}");
                    $smsSentCount++;
                } else {
                    $smsErrors[] = "Failed sending SMS to {$rcpt['phone']}";
                }
            }
        }

        $emailSentCount = 0;
        $emailErrors = [];
        if ($sendEmail && !empty($emailRecipients)) {
            $smtpConfig = get_smtp_config($pdo, $businessId);
            $formattedRate = number_format((float) $t['rate'], 0) . ' TZS';
            $htmlBody = "
            <div style='font-family:-apple-system,BlinkMacSystemFont,Segoe UI,Roboto,sans-serif;max-width:560px;margin:0 auto;padding:24px;background:#ffffff;border:1px solid #e2e8f0;border-radius:12px;'>
                <div style='border-bottom:2px solid #2563eb;padding-bottom:12px;margin-bottom:20px;'>
                    <h2 style='margin:0;color:#1e293b;font-size:1.3rem;'>Rent Reminder Notice</h2>
                    <small style='color:#64748b;'>{$t['property_name']} &bull; Unit: {$t['unit_name']}</small>
                </div>
                <p style='color:#334155;font-size:0.95rem;line-height:1.5;'>
                    Hello <strong>" . htmlspecialchars($t['tenant_name'], ENT_QUOTES, 'UTF-8') . "</strong>,
                </p>
                <p style='color:#334155;font-size:0.92rem;line-height:1.5;'>
                    This is an automated reminder regarding the rent payment for <strong>" . htmlspecialchars($t['property_name'] . ' - ' . $t['unit_name'], ENT_QUOTES, 'UTF-8') . "</strong>.
                </p>
                <div style='background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;padding:14px;margin:18px 0;'>
                    <table style='width:100%;font-size:0.9rem;color:#334155;'>
                        <tr><td style='padding:4px 0;color:#64748b;'>Rent Amount:</td><td style='text-align:right;font-weight:700;color:#2563eb;'>{$formattedRate} (" . htmlspecialchars($t['charge_type'], ENT_QUOTES, 'UTF-8') . ")</td></tr>
                        <tr><td style='padding:4px 0;color:#64748b;'>Expected Payment Date:</td><td style='text-align:right;font-weight:700;color:#1e293b;'>{$math['expected_date']}</td></tr>
                        <tr><td style='padding:4px 0;color:#64748b;'>Stay Duration:</td><td style='text-align:right;font-weight:600;color:#1e293b;'>{$math['stay_duration']}</td></tr>
                        <tr><td style='padding:4px 0;color:#64748b;'>Total Paid So Far:</td><td style='text-align:right;font-weight:600;color:#1e293b;'>" . number_format((float) $t['total_paid'], 0) . " TZS ({$math['periods_covered']} periods)</td></tr>
                    </table>
                </div>
                <p style='color:#64748b;font-size:0.85rem;line-height:1.5;'>
                    Please ensure payment is completed by the expected date. Thank you for your cooperation!
                </p>
                <div style='margin-top:24px;padding-top:12px;border-top:1px solid #e2e8f0;font-size:0.8rem;color:#94a3b8;'>
                    " . htmlspecialchars($landlordContact['business_name'] ?: 'Property Management', ENT_QUOTES, 'UTF-8') . "
                </div>
            </div>";
            $textBody = "Rent Reminder: {$t['property_name']} ({$t['unit_name']})\nTenant: {$t['tenant_name']}\nExpected Date: {$math['expected_date']}\nAmount: {$formattedRate}\nPlease make timely payment. Thank you!";

            foreach ($emailRecipients as $ercpt) {
                $eRes = send_rent_email($smtpConfig, $ercpt['email'], $ercpt['name'], $emailSubject, $htmlBody, $textBody, (string) ($landlordContact['business_name'] ?? ''));
                if ($eRes['ok']) {
                    $emailSentCount++;
                } else {
                    $emailErrors[] = $eRes['message'] ?? "Failed sending email to {$ercpt['email']}";
                }
            }
        }

        $newSmsBalance = get_business_sms_balance($pdo, $businessId);
        $summaryParts = [];
        if ($smsSentCount > 0) $summaryParts[] = "{$smsSentCount} SMS sent";
        if ($emailSentCount > 0) $summaryParts[] = "{$emailSentCount} email(s) sent";
        $msg = !empty($summaryParts) ? implode(' and ', $summaryParts) . ' successfully.' : 'Reminder processed.';
        if (!empty($smsErrors) || !empty($emailErrors)) {
            $msg .= ' (' . implode(', ', array_merge($smsErrors, $emailErrors)) . ')';
        }

        respond(200, [
            'ok' => true,
            'message' => $msg,
            'sms_sent' => $smsSentCount,
            'email_sent' => $emailSentCount,
            'sms_balance' => $newSmsBalance,
        ]);
    }

    respond(422, ['ok' => false, 'message' => 'Unknown real estate action.']);
} catch (Throwable $error) {
    respond(500, ['ok' => false, 'message' => $error->getMessage() ?: 'Unable to process real estate request right now.']);
}
