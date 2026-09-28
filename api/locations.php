<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

try {
    $pdo = db();

    $rows = $pdo
        ->query('SELECT region_code, region_name, district_code, district_name FROM tbl_tanzania_locations ORDER BY region_name, district_name')
        ->fetchAll();

    $regions = [];
    foreach ($rows as $row) {
        $regionCode = $row['region_code'];
        if (!isset($regions[$regionCode])) {
            $regions[$regionCode] = [
                'value' => $regionCode,
                'label' => $row['region_name'],
                'districts' => [],
            ];
        }

        $regions[$regionCode]['districts'][] = [
            'value' => $row['district_code'],
            'label' => $row['district_name'],
        ];
    }

    echo json_encode([
        'ok' => true,
        'regions' => array_values($regions),
    ], JSON_THROW_ON_ERROR);
} catch (Throwable $error) {
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'message' => 'Unable to load locations.',
    ]);
}
