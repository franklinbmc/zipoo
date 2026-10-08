<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/uploaded_files_lib.php';

session_start();

$userId = (int) ($_SESSION['zipoo_user_id'] ?? 0);
$businessId = (int) ($_SESSION['zipoo_business_id'] ?? 0);
$id = (int) ($_GET['id'] ?? 0);

if ($userId <= 0 || $id <= 0) {
    http_response_code(404);
    exit;
}

try {
    $pdo = db();
    ensure_uploaded_files_table($pdo);

    $stmt = $pdo->prepare(
        'SELECT mime_type, disk_path, file_data
         FROM tbl_uploaded_files
         WHERE id = :id
           AND (
                user_id = :user_id
                OR (business_id = :business_id AND :business_id > 0)
                OR EXISTS (
                    SELECT 1
                    FROM tbl_businesses b
                    WHERE b.owner_user_id = :user_id
                      AND b.id = tbl_uploaded_files.business_id
                )
           )
         LIMIT 1'
    );
    $stmt->execute([
        ':id' => $id,
        ':user_id' => $userId,
        ':business_id' => $businessId,
    ]);
    $file = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$file) {
        http_response_code(404);
        exit;
    }

    $mime = (string) ($file['mime_type'] ?? 'application/octet-stream');
    if (!str_starts_with($mime, 'image/')) {
        http_response_code(403);
        exit;
    }

    header('Content-Type: ' . $mime);
    header('Cache-Control: private, max-age=86400');
    header('X-Content-Type-Options: nosniff');

    $diskPath = (string) ($file['disk_path'] ?? '');
    $absolutePath = $diskPath !== '' ? dirname(__DIR__) . str_replace('/', DIRECTORY_SEPARATOR, $diskPath) : '';
    if ($absolutePath !== '' && is_file($absolutePath)) {
        header('Content-Length: ' . filesize($absolutePath));
        readfile($absolutePath);
        exit;
    }

    $data = $file['file_data'];
    if (is_resource($data)) {
        $data = stream_get_contents($data);
    }
    $data = (string) $data;
    header('Content-Length: ' . strlen($data));
    echo $data;
} catch (Throwable) {
    http_response_code(500);
}
