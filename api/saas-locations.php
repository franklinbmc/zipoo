<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';

session_start();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function respond(int $status, array $payload): void
{
    http_response_code($status);
    echo json_encode($payload);
    exit;
}

function input(string $key): string
{
    return trim((string) ($_POST[$key] ?? ''));
}

if (empty($_SESSION['saas_admin_id'])) {
    respond(401, ['ok' => false, 'message' => 'Admin login required.']);
}

try {
    $pdo = db();

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        respond(405, ['ok' => false, 'message' => 'Method not allowed.']);
    }

    $type = input('type');
    $mode = input('mode');

    if ($type === 'region') {
        $regionCode = input('region_code');
        $regionName = input('region_name');
        $originalCode = input('original_region_code') ?: $regionCode;
        if ($regionCode === '' || $regionName === '') {
            respond(422, ['ok' => false, 'message' => 'Region code and name are required.']);
        }

        if ($mode === 'edit') {
            $stmt = $pdo->prepare(
                'UPDATE tbl_tanzania_locations
                 SET region_code = :region_code, region_name = :region_name
                 WHERE region_code = :original_region_code'
            );
            $stmt->execute([
                ':region_code' => $regionCode,
                ':region_name' => $regionName,
                ':original_region_code' => $originalCode,
            ]);
        } else {
            $districtCode = $regionCode . '00';
            $stmt = $pdo->prepare(
                'INSERT INTO tbl_tanzania_locations (region_code, region_name, district_code, district_name)
                 VALUES (:region_code, :region_name, :district_code, :district_name)'
            );
            $stmt->execute([
                ':region_code' => $regionCode,
                ':region_name' => $regionName,
                ':district_code' => $districtCode,
                ':district_name' => $regionName,
            ]);
        }

        respond(200, ['ok' => true, 'message' => 'Region saved.']);
    }

    if ($type === 'district') {
        $regionCode = input('region_code');
        $districtCode = input('district_code');
        $districtName = input('district_name');
        $originalDistrictCode = input('original_district_code') ?: $districtCode;
        if ($regionCode === '' || $districtCode === '' || $districtName === '') {
            respond(422, ['ok' => false, 'message' => 'Region, district code, and district name are required.']);
        }

        $region = $pdo->prepare('SELECT region_name FROM tbl_tanzania_locations WHERE region_code = :region_code LIMIT 1');
        $region->execute([':region_code' => $regionCode]);
        $regionName = (string) $region->fetchColumn();
        if ($regionName === '') {
            respond(422, ['ok' => false, 'message' => 'Choose a valid region.']);
        }

        if ($mode === 'edit') {
            $stmt = $pdo->prepare(
                'UPDATE tbl_tanzania_locations
                 SET region_code = :region_code, region_name = :region_name, district_code = :district_code, district_name = :district_name
                 WHERE district_code = :original_district_code'
            );
            $stmt->execute([
                ':region_code' => $regionCode,
                ':region_name' => $regionName,
                ':district_code' => $districtCode,
                ':district_name' => $districtName,
                ':original_district_code' => $originalDistrictCode,
            ]);
        } else {
            $stmt = $pdo->prepare(
                'INSERT INTO tbl_tanzania_locations (region_code, region_name, district_code, district_name)
                 VALUES (:region_code, :region_name, :district_code, :district_name)'
            );
            $stmt->execute([
                ':region_code' => $regionCode,
                ':region_name' => $regionName,
                ':district_code' => $districtCode,
                ':district_name' => $districtName,
            ]);
        }

        respond(200, ['ok' => true, 'message' => 'District saved.']);
    }

    respond(422, ['ok' => false, 'message' => 'Invalid definition type.']);
} catch (Throwable $error) {
    respond(500, ['ok' => false, 'message' => 'Unable to save definition.']);
}
