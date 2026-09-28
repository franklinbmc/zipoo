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