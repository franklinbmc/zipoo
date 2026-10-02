<?php
declare(strict_types=1);

/**
 * Shared Bank & Cash account helpers. Safe to require_once from any endpoint or
 * migration: declares functions only, no request-handling side effects.
 *
 * Account `type` is stored as one of: cash | bank | mobile.
 * The UI labels `mobile` as "Lipa kwa simu".
 */

function ensure_accounts_tables(PDO $pdo): void
{
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS tbl_accounts (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            business_id INT UNSIGNED NOT NULL,
            name VARCHAR(190) NOT NULL,
            type ENUM("cash", "bank", "mobile") NOT NULL DEFAULT "cash",
            bank_name VARCHAR(190) NULL,
            account_number VARCHAR(100) NULL,
            opening_balance DECIMAL(14, 2) NOT NULL DEFAULT 0.00,
            is_default TINYINT(1) NOT NULL DEFAULT 0,
            status ENUM("active", "inactive") NOT NULL DEFAULT "active",
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            KEY idx_acc_biz (business_id),
            KEY idx_acc_default (business_id, is_default),
            KEY idx_acc_type (business_id, type)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS tbl_account_transactions (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            business_id INT UNSIGNED NOT NULL,
            account_id INT UNSIGNED NOT NULL,
            direction ENUM("in", "out") NOT NULL,
            type ENUM("sale", "invoice_payment", "rent", "deposit", "withdrawal", "transfer_in", "transfer_out", "expense", "opening", "adjustment") NOT NULL,
            amount DECIMAL(14, 2) NOT NULL DEFAULT 0.00,
            reference_type VARCHAR(50) NULL,
            reference_id VARCHAR(100) NULL,
            counterparty_account_id INT UNSIGNED NULL,
            notes TEXT NULL,
            created_by INT UNSIGNED NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            KEY idx_at_biz_acc (business_id, account_id),
            KEY idx_at_date (created_at),
            KEY idx_at_ref (business_id, reference_type, reference_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );
}

/**
 * Self-heals the account-transaction `type` ENUM on databases created before
 * the `rent` value existed. Cheap: one SHOW COLUMNS, ALTER only when missing.
 */
function ensure_rent_txn_type(PDO $pdo): void
{
    ensure_accounts_tables($pdo);
    $col = $pdo->query("SHOW COLUMNS FROM tbl_account_transactions LIKE 'type'")->fetch();
    if (!$col) {
        return;
    }
    $definition = (string) ($col['Type'] ?? '');
    if (stripos($definition, "'rent'") !== false) {
        return;
    }
    $pdo->exec(
        'ALTER TABLE tbl_account_transactions MODIFY COLUMN type
         ENUM("sale", "invoice_payment", "rent", "deposit", "withdrawal", "transfer_in", "transfer_out", "expense", "opening", "adjustment") NOT NULL'
    );
}

/**
 * Guarantees the business has at least one account, creating a default "Cash"
 * account when none exist. Returns the default account id.
 */
function ensure_default_account(PDO $pdo, int $businessId): int
{
    ensure_accounts_tables($pdo);

    $stmt = $pdo->prepare('SELECT id FROM tbl_accounts WHERE business_id = :bid AND is_default = 1 LIMIT 1');
    $stmt->execute([':bid' => $businessId]);
    $defaultId = (int) ($stmt->fetchColumn() ?: 0);
    if ($defaultId > 0) {
        return $defaultId;
    }

    // An account exists but none flagged default: promote the first one.
    $stmt = $pdo->prepare('SELECT id FROM tbl_accounts WHERE business_id = :bid ORDER BY id ASC LIMIT 1');
    $stmt->execute([':bid' => $businessId]);
    $existingId = (int) ($stmt->fetchColumn() ?: 0);
    if ($existingId > 0) {
        $pdo->prepare('UPDATE tbl_accounts SET is_default = 1 WHERE id = :id')->execute([':id' => $existingId]);
        return $existingId;
    }

    $ins = $pdo->prepare(
        'INSERT INTO tbl_accounts (business_id, name, type, is_default, status)
         VALUES (:bid, "Cash", "cash", 1, "active")'
    );
    $ins->execute([':bid' => $businessId]);

    return (int) $pdo->lastInsertId();
}

/** Maps a POS payment-method string to an account type (cash|bank|mobile). */
function account_type_for_payment_method(string $method): string
{
    $m = strtolower(trim($method));
    if ($m === 'bank' || $m === 'card' || $m === 'cheque' || $m === 'check' || $m === 'transfer') {
        return 'bank';
    }
    if ($m === 'mobile' || $m === 'lipa' || $m === 'lipa_namba' || $m === 'mpesa' || $m === 'tigopesa' || $m === 'airtel' || $m === 'mobile_money') {
        return 'mobile';
    }
    return 'cash';
}

/**
 * Resolves the account money should land in for a given type, preferring the
 * default account of that type, then any active account of that type, then the
 * business default account. Returns 0 when the business has no accounts at all.
 */
function resolve_account_for_type(PDO $pdo, int $businessId, string $type): int
{
    $stmt = $pdo->prepare(
        'SELECT id FROM tbl_accounts
         WHERE business_id = :bid AND type = :type AND status = "active"
         ORDER BY is_default DESC, id ASC LIMIT 1'
    );
    $stmt->execute([':bid' => $businessId, ':type' => $type]);
    $id = (int) ($stmt->fetchColumn() ?: 0);
    if ($id > 0) {
        return $id;
    }

    return ensure_default_account($pdo, $businessId);
}

/**
 * Records a single ledger entry. Caller is responsible for transaction scope.
 * Returns the new transaction id.
 */
function post_account_txn(
    PDO $pdo,
    int $businessId,
    int $accountId,
    string $direction,
    string $type,
    float $amount,
    ?string $referenceType = null,
    ?string $referenceId = null,
    ?string $notes = null,
    ?int $userId = null,
    ?int $counterpartyAccountId = null
): int {
    $stmt = $pdo->prepare(
        'INSERT INTO tbl_account_transactions
            (business_id, account_id, direction, type, amount, reference_type, reference_id, counterparty_account_id, notes, created_by)
         VALUES (:bid, :acc, :dir, :type, :amt, :rtype, :rid, :cp, :notes, :uid)'
    );
    $stmt->execute([
        ':bid' => $businessId,
        ':acc' => $accountId,
        ':dir' => $direction,
        ':type' => $type,
        ':amt' => round($amount, 2),
        ':rtype' => $referenceType,
        ':rid' => $referenceId,
        ':cp' => $counterpartyAccountId,
        ':notes' => $notes,
        ':uid' => $userId,
    ]);

    return (int) $pdo->lastInsertId();
}

/** True when a ledger entry for this reference already exists (idempotency guard). */
function account_txn_exists(PDO $pdo, int $businessId, string $referenceType, string $referenceId, string $type): bool
{
    $stmt = $pdo->prepare(
        'SELECT 1 FROM tbl_account_transactions
         WHERE business_id = :bid AND reference_type = :rtype AND reference_id = :rid AND type = :type LIMIT 1'
    );
    $stmt->execute([':bid' => $businessId, ':rtype' => $referenceType, ':rid' => $referenceId, ':type' => $type]);

    return (bool) $stmt->fetchColumn();
}

/** Computed balance for one account: opening + sum(in) - sum(out). */
function account_balance(PDO $pdo, int $businessId, int $accountId): float
{
    $stmt = $pdo->prepare(
        'SELECT a.opening_balance
                + COALESCE((SELECT SUM(CASE WHEN t.direction = "in" THEN t.amount ELSE -t.amount END)
                            FROM tbl_account_transactions t WHERE t.account_id = a.id), 0) AS balance
         FROM tbl_accounts a WHERE a.id = :id AND a.business_id = :bid LIMIT 1'
    );
    $stmt->execute([':id' => $accountId, ':bid' => $businessId]);

    return (float) ($stmt->fetchColumn() ?: 0);
}
