<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/accounts_lib.php';
require_once __DIR__ . '/audit_lib.php';
require_once __DIR__ . '/permissions_lib.php';
require_once __DIR__ . '/expenses_export_lib.php';

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

const ACCOUNT_TYPE_LABELS = [
    'cash' => 'Cash Account',
    'bank' => 'Bank Account',
    'mobile' => 'Lipa kwa simu Account',
];

const TXN_TYPE_LABELS = [
    'sale' => 'POS sale',
    'invoice_payment' => 'Invoice payment',
    'rent' => 'Rent payment',
    'deposit' => 'Deposit',
    'withdrawal' => 'Withdrawal',
    'transfer_in' => 'Transfer in',
    'transfer_out' => 'Transfer out',
    'expense' => 'Expense',
    'opening' => 'Opening balance',
    'adjustment' => 'Adjustment',
];

function money_value($value): float
{
    $clean = preg_replace('/[^0-9.\-]/', '', (string) $value);
    if ($clean === '' || $clean === '-' || $clean === '.') {
        return 0.0;
    }
    return round((float) $clean, 2);
}

function account_payload(array $row): array
{
    $type = (string) ($row['type'] ?? 'cash');
    return [
        'id' => (int) $row['id'],
        'name' => (string) $row['name'],
        'type' => $type,
        'type_label' => ACCOUNT_TYPE_LABELS[$type] ?? $type,
        'bank_name' => (string) ($row['bank_name'] ?? ''),
        'account_number' => (string) ($row['account_number'] ?? ''),
        'opening_balance' => (float) ($row['opening_balance'] ?? 0),
        'balance' => (float) ($row['balance'] ?? $row['opening_balance'] ?? 0),
        'is_default' => (int) ($row['is_default'] ?? 0) === 1,
        'status' => (string) ($row['status'] ?? 'active'),
        'created_at' => (string) ($row['created_at'] ?? ''),
    ];
}

function load_accounts(PDO $pdo, int $businessId): array
{
    $stmt = $pdo->prepare(
        'SELECT a.*,
                a.opening_balance
                + COALESCE((SELECT SUM(CASE WHEN t.direction = "in" THEN t.amount ELSE -t.amount END)
                            FROM tbl_account_transactions t WHERE t.account_id = a.id), 0) AS balance
         FROM tbl_accounts a
         WHERE a.business_id = :bid
         ORDER BY a.is_default DESC, a.name ASC'
    );
    $stmt->execute([':bid' => $businessId]);
    return array_map('account_payload', $stmt->fetchAll());
}

function account_summary(array $accounts): array
{
    $byType = ['cash' => 0.0, 'bank' => 0.0, 'mobile' => 0.0];
    $net = 0.0;
    foreach ($accounts as $a) {
        if (($a['status'] ?? 'active') === 'inactive') {
            continue;
        }
        $byType[$a['type']] = ($byType[$a['type']] ?? 0) + (float) $a['balance'];
        $net += (float) $a['balance'];
    }
    return [
        'cash' => round($byType['cash'], 2),
        'bank' => round($byType['bank'], 2),
        'mobile' => round($byType['mobile'], 2),
        'net' => round($net, 2),
        'accounts_count' => count($accounts),
    ];
}

function category_payload(array $row): array
{
    return [
        'id' => (int) $row['id'],
        'name' => (string) $row['name'],
        'status' => (string) ($row['status'] ?? 'active'),
        'used_count' => (int) ($row['used_count'] ?? 0),
        'total_amount' => (float) ($row['total_amount'] ?? 0),
        'created_at' => (string) ($row['created_at'] ?? ''),
    ];
}

function reconciliation_period(string $period): array
{
    $today = new DateTimeImmutable('today');
    if ($period === 'today') {
        return [$today->format('Y-m-d'), $today->format('Y-m-d'), 'Today'];
    }
    if ($period === 'year') {
        return [$today->setDate((int) $today->format('Y'), 1, 1)->format('Y-m-d'), $today->format('Y-m-d'), 'This Year'];
    }
    if ($period === 'all') {
        return ['', '', 'All Time'];
    }
    return [$today->modify('first day of this month')->format('Y-m-d'), $today->format('Y-m-d'), 'This Month'];
}

function sum_one(PDO $pdo, string $sql, array $params): float
{
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return round((float) ($stmt->fetchColumn() ?: 0), 2);
}

function reconciliation_report(PDO $pdo, int $businessId, string $period): array
{
    [$from, $to, $label] = reconciliation_period($period);
    $salesDate = $from !== '' ? ' AND s.issue_date BETWEEN :from AND :to' : '';
    $txnDate = $from !== '' ? ' AND DATE(t.created_at) BETWEEN :from AND :to' : '';
    $salesParams = [':bid' => $businessId];
    $txnParams = [':bid' => $businessId];
    if ($from !== '') {
        $salesParams[':from'] = $from;
        $salesParams[':to'] = $to;
        $txnParams[':from'] = $from;
        $txnParams[':to'] = $to;
    }

    $salesBilled = sum_one(
        $pdo,
        'SELECT COALESCE(SUM(s.total_amount), 0)
         FROM tbl_sales s
         WHERE s.business_id = :bid AND s.status NOT IN ("cancelled", "draft")' . $salesDate,
        $salesParams
    );
    $salesCollected = sum_one(
        $pdo,
        'SELECT COALESCE(SUM(s.amount_paid), 0)
         FROM tbl_sales s
         WHERE s.business_id = :bid AND s.status NOT IN ("cancelled", "draft")' . $salesDate,
        $salesParams
    );
    $outstanding = sum_one(
        $pdo,
        'SELECT COALESCE(SUM(GREATEST(s.total_amount - s.amount_paid, 0)), 0)
         FROM tbl_sales s
         WHERE s.business_id = :bid AND s.status NOT IN ("cancelled", "draft")' . $salesDate,
        $salesParams
    );
    $postedCollections = sum_one(
        $pdo,
        'SELECT COALESCE(SUM(t.amount), 0)
         FROM tbl_account_transactions t
         WHERE t.business_id = :bid AND t.direction = "in" AND t.type IN ("sale", "invoice_payment")' . $txnDate,
        $txnParams
    );
    $expensesPaid = sum_one(
        $pdo,
        'SELECT COALESCE(SUM(t.amount), 0)
         FROM tbl_account_transactions t
         WHERE t.business_id = :bid AND t.direction = "out" AND t.type = "expense"' . $txnDate,
        $txnParams
    );
    $manualDeposits = sum_one(
        $pdo,
        'SELECT COALESCE(SUM(t.amount), 0)
         FROM tbl_account_transactions t
         WHERE t.business_id = :bid AND t.direction = "in" AND t.type = "deposit"' . $txnDate,
        $txnParams
    );
    $manualWithdrawals = sum_one(
        $pdo,
        'SELECT COALESCE(SUM(t.amount), 0)
         FROM tbl_account_transactions t
         WHERE t.business_id = :bid AND t.direction = "out" AND t.type = "withdrawal"' . $txnDate,
        $txnParams
    );

    $accounts = load_accounts($pdo, $businessId);
    $summary = account_summary($accounts);
    $collectionDifference = round($salesCollected - $postedCollections, 2);
    $checks = [
        [
            'label' => 'Sales collected vs account postings',
            'status' => abs($collectionDifference) <= 1 ? 'ok' : 'review',
            'difference' => $collectionDifference,
        ],
        [
            'label' => 'Outstanding debts',
            'status' => $outstanding > 0 ? 'review' : 'ok',
            'difference' => $outstanding,
        ],
    ];

    return [
        'period' => $period,
        'label' => $label,
        'from' => $from,
        'to' => $to,
        'sales_billed' => $salesBilled,
        'sales_collected' => $salesCollected,
        'account_posted_collections' => $postedCollections,
        'collection_difference' => $collectionDifference,
        'outstanding_debts' => $outstanding,
        'expenses_paid' => $expensesPaid,
        'manual_deposits' => $manualDeposits,
        'manual_withdrawals' => $manualWithdrawals,
        'account_balance' => (float) $summary['net'],
        'account_summary' => $summary,
        'checks' => $checks,
    ];
}

function load_expense_categories(PDO $pdo, int $businessId): array
{
    $stmt = $pdo->prepare(
        'SELECT c.*,
                COALESCE((SELECT COUNT(*) FROM tbl_account_transactions t
                          WHERE t.business_id = c.business_id
                            AND t.expense_category_id = c.id
                            AND t.type = "expense"), 0) AS used_count,
                COALESCE((SELECT SUM(t.amount) FROM tbl_account_transactions t
                          WHERE t.business_id = c.business_id
                            AND t.expense_category_id = c.id
                            AND t.type = "expense"), 0) AS total_amount
         FROM tbl_expense_categories c
         WHERE c.business_id = :bid
         ORDER BY c.status ASC, c.name ASC'
    );
    $stmt->execute([':bid' => $businessId]);
    return array_map('category_payload', $stmt->fetchAll());
}

function ensure_default_expense_categories(PDO $pdo, int $businessId): void
{
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM tbl_expense_categories WHERE business_id = :bid');
    $stmt->execute([':bid' => $businessId]);
    if ((int) $stmt->fetchColumn() > 0) {
        return;
    }

    $ins = $pdo->prepare(
        'INSERT INTO tbl_expense_categories (business_id, name, status)
         VALUES (:bid, :name, "active")'
    );
    foreach (['Rent', 'Transport', 'Supplies', 'Utilities'] as $name) {
        $ins->execute([':bid' => $businessId, ':name' => $name]);
    }
}

function ensure_salary_category(PDO $pdo, int $businessId): int
{
    $stmt = $pdo->prepare('SELECT id FROM tbl_expense_categories WHERE business_id = :bid AND name = "Salaries" LIMIT 1');
    $stmt->execute([':bid' => $businessId]);
    $id = (int) ($stmt->fetchColumn() ?: 0);
    if ($id > 0) {
        $pdo->prepare('UPDATE tbl_expense_categories SET status = "active" WHERE id = :id AND business_id = :bid')
            ->execute([':id' => $id, ':bid' => $businessId]);
        return $id;
    }

    $ins = $pdo->prepare('INSERT INTO tbl_expense_categories (business_id, name, status) VALUES (:bid, "Salaries", "active")');
    $ins->execute([':bid' => $businessId]);
    return (int) $pdo->lastInsertId();
}

function fetch_category_or_404(PDO $pdo, int $businessId, int $categoryId): array
{
    $stmt = $pdo->prepare('SELECT * FROM tbl_expense_categories WHERE id = :id AND business_id = :bid LIMIT 1');
    $stmt->execute([':id' => $categoryId, ':bid' => $businessId]);
    $row = $stmt->fetch();
    if (!$row) {
        respond(404, ['ok' => false, 'message' => 'Category not found.']);
    }
    return $row;
}

function valid_payroll_month(string $month): bool
{
    return (bool) preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month);
}

function payroll_payload(PDO $pdo, int $businessId, string $month): array
{
    $runStmt = $pdo->prepare('SELECT * FROM tbl_payroll_runs WHERE business_id = :bid AND payroll_month = :month LIMIT 1');
    $runStmt->execute([':bid' => $businessId, ':month' => $month]);
    $run = $runStmt->fetch();
    if (!$run) {
        return ['run' => null, 'items' => [], 'summary' => ['total' => 0, 'paid' => 0, 'pending' => 0, 'staff_count' => 0]];
    }

    $itemStmt = $pdo->prepare('SELECT * FROM tbl_payroll_items WHERE business_id = :bid AND run_id = :run ORDER BY staff_name ASC');
    $itemStmt->execute([':bid' => $businessId, ':run' => (int) $run['id']]);
    $items = array_map(static function ($row) {
        return [
            'id' => (int) $row['id'],
            'user_id' => (int) $row['user_id'],
            'staff_name' => (string) $row['staff_name'],
            'role' => (string) ($row['role'] ?? ''),
            'salary_amount' => (float) $row['salary_amount'],
            'status' => (string) $row['status'],
            'paid_at' => (string) ($row['paid_at'] ?? ''),
        ];
    }, $itemStmt->fetchAll());

    $total = 0.0;
    $paid = 0.0;
    foreach ($items as $item) {
        $total += (float) $item['salary_amount'];
        if ($item['status'] === 'paid') {
            $paid += (float) $item['salary_amount'];
        }
    }

    return [
        'run' => [
            'id' => (int) $run['id'],
            'payroll_month' => (string) $run['payroll_month'],
            'status' => (string) $run['status'],
        ],
        'items' => $items,
        'summary' => [
            'total' => round($total, 2),
            'paid' => round($paid, 2),
            'pending' => round($total - $paid, 2),
            'staff_count' => count($items),
        ],
    ];
}

function fetch_account_or_404(PDO $pdo, int $businessId, int $accountId): array
{
    $stmt = $pdo->prepare('SELECT * FROM tbl_accounts WHERE id = :id AND business_id = :bid LIMIT 1');
    $stmt->execute([':id' => $accountId, ':bid' => $businessId]);
    $row = $stmt->fetch();
    if (!$row) {
        respond(404, ['ok' => false, 'message' => 'Account not found.']);
    }
    return $row;
}

try {
    $pdo = db();
    $userId = require_user();
    ensure_accounts_tables($pdo);
    ensure_audit_logs_table($pdo);
    ensure_payroll_tables($pdo);

    $businessId = active_business_id($pdo, $userId);
    if ($businessId <= 0) {
        respond(422, ['ok' => false, 'message' => 'No active business selected.']);
    }

    // Lazy seed: guarantees a default Cash account for this (possibly pre-existing) business.
    ensure_default_account($pdo, $businessId);
    ensure_default_expense_categories($pdo, $businessId);

    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        // Single account detail + its ledger.
        if (isset($_GET['id'])) {
            $accountId = (int) $_GET['id'];
            $row = fetch_account_or_404($pdo, $businessId, $accountId);
            $row['balance'] = account_balance($pdo, $businessId, $accountId);

            $tStmt = $pdo->prepare(
                'SELECT * FROM tbl_account_transactions WHERE account_id = :id AND business_id = :bid ORDER BY id DESC LIMIT 200'
            );
            $tStmt->execute([':id' => $accountId, ':bid' => $businessId]);
            $txns = array_map(static function ($t) {
                $type = (string) $t['type'];
                return [
                    'id' => (int) $t['id'],
                    'direction' => (string) $t['direction'],
                    'type' => $type,
                    'type_label' => TXN_TYPE_LABELS[$type] ?? $type,
                    'amount' => (float) $t['amount'],
                    'reference_type' => (string) ($t['reference_type'] ?? ''),
                    'reference_id' => (string) ($t['reference_id'] ?? ''),
                    'notes' => (string) ($t['notes'] ?? ''),
                    'created_at' => (string) ($t['created_at'] ?? ''),
                ];
            }, $tStmt->fetchAll());

            $out = account_payload($row);
            $out['transactions'] = $txns;
            respond(200, ['ok' => true, 'account' => $out]);
        }

        // Recent activity across all accounts.
        if (($_GET['action'] ?? '') === 'activity') {
            $stmt = $pdo->prepare(
                'SELECT t.*, a.name AS account_name, a.type AS account_type, c.name AS category_name, c.status AS category_status
                 FROM tbl_account_transactions t
                 JOIN tbl_accounts a ON a.id = t.account_id
                 LEFT JOIN tbl_expense_categories c ON c.id = t.expense_category_id AND c.business_id = t.business_id
                 WHERE t.business_id = :bid
                 ORDER BY t.id DESC LIMIT 100'
            );
            $stmt->execute([':bid' => $businessId]);
            $activity = array_map(static function ($t) {
                $type = (string) $t['type'];
                return [
                    'id' => (int) $t['id'],
                    'account_id' => (int) $t['account_id'],
                    'account_name' => (string) $t['account_name'],
                    'account_type' => (string) $t['account_type'],
                    'direction' => (string) $t['direction'],
                    'type' => $type,
                    'type_label' => TXN_TYPE_LABELS[$type] ?? $type,
                    'amount' => (float) $t['amount'],
                    'reference_type' => (string) ($t['reference_type'] ?? ''),
                    'reference_id' => (string) ($t['reference_id'] ?? ''),
                    'expense_category_id' => (int) ($t['expense_category_id'] ?? 0),
                    'category_name' => (string) ($t['category_name'] ?? ''),
                    'category_status' => (string) ($t['category_status'] ?? ''),
                    'notes' => (string) ($t['notes'] ?? ''),
                    'created_at' => (string) ($t['created_at'] ?? ''),
                ];
            }, $stmt->fetchAll());
            respond(200, ['ok' => true, 'activity' => $activity]);
        }

        if (($_GET['action'] ?? '') === 'reconciliation') {
            $period = strtolower(trim((string) ($_GET['period'] ?? 'month')));
            if (!in_array($period, ['today', 'month', 'year', 'all'], true)) {
                $period = 'month';
            }
            respond(200, ['ok' => true, 'report' => reconciliation_report($pdo, $businessId, $period)]);
        }

        if (($_GET['action'] ?? '') === 'expenses') {
            $stmt = $pdo->prepare(
                'SELECT t.*, COALESCE(a.name, "Default Account") AS account_name, COALESCE(a.type, "cash") AS account_type, c.name AS category_name, c.status AS category_status
                 FROM tbl_account_transactions t
                 LEFT JOIN tbl_accounts a ON a.id = t.account_id
                 LEFT JOIN tbl_expense_categories c ON c.id = t.expense_category_id AND c.business_id = t.business_id
                 WHERE t.business_id = :bid AND t.type = "expense"
                 ORDER BY t.created_at DESC, t.id DESC'
            );
            $stmt->execute([':bid' => $businessId]);
            $expenses = array_map(static function ($t) {
                return [
                    'id' => (int) $t['id'],
                    'account_id' => (int) $t['account_id'],
                    'account_name' => (string) $t['account_name'],
                    'account_type' => (string) $t['account_type'],
                    'direction' => (string) $t['direction'],
                    'type' => 'expense',
                    'type_label' => TXN_TYPE_LABELS['expense'],
                    'amount' => (float) $t['amount'],
                    'reference_type' => (string) ($t['reference_type'] ?? ''),
                    'reference_id' => (string) ($t['reference_id'] ?? ''),
                    'expense_category_id' => (int) ($t['expense_category_id'] ?? 0),
                    'category_name' => (string) ($t['category_name'] ?? ''),
                    'category_status' => (string) ($t['category_status'] ?? ''),
                    'notes' => (string) ($t['notes'] ?? ''),
                    'created_at' => (string) ($t['created_at'] ?? ''),
                ];
            }, $stmt->fetchAll());
            respond(200, ['ok' => true, 'expenses' => $expenses]);
        }

        if (($_GET['action'] ?? '') === 'export_expenses_excel') {
            $level = trim((string) ($_GET['level'] ?? 'years'));
            $year = trim((string) ($_GET['year'] ?? ''));
            $month = trim((string) ($_GET['month'] ?? ''));
            $date = trim((string) ($_GET['date'] ?? ''));
            export_expenses_excel($pdo, $businessId, $level, $year, $month, $date);
            exit;
        }

        if (($_GET['action'] ?? '') === 'export_expenses_pdf') {
            $level = trim((string) ($_GET['level'] ?? 'years'));
            $year = trim((string) ($_GET['year'] ?? ''));
            $month = trim((string) ($_GET['month'] ?? ''));
            $date = trim((string) ($_GET['date'] ?? ''));
            export_expenses_pdf_download($pdo, $businessId, $level, $year, $month, $date);
            exit;
        }

        if (($_GET['action'] ?? '') === 'expense_categories') {
            respond(200, ['ok' => true, 'categories' => load_expense_categories($pdo, $businessId)]);
        }

        if (($_GET['action'] ?? '') === 'export_categories_excel') {
            export_categories_excel($pdo, $businessId);
            exit;
        }

        if (($_GET['action'] ?? '') === 'export_categories_pdf') {
            export_categories_pdf_download($pdo, $businessId);
            exit;
        }

        if (($_GET['action'] ?? '') === 'payroll') {
            $month = trim((string) ($_GET['month'] ?? date('Y-m')));
            if (!valid_payroll_month($month)) {
                respond(422, ['ok' => false, 'message' => 'Choose a valid payroll month.']);
            }
            respond(200, ['ok' => true] + payroll_payload($pdo, $businessId, $month));
        }

        if (($_GET['action'] ?? '') === 'export_payroll_excel') {
            $month = trim((string) ($_GET['month'] ?? date('Y-m')));
            export_payroll_excel($pdo, $businessId, $month);
            exit;
        }

        if (($_GET['action'] ?? '') === 'export_payroll_pdf') {
            $month = trim((string) ($_GET['month'] ?? date('Y-m')));
            export_payroll_pdf_download($pdo, $businessId, $month);
            exit;
        }

        $accounts = load_accounts($pdo, $businessId);
        respond(200, [
            'ok' => true,
            'accounts' => $accounts,
            'summary' => account_summary($accounts),
        ]);
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        respond(405, ['ok' => false, 'message' => 'Method not allowed.']);
    }

    $action = trim((string) ($_POST['action'] ?? ''));

    if ($action === 'create' || $action === 'update') {
        require_permission($pdo, $businessId, $userId, 'accounts.manage');
        $name = trim((string) ($_POST['name'] ?? ''));
        $type = strtolower(trim((string) ($_POST['type'] ?? 'cash')));
        if (!in_array($type, ['cash', 'bank', 'mobile'], true)) {
            $type = 'cash';
        }
        $bankName = trim((string) ($_POST['bank_name'] ?? ''));
        $accountNumber = trim((string) ($_POST['account_number'] ?? ''));
        $openingBalance = money_value($_POST['opening_balance'] ?? 0);
        $makeDefault = (int) ($_POST['is_default'] ?? 0) === 1;

        if ($name === '') {
            respond(422, ['ok' => false, 'message' => 'Account name is required.']);
        }

        $pdo->beginTransaction();
        try {
            if ($action === 'update') {
                $accountId = (int) ($_POST['account_id'] ?? 0);
                fetch_account_or_404($pdo, $businessId, $accountId);
                // Opening balance is immutable after creation to keep the ledger honest.
                $pdo->prepare(
                    'UPDATE tbl_accounts SET name = :name, type = :type, bank_name = :bank, account_number = :num
                     WHERE id = :id AND business_id = :bid'
                )->execute([
                    ':name' => $name,
                    ':type' => $type,
                    ':bank' => $bankName !== '' ? $bankName : null,
                    ':num' => $accountNumber !== '' ? $accountNumber : null,
                    ':id' => $accountId,
                    ':bid' => $businessId,
                ]);
            } else {
                $ins = $pdo->prepare(
                    'INSERT INTO tbl_accounts (business_id, name, type, bank_name, account_number, opening_balance, is_default, status)
                     VALUES (:bid, :name, :type, :bank, :num, :open, 0, "active")'
                );
                $ins->execute([
                    ':bid' => $businessId,
                    ':name' => $name,
                    ':type' => $type,
                    ':bank' => $bankName !== '' ? $bankName : null,
                    ':num' => $accountNumber !== '' ? $accountNumber : null,
                    ':open' => round($openingBalance, 2),
                ]);
                $accountId = (int) $pdo->lastInsertId();
            }

            if ($makeDefault) {
                $pdo->prepare('UPDATE tbl_accounts SET is_default = 0 WHERE business_id = :bid')->execute([':bid' => $businessId]);
                $pdo->prepare('UPDATE tbl_accounts SET is_default = 1 WHERE id = :id AND business_id = :bid')
                    ->execute([':id' => $accountId, ':bid' => $businessId]);
            }

            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        $accounts = load_accounts($pdo, $businessId);
        respond($action === 'create' ? 201 : 200, [
            'ok' => true,
            'message' => $action === 'create' ? 'Account created.' : 'Account updated.',
            'accounts' => $accounts,
            'summary' => account_summary($accounts),
        ]);
    }

    if ($action === 'set_default') {
        require_permission($pdo, $businessId, $userId, 'accounts.manage');
        $accountId = (int) ($_POST['account_id'] ?? 0);
        fetch_account_or_404($pdo, $businessId, $accountId);
        $pdo->beginTransaction();
        try {
            $pdo->prepare('UPDATE tbl_accounts SET is_default = 0 WHERE business_id = :bid')->execute([':bid' => $businessId]);
            $pdo->prepare('UPDATE tbl_accounts SET is_default = 1 WHERE id = :id AND business_id = :bid')
                ->execute([':id' => $accountId, ':bid' => $businessId]);
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
        $accounts = load_accounts($pdo, $businessId);
        respond(200, ['ok' => true, 'message' => 'Default account updated.', 'accounts' => $accounts, 'summary' => account_summary($accounts)]);
    }

    if ($action === 'delete') {
        require_permission($pdo, $businessId, $userId, 'accounts.manage');
        $accountId = (int) ($_POST['account_id'] ?? 0);
        $row = fetch_account_or_404($pdo, $businessId, $accountId);
        if ((int) $row['is_default'] === 1) {
            respond(422, ['ok' => false, 'message' => 'Set another account as default before deleting this one.']);
        }
        $countStmt = $pdo->prepare('SELECT COUNT(*) FROM tbl_accounts WHERE business_id = :bid');
        $countStmt->execute([':bid' => $businessId]);
        if ((int) $countStmt->fetchColumn() <= 1) {
            respond(422, ['ok' => false, 'message' => 'A business must have at least one account.']);
        }

        $pdo->beginTransaction();
        try {
            $pdo->prepare('DELETE FROM tbl_account_transactions WHERE account_id = :id AND business_id = :bid')
                ->execute([':id' => $accountId, ':bid' => $businessId]);
            $pdo->prepare('DELETE FROM tbl_accounts WHERE id = :id AND business_id = :bid')
                ->execute([':id' => $accountId, ':bid' => $businessId]);
            audit_log($pdo, $businessId, $userId, 'account_deleted', 'account', (string) $accountId, $row, null);
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        $accounts = load_accounts($pdo, $businessId);
        respond(200, ['ok' => true, 'message' => 'Account deleted.', 'accounts' => $accounts, 'summary' => account_summary($accounts)]);
    }

    if ($action === 'deposit' || $action === 'withdraw' || $action === 'expense') {
        $accountId = (int) ($_POST['account_id'] ?? 0);
        $amount = money_value($_POST['amount'] ?? 0);
        $notes = trim((string) ($_POST['notes'] ?? ''));
        $categoryId = (int) ($_POST['category_id'] ?? 0);
        fetch_account_or_404($pdo, $businessId, $accountId);

        if ($amount <= 0) {
            respond(422, ['ok' => false, 'message' => 'Enter an amount greater than zero.']);
        }

        if ($action === 'deposit') {
            $direction = 'in';
            $type = 'deposit';
        } elseif ($action === 'withdraw') {
            $direction = 'out';
            $type = 'withdrawal';
        } else {
            $direction = 'out';
            $type = 'expense';
            if ($categoryId <= 0) {
                respond(422, ['ok' => false, 'message' => 'Choose an expense category.']);
            }
            $category = fetch_category_or_404($pdo, $businessId, $categoryId);
            if (($category['status'] ?? 'active') !== 'active') {
                respond(422, ['ok' => false, 'message' => 'Choose an active expense category.']);
            }
        }

        if ($direction === 'out') {
            $balance = account_balance($pdo, $businessId, $accountId);
            if ($amount > $balance) {
                respond(422, ['ok' => false, 'message' => 'Insufficient balance. Available: ' . number_format($balance, 0) . '.']);
            }
        }

        post_account_txn($pdo, $businessId, $accountId, $direction, $type, $amount, 'manual', null, $notes !== '' ? $notes : null, $userId, null, $type === 'expense' ? $categoryId : null);
        audit_log($pdo, $businessId, $userId, $type . '_recorded', 'account_transaction', null, null, [
            'account_id' => $accountId,
            'direction' => $direction,
            'type' => $type,
            'amount' => $amount,
            'notes' => $notes,
            'expense_category_id' => $type === 'expense' ? $categoryId : null,
        ]);

        $accounts = load_accounts($pdo, $businessId);
        respond(200, ['ok' => true, 'message' => 'Transaction recorded.', 'accounts' => $accounts, 'summary' => account_summary($accounts)]);
    }

    if ($action === 'create_expense_category') {
        require_permission($pdo, $businessId, $userId, 'expenses.manage_categories');
        $name = trim((string) ($_POST['name'] ?? ''));
        if ($name === '') {
            respond(422, ['ok' => false, 'message' => 'Category name is required.']);
        }
        if (strlen($name) > 190) {
            respond(422, ['ok' => false, 'message' => 'Category name is too long.']);
        }
        try {
            $stmt = $pdo->prepare('INSERT INTO tbl_expense_categories (business_id, name, status) VALUES (:bid, :name, "active")');
            $stmt->execute([':bid' => $businessId, ':name' => $name]);
        } catch (PDOException $e) {
            respond(422, ['ok' => false, 'message' => 'That category already exists.']);
        }
        respond(201, ['ok' => true, 'message' => 'Category added.', 'categories' => load_expense_categories($pdo, $businessId)]);
    }

    if ($action === 'set_expense_category_status') {
        require_permission($pdo, $businessId, $userId, 'expenses.manage_categories');
        $categoryId = (int) ($_POST['category_id'] ?? 0);
        $status = strtolower(trim((string) ($_POST['status'] ?? 'active')));
        if (!in_array($status, ['active', 'inactive'], true)) {
            respond(422, ['ok' => false, 'message' => 'Invalid category status.']);
        }
        $category = fetch_category_or_404($pdo, $businessId, $categoryId);
        $stmt = $pdo->prepare('UPDATE tbl_expense_categories SET status = :status WHERE id = :id AND business_id = :bid');
        $stmt->execute([':status' => $status, ':id' => $categoryId, ':bid' => $businessId]);
        audit_log($pdo, $businessId, $userId, 'expense_category_status_changed', 'expense_category', (string) $categoryId, [
            'status' => $category['status'] ?? null,
        ], [
            'status' => $status,
        ]);
        respond(200, ['ok' => true, 'message' => 'Category updated.', 'categories' => load_expense_categories($pdo, $businessId)]);
    }

    if ($action === 'delete_expense_category') {
        require_permission($pdo, $businessId, $userId, 'expenses.manage_categories');
        $categoryId = (int) ($_POST['category_id'] ?? 0);
        $category = fetch_category_or_404($pdo, $businessId, $categoryId);
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM tbl_account_transactions WHERE business_id = :bid AND expense_category_id = :id AND type = "expense"');
        $stmt->execute([':bid' => $businessId, ':id' => $categoryId]);
        if ((int) $stmt->fetchColumn() > 0) {
            respond(422, ['ok' => false, 'message' => 'This category is already used. Deactivate it instead.']);
        }
        $del = $pdo->prepare('DELETE FROM tbl_expense_categories WHERE id = :id AND business_id = :bid');
        $del->execute([':id' => $categoryId, ':bid' => $businessId]);
        audit_log($pdo, $businessId, $userId, 'expense_category_deleted', 'expense_category', (string) $categoryId, $category, null);
        respond(200, ['ok' => true, 'message' => 'Category deleted.', 'categories' => load_expense_categories($pdo, $businessId)]);
    }

    if ($action === 'prepare_payroll') {
        require_permission($pdo, $businessId, $userId, 'payroll.manage');
        $month = trim((string) ($_POST['month'] ?? date('Y-m')));
        if (!valid_payroll_month($month)) {
            respond(422, ['ok' => false, 'message' => 'Choose a valid payroll month.']);
        }
        $salaryCategoryId = ensure_salary_category($pdo, $businessId);

        $pdo->beginTransaction();
        try {
            $runStmt = $pdo->prepare('SELECT id FROM tbl_payroll_runs WHERE business_id = :bid AND payroll_month = :month LIMIT 1');
            $runStmt->execute([':bid' => $businessId, ':month' => $month]);
            $runId = (int) ($runStmt->fetchColumn() ?: 0);
            if ($runId <= 0) {
                $ins = $pdo->prepare('INSERT INTO tbl_payroll_runs (business_id, payroll_month, expense_category_id, created_by) VALUES (:bid, :month, :cat, :uid)');
                $ins->execute([':bid' => $businessId, ':month' => $month, ':cat' => $salaryCategoryId, ':uid' => $userId]);
                $runId = (int) $pdo->lastInsertId();
            }

            $ownerStmt = $pdo->prepare('SELECT owner_user_id FROM tbl_businesses WHERE id = :bid LIMIT 1');
            $ownerStmt->execute([':bid' => $businessId]);
            $ownerId = (int) ($ownerStmt->fetchColumn() ?: 0);

            $staffStmt = $pdo->prepare('SELECT id, full_name, role, monthly_salary FROM tbl_users WHERE business_id = :bid AND id != :owner AND status = "active" ORDER BY full_name ASC');
            $staffStmt->execute([':bid' => $businessId, ':owner' => $ownerId]);
            $itemIns = $pdo->prepare(
                'INSERT IGNORE INTO tbl_payroll_items (run_id, business_id, user_id, staff_name, role, salary_amount)
                 VALUES (:run, :bid, :uid, :name, :role, :salary)'
            );
            foreach ($staffStmt->fetchAll() as $staff) {
                $itemIns->execute([
                    ':run' => $runId,
                    ':bid' => $businessId,
                    ':uid' => (int) $staff['id'],
                    ':name' => (string) $staff['full_name'],
                    ':role' => (string) ($staff['role'] ?? 'staff'),
                    ':salary' => round((float) ($staff['monthly_salary'] ?? 0), 2),
                ]);
            }
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        respond(200, ['ok' => true, 'message' => 'Payroll prepared.'] + payroll_payload($pdo, $businessId, $month));
    }

    if ($action === 'update_payroll_item') {
        require_permission($pdo, $businessId, $userId, 'payroll.manage');
        $itemId = (int) ($_POST['item_id'] ?? 0);
        $amount = money_value($_POST['salary_amount'] ?? 0);
        if ($amount < 0) {
            respond(422, ['ok' => false, 'message' => 'Salary cannot be negative.']);
        }
        $stmt = $pdo->prepare(
            'SELECT i.*, r.payroll_month FROM tbl_payroll_items i
             JOIN tbl_payroll_runs r ON r.id = i.run_id
             WHERE i.id = :id AND i.business_id = :bid LIMIT 1'
        );
        $stmt->execute([':id' => $itemId, ':bid' => $businessId]);
        $item = $stmt->fetch();
        if (!$item) {
            respond(404, ['ok' => false, 'message' => 'Payroll item not found.']);
        }
        if (($item['status'] ?? 'pending') === 'paid') {
            respond(422, ['ok' => false, 'message' => 'Paid salary cannot be changed.']);
        }
        $pdo->prepare('UPDATE tbl_payroll_items SET salary_amount = :amount WHERE id = :id AND business_id = :bid')
            ->execute([':amount' => $amount, ':id' => $itemId, ':bid' => $businessId]);
        audit_log($pdo, $businessId, $userId, 'payroll_salary_updated', 'payroll_item', (string) $itemId, [
            'salary_amount' => (float) $item['salary_amount'],
        ], [
            'salary_amount' => $amount,
            'payroll_month' => $item['payroll_month'],
        ]);
        respond(200, ['ok' => true, 'message' => 'Salary updated.'] + payroll_payload($pdo, $businessId, (string) $item['payroll_month']));
    }

    if ($action === 'pay_payroll') {
        require_permission($pdo, $businessId, $userId, 'payroll.manage');
        $runId = (int) ($_POST['run_id'] ?? 0);
        $accountId = (int) ($_POST['account_id'] ?? 0);
        fetch_account_or_404($pdo, $businessId, $accountId);
        $runStmt = $pdo->prepare('SELECT * FROM tbl_payroll_runs WHERE id = :id AND business_id = :bid LIMIT 1');
        $runStmt->execute([':id' => $runId, ':bid' => $businessId]);
        $run = $runStmt->fetch();
        if (!$run) {
            respond(404, ['ok' => false, 'message' => 'Payroll run not found.']);
        }

        $itemsStmt = $pdo->prepare('SELECT * FROM tbl_payroll_items WHERE run_id = :run AND business_id = :bid AND status = "pending" ORDER BY staff_name ASC');
        $itemsStmt->execute([':run' => $runId, ':bid' => $businessId]);
        $items = $itemsStmt->fetchAll();
        $payable = array_values(array_filter($items, static fn($item) => (float) $item['salary_amount'] > 0));
        if (empty($payable)) {
            respond(422, ['ok' => false, 'message' => 'Set at least one salary amount before paying.']);
        }
        $total = array_reduce($payable, static fn($sum, $item) => $sum + (float) $item['salary_amount'], 0.0);
        $balance = account_balance($pdo, $businessId, $accountId);
        if ($total > $balance) {
            respond(422, ['ok' => false, 'message' => 'Insufficient balance. Available: ' . number_format($balance, 0) . '.']);
        }
        $salaryCategoryId = ensure_salary_category($pdo, $businessId);

        $pdo->beginTransaction();
        try {
            foreach ($payable as $item) {
                $txnId = post_account_txn(
                    $pdo,
                    $businessId,
                    $accountId,
                    'out',
                    'expense',
                    (float) $item['salary_amount'],
                    'payroll',
                    (string) $item['id'],
                    'Salary ' . $run['payroll_month'] . ' - ' . $item['staff_name'],
                    $userId,
                    null,
                    $salaryCategoryId
                );
                $pdo->prepare('UPDATE tbl_payroll_items SET status = "paid", account_txn_id = :txn, paid_at = NOW() WHERE id = :id AND business_id = :bid')
                    ->execute([':txn' => $txnId, ':id' => (int) $item['id'], ':bid' => $businessId]);
            }
            $remainingStmt = $pdo->prepare('SELECT COUNT(*) FROM tbl_payroll_items WHERE run_id = :run AND business_id = :bid AND status = "pending" AND salary_amount > 0');
            $remainingStmt->execute([':run' => $runId, ':bid' => $businessId]);
            $status = (int) $remainingStmt->fetchColumn() > 0 ? 'partial' : 'paid';
            $pdo->prepare('UPDATE tbl_payroll_runs SET status = :status, expense_category_id = :cat WHERE id = :id AND business_id = :bid')
                ->execute([':status' => $status, ':cat' => $salaryCategoryId, ':id' => $runId, ':bid' => $businessId]);
            audit_log($pdo, $businessId, $userId, 'payroll_paid', 'payroll_run', (string) $runId, [
                'status' => $run['status'],
            ], [
                'status' => $status,
                'payroll_month' => $run['payroll_month'],
                'account_id' => $accountId,
                'total_amount' => $total,
                'paid_items' => count($payable),
            ]);
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        respond(200, ['ok' => true, 'message' => 'Payroll paid.'] + payroll_payload($pdo, $businessId, (string) $run['payroll_month']));
    }

    if ($action === 'transfer') {
        require_permission($pdo, $businessId, $userId, 'accounts.transfer');
        $fromId = (int) ($_POST['account_id'] ?? 0);
        $toId = (int) ($_POST['to_account_id'] ?? 0);
        $amount = money_value($_POST['amount'] ?? 0);
        $notes = trim((string) ($_POST['notes'] ?? ''));

        if ($fromId <= 0 || $toId <= 0 || $fromId === $toId) {
            respond(422, ['ok' => false, 'message' => 'Choose two different accounts.']);
        }
        $from = fetch_account_or_404($pdo, $businessId, $fromId);
        $to = fetch_account_or_404($pdo, $businessId, $toId);
        if ($amount <= 0) {
            respond(422, ['ok' => false, 'message' => 'Enter an amount greater than zero.']);
        }

        $balance = account_balance($pdo, $businessId, $fromId);
        if ($amount > $balance) {
            respond(422, ['ok' => false, 'message' => 'Insufficient balance in "' . $from['name'] . '". Available: ' . number_format($balance, 0) . '.']);
        }

        $pdo->beginTransaction();
        try {
            post_account_txn($pdo, $businessId, $fromId, 'out', 'transfer_out', $amount, 'transfer', null, ('Transfer to ' . $to['name'] . ($notes !== '' ? ' — ' . $notes : '')), $userId, $toId);
            post_account_txn($pdo, $businessId, $toId, 'in', 'transfer_in', $amount, 'transfer', null, ('Transfer from ' . $from['name'] . ($notes !== '' ? ' — ' . $notes : '')), $userId, $fromId);
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        $accounts = load_accounts($pdo, $businessId);
        respond(200, ['ok' => true, 'message' => 'Transfer completed.', 'accounts' => $accounts, 'summary' => account_summary($accounts)]);
    }

    if ($action === 'email_expenses_pdf') {
        $level = trim((string) ($_POST['level'] ?? 'years'));
        $year = trim((string) ($_POST['year'] ?? ''));
        $month = trim((string) ($_POST['month'] ?? ''));
        $date = trim((string) ($_POST['date'] ?? ''));
        $recipient = trim((string) ($_POST['recipient_email'] ?? ''));
        if ($recipient === '' || !filter_var($recipient, FILTER_VALIDATE_EMAIL)) {
            respond(422, ['ok' => false, 'message' => 'Please provide a valid recipient email address.']);
        }
        $result = send_expenses_email($pdo, $businessId, $level, $year, $month, $date, $recipient);
        respond($result['ok'] ? 200 : 422, $result);
    }

    if ($action === 'email_payroll_pdf') {
        $month = trim((string) ($_POST['month'] ?? date('Y-m')));
        $recipient = trim((string) ($_POST['recipient_email'] ?? ''));
        if ($recipient === '' || !filter_var($recipient, FILTER_VALIDATE_EMAIL)) {
            respond(422, ['ok' => false, 'message' => 'Please provide a valid recipient email address.']);
        }
        $result = send_payroll_email($pdo, $businessId, $month, $recipient);
        respond($result['ok'] ? 200 : 422, $result);
    }

    if ($action === 'email_categories_pdf') {
        $recipient = trim((string) ($_POST['recipient_email'] ?? ''));
        if ($recipient === '' || !filter_var($recipient, FILTER_VALIDATE_EMAIL)) {
            respond(422, ['ok' => false, 'message' => 'Please provide a valid recipient email address.']);
        }
        $result = send_categories_email($pdo, $businessId, $recipient);
        respond($result['ok'] ? 200 : 422, $result);
    }

    respond(422, ['ok' => false, 'message' => 'Unknown account action.']);
} catch (Throwable $error) {
    respond(500, ['ok' => false, 'message' => 'Unable to process accounts right now.']);
}
