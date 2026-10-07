<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/idempotency_lib.php';
require_once __DIR__ . '/vat_lib.php';

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

function supplier_payload(array $row): array
{
    $name = (string) ($row['supplier_name'] ?? '');
    return [
        'id' => (int) $row['id'],
        'business_id' => (int) $row['business_id'],
        'supplier_name' => $name,
        'full_name' => $name,
        'contact_person' => (string) ($row['contact_person'] ?? ''),
        'phone' => (string) ($row['phone'] ?? ''),
        'email' => (string) ($row['email'] ?? ''),
        'address' => (string) ($row['address'] ?? ''),
        'tin' => (string) ($row['tin'] ?? ''),
        'vrn' => (string) ($row['vrn'] ?? ''),
        'notes' => (string) ($row['notes'] ?? ''),
        'total_purchases' => (float) ($row['total_purchases'] ?? 0),
        'purchases_count' => (int) ($row['purchases_count'] ?? 0),
        'created_at' => (string) ($row['created_at'] ?? ''),
        'updated_at' => (string) ($row['updated_at'] ?? ''),
    ];
}

function ensure_suppliers_table(PDO $pdo): void
{
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS tbl_suppliers (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            business_id BIGINT UNSIGNED NOT NULL,
            supplier_name VARCHAR(190) NOT NULL,
            contact_person VARCHAR(190) NULL,
            phone VARCHAR(50) NULL,
            email VARCHAR(190) NULL,
            address VARCHAR(255) NULL,
            notes TEXT NULL,
            total_purchases DECIMAL(15, 2) NOT NULL DEFAULT 0.00,
            purchases_count INT UNSIGNED NOT NULL DEFAULT 0,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            KEY idx_business (business_id),
            KEY idx_business_name (business_id, supplier_name),
            KEY idx_phone (phone)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );
}

try {
    $userId = require_user();
    $pdo = db();
    ensure_suppliers_table($pdo);
    ensure_party_tax_ids($pdo);
    $businessId = get_active_business_id($pdo, $userId);

    if ($businessId <= 0) {
        respond(400, ['ok' => false, 'message' => 'No active business selected.']);
    }

    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $supplierId = isset($_GET['id']) ? (int) $_GET['id'] : 0;
        if ($supplierId > 0) {
            $stmt = $pdo->prepare('SELECT * FROM tbl_suppliers WHERE id = :id AND business_id = :bid LIMIT 1');
            $stmt->execute([':id' => $supplierId, ':bid' => $businessId]);
            $row = $stmt->fetch();
            if (!$row) {
                respond(404, ['ok' => false, 'message' => 'Supplier not found.']);
            }
            respond(200, ['ok' => true, 'supplier' => supplier_payload($row)]);
        }

        $search = trim((string) ($_GET['q'] ?? ''));
        if ($search !== '') {
            $stmt = $pdo->prepare(
                'SELECT * FROM tbl_suppliers 
                 WHERE business_id = :bid AND (supplier_name LIKE :q OR contact_person LIKE :q OR phone LIKE :q OR email LIKE :q) 
                 ORDER BY supplier_name ASC'
            );
            $stmt->execute([':bid' => $businessId, ':q' => '%' . $search . '%']);
        } else {
            $stmt = $pdo->prepare('SELECT * FROM tbl_suppliers WHERE business_id = :bid ORDER BY supplier_name ASC');
            $stmt->execute([':bid' => $businessId]);
        }

        $suppliers = array_map('supplier_payload', $stmt->fetchAll());
        respond(200, [
            'ok' => true,
            'business_id' => $businessId,
            'suppliers' => $suppliers,
            'total' => count($suppliers),
        ]);
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        respond(405, ['ok' => false, 'message' => 'Method not allowed.']);
    }

    $action = trim((string) ($_POST['action'] ?? 'create'));

    if ($action === 'create') {
        $supplierName = trim((string) ($_POST['supplier_name'] ?? $_POST['full_name'] ?? ''));
        $contactPerson = trim((string) ($_POST['contact_person'] ?? ''));
        $phone = trim((string) ($_POST['phone'] ?? ''));
        $email = trim((string) ($_POST['email'] ?? ''));
        $address = trim((string) ($_POST['address'] ?? ''));
        $tin = trim((string) ($_POST['tin'] ?? ''));
        $vrn = trim((string) ($_POST['vrn'] ?? ''));
        $notes = trim((string) ($_POST['notes'] ?? ''));

        if ($supplierName === '') {
            respond(422, ['ok' => false, 'message' => 'Supplier name is required.']);
        }

        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            respond(422, ['ok' => false, 'message' => 'Please provide a valid email address.']);
        }

        $stmt = $pdo->prepare(
            'INSERT INTO tbl_suppliers (business_id, supplier_name, contact_person, phone, email, address, tin, vrn, notes, total_purchases, purchases_count)
             VALUES (:bid, :supplier_name, :contact_person, :phone, :email, :address, :tin, :vrn, :notes, 0.00, 0)'
        );
        $stmt->execute([
            ':bid' => $businessId,
            ':supplier_name' => $supplierName,
            ':contact_person' => $contactPerson !== '' ? $contactPerson : null,
            ':phone' => $phone !== '' ? $phone : null,
            ':email' => $email !== '' ? $email : null,
            ':address' => $address !== '' ? $address : null,
            ':tin' => $tin !== '' ? $tin : null,
            ':vrn' => $vrn !== '' ? $vrn : null,
            ':notes' => $notes !== '' ? $notes : null,
        ]);

        $newId = (int) $pdo->lastInsertId();
        $stmt = $pdo->prepare('SELECT * FROM tbl_suppliers WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $newId]);
        $supplier = $stmt->fetch();

        respond(201, [
            'ok' => true,
            'message' => 'Supplier added successfully.',
            'supplier' => $supplier ? supplier_payload($supplier) : null,
        ]);
    }

    if ($action === 'update') {
        $supplierId = (int) ($_POST['supplier_id'] ?? $_POST['id'] ?? 0);
        $supplierName = trim((string) ($_POST['supplier_name'] ?? $_POST['full_name'] ?? ''));
        $contactPerson = trim((string) ($_POST['contact_person'] ?? ''));
        $phone = trim((string) ($_POST['phone'] ?? ''));
        $email = trim((string) ($_POST['email'] ?? ''));
        $address = trim((string) ($_POST['address'] ?? ''));
        $tin = trim((string) ($_POST['tin'] ?? ''));
        $vrn = trim((string) ($_POST['vrn'] ?? ''));
        $notes = trim((string) ($_POST['notes'] ?? ''));

        if ($supplierId <= 0) {
            respond(422, ['ok' => false, 'message' => 'Invalid supplier ID.']);
        }

        if ($supplierName === '') {
            respond(422, ['ok' => false, 'message' => 'Supplier name is required.']);
        }

        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            respond(422, ['ok' => false, 'message' => 'Please provide a valid email address.']);
        }

        $stmt = $pdo->prepare(
            'UPDATE tbl_suppliers
             SET supplier_name = :supplier_name, contact_person = :contact_person, phone = :phone, email = :email, address = :address, tin = :tin, vrn = :vrn, notes = :notes
             WHERE id = :id AND business_id = :bid'
        );
        $stmt->execute([
            ':id' => $supplierId,
            ':bid' => $businessId,
            ':supplier_name' => $supplierName,
            ':contact_person' => $contactPerson !== '' ? $contactPerson : null,
            ':phone' => $phone !== '' ? $phone : null,
            ':email' => $email !== '' ? $email : null,
            ':address' => $address !== '' ? $address : null,
            ':tin' => $tin !== '' ? $tin : null,
            ':vrn' => $vrn !== '' ? $vrn : null,
            ':notes' => $notes !== '' ? $notes : null,
        ]);

        $stmt = $pdo->prepare('SELECT * FROM tbl_suppliers WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $supplierId]);
        $supplier = $stmt->fetch();

        respond(200, [
            'ok' => true,
            'message' => 'Supplier updated successfully.',
            'supplier' => $supplier ? supplier_payload($supplier) : null,
        ]);
    }

    if ($action === 'delete') {
        $supplierId = (int) ($_POST['supplier_id'] ?? $_POST['id'] ?? 0);
        if ($supplierId <= 0) {
            respond(422, ['ok' => false, 'message' => 'Invalid supplier ID.']);
        }

        $stmt = $pdo->prepare('DELETE FROM tbl_suppliers WHERE id = :id AND business_id = :bid');
        $stmt->execute([':id' => $supplierId, ':bid' => $businessId]);

        if ($stmt->rowCount() === 0) {
            respond(404, ['ok' => false, 'message' => 'Supplier not found or already deleted.']);
        }

        respond(200, [
            'ok' => true,
            'message' => 'Supplier deleted successfully.',
        ]);
    }

    respond(400, ['ok' => false, 'message' => 'Unsupported action.']);
} catch (Throwable $e) {
    respond(500, ['ok' => false, 'message' => $e->getMessage()]);
}
