<?php
declare(strict_types=1);

/**
 * Shared POS shift (till) helpers. Declares functions only — safe to require_once
 * from any endpoint or migration.
 */

function ensure_pos_shifts_table(PDO $pdo): void
{
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS tbl_pos_shifts (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            business_id INT UNSIGNED NOT NULL,
            user_id INT UNSIGNED NOT NULL,
            opened_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            opening_balance DECIMAL(14, 2) NOT NULL DEFAULT 0.00,
            closed_at TIMESTAMP NULL,
            closing_balance DECIMAL(14, 2) NULL,
            expected_cash DECIMAL(14, 2) NULL,
            cash_sales DECIMAL(14, 2) NULL,
            sales_total DECIMAL(14, 2) NULL,
            sales_count INT UNSIGNED NULL,
            variance DECIMAL(14, 2) NULL,
            status ENUM("open", "closed") NOT NULL DEFAULT "open",
            notes TEXT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            KEY idx_shift_biz_status (business_id, status),
            KEY idx_shift_biz_user (business_id, user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );
}

/** Self-heals the POS link columns on tbl_sales (shift_id, payment_method). */
function ensure_sales_pos_columns(PDO $pdo): void
{
    $addColumn = static function (string $table, string $column, string $definition) use ($pdo): void {
        try {
            $cols = $pdo->query("SHOW COLUMNS FROM {$table} LIKE '{$column}'")->fetchAll();
        } catch (Throwable) {
            return;
        }
        if (empty($cols)) {
            $pdo->exec("ALTER TABLE {$table} ADD COLUMN {$column} {$definition}");
        }
    };

    $addColumn('tbl_sales', 'shift_id', 'INT UNSIGNED NULL AFTER created_by');
    $addColumn('tbl_sales', 'payment_method', 'VARCHAR(30) NULL AFTER shift_id');
}

/** Returns the user's currently open shift row, or null. */
function current_open_shift(PDO $pdo, int $businessId, int $userId): ?array
{
    $stmt = $pdo->prepare(
        'SELECT * FROM tbl_pos_shifts WHERE business_id = :bid AND user_id = :uid AND status = "open" ORDER BY id DESC LIMIT 1'
    );
    $stmt->execute([':bid' => $businessId, ':uid' => $userId]);
    $row = $stmt->fetch();
    return $row ?: null;
}

/** Live totals for a shift computed from its POS sales. */
function shift_sales_totals(PDO $pdo, int $businessId, int $shiftId): array
{
    $stmt = $pdo->prepare(
        'SELECT
            COUNT(*) AS cnt,
            COALESCE(SUM(amount_paid), 0) AS paid_total,
            COALESCE(SUM(total_amount), 0) AS sales_total,
            COALESCE(SUM(CASE WHEN payment_method = "cash" THEN amount_paid ELSE 0 END), 0) AS cash_sales
         FROM tbl_sales
         WHERE business_id = :bid AND sale_type = "pos" AND status != "cancelled" AND shift_id = :sid'
    );
    $stmt->execute([':bid' => $businessId, ':sid' => $shiftId]);
    $r = $stmt->fetch() ?: [];
    return [
        'sales_count' => (int) ($r['cnt'] ?? 0),
        'sales_total' => (float) ($r['sales_total'] ?? 0),
        'cash_sales' => (float) ($r['cash_sales'] ?? 0),
        'paid_total' => (float) ($r['paid_total'] ?? 0),
    ];
}
