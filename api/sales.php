<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';

session_start();

function respond(int $status, array $payload): void
{
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
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

function get_active_business(PDO $pdo, int $userId): array
{
    $businessId = isset($_SESSION['zipoo_business_id']) ? (int) $_SESSION['zipoo_business_id'] : 0;
    if ($businessId > 0) {
        $stmt = $pdo->prepare('SELECT * FROM tbl_businesses WHERE id = :bid AND (owner_user_id = :uid OR id = :check_bid) LIMIT 1');
        $stmt->execute([':bid' => $businessId, ':uid' => $userId, ':check_bid' => $businessId]);
        $b = $stmt->fetch();
        if ($b) {
            return $b;
        }
    }

    $stmt = $pdo->prepare('SELECT business_id FROM tbl_users WHERE id = :uid LIMIT 1');
    $stmt->execute([':uid' => $userId]);
    $userBid = (int) ($stmt->fetchColumn() ?: 0);
    if ($userBid > 0) {
        $stmt = $pdo->prepare('SELECT * FROM tbl_businesses WHERE id = :bid LIMIT 1');
        $stmt->execute([':bid' => $userBid]);
        $b = $stmt->fetch();
        if ($b) {
            $_SESSION['zipoo_business_id'] = $userBid;
            return $b;
        }
    }

    $stmt = $pdo->prepare('SELECT * FROM tbl_businesses WHERE owner_user_id = :uid ORDER BY id ASC LIMIT 1');
    $stmt->execute([':uid' => $userId]);
    $first = $stmt->fetch();
    if ($first) {
        $_SESSION['zipoo_business_id'] = (int) $first['id'];
        return $first;
    }

    return [];
}

function ensure_sales_tables(PDO $pdo): void
{
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS tbl_sales (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            business_id BIGINT UNSIGNED NOT NULL,
            sale_type ENUM("invoice", "pos") NOT NULL DEFAULT "invoice",
            invoice_number VARCHAR(50) NOT NULL,
            customer_id BIGINT UNSIGNED NULL,
            customer_name VARCHAR(190) NULL,
            status ENUM("draft", "sent", "paid", "overdue", "cancelled") NOT NULL DEFAULT "draft",
            issue_date DATE NOT NULL,
            due_date DATE NULL,
            subtotal DECIMAL(14, 2) NOT NULL DEFAULT 0.00,
            discount DECIMAL(14, 2) NOT NULL DEFAULT 0.00,
            tax_rate DECIMAL(6, 2) NOT NULL DEFAULT 0.00,
            tax_amount DECIMAL(14, 2) NOT NULL DEFAULT 0.00,
            total_amount DECIMAL(14, 2) NOT NULL DEFAULT 0.00,
            amount_paid DECIMAL(14, 2) NOT NULL DEFAULT 0.00,
            notes TEXT NULL,
            created_by BIGINT UNSIGNED NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_biz_invoice (business_id, invoice_number),
            KEY idx_biz_status (business_id, status),
            KEY idx_biz_type (business_id, sale_type),
            KEY idx_biz_issue (business_id, issue_date),
            KEY idx_customer (customer_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS tbl_sale_items (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            sale_id BIGINT UNSIGNED NOT NULL,
            item_id INT UNSIGNED NULL,
            item_name VARCHAR(190) NOT NULL,
            quantity DECIMAL(12, 2) NOT NULL DEFAULT 1.00,
            unit_price DECIMAL(14, 2) NOT NULL DEFAULT 0.00,
            line_total DECIMAL(14, 2) NOT NULL DEFAULT 0.00,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            KEY idx_sale (sale_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );
}

// Effective status: a "sent" invoice past its due date reads as "overdue".
function effective_status(array $row): string
{
    $status = (string) ($row['status'] ?? 'draft');
    if ($status === 'sent' && !empty($row['due_date'])) {
        if ($row['due_date'] < date('Y-m-d')) {
            return 'overdue';
        }
    }
    return $status;
}

function present_sale(array $row, int $itemsCount = 0): array
{
    return [
        'id' => (int) $row['id'],
        'invoice_number' => (string) $row['invoice_number'],
        'customer_id' => $row['customer_id'] !== null ? (int) $row['customer_id'] : null,
        'customer_name' => (string) ($row['customer_name'] ?? ''),
        'status' => (string) $row['status'],
        'effective_status' => effective_status($row),
        'issue_date' => (string) $row['issue_date'],
        'due_date' => $row['due_date'] !== null ? (string) $row['due_date'] : null,
        'subtotal' => (float) $row['subtotal'],
        'discount' => (float) $row['discount'],
        'tax_rate' => (float) $row['tax_rate'],
        'tax_amount' => (float) $row['tax_amount'],
        'total_amount' => (float) $row['total_amount'],
        'amount_paid' => (float) $row['amount_paid'],
        'balance_due' => (float) $row['total_amount'] - (float) $row['amount_paid'],
        'notes' => (string) ($row['notes'] ?? ''),
        'items_count' => $itemsCount,
        'created_at' => (string) ($row['created_at'] ?? ''),
    ];
}

try {
    $pdo = db();
    $userId = require_user();
    $biz = get_active_business($pdo, $userId);
    $businessId = (int) ($biz['id'] ?? 0);
    if ($businessId <= 0) {
        respond(422, ['ok' => false, 'message' => 'No active business selected.']);
    }

    ensure_sales_tables($pdo);

    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        // Single invoice detail
        if (isset($_GET['id'])) {
            $id = (int) $_GET['id'];
            $stmt = $pdo->prepare('SELECT * FROM tbl_sales WHERE id = :id AND business_id = :bid LIMIT 1');
            $stmt->execute([':id' => $id, ':bid' => $businessId]);
            $sale = $stmt->fetch();
            if (!$sale) {
                respond(404, ['ok' => false, 'message' => 'Invoice not found.']);
            }

            $iStmt = $pdo->prepare('SELECT * FROM tbl_sale_items WHERE sale_id = :sid ORDER BY id ASC');
            $iStmt->execute([':sid' => $id]);
            $items = $iStmt->fetchAll();

            $customer = null;
            if (!empty($sale['customer_id'])) {
                $cStmt = $pdo->prepare('SELECT id, full_name, phone, email, address FROM tbl_customers WHERE id = :cid AND business_id = :bid LIMIT 1');
                $cStmt->execute([':cid' => (int) $sale['customer_id'], ':bid' => $businessId]);
                $customer = $cStmt->fetch() ?: null;
            }

            $out = present_sale($sale, count($items));
            $out['items'] = array_map(static function ($it) {
                return [
                    'id' => (int) $it['id'],
                    'item_id' => $it['item_id'] !== null ? (int) $it['item_id'] : null,
                    'item_name' => (string) $it['item_name'],
                    'quantity' => (float) $it['quantity'],
                    'unit_price' => (float) $it['unit_price'],
                    'line_total' => (float) $it['line_total'],
                ];
            }, $items);
            $out['customer'] = $customer ? [
                'id' => (int) $customer['id'],
                'full_name' => (string) $customer['full_name'],
                'phone' => (string) ($customer['phone'] ?? ''),
                'email' => (string) ($customer['email'] ?? ''),
                'address' => (string) ($customer['address'] ?? ''),
            ] : null;

            respond(200, ['ok' => true, 'invoice' => $out]);
        }

        // List invoices
        $status = trim((string) ($_GET['status'] ?? 'all'));
        $q = trim((string) ($_GET['q'] ?? ''));

        $conditions = ['business_id = :bid', 'sale_type = "invoice"'];
        $params = [':bid' => $businessId];

        if ($status === 'overdue') {
            $conditions[] = 'status = "sent" AND due_date IS NOT NULL AND due_date < CURDATE()';
        } elseif ($status === 'sent') {
            $conditions[] = 'status = "sent" AND (due_date IS NULL OR due_date >= CURDATE())';
        } elseif (in_array($status, ['draft', 'paid', 'cancelled'], true)) {
            $conditions[] = 'status = :status';
            $params[':status'] = $status;
        }

        if ($q !== '') {
            $conditions[] = '(invoice_number LIKE :q OR customer_name LIKE :q)';
            $params[':q'] = '%' . $q . '%';
        }

        $where = implode(' AND ', $conditions);
        $stmt = $pdo->prepare(
            "SELECT s.*, (SELECT COUNT(*) FROM tbl_sale_items si WHERE si.sale_id = s.id) AS items_count
             FROM tbl_sales s WHERE {$where} ORDER BY s.id DESC"
        );
        $stmt->execute($params);
        $rows = $stmt->fetchAll();

        $invoices = array_map(static function ($r) {
            return present_sale($r, (int) ($r['items_count'] ?? 0));
        }, $rows);

        // Stats
        $statStmt = $pdo->prepare(
            'SELECT
                COUNT(*) AS total_count,
                SUM(CASE WHEN status = "draft" THEN 1 ELSE 0 END) AS draft_count,
                SUM(CASE WHEN status = "sent" AND (due_date IS NULL OR due_date >= CURDATE()) THEN 1 ELSE 0 END) AS sent_count,
                SUM(CASE WHEN status = "paid" THEN 1 ELSE 0 END) AS paid_count,
                SUM(CASE WHEN status = "sent" AND due_date IS NOT NULL AND due_date < CURDATE() THEN 1 ELSE 0 END) AS overdue_count,
                SUM(CASE WHEN status IN ("sent") THEN (total_amount - amount_paid) ELSE 0 END) AS amount_due,
                SUM(CASE WHEN status != "cancelled" AND issue_date = CURDATE() THEN total_amount ELSE 0 END) AS sales_today,
                SUM(CASE WHEN status != "cancelled" AND issue_date = CURDATE() THEN 1 ELSE 0 END) AS txn_today,
                SUM(CASE WHEN status != "cancelled" AND YEAR(issue_date) = YEAR(CURDATE()) AND MONTH(issue_date) = MONTH(CURDATE()) THEN total_amount ELSE 0 END) AS sales_month
             FROM tbl_sales WHERE business_id = :bid AND sale_type = "invoice"'
        );
        $statStmt->execute([':bid' => $businessId]);
        $stats = $statStmt->fetch() ?: [];

        respond(200, [
            'ok' => true,
            'invoices' => $invoices,
            'stats' => [
                'total' => (int) ($stats['total_count'] ?? 0),
                'draft' => (int) ($stats['draft_count'] ?? 0),
                'sent' => (int) ($stats['sent_count'] ?? 0),
                'paid' => (int) ($stats['paid_count'] ?? 0),
                'overdue' => (int) ($stats['overdue_count'] ?? 0),
                'amount_due' => (float) ($stats['amount_due'] ?? 0),
                'sales_today' => (float) ($stats['sales_today'] ?? 0),
                'txn_today' => (int) ($stats['txn_today'] ?? 0),
                'sales_month' => (float) ($stats['sales_month'] ?? 0),
            ],
        ]);
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $action = trim((string) ($_POST['action'] ?? ''));

        if ($action === 'create_invoice' || $action === 'update_invoice') {
            $isEditing = ($action === 'update_invoice');
            $saleId = (int) ($_POST['invoice_id'] ?? 0);
            $customerId = (int) ($_POST['customer_id'] ?? 0);
            $customerNameInput = trim((string) ($_POST['customer_name'] ?? ''));
            $issueDate = trim((string) ($_POST['issue_date'] ?? date('Y-m-d')));
            $dueDate = trim((string) ($_POST['due_date'] ?? ''));
            $notes = trim((string) ($_POST['notes'] ?? ''));
            $taxRate = (float) ($_POST['tax_rate'] ?? 0);
            $discount = (float) ($_POST['discount'] ?? 0);
            $itemsRaw = $_POST['items'] ?? '[]';
            $items = is_string($itemsRaw) ? json_decode($itemsRaw, true) : $itemsRaw;

            if (!is_array($items) || empty($items)) {
                respond(422, ['ok' => false, 'message' => 'Please add at least one line item to the invoice.']);
            }

            // Resolve customer (optional)
            $customerName = $customerNameInput;
            if ($customerId > 0) {
                $cStmt = $pdo->prepare('SELECT id, full_name FROM tbl_customers WHERE id = :cid AND business_id = :bid LIMIT 1');
                $cStmt->execute([':cid' => $customerId, ':bid' => $businessId]);
                $cRow = $cStmt->fetch();
                if (!$cRow) {
                    respond(404, ['ok' => false, 'message' => 'Selected customer not found.']);
                }
                $customerName = (string) $cRow['full_name'];
            } else {
                $customerId = 0;
                if ($customerName === '') {
                    $customerName = 'Walk-in Customer';
                }
            }

            // Validate line items
            $subtotal = 0.0;
            $validatedItems = [];
            foreach ($items as $item) {
                $itemId = (int) ($item['item_id'] ?? 0);
                $qty = (float) ($item['quantity'] ?? 1);
                $price = (float) ($item['unit_price'] ?? 0);
                $nameFallback = trim((string) ($item['item_name'] ?? ''));

                if ($qty <= 0) {
                    continue;
                }

                $itemName = $nameFallback;
                $resolvedItemId = null;
                if ($itemId > 0) {
                    $iStmt = $pdo->prepare('SELECT id, name FROM tbl_items WHERE id = :id AND business_id = :bid LIMIT 1');
                    $iStmt->execute([':id' => $itemId, ':bid' => $businessId]);
                    $iRow = $iStmt->fetch();
                    if ($iRow) {
                        $resolvedItemId = (int) $iRow['id'];
                        $itemName = (string) $iRow['name'];
                    }
                }

                if ($itemName === '') {
                    continue;
                }

                $lineTotal = round($qty * $price, 2);
                $subtotal += $lineTotal;
                $validatedItems[] = [
                    'item_id' => $resolvedItemId,
                    'item_name' => $itemName,
                    'quantity' => $qty,
                    'unit_price' => $price,
                    'line_total' => $lineTotal,
                ];
            }

            if (empty($validatedItems)) {
                respond(422, ['ok' => false, 'message' => 'No valid line items provided.']);
            }

            if ($discount < 0) {
                $discount = 0.0;
            }
            $taxableBase = max(0.0, $subtotal - $discount);
            $taxAmount = round(($taxableBase * $taxRate) / 100, 2);
            $totalAmount = round($taxableBase + $taxAmount, 2);

            $pdo->beginTransaction();
            try {
                if ($isEditing) {
                    $chk = $pdo->prepare('SELECT id, status FROM tbl_sales WHERE id = :id AND business_id = :bid LIMIT 1');
                    $chk->execute([':id' => $saleId, ':bid' => $businessId]);
                    $existing = $chk->fetch();
                    if (!$existing) {
                        respond(404, ['ok' => false, 'message' => 'Invoice not found.']);
                    }
                    if ($existing['status'] === 'paid' || $existing['status'] === 'cancelled') {
                        respond(422, ['ok' => false, 'message' => 'A paid or cancelled invoice cannot be edited.']);
                    }

                    $upd = $pdo->prepare(
                        'UPDATE tbl_sales SET customer_id = :cid, customer_name = :cname, issue_date = :idate,
                            due_date = :ddate, subtotal = :sub, discount = :disc, tax_rate = :trate,
                            tax_amount = :tamt, total_amount = :tot, notes = :notes
                         WHERE id = :id AND business_id = :bid'
                    );
                    $upd->execute([
                        ':cid' => $customerId > 0 ? $customerId : null,
                        ':cname' => $customerName,
                        ':idate' => $issueDate,
                        ':ddate' => $dueDate !== '' ? $dueDate : null,
                        ':sub' => $subtotal,
                        ':disc' => $discount,
                        ':trate' => $taxRate,
                        ':tamt' => $taxAmount,
                        ':tot' => $totalAmount,
                        ':notes' => $notes !== '' ? $notes : null,
                        ':id' => $saleId,
                        ':bid' => $businessId,
                    ]);

                    $pdo->prepare('DELETE FROM tbl_sale_items WHERE sale_id = :sid')->execute([':sid' => $saleId]);
                    $invoiceNumber = null;
                } else {
                    $countStmt = $pdo->prepare('SELECT COUNT(*) FROM tbl_sales WHERE business_id = :bid AND sale_type = "invoice"');
                    $countStmt->execute([':bid' => $businessId]);
                    $seq = ((int) $countStmt->fetchColumn()) + 1;
                    $invoiceNumber = 'INV-' . date('Ym') . '-' . str_pad((string) $seq, 4, '0', STR_PAD_LEFT);

                    $ins = $pdo->prepare(
                        'INSERT INTO tbl_sales
                            (business_id, sale_type, invoice_number, customer_id, customer_name, status, issue_date, due_date,
                             subtotal, discount, tax_rate, tax_amount, total_amount, amount_paid, notes, created_by)
                         VALUES (:bid, "invoice", :inv, :cid, :cname, "draft", :idate, :ddate,
                             :sub, :disc, :trate, :tamt, :tot, 0.00, :notes, :uid)'
                    );
                    $ins->execute([
                        ':bid' => $businessId,
                        ':inv' => $invoiceNumber,
                        ':cid' => $customerId > 0 ? $customerId : null,
                        ':cname' => $customerName,
                        ':idate' => $issueDate,
                        ':ddate' => $dueDate !== '' ? $dueDate : null,
                        ':sub' => $subtotal,
                        ':disc' => $discount,
                        ':trate' => $taxRate,
                        ':tamt' => $taxAmount,
                        ':tot' => $totalAmount,
                        ':notes' => $notes !== '' ? $notes : null,
                        ':uid' => $userId,
                    ]);
                    $saleId = (int) $pdo->lastInsertId();
                }

                $liStmt = $pdo->prepare(
                    'INSERT INTO tbl_sale_items (sale_id, item_id, item_name, quantity, unit_price, line_total)
                     VALUES (:sid, :iid, :iname, :qty, :price, :ltot)'
                );
                foreach ($validatedItems as $v) {
                    $liStmt->execute([
                        ':sid' => $saleId,
                        ':iid' => $v['item_id'],
                        ':iname' => $v['item_name'],
                        ':qty' => $v['quantity'],
                        ':price' => $v['unit_price'],
                        ':ltot' => $v['line_total'],
                    ]);
                }

                $pdo->commit();
            } catch (Throwable $e) {
                $pdo->rollBack();
                throw $e;
            }

            respond($isEditing ? 200 : 201, [
                'ok' => true,
                'message' => $isEditing ? 'Invoice updated.' : "Invoice {$invoiceNumber} created.",
                'invoice_id' => $saleId,
            ]);
        }

        if ($action === 'update_status') {
            $saleId = (int) ($_POST['invoice_id'] ?? 0);
            $newStatus = trim((string) ($_POST['status'] ?? ''));
            $allowed = ['draft', 'sent', 'paid'];
            if (!in_array($newStatus, $allowed, true)) {
                respond(422, ['ok' => false, 'message' => 'Invalid status.']);
            }

            $chk = $pdo->prepare('SELECT id, status, total_amount FROM tbl_sales WHERE id = :id AND business_id = :bid LIMIT 1');
            $chk->execute([':id' => $saleId, ':bid' => $businessId]);
            $sale = $chk->fetch();
            if (!$sale) {
                respond(404, ['ok' => false, 'message' => 'Invoice not found.']);
            }
            if ($sale['status'] === 'cancelled') {
                respond(422, ['ok' => false, 'message' => 'Reactivate the invoice before changing its status.']);
            }

            if ($newStatus === 'paid') {
                $pdo->prepare('UPDATE tbl_sales SET status = "paid", amount_paid = total_amount WHERE id = :id')
                    ->execute([':id' => $saleId]);
            } else {
                $pdo->prepare('UPDATE tbl_sales SET status = :st WHERE id = :id')
                    ->execute([':st' => $newStatus, ':id' => $saleId]);
            }

            respond(200, ['ok' => true, 'message' => 'Invoice status updated.']);
        }

        if ($action === 'cancel_invoice' || $action === 'uncancel_invoice') {
            $saleId = (int) ($_POST['invoice_id'] ?? 0);
            $chk = $pdo->prepare('SELECT id, status FROM tbl_sales WHERE id = :id AND business_id = :bid LIMIT 1');
            $chk->execute([':id' => $saleId, ':bid' => $businessId]);
            $sale = $chk->fetch();
            if (!$sale) {
                respond(404, ['ok' => false, 'message' => 'Invoice not found.']);
            }

            if ($action === 'cancel_invoice') {
                if ($sale['status'] === 'paid') {
                    respond(422, ['ok' => false, 'message' => 'A paid invoice cannot be cancelled.']);
                }
                $pdo->prepare('UPDATE tbl_sales SET status = "cancelled" WHERE id = :id')->execute([':id' => $saleId]);
                respond(200, ['ok' => true, 'message' => 'Invoice cancelled.']);
            }

            if ($sale['status'] !== 'cancelled') {
                respond(422, ['ok' => false, 'message' => 'Only a cancelled invoice can be reactivated.']);
            }
            $pdo->prepare('UPDATE tbl_sales SET status = "draft" WHERE id = :id')->execute([':id' => $saleId]);
            respond(200, ['ok' => true, 'message' => 'Invoice reactivated.']);
        }

        if ($action === 'delete_invoice') {
            $saleId = (int) ($_POST['invoice_id'] ?? 0);
            $chk = $pdo->prepare('SELECT id FROM tbl_sales WHERE id = :id AND business_id = :bid LIMIT 1');
            $chk->execute([':id' => $saleId, ':bid' => $businessId]);
            if (!$chk->fetch()) {
                respond(404, ['ok' => false, 'message' => 'Invoice not found.']);
            }
            $pdo->beginTransaction();
            try {
                $pdo->prepare('DELETE FROM tbl_sale_items WHERE sale_id = :sid')->execute([':sid' => $saleId]);
                $pdo->prepare('DELETE FROM tbl_sales WHERE id = :id AND business_id = :bid')->execute([':id' => $saleId, ':bid' => $businessId]);
                $pdo->commit();
            } catch (Throwable $e) {
                $pdo->rollBack();
                throw $e;
            }
            respond(200, ['ok' => true, 'message' => 'Invoice deleted.']);
        }

        respond(422, ['ok' => false, 'message' => 'Unknown sales action.']);
    }

    respond(405, ['ok' => false, 'message' => 'Method not allowed.']);
} catch (Throwable $e) {
    respond(500, ['ok' => false, 'message' => 'Unable to process sales: ' . $e->getMessage()]);
}
