<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/api/db.php';

function run_pending_migrations(PDO $pdo): array
{
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS tbl_migrations (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            migration VARCHAR(190) NOT NULL UNIQUE,
            batch INT UNSIGNED NOT NULL,
            executed_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );

    $stmt = $pdo->query('SELECT migration FROM tbl_migrations');
    $executed = $stmt->fetchAll(PDO::FETCH_COLUMN);
    $executedMap = array_flip($executed);

    $migrationsDir = __DIR__ . '/migrations';
    if (!is_dir($migrationsDir)) {
        return ['status' => 'no_dir', 'applied' => [], 'message' => 'No migrations directory found.'];
    }

    $files = scandir($migrationsDir);
    sort($files);

    $batchStmt = $pdo->query('SELECT COALESCE(MAX(batch), 0) + 1 FROM tbl_migrations');
    $nextBatch = (int) $batchStmt->fetchColumn();

    $applied = [];

    foreach ($files as $file) {
        if ($file === '.' || $file === '..') {
            continue;
        }

        $ext = pathinfo($file, PATHINFO_EXTENSION);
        if ($ext !== 'sql' && $ext !== 'php') {
            continue;
        }

        if (isset($executedMap[$file])) {
            continue;
        }

        $filePath = $migrationsDir . '/' . $file;

        if ($ext === 'sql') {
            $sqlContent = (string) file_get_contents($filePath);
            $statements = array_filter(
                array_map('trim', explode(';', $sqlContent)),
                fn($s) => $s !== ''
            );

            foreach ($statements as $statement) {
                $pdo->exec($statement);
            }
        } elseif ($ext === 'php') {
            $migrationFn = require $filePath;
            if (is_callable($migrationFn)) {
                $migrationFn($pdo);
            }
        }

        $ins = $pdo->prepare('INSERT INTO tbl_migrations (migration, batch) VALUES (:migration, :batch)');
        $ins->execute([':migration' => $file, ':batch' => $nextBatch]);

        $applied[] = $file;
    }

    return [
        'status' => 'success',
        'applied' => $applied,
        'batch' => count($applied) > 0 ? $nextBatch : null,
    ];
}

// Execution handler (CLI or HTTP)
$isCli = (php_sapi_name() === 'cli');

try {
    $pdo = db();
    $result = run_pending_migrations($pdo);

    if ($isCli) {
        if (empty($result['applied'])) {
            echo "Database is up to date. No pending migrations.\n";
        } else {
            echo "Migration batch {$result['batch']} completed successfully:\n";
            foreach ($result['applied'] as $mig) {
                echo "  [OK] {$mig}\n";
            }
        }
    } else {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'ok' => true,
            'message' => empty($result['applied']) ? 'Database is up to date.' : 'Migrations applied.',
            'applied' => $result['applied'],
            'batch' => $result['batch'] ?? null,
        ], JSON_PRETTY_PRINT);
    }
} catch (Throwable $e) {
    if ($isCli) {
        fwrite(STDERR, "Migration Error: " . $e->getMessage() . "\n");
        exit(1);
    } else {
        header('Content-Type: application/json; charset=utf-8');
        http_response_code(500);
        echo json_encode([
            'ok' => false,
            'error' => $e->getMessage(),
        ], JSON_PRETTY_PRINT);
    }
}
