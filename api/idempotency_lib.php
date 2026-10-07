<?php
declare(strict_types=1);

/**
 * Idempotent replay for offline sync.
 *
 * The browser queues writes made while offline and replays them when the
 * connection returns. Every queued write carries a unique `X-Zipoo-Op-Id`
 * header. If a replay reaches the server twice (e.g. the response was lost on a
 * flaky connection), the first stored response is returned instead of running
 * the action again, so sales, payments and stock changes are never duplicated.
 *
 * Include this file right after db.php in any API that accepts queued writes.
 * It is a no-op for requests without the header, and it never throws.
 */
require_once __DIR__ . '/db.php';

(static function (): void {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        return;
    }

    $opId = (string) ($_SERVER['HTTP_X_ZIPOO_OP_ID'] ?? '');
    if (!preg_match('/^[A-Za-z0-9\-]{16,64}$/', $opId)) {
        return;
    }

    try {
        $pdo = db();
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS tbl_offline_ops (
                op_id VARCHAR(64) NOT NULL PRIMARY KEY,
                status_code SMALLINT NOT NULL,
                body MEDIUMTEXT NOT NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                KEY idx_created (created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );

        $stmt = $pdo->prepare('SELECT status_code, body FROM tbl_offline_ops WHERE op_id = :id LIMIT 1');
        $stmt->execute([':id' => $opId]);
        $row = $stmt->fetch();
        if ($row) {
            http_response_code((int) $row['status_code']);
            header('Content-Type: application/json; charset=utf-8');
            header('Cache-Control: no-store');
            header('X-Zipoo-Replayed: 1');
            echo $row['body'];
            exit;
        }

        ob_start(static function (string $buffer) use ($opId): string {
            try {
                $code = (int) http_response_code();
                if ($code >= 200 && $code < 300 && $buffer !== '') {
                    $pdo = db();
                    $pdo->prepare('INSERT IGNORE INTO tbl_offline_ops (op_id, status_code, body) VALUES (:id, :code, :body)')
                        ->execute([':id' => $opId, ':code' => $code, ':body' => $buffer]);
                    if (random_int(1, 100) === 1) {
                        $pdo->exec('DELETE FROM tbl_offline_ops WHERE created_at < (NOW() - INTERVAL 45 DAY)');
                    }
                }
            } catch (Throwable $e) {
                // Never break the real response because bookkeeping failed.
            }
            return $buffer;
        });
    } catch (Throwable $e) {
        // Idempotency is best-effort; fall through to normal processing.
    }
})();
