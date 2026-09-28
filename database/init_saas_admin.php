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
    'CREATE TABLE IF NOT EXISTS tbl_businesses (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        owner_user_id BIGINT UNSIGNED NULL,
        business_name VARCHAR(190) NOT NULL,
        business_type VARCHAR(80) NOT NULL,
        region_code VARCHAR(16) NOT NULL,
        district_code VARCHAR(16) NOT NULL,
        plan_name VARCHAR(80) NOT NULL DEFAULT "Starter",
        account_status ENUM("active", "inactive") NOT NULL DEFAULT "active",
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        KEY owner_user_id (owner_user_id),
        KEY account_status (account_status),
        KEY created_at (created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
);

$columns = $pdo->query('SHOW COLUMNS FROM tbl_users LIKE "business_id"')->fetch();
if (!$columns) {
    $pdo->exec('ALTER TABLE tbl_users ADD business_id BIGINT UNSIGNED NULL AFTER id, ADD KEY business_id (business_id)');
}

$existingUsers = $pdo->query(
    'SELECT id, business_name, business_type, region_code, district_code, created_at
     FROM tbl_users
     WHERE business_id IS NULL'
)->fetchAll();

$insertBusiness = $pdo->prepare(
    'INSERT INTO tbl_businesses
        (owner_user_id, business_name, business_type, region_code, district_code, created_at)
     VALUES
        (:owner_user_id, :business_name, :business_type, :region_code, :district_code, :created_at)'
);
$updateUser = $pdo->prepare('UPDATE tbl_users SET business_id = :business_id WHERE id = :id');

foreach ($existingUsers as $user) {
    $insertBusiness->execute([
        ':owner_user_id' => $user['id'],
        ':business_name' => $user['business_name'],
        ':business_type' => $user['business_type'],
        ':region_code' => $user['region_code'],
        ':district_code' => $user['district_code'],
        ':created_at' => $user['created_at'],
    ]);
    $updateUser->execute([
        ':business_id' => $pdo->lastInsertId(),
        ':id' => $user['id'],
    ]);
}

$pdo->exec(
    'CREATE TABLE IF NOT EXISTS tbl_saas_admins (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        full_name VARCHAR(160) NOT NULL,
        email VARCHAR(190) NOT NULL,
        password_hash VARCHAR(255) NOT NULL,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        last_login_at TIMESTAMP NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY unique_email (email)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
);

$pdo->exec(
    'CREATE TABLE IF NOT EXISTS tbl_saas_settings (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        setting_group VARCHAR(80) NOT NULL,
        setting_key VARCHAR(120) NOT NULL,
        setting_value TEXT NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY unique_setting (setting_group, setting_key)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
);

$adminEmail = 'admin@zipoo.local';
$adminExists = $pdo->prepare('SELECT COUNT(*) FROM tbl_saas_admins WHERE email = :email');
$adminExists->execute([':email' => $adminEmail]);
if ((int) $adminExists->fetchColumn() === 0) {
    $admin = $pdo->prepare(
        'INSERT INTO tbl_saas_admins (full_name, email, password_hash)
         VALUES (:full_name, :email, :password_hash)'
    );
    $admin->execute([
        ':full_name' => 'Zipoo Admin',
        ':email' => $adminEmail,
        ':password_hash' => password_hash('Admin@12345', PASSWORD_DEFAULT),
    ]);
}

echo "SaaS admin tables are ready.\n";
