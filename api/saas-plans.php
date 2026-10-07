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

if (empty($_SESSION['saas_admin_id'])) {
    respond(401, ['ok' => false, 'message' => 'Admin login required.']);
}

function ensure_plans_table(PDO $pdo): void
{
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS tbl_saas_plans (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            plan_name VARCHAR(80) NOT NULL UNIQUE,
            monthly_price DECIMAL(14,2) NOT NULL DEFAULT 0.00,
            user_limit INT UNSIGNED NOT NULL DEFAULT 1,
            business_limit INT UNSIGNED NOT NULL DEFAULT 1,
            status ENUM("active", "inactive") NOT NULL DEFAULT "active",
            notes VARCHAR(255) NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );

    $count = (int) $pdo->query('SELECT COUNT(*) FROM tbl_saas_plans')->fetchColumn();
    if ($count === 0) {
        $stmt = $pdo->prepare(
            'INSERT INTO tbl_saas_plans (plan_name, monthly_price, user_limit, business_limit, status, notes)
             VALUES (:name, :price, :users, :businesses, "active", :notes)'
        );
        foreach ([
            ['Starter', 0, 3, 1, 'Entry plan for small shops.'],
            ['Growth', 25000, 10, 2, 'Growing team plan.'],
            ['Business', 50000, 25, 5, 'SME operations plan.'],
        ] as $plan) {
            $stmt->execute([
                ':name' => $plan[0],
                ':price' => $plan[1],
                ':users' => $plan[2],
                ':businesses' => $plan[3],
                ':notes' => $plan[4],
            ]);
        }
    }
}

function load_plans(PDO $pdo): array
{
    return $pdo->query(
        'SELECT p.*,
                (SELECT COUNT(*) FROM tbl_businesses b WHERE b.plan_name = p.plan_name) AS business_count
         FROM tbl_saas_plans p
         ORDER BY p.monthly_price ASC, p.plan_name ASC'
    )->fetchAll();
}

try {
    $pdo = db();
    ensure_plans_table($pdo);

    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        respond(200, ['ok' => true, 'plans' => load_plans($pdo)]);
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        respond(405, ['ok' => false, 'message' => 'Method not allowed.']);
    }

    $action = trim((string) ($_POST['action'] ?? 'save'));
    $planId = (int) ($_POST['plan_id'] ?? 0);

    if ($action === 'delete') {
        if ($planId <= 0) {
            respond(422, ['ok' => false, 'message' => 'Select a valid plan.']);
        }
        $used = $pdo->prepare('SELECT COUNT(*) FROM tbl_businesses b JOIN tbl_saas_plans p ON p.plan_name = b.plan_name WHERE p.id = :id');
        $used->execute([':id' => $planId]);
        if ((int) $used->fetchColumn() > 0) {
            respond(422, ['ok' => false, 'message' => 'Plan is used by businesses. Deactivate it instead.']);
        }
        $pdo->prepare('DELETE FROM tbl_saas_plans WHERE id = :id')->execute([':id' => $planId]);
        respond(200, ['ok' => true, 'message' => 'Plan deleted.', 'plans' => load_plans($pdo)]);
    }

    $name = trim((string) ($_POST['plan_name'] ?? ''));
    $price = (float) preg_replace('/[^\d.]/', '', (string) ($_POST['monthly_price'] ?? '0'));
    $users = max(1, (int) ($_POST['user_limit'] ?? 1));
    $businesses = max(1, (int) ($_POST['business_limit'] ?? 1));
    $status = trim((string) ($_POST['status'] ?? 'active'));
    $notes = trim((string) ($_POST['notes'] ?? ''));

    if ($name === '') {
        respond(422, ['ok' => false, 'message' => 'Plan name is required.']);
    }
    if (!in_array($status, ['active', 'inactive'], true)) {
        respond(422, ['ok' => false, 'message' => 'Invalid plan status.']);
    }

    if ($planId > 0) {
        $stmt = $pdo->prepare(
            'UPDATE tbl_saas_plans
             SET plan_name = :name, monthly_price = :price, user_limit = :users,
                 business_limit = :businesses, status = :status, notes = :notes
             WHERE id = :id'
        );
        $stmt->execute([
            ':name' => $name,
            ':price' => $price,
            ':users' => $users,
            ':businesses' => $businesses,
            ':status' => $status,
            ':notes' => $notes,
            ':id' => $planId,
        ]);
    } else {
        $stmt = $pdo->prepare(
            'INSERT INTO tbl_saas_plans (plan_name, monthly_price, user_limit, business_limit, status, notes)
             VALUES (:name, :price, :users, :businesses, :status, :notes)'
        );
        $stmt->execute([
            ':name' => $name,
            ':price' => $price,
            ':users' => $users,
            ':businesses' => $businesses,
            ':status' => $status,
            ':notes' => $notes,
        ]);
    }

    respond(200, ['ok' => true, 'message' => 'Plan saved.', 'plans' => load_plans($pdo)]);
} catch (Throwable $error) {
    respond(500, ['ok' => false, 'message' => 'Unable to manage plans right now.']);
}
