<?php
declare(strict_types=1);

$pdo = new PDO(
    'mysql:host=localhost;dbname=zipoo;charset=utf8mb4',
    'root',
    '',
    [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]
);

$pdo->exec(
    'CREATE TABLE IF NOT EXISTS tbl_users (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        full_name VARCHAR(160) NOT NULL,
        phone VARCHAR(10) NOT NULL,
        email VARCHAR(190) NULL,
        business_name VARCHAR(190) NOT NULL,
        business_type VARCHAR(80) NOT NULL,
        region_code VARCHAR(16) NOT NULL,
        district_code VARCHAR(16) NOT NULL,
        password_hash VARCHAR(255) NOT NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY unique_phone (phone),
        UNIQUE KEY unique_email (email),
        KEY region_code (region_code),
        KEY district_code (district_code)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
);

echo "Registration table is ready.\n";
