<?php
declare(strict_types=1);

return function (PDO $pdo): void {
    $roleCol = $pdo->query("SHOW COLUMNS FROM tbl_users LIKE 'role'")->fetchAll();
    if (empty($roleCol)) {
        $pdo->exec("ALTER TABLE tbl_users ADD COLUMN role VARCHAR(50) NOT NULL DEFAULT 'staff' AFTER business_id");
    }

    $statusCol = $pdo->query("SHOW COLUMNS FROM tbl_users LIKE 'status'")->fetchAll();
    if (empty($statusCol)) {
        $pdo->exec("ALTER TABLE tbl_users ADD COLUMN status ENUM('active', 'inactive') NOT NULL DEFAULT 'active' AFTER role");
    }
};
