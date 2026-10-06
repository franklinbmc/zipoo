<?php
declare(strict_types=1);

function ensure_audit_logs_table(PDO $pdo): void
{
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS tbl_audit_logs (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            business_id BIGINT UNSIGNED NOT NULL,
            user_id BIGINT UNSIGNED NULL,
            action VARCHAR(80) NOT NULL,
            entity_type VARCHAR(80) NOT NULL,
            entity_id VARCHAR(80) NULL,
            before_json JSON NULL,
            after_json JSON NULL,
            ip_address VARCHAR(64) NULL,
            user_agent VARCHAR(255) NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            KEY idx_business_created (business_id, created_at),
            KEY idx_entity (business_id, entity_type, entity_id),
            KEY idx_action (business_id, action)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );
}

function audit_log(PDO $pdo, int $businessId, ?int $userId, string $action, string $entityType, ?string $entityId = null, ?array $before = null, ?array $after = null): void
{
    $stmt = $pdo->prepare(
        'INSERT INTO tbl_audit_logs
            (business_id, user_id, action, entity_type, entity_id, before_json, after_json, ip_address, user_agent)
         VALUES
            (:bid, :uid, :action, :entity_type, :entity_id, :before_json, :after_json, :ip, :agent)'
    );
    $stmt->execute([
        ':bid' => $businessId,
        ':uid' => $userId ?: null,
        ':action' => $action,
        ':entity_type' => $entityType,
        ':entity_id' => $entityId,
        ':before_json' => $before !== null ? json_encode($before, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
        ':after_json' => $after !== null ? json_encode($after, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
        ':ip' => substr((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 64) ?: null,
        ':agent' => substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255) ?: null,
    ]);
}
