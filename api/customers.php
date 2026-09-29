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

function customer_payload(array $row): array
{
    return [
        'id' => (int) $row['id'],
        'business_id' => (int) $row['business_id'],
        'full_name' => (string) $row['full_name'],
        'phone' => (string) ($row['phone'] ?? ''),
        'email' => (string) ($row['email'] ?? ''),
        'address' => (string) ($row['address'] ?? ''),
        'notes' => (string) ($row['notes'] ?? ''),
        'total_sales' => (float) ($row['total_sales'] ?? 0),
        'sales_count' => (int) ($row['sales_count'] ?? 0),
        'created_at' => (string) ($row['created_at'] ?? ''),
        'updated_at' => (string) ($row['updated_at'] ?? ''),
    ];
}

try {
    $userId = require_user();
    $pdo = db();
    $businessId = get_active_business_id($pdo, $userId);

    if ($businessId <= 0) {
        respond(400, ['ok' => false, 'message' => 'No active business selected.']);
    }

    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $customerId = isset($_GET['id']) ? (int) $_GET['id'] : 0;
        if ($customerId > 0) {
            $stmt = $pdo->prepare('SELECT * FROM tbl_customers WHERE id = :id AND business_id = :bid LIMIT 1');
            $stmt->execute([':id' => $customerId, ':bid' => $businessId]);
            $row = $stmt->fetch();
            if (!$row) {
                respond(404, ['ok' => false, 'message' => 'Customer not found.']);
            }
            respond(200, ['ok' => true, 'customer' => customer_payload($row)]);
        }

        $search = trim((string) ($_GET['q'] ?? ''));
        if ($search !== '') {
            $stmt = $pdo->prepare(
                'SELECT * FROM tbl_customers 
                 WHERE business_id = :bid AND (full_name LIKE :q OR phone LIKE :q OR email LIKE :q) 
                 ORDER BY full_name ASC'
            );
            $stmt->execute([':bid' => $businessId, ':q' => '%' . $search . '%']);
        } else {
            $stmt = $pdo->prepare('SELECT * FROM tbl_customers WHERE business_id = :bid ORDER BY full_name ASC');
            $stmt->execute([':bid' => $businessId]);
        }

        $customers = array_map('customer_payload', $stmt->fetchAll());
        respond(200, [
            'ok' => true,
            'business_id' => $businessId,
            'customers' => $customers,
            'total' => count($customers),
        ]);
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        respond(405, ['ok' => false, 'message' => 'Method not allowed.']);
    }

    $action = trim((string) ($_POST['action'] ?? 'create'));

    if ($action === 'create') {
        $fullName = trim((string) ($_POST['full_name'] ?? ''));
        $phone = trim((string) ($_POST['phone'] ?? ''));
        $email = trim((string) ($_POST['email'] ?? ''));
        $address = trim((string) ($_POST['address'] ?? ''));
        $notes = trim((string) ($_POST['notes'] ?? ''));

        if ($fullName === '') {
            respond(422, ['ok' => false, 'message' => 'Customer name is required.']);
        }

        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            respond(422, ['ok' => false, 'message' => 'Please provide a valid email address.']);
        }

        $stmt = $pdo->prepare(
            'INSERT INTO tbl_customers (business_id, full_name, phone, email, address, notes, total_sales, sales_count) 
             VALUES (:bid, :full_name, :phone, :email, :address, :notes, 0.00, 0)'
        );
        $stmt->execute([
            ':bid' => $businessId,
            ':full_name' => $fullName,
            ':phone' => $phone !== '' ? $phone : null,
            ':email' => $email !== '' ? $email : null,
            ':address' => $address !== '' ? $address : null,
            ':notes' => $notes !== '' ? $notes : null,
        ]);

        $newId = (int) $pdo->lastInsertId();
        $stmt = $pdo->prepare('SELECT * FROM tbl_customers WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $newId]);
        $customer = $stmt->fetch();

        respond(201, [
            'ok' => true,
            'message' => 'Customer added successfully.',
            'customer' => $customer ? customer_payload($customer) : null,
        ]);
    }

    if ($action === 'update') {
        $customerId = (int) ($_POST['customer_id'] ?? 0);
        $fullName = trim((string) ($_POST['full_name'] ?? ''));
        $phone = trim((string) ($_POST['phone'] ?? ''));
        $email = trim((string) ($_POST['email'] ?? ''));
        $address = trim((string) ($_POST['address'] ?? ''));
        $notes = trim((string) ($_POST['notes'] ?? ''));

        if ($customerId <= 0) {
            respond(422, ['ok' => false, 'message' => 'Invalid customer ID.']);
        }

        if ($fullName === '') {
            respond(422, ['ok' => false, 'message' => 'Customer name is required.']);
        }

        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            respond(422, ['ok' => false, 'message' => 'Please provide a valid email address.']);
        }

        $stmt = $pdo->prepare(
            'UPDATE tbl_customers 
             SET full_name = :full_name, phone = :phone, email = :email, address = :address, notes = :notes 
             WHERE id = :id AND business_id = :bid'
        );
        $stmt->execute([
            ':id' => $customerId,
            ':bid' => $businessId,
            ':full_name' => $fullName,
            ':phone' => $phone !== '' ? $phone : null,
            ':email' => $email !== '' ? $email : null,
            ':address' => $address !== '' ? $address : null,
            ':notes' => $notes !== '' ? $notes : null,
        ]);

        $stmt = $pdo->prepare('SELECT * FROM tbl_customers WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $customerId]);
        $customer = $stmt->fetch();

        respond(200, [
            'ok' => true,
            'message' => 'Customer updated successfully.',
            'customer' => $customer ? customer_payload($customer) : null,
        ]);
    }

    if ($action === 'delete') {
        $customerId = (int) ($_POST['customer_id'] ?? 0);
        if ($customerId <= 0) {
            respond(422, ['ok' => false, 'message' => 'Invalid customer ID.']);
        }

        $stmt = $pdo->prepare('DELETE FROM tbl_customers WHERE id = :id AND business_id = :bid');
        $stmt->execute([':id' => $customerId, ':bid' => $businessId]);

        if ($stmt->rowCount() === 0) {
            respond(404, ['ok' => false, 'message' => 'Customer not found or already deleted.']);
        }

        respond(200, [
            'ok' => true,
            'message' => 'Customer deleted successfully.',
        ]);
    }

    respond(400, ['ok' => false, 'message' => 'Unsupported action.']);
} catch (Throwable $e) {
    respond(500, ['ok' => false, 'message' => $e->getMessage()]);
}
