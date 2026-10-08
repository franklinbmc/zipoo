<?php
declare(strict_types=1);

function ensure_uploaded_files_table(PDO $pdo): void
{
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS tbl_uploaded_files (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            public_token CHAR(32) NOT NULL UNIQUE,
            business_id BIGINT UNSIGNED NULL,
            user_id BIGINT UNSIGNED NULL,
            storage_group VARCHAR(50) NOT NULL,
            original_name VARCHAR(190) NULL,
            mime_type VARCHAR(100) NOT NULL,
            file_ext VARCHAR(16) NOT NULL,
            file_size BIGINT UNSIGNED NOT NULL DEFAULT 0,
            disk_path VARCHAR(255) NULL,
            file_data LONGBLOB NOT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_business_group (business_id, storage_group),
            KEY idx_user (user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );
}

function upload_public_url(int $id, string $token): string
{
    return '/api/upload-file.php?id=' . $id;
}

function save_uploaded_image_file(
    PDO $pdo,
    string $tmpPath,
    string $originalName,
    string $mime,
    string $extension,
    string $storageGroup,
    ?int $businessId,
    ?int $userId,
    ?string $diskPath = null
): string {
    ensure_uploaded_files_table($pdo);

    $data = file_get_contents($tmpPath);
    if ($data === false) {
        throw new RuntimeException('Unable to read uploaded file.');
    }

    $token = bin2hex(random_bytes(16));
    $stmt = $pdo->prepare(
        'INSERT INTO tbl_uploaded_files
            (public_token, business_id, user_id, storage_group, original_name, mime_type, file_ext, file_size, disk_path, file_data)
         VALUES
            (:token, :business_id, :user_id, :storage_group, :original_name, :mime_type, :file_ext, :file_size, :disk_path, :file_data)'
    );
    $stmt->bindValue(':token', $token);
    $stmt->bindValue(':business_id', $businessId, $businessId === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
    $stmt->bindValue(':user_id', $userId, $userId === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
    $stmt->bindValue(':storage_group', $storageGroup);
    $stmt->bindValue(':original_name', $originalName !== '' ? $originalName : null);
    $stmt->bindValue(':mime_type', $mime);
    $stmt->bindValue(':file_ext', $extension);
    $stmt->bindValue(':file_size', strlen($data), PDO::PARAM_INT);
    $stmt->bindValue(':disk_path', $diskPath);
    $stmt->bindValue(':file_data', $data, PDO::PARAM_LOB);
    $stmt->execute();

    return upload_public_url((int) $pdo->lastInsertId(), $token);
}
