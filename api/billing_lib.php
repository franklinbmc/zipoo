<?php
declare(strict_types=1);

require_once __DIR__ . '/lipa_sms_parser_lib.php';

function ensure_billing_tables(PDO $pdo): void
{
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS tbl_saas_plans (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            plan_name VARCHAR(80) NOT NULL UNIQUE,
            monthly_price DECIMAL(14,2) NOT NULL DEFAULT 0.00,
            user_limit INT UNSIGNED NOT NULL DEFAULT 1,
            business_limit INT UNSIGNED NOT NULL DEFAULT 1,
            extra_business_price DECIMAL(14,2) NOT NULL DEFAULT 0.00,
            included_sms INT UNSIGNED NOT NULL DEFAULT 0,
            status ENUM("active", "inactive") NOT NULL DEFAULT "active",
            notes VARCHAR(255) NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );

    $planCount = (int) $pdo->query('SELECT COUNT(*) FROM tbl_saas_plans')->fetchColumn();
    if ($planCount === 0) {
        $stmt = $pdo->prepare(
            'INSERT INTO tbl_saas_plans (plan_name, monthly_price, user_limit, business_limit, extra_business_price, included_sms, status, notes)
             VALUES (:name, :price, :users, :businesses, :extra_business_price, :included_sms, "active", :notes)'
        );
        foreach ([
            ['Starter', 0, 3, 1, 10000, 0, 'Entry plan for small shops.'],
            ['Growth', 25000, 10, 2, 10000, 100, 'Growing team plan.'],
            ['Business', 50000, 25, 5, 8000, 500, 'SME operations plan.'],
        ] as $plan) {
            $stmt->execute([
                ':name' => $plan[0],
                ':price' => $plan[1],
                ':users' => $plan[2],
                ':businesses' => $plan[3],
                ':extra_business_price' => $plan[4],
                ':included_sms' => $plan[5],
                ':notes' => $plan[6],
            ]);
        }
    }

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS tbl_saas_settings (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            setting_group VARCHAR(50) NOT NULL,
            setting_key VARCHAR(100) NOT NULL,
            setting_value LONGTEXT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY unique_group_key (setting_group, setting_key)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS tbl_billing_accounts (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            owner_user_id BIGINT UNSIGNED NOT NULL UNIQUE,
            account_name VARCHAR(160) NOT NULL,
            status ENUM("active","grace","suspended","cancelled") NOT NULL DEFAULT "active",
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            KEY idx_status (status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );
    $accountOwnerCol = $pdo->query("SHOW COLUMNS FROM tbl_billing_accounts LIKE 'owner_user_id'")->fetchAll();
    if (empty($accountOwnerCol)) {
        $pdo->exec('ALTER TABLE tbl_billing_accounts ADD COLUMN owner_user_id BIGINT UNSIGNED NOT NULL AFTER id');
        $pdo->exec('ALTER TABLE tbl_billing_accounts ADD UNIQUE KEY unique_owner_user (owner_user_id)');
    }

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS tbl_account_businesses (
            account_id BIGINT UNSIGNED NOT NULL,
            business_id BIGINT UNSIGNED NOT NULL UNIQUE,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (account_id, business_id),
            KEY idx_account (account_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS tbl_plan_features (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            plan_id BIGINT UNSIGNED NOT NULL,
            feature_key VARCHAR(120) NOT NULL,
            feature_value VARCHAR(120) NOT NULL DEFAULT "1",
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY unique_plan_feature (plan_id, feature_key),
            KEY idx_plan (plan_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );

    $extraBusiness = $pdo->query("SHOW COLUMNS FROM tbl_saas_plans LIKE 'extra_business_price'")->fetchAll();
    if (empty($extraBusiness)) {
        $pdo->exec("ALTER TABLE tbl_saas_plans ADD COLUMN extra_business_price DECIMAL(14,2) NOT NULL DEFAULT 0.00 AFTER business_limit");
    }
    $includedSms = $pdo->query("SHOW COLUMNS FROM tbl_saas_plans LIKE 'included_sms'")->fetchAll();
    if (empty($includedSms)) {
        $pdo->exec("ALTER TABLE tbl_saas_plans ADD COLUMN included_sms INT UNSIGNED NOT NULL DEFAULT 0 AFTER extra_business_price");
    }

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS tbl_account_subscriptions (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            account_id BIGINT UNSIGNED NOT NULL UNIQUE,
            plan_id BIGINT UNSIGNED NULL,
            businesses_paid_count INT UNSIGNED NULL,
            status ENUM("trial","active","grace","suspended","cancelled") NOT NULL DEFAULT "trial",
            current_period_start DATE NULL,
            current_period_end DATE NULL,
            next_due_date DATE NULL,
            grace_until DATE NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            KEY idx_status (status),
            KEY idx_plan (plan_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );
    $paidBusinesses = $pdo->query("SHOW COLUMNS FROM tbl_account_subscriptions LIKE 'businesses_paid_count'")->fetchAll();
    if (empty($paidBusinesses)) {
        $pdo->exec("ALTER TABLE tbl_account_subscriptions ADD COLUMN businesses_paid_count INT UNSIGNED NULL AFTER plan_id");
    }

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS tbl_account_subscription_invoices (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            account_id BIGINT UNSIGNED NOT NULL,
            subscription_id BIGINT UNSIGNED NULL,
            invoice_number VARCHAR(40) NOT NULL UNIQUE,
            amount DECIMAL(14,2) NOT NULL DEFAULT 0.00,
            currency VARCHAR(10) NOT NULL DEFAULT "TZS",
            businesses_count INT UNSIGNED NOT NULL DEFAULT 1,
            extra_businesses_count INT UNSIGNED NOT NULL DEFAULT 0,
            payment_method VARCHAR(50) NULL,
            payment_reference VARCHAR(80) NULL,
            status ENUM("draft","pending_payment","pending_review","paid","cancelled","failed") NOT NULL DEFAULT "pending_payment",
            due_date DATE NULL,
            paid_at DATETIME NULL,
            notes TEXT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            KEY idx_account_status (account_id, status),
            KEY idx_reference (payment_reference)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS tbl_account_payments (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            account_id BIGINT UNSIGNED NOT NULL,
            invoice_id BIGINT UNSIGNED NULL,
            amount DECIMAL(14,2) NOT NULL DEFAULT 0.00,
            currency VARCHAR(10) NOT NULL DEFAULT "TZS",
            payment_method VARCHAR(50) NOT NULL,
            reference VARCHAR(80) NULL,
            status ENUM("pending","confirmed","rejected") NOT NULL DEFAULT "pending",
            received_at DATETIME NULL,
            recorded_by BIGINT UNSIGNED NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_account (account_id),
            KEY idx_invoice (invoice_id),
            KEY idx_reference (reference)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS tbl_sms_bundles (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(120) NOT NULL,
            sms_count INT UNSIGNED NOT NULL,
            price DECIMAL(14,2) NOT NULL DEFAULT 0.00,
            status ENUM("active","inactive") NOT NULL DEFAULT "active",
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS tbl_account_sms_wallets (
            account_id BIGINT UNSIGNED PRIMARY KEY,
            balance INT NOT NULL DEFAULT 0,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS tbl_account_sms_transactions (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            account_id BIGINT UNSIGNED NOT NULL,
            business_id BIGINT UNSIGNED NULL,
            type ENUM("purchase","send","refund","adjustment") NOT NULL,
            sms_count INT NOT NULL,
            balance_after INT NOT NULL,
            reference VARCHAR(80) NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_account (account_id),
            KEY idx_business (business_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS tbl_lipa_devices (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            uuid VARCHAR(36) NOT NULL UNIQUE,
            name VARCHAR(100) NOT NULL,
            token_hash CHAR(64) NULL UNIQUE,
            pairing_code_hash CHAR(64) NULL,
            pairing_expires_at DATETIME NULL,
            status ENUM("pending","active","revoked") NOT NULL DEFAULT "pending",
            device_model VARCHAR(100) NULL,
            app_version VARCHAR(20) NULL,
            last_seen_at DATETIME NULL,
            last_ip VARCHAR(45) NULL,
            created_by BIGINT UNSIGNED NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS tbl_lipa_transactions (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            device_id BIGINT UNSIGNED NULL,
            message_hash CHAR(64) NOT NULL UNIQUE,
            sender VARCHAR(50) NOT NULL,
            body TEXT NOT NULL,
            received_at DATETIME NULL,
            provider VARCHAR(30) NULL,
            reference VARCHAR(80) NULL UNIQUE,
            amount DECIMAL(14,2) NULL,
            payer_phone VARCHAR(20) NULL,
            payer_name VARCHAR(100) NULL,
            status ENUM("unparsed","unmatched","matched","review","ignored") NOT NULL DEFAULT "unmatched",
            invoice_id BIGINT UNSIGNED NULL,
            note VARCHAR(255) NULL,
            matched_at DATETIME NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
            KEY idx_status (status),
            KEY idx_received (received_at),
            KEY idx_invoice (invoice_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );
}

function saas_setting(PDO $pdo, string $group, string $key, string $default = ''): string
{
    $stmt = $pdo->prepare('SELECT setting_value FROM tbl_saas_settings WHERE setting_group = :grp AND setting_key = :key LIMIT 1');
    $stmt->execute([':grp' => $group, ':key' => $key]);
    $value = $stmt->fetchColumn();
    return $value === false || $value === null ? $default : (string) $value;
}

function ensure_account_for_owner(PDO $pdo, int $ownerUserId): int
{
    ensure_billing_tables($pdo);
    $stmt = $pdo->prepare('SELECT id FROM tbl_billing_accounts WHERE owner_user_id = :uid LIMIT 1');
    $stmt->execute([':uid' => $ownerUserId]);
    $accountId = (int) ($stmt->fetchColumn() ?: 0);
    if ($accountId > 0) {
        return $accountId;
    }

    $userStmt = $pdo->prepare('SELECT full_name FROM tbl_users WHERE id = :uid LIMIT 1');
    $userStmt->execute([':uid' => $ownerUserId]);
    $name = (string) ($userStmt->fetchColumn() ?: 'Zipoo Account');
    $pdo->prepare('INSERT INTO tbl_billing_accounts (owner_user_id, account_name) VALUES (:uid, :name)')
        ->execute([':uid' => $ownerUserId, ':name' => $name]);
    return (int) $pdo->lastInsertId();
}

function ensure_business_account(PDO $pdo, int $businessId): int
{
    ensure_billing_tables($pdo);
    $stmt = $pdo->prepare('SELECT account_id FROM tbl_account_businesses WHERE business_id = :bid LIMIT 1');
    $stmt->execute([':bid' => $businessId]);
    $accountId = (int) ($stmt->fetchColumn() ?: 0);
    if ($accountId > 0) {
        return $accountId;
    }

    $ownerStmt = $pdo->prepare('SELECT owner_user_id FROM tbl_businesses WHERE id = :bid LIMIT 1');
    $ownerStmt->execute([':bid' => $businessId]);
    $ownerId = (int) ($ownerStmt->fetchColumn() ?: 0);
    if ($ownerId <= 0) {
        return 0;
    }
    $accountId = ensure_account_for_owner($pdo, $ownerId);
    $pdo->prepare('INSERT IGNORE INTO tbl_account_businesses (account_id, business_id) VALUES (:aid, :bid)')
        ->execute([':aid' => $accountId, ':bid' => $businessId]);
    ensure_account_subscription($pdo, $accountId);
    return $accountId;
}

function ensure_account_subscription(PDO $pdo, int $accountId): int
{
    $stmt = $pdo->prepare('SELECT id FROM tbl_account_subscriptions WHERE account_id = :aid LIMIT 1');
    $stmt->execute([':aid' => $accountId]);
    $subscriptionId = (int) ($stmt->fetchColumn() ?: 0);
    if ($subscriptionId > 0) {
        return $subscriptionId;
    }

    $planId = (int) ($pdo->query('SELECT id FROM tbl_saas_plans WHERE status = "active" ORDER BY monthly_price ASC, id ASC LIMIT 1')->fetchColumn() ?: 0);
    $pdo->prepare(
        'INSERT INTO tbl_account_subscriptions
            (account_id, plan_id, status, current_period_start, current_period_end, next_due_date, grace_until)
         VALUES
            (:aid, :plan_id, "trial", CURDATE(), DATE_ADD(CURDATE(), INTERVAL 30 DAY), DATE_ADD(CURDATE(), INTERVAL 30 DAY), DATE_ADD(CURDATE(), INTERVAL 37 DAY))'
    )->execute([':aid' => $accountId, ':plan_id' => $planId > 0 ? $planId : null]);
    return (int) $pdo->lastInsertId();
}

function seed_billing_for_existing_businesses(PDO $pdo): void
{
    ensure_billing_tables($pdo);
    $businesses = $pdo->query('SELECT id FROM tbl_businesses WHERE owner_user_id IS NOT NULL AND owner_user_id > 0')->fetchAll(PDO::FETCH_COLUMN);
    foreach ($businesses as $businessId) {
        ensure_business_account($pdo, (int) $businessId);
    }
}

function billing_generate_invoice_number(int $accountId): string
{
    return 'ZP-' . date('Ym') . '-' . str_pad((string) $accountId, 5, '0', STR_PAD_LEFT) . '-' . strtoupper(bin2hex(random_bytes(2)));
}

function lipa_reconcile_reference(PDO $pdo, string $reference): array
{
    ensure_billing_tables($pdo);
    $reference = lipa_normalize_reference($reference);
    $pdo->beginTransaction();
    try {
        $txnStmt = $pdo->prepare('SELECT * FROM tbl_lipa_transactions WHERE reference = :ref FOR UPDATE');
        $txnStmt->execute([':ref' => $reference]);
        $txn = $txnStmt->fetch(PDO::FETCH_ASSOC) ?: null;

        $invoiceStmt = $pdo->prepare(
            'SELECT * FROM tbl_account_subscription_invoices
             WHERE payment_method = "lipa_namba" AND payment_reference = :ref AND status IN ("pending_payment","pending_review")
             ORDER BY id ASC LIMIT 1 FOR UPDATE'
        );
        $invoiceStmt->execute([':ref' => $reference]);
        $invoice = $invoiceStmt->fetch(PDO::FETCH_ASSOC) ?: null;

        if ($txn && !in_array($txn['status'], ['unmatched', 'review'], true)) {
            $pdo->commit();
            return ['outcome' => 'used', 'message' => 'This payment reference has already been used.'];
        }
        if (!$txn) {
            $pdo->commit();
            return ['outcome' => 'awaiting_sms', 'message' => 'Reference saved. Zipoo will confirm it automatically once the Lipa Namba SMS arrives, or SaaS can approve it manually.'];
        }
        if (!$invoice) {
            $pdo->commit();
            return ['outcome' => 'awaiting_claim', 'message' => 'Payment SMS received, but no Zipoo invoice has claimed this reference yet.'];
        }

        $due = (float) $invoice['amount'];
        $paid = (float) $txn['amount'];
        $autoApprove = saas_setting($pdo, 'lipa', 'lipa_auto_approve', '1') === '1';
        $maxAuto = (float) saas_setting($pdo, 'lipa', 'lipa_auto_approve_max', '0');
        $review = null;
        if ($paid + 0.999 < $due) {
            $review = 'Amount paid is less than invoice total.';
        } elseif (!$autoApprove) {
            $review = 'Lipa Namba auto validation is off.';
        } elseif ($maxAuto > 0 && $due > $maxAuto) {
            $review = 'Invoice total exceeds auto approval limit.';
        }

        if ($review !== null) {
            $pdo->prepare('UPDATE tbl_lipa_transactions SET status = "review", invoice_id = :iid, note = :note, updated_at = NOW() WHERE id = :id')
                ->execute([':iid' => $invoice['id'], ':note' => $review, ':id' => $txn['id']]);
            $pdo->prepare('UPDATE tbl_account_subscription_invoices SET status = "pending_review", notes = CONCAT_WS("\n", notes, :note), updated_at = NOW() WHERE id = :id')
                ->execute([':note' => 'Lipa Namba ' . $reference . ': ' . $review, ':id' => $invoice['id']]);
            $pdo->commit();
            return ['outcome' => 'review', 'message' => 'Payment received and sent to SaaS for review.'];
        }

        $pdo->prepare('UPDATE tbl_lipa_transactions SET status = "matched", invoice_id = :iid, matched_at = NOW(), updated_at = NOW() WHERE id = :id')
            ->execute([':iid' => $invoice['id'], ':id' => $txn['id']]);
        $pdo->prepare('UPDATE tbl_account_subscription_invoices SET status = "paid", paid_at = NOW(), updated_at = NOW() WHERE id = :id')
            ->execute([':id' => $invoice['id']]);
        $pdo->prepare(
            'INSERT INTO tbl_account_payments (account_id, invoice_id, amount, currency, payment_method, reference, status, received_at)
             VALUES (:aid, :iid, :amount, :currency, "lipa_namba", :ref, "confirmed", NOW())'
        )->execute([
            ':aid' => $invoice['account_id'],
            ':iid' => $invoice['id'],
            ':amount' => $paid,
            ':currency' => $invoice['currency'],
            ':ref' => $reference,
        ]);
        $pdo->prepare('UPDATE tbl_account_subscriptions SET status = "active", current_period_start = CURDATE(), current_period_end = DATE_ADD(CURDATE(), INTERVAL 30 DAY), next_due_date = DATE_ADD(CURDATE(), INTERVAL 30 DAY), grace_until = DATE_ADD(CURDATE(), INTERVAL 37 DAY), updated_at = NOW() WHERE account_id = :aid')
            ->execute([':aid' => $invoice['account_id']]);
        $pdo->commit();
        return ['outcome' => 'approved', 'message' => 'Payment confirmed. Your Zipoo subscription is active.'];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

function lipa_ingest_message(PDO $pdo, ?int $deviceId, string $sender, string $body, ?string $receivedAt): array
{
    ensure_billing_tables($pdo);
    $hash = hash('sha256', strtoupper(trim($sender)) . '|' . trim($body));
    $existing = $pdo->prepare('SELECT * FROM tbl_lipa_transactions WHERE message_hash = :hash LIMIT 1');
    $existing->execute([':hash' => $hash]);
    $row = $existing->fetch(PDO::FETCH_ASSOC);
    if ($row) {
        return ['duplicate' => true, 'transaction' => $row, 'outcome' => null];
    }

    $parsed = lipa_parse_sms($sender, $body);
    $status = 'unmatched';
    $note = null;
    $reference = $parsed['reference'];
    if (!$parsed['is_payment']) {
        $status = 'ignored';
        $note = 'Not an incoming payment message.';
        $reference = null;
    } elseif ($reference === null || $parsed['amount'] === null) {
        $status = 'unparsed';
        $note = 'Could not read the reference or amount.';
    }

    $stmt = $pdo->prepare(
        'INSERT INTO tbl_lipa_transactions
            (device_id, message_hash, sender, body, received_at, provider, reference, amount, payer_phone, payer_name, status, note)
         VALUES
            (:device_id, :hash, :sender, :body, :received_at, :provider, :reference, :amount, :payer_phone, :payer_name, :status, :note)'
    );
    $stmt->execute([
        ':device_id' => $deviceId,
        ':hash' => $hash,
        ':sender' => substr($sender, 0, 50),
        ':body' => $body,
        ':received_at' => $receivedAt,
        ':provider' => $parsed['provider'],
        ':reference' => $reference,
        ':amount' => $parsed['amount'],
        ':payer_phone' => $parsed['payer_phone'],
        ':payer_name' => $parsed['payer_name'],
        ':status' => $status,
        ':note' => $note,
    ]);

    $outcome = $status === 'unmatched' && $reference !== null ? lipa_reconcile_reference($pdo, $reference) : null;
    return ['duplicate' => false, 'transaction_id' => (int) $pdo->lastInsertId(), 'outcome' => $outcome];
}

