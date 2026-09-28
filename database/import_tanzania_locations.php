<?php
declare(strict_types=1);

$sourceFile = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'data-all-flat.json';

if (!is_file($sourceFile)) {
    fwrite(STDERR, "Missing source file: {$sourceFile}\n");
    exit(1);
}

$payload = json_decode((string) file_get_contents($sourceFile), true);
if (!is_array($payload) || !isset($payload['data']) || !is_array($payload['data'])) {
    fwrite(STDERR, "Invalid Tanzania locations source data.\n");
    exit(1);
}

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
    'CREATE TABLE IF NOT EXISTS tbl_tanzania_locations (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        region_code VARCHAR(16) NOT NULL,
        region_name VARCHAR(100) NOT NULL,
        district_code VARCHAR(16) NOT NULL,
        district_name VARCHAR(120) NOT NULL,
        source_url VARCHAR(255) NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY unique_region_district (region_code, district_code),
        KEY region_name (region_name),
        KEY district_name (district_name)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
);

$regions = [];
foreach ($payload['data'] as $item) {
    if (($item['level'] ?? null) === 1) {
        $regions[$item['id']] = $item['name']['local'] ?? $item['name']['en'] ?? $item['id'];
    }
}

$insert = $pdo->prepare(
    'INSERT INTO tbl_tanzania_locations
        (region_code, region_name, district_code, district_name, source_url)
     VALUES
        (:region_code, :region_name, :district_code, :district_name, :source_url)
     ON DUPLICATE KEY UPDATE
        region_name = VALUES(region_name),
        district_name = VALUES(district_name),
        source_url = VALUES(source_url)'
);

$count = 0;
$pdo->beginTransaction();
foreach ($payload['data'] as $item) {
    if (($item['level'] ?? null) !== 2) {
        continue;
    }

    $regionCode = $item['parent']['id'] ?? null;
    if (!$regionCode || !isset($regions[$regionCode])) {
        continue;
    }

    $insert->execute([
        ':region_code' => $regionCode,
        ':region_name' => $regions[$regionCode],
        ':district_code' => $item['id'],
        ':district_name' => $item['name']['local'] ?? $item['name']['en'] ?? $item['id'],
        ':source_url' => $item['source']['url'] ?? null,
    ]);
    $count += 1;
}
$pdo->commit();

echo "Imported {$count} Tanzania districts into tbl_tanzania_locations.\n";
