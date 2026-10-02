<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/realestate_lib.php';
require_once __DIR__ . '/accounts_lib.php';

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
        'tenant_id' => isset($row['tenant_id']) && $row['tenant_id'] !== null ? (int) $row['tenant_id'] : null,
        'tenant_name' => (string) ($row['tenant_name'] ?? ''),
        'tenancy_id' => isset($row['tenancy_id']) && $row['tenancy_id'] !== null ? (int) $row['tenancy_id'] : null,
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
                t.id AS tenancy_id, t.customer_id AS tenant_id, c.full_name AS tenant_name
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
    ensure_accounts_tables($pdo);
    ensure_rent_txn_type($pdo);

    $businessId = active_business_id($pdo, $userId);
    if ($businessId <= 0) {
        respond(422, ['ok' => false, 'message' => 'No active business selected.']);
    }

    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $resource = trim((string) ($_GET['resource'] ?? ''));

        // Property detail (property + its units).
        if (isset($_GET['id'])) {
            $propertyId = (int) $_GET['id'];
            $property = fetch_or_404($pdo, 'tbl_properties', $businessId, $propertyId, 'Property');
            $units = load_units($pdo, $businessId, $propertyId);
            $out = property_payload($property);
            $out['units'] = $units;
            respond(200, ['ok' => true, 'property' => $out]);
        }

        if ($resource === 'summary') {
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
            respond(200, ['ok' => true, 'summary' => [
                'properties' => $propCount,
                'units_total' => (int) ($u['total'] ?? 0),
                'units_occupied' => (int) ($u['occupied'] ?? 0),
                'active_tenancies' => $activeTenancies,
                'rent_collected_month' => (float) $rentStmt->fetchColumn(),
            ]]);
        }

        if ($resource === 'properties') {
            respond(200, ['ok' => true, 'properties' => load_properties($pdo, $businessId)]);
        }

        if ($resource === 'units') {
            $propertyId = isset($_GET['property_id']) ? (int) $_GET['property_id'] : null;
            respond(200, ['ok' => true, 'units' => load_units($pdo, $businessId, $propertyId)]);
        }

        if ($resource === 'tenancies') {
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
            $tenancies = array_map(static function ($t) {
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
            respond(200, ['ok' => true, 'tenancies' => $tenancies]);
        }

        if ($resource === 'payments') {
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
            $payments = array_map(static function ($r) {
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
            respond(200, ['ok' => true, 'payments' => $payments]);
        }

        if ($resource === 'staff') {
            $stmt = $pdo->prepare(
                'SELECT s.*, p.name AS property_name FROM tbl_realestate_staff s
                 LEFT JOIN tbl_properties p ON p.id = s.property_id
                 WHERE s.business_id = :bid ORDER BY s.name ASC'
            );
            $stmt->execute([':bid' => $businessId]);
            $staff = array_map(static function ($s) {
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
            respond(200, ['ok' => true, 'staff' => $staff]);
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
        fetch_or_404($pdo, 'tbl_properties', $businessId, $propertyId, 'Property');
        if ($name === '') {
            respond(422, ['ok' => false, 'message' => 'Unit name is required.']);
        }
        if ($action === 'unit_update') {
            $id = (int) ($_POST['unit_id'] ?? 0);
            fetch_or_404($pdo, 'tbl_property_units', $businessId, $id, 'Unit');
            $pdo->prepare('UPDATE tbl_property_units SET name = :name, charge_type = :ct, rate = :rate, notes = :notes WHERE id = :id AND business_id = :bid')
                ->execute([':name' => $name, ':ct' => $chargeType, ':rate' => $rate, ':notes' => $notes !== '' ? $notes : null, ':id' => $id, ':bid' => $businessId]);
        } else {
            $pdo->prepare('INSERT INTO tbl_property_units (business_id, property_id, name, charge_type, rate, status, notes) VALUES (:bid, :pid, :name, :ct, :rate, "vacant", :notes)')
                ->execute([':bid' => $businessId, ':pid' => $propertyId, ':name' => $name, ':ct' => $chargeType, ':rate' => $rate, ':notes' => $notes !== '' ? $notes : null]);
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
        respond(201, ['ok' => true, 'message' => 'Tenancy created.']);
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
        respond(200, ['ok' => true, 'message' => 'Tenancy ended.']);
    }

    // ---------- Rent payments (auto-post into Bank & Cash) ----------
    if ($action === 'rent_payment_create') {
        $tenancyId = (int) ($_POST['tenancy_id'] ?? 0);
        $amount = round((float) ($_POST['amount'] ?? 0), 2);
        $periodLabel = trim((string) ($_POST['period_label'] ?? ''));
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
                'INSERT INTO tbl_rent_payments (business_id, tenancy_id, unit_id, property_id, customer_id, amount, period_label, paid_date, account_id, account_txn_id, notes, created_by)
                 VALUES (:bid, :ten, :uid, :pid, :cid, :amt, :period, :paid, :acc, :txn, :notes, :by)'
            );
            $ins->execute([
                ':bid' => $businessId, ':ten' => $tenancyId, ':uid' => (int) $tenancy['unit_id'], ':pid' => (int) $tenancy['property_id'],
                ':cid' => (int) $tenancy['customer_id'], ':amt' => $amount, ':period' => $periodLabel !== '' ? $periodLabel : null,
                ':paid' => $paidDate, ':acc' => $accountId, ':txn' => $txnId, ':notes' => $notes !== '' ? $notes : null, ':by' => $userId,
            ]);
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
        respond(201, ['ok' => true, 'message' => 'Rent payment recorded.']);
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
        respond(200, ['ok' => true, 'message' => 'Rent payment deleted.']);
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

    respond(422, ['ok' => false, 'message' => 'Unknown real estate action.']);
} catch (Throwable $error) {
    respond(500, ['ok' => false, 'message' => 'Unable to process real estate request right now.']);
}
