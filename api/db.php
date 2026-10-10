<?php
declare(strict_types=1);

function db_config(): array
{
    $configPath = __DIR__ . '/config.php';
    if (is_file($configPath)) {
        $config = require $configPath;
        if (is_array($config)) {
            return $config;
        }
    }

    return [
        'host' => $_SERVER['ZIPO_DB_HOST'] ?? getenv('ZIPO_DB_HOST') ?: 'localhost',
        'name' => $_SERVER['ZIPO_DB_NAME'] ?? getenv('ZIPO_DB_NAME') ?: 'zipoo',
        'user' => $_SERVER['ZIPO_DB_USER'] ?? getenv('ZIPO_DB_USER') ?: 'root',
        'pass' => $_SERVER['ZIPO_DB_PASS'] ?? getenv('ZIPO_DB_PASS') ?: '',
    ];
}

function db(): PDO
{
    $config = db_config();
    $host = (string) ($config['host'] ?? 'localhost');
    $name = (string) ($config['name'] ?? 'zipoo');
    $user = (string) ($config['user'] ?? 'root');
    $pass = (string) ($config['pass'] ?? '');

    return new PDO(
        "mysql:host={$host};dbname={$name};charset=utf8mb4",
        $user,
        $pass,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]
    );
}

function safe_session_start(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    if (headers_sent()) {
        @session_start();
        return;
    }

    // 1 year in seconds = 31536000
    $lifetime = 31536000;

    // Isolate session files to prevent shared /tmp garbage collection from purging sessions after 24m
    $sessionsDir = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'sessions';
    if (!is_dir($sessionsDir)) {
        @mkdir($sessionsDir, 0700, true);
    }
    if (is_dir($sessionsDir) && is_writable($sessionsDir)) {
        @ini_set('session.save_path', $sessionsDir);
    }

    @ini_set('session.gc_maxlifetime', (string) $lifetime);
    @ini_set('session.cookie_lifetime', (string) $lifetime);

    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (isset($_SERVER['SERVER_PORT']) && (int) $_SERVER['SERVER_PORT'] === 443)
        || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');

    session_set_cookie_params([
        'lifetime' => $lifetime,
        'path' => '/',
        'domain' => '',
        'secure' => $isHttps,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);

    @session_start();
}

// Auto-start or configure session with long-lived persistence whenever db.php is included
safe_session_start();