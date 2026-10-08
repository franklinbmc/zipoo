<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/permissions_lib.php';
require_once __DIR__ . '/uploaded_files_lib.php';

session_start();

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function respond(int $status, array $payload): void
{
    http_response_code($status);
    echo json_encode($payload);
    exit;
}

function require_user(): int
{
    $userId = (int) ($_SESSION['zipoo_user_id'] ?? 0);
    if ($userId <= 0) {
        respond(401, ['ok' => false, 'message' => 'Please login first.']);
    }

    return $userId;
}

function get_active_business_info(PDO $pdo, int $userId): array
{
    $businessId = isset($_SESSION['zipoo_business_id']) ? (int) $_SESSION['zipoo_business_id'] : 0;
    if ($businessId > 0) {
        $stmt = $pdo->prepare('SELECT id, owner_user_id, business_name, business_type, region_code, district_code FROM tbl_businesses WHERE id = :bid LIMIT 1');
        $stmt->execute([':bid' => $businessId]);
        $biz = $stmt->fetch();
        if ($biz) {
            return $biz;
        }
    }

    $stmt = $pdo->prepare('SELECT business_id FROM tbl_users WHERE id = :uid LIMIT 1');
    $stmt->execute([':uid' => $userId]);
    $userBid = (int) ($stmt->fetchColumn() ?: 0);
    if ($userBid > 0) {
        $stmt = $pdo->prepare('SELECT id, owner_user_id, business_name, business_type, region_code, district_code FROM tbl_businesses WHERE id = :bid LIMIT 1');
        $stmt->execute([':bid' => $userBid]);
        $biz = $stmt->fetch();
        if ($biz) {
            $_SESSION['zipoo_business_id'] = $userBid;
            return $biz;
        }
    }

    $stmt = $pdo->prepare('SELECT id, owner_user_id, business_name, business_type, region_code, district_code FROM tbl_businesses WHERE owner_user_id = :uid ORDER BY id ASC LIMIT 1');
    $stmt->execute([':uid' => $userId]);
    $firstBiz = $stmt->fetch();
    if ($firstBiz) {
        $_SESSION['zipoo_business_id'] = (int) $firstBiz['id'];
        return $firstBiz;
    }

    return [];
}

function ensure_user_columns(PDO $pdo): void
{
    $roleCol = $pdo->query("SHOW COLUMNS FROM tbl_users LIKE 'role'")->fetchAll();
    if (empty($roleCol)) {
        $pdo->exec("ALTER TABLE tbl_users ADD COLUMN role VARCHAR(50) NOT NULL DEFAULT 'staff' AFTER business_id");
    }

    $statusCol = $pdo->query("SHOW COLUMNS FROM tbl_users LIKE 'status'")->fetchAll();
    if (empty($statusCol)) {
        $pdo->exec("ALTER TABLE tbl_users ADD COLUMN status ENUM('active', 'inactive') NOT NULL DEFAULT 'active' AFTER role");
    }

    $salaryCol = $pdo->query("SHOW COLUMNS FROM tbl_users LIKE 'monthly_salary'")->fetchAll();
    if (empty($salaryCol)) {
        $pdo->exec("ALTER TABLE tbl_users ADD COLUMN monthly_salary DECIMAL(14,2) NOT NULL DEFAULT 0.00 AFTER status");
    }

    $tinCol = $pdo->query("SHOW COLUMNS FROM tbl_users LIKE 'tin'")->fetchAll();
    if (empty($tinCol)) {
        $pdo->exec("ALTER TABLE tbl_users ADD COLUMN tin VARCHAR(50) NULL AFTER monthly_salary");
    }

    $nidaCol = $pdo->query("SHOW COLUMNS FROM tbl_users LIKE 'nida'")->fetchAll();
    if (empty($nidaCol)) {
        $pdo->exec("ALTER TABLE tbl_users ADD COLUMN nida VARCHAR(50) NULL AFTER tin");
    }

    $nssfCol = $pdo->query("SHOW COLUMNS FROM tbl_users LIKE 'nssf'")->fetchAll();
    if (empty($nssfCol)) {
        $pdo->exec("ALTER TABLE tbl_users ADD COLUMN nssf VARCHAR(50) NULL AFTER nida");
    }

    $heslbCol = $pdo->query("SHOW COLUMNS FROM tbl_users LIKE 'heslb'")->fetchAll();
    if (empty($heslbCol)) {
        $pdo->exec("ALTER TABLE tbl_users ADD COLUMN heslb VARCHAR(50) NULL AFTER nssf");
    }

    $photoCol = $pdo->query("SHOW COLUMNS FROM tbl_users LIKE 'photo_path'")->fetchAll();
    if (empty($photoCol)) {
        $pdo->exec("ALTER TABLE tbl_users ADD COLUMN photo_path VARCHAR(255) NULL AFTER heslb");
    }
}

function money_value($value): float
{
    $clean = preg_replace('/[^0-9.\-]/', '', (string) $value);
    if ($clean === '' || $clean === '-' || $clean === '.') {
        return 0.0;
    }

    return round((float) $clean, 2);
}

function validate_user_fields(string $phone, string $email, float $monthlySalary, string $tin, string $nida, string $nssf): void
{
    if (!preg_match('/^[0-9+()\-\s]{7,24}$/', $phone)) {
        respond(422, ['ok' => false, 'message' => 'Please enter a valid phone number.']);
    }
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        respond(422, ['ok' => false, 'message' => 'Please provide a valid email address.']);
    }
    if ($monthlySalary < 0 || $monthlySalary > 999999999) {
        respond(422, ['ok' => false, 'message' => 'Please enter a valid salary amount.']);
    }
    foreach (['TIN' => $tin, 'NIDA' => $nida, 'NSSF' => $nssf] as $label => $value) {
        if ($value !== '' && strlen($value) > 50) {
            respond(422, ['ok' => false, 'message' => $label . ' is too long.']);
        }
    }
}

function upload_user_photo(PDO $pdo, int $businessId, int $userId, string $field): ?string
{
    if (empty($_FILES[$field]) || ($_FILES[$field]['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return null;
    }

    if ($_FILES[$field]['error'] !== UPLOAD_ERR_OK) {
        respond(422, ['ok' => false, 'message' => 'Photo upload failed.']);
    }

    if ((int) ($_FILES[$field]['size'] ?? 0) > 3 * 1024 * 1024) {
        respond(422, ['ok' => false, 'message' => 'Photo must be 3MB or smaller.']);
    }

    $allowed = [
        'image/png' => 'png',
        'image/jpeg' => 'jpg',
        'image/webp' => 'webp',
    ];
    $mime = mime_content_type($_FILES[$field]['tmp_name']);
    if (!isset($allowed[$mime])) {
        respond(422, ['ok' => false, 'message' => 'Only PNG, JPG, and WEBP photos are allowed.']);
    }

    $directory = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'users';
    if (!is_dir($directory)) {
        mkdir($directory, 0775, true);
    }

    $filename = 'user-' . date('YmdHis') . '-' . bin2hex(random_bytes(4)) . '.' . $allowed[$mime];
    $target = $directory . DIRECTORY_SEPARATOR . $filename;
    if (!move_uploaded_file($_FILES[$field]['tmp_name'], $target)) {
        respond(500, ['ok' => false, 'message' => 'Unable to save user photo.']);
    }

    return save_uploaded_image_file(
        $pdo,
        $target,
        (string) ($_FILES[$field]['name'] ?? ''),
        $mime,
        $allowed[$mime],
        'users',
        $businessId,
        $userId,
        '/uploads/users/' . $filename
    );
}

function user_payload(array $row, int $ownerId): array
{
    return [
        'id' => (int) $row['id'],
        'business_id' => (int) ($row['business_id'] ?? 0),
        'full_name' => (string) $row['full_name'],
        'phone' => (string) $row['phone'],
        'email' => (string) ($row['email'] ?? ''),
        'role' => (string) ($row['role'] ?? 'staff'),
        'role_id' => (int) ($row['role_id'] ?? 0),
        'role_name' => (string) ($row['role_name'] ?? ($row['role'] ?? 'staff')),
        'status' => (string) ($row['status'] ?? 'active'),
        'monthly_salary' => (float) ($row['monthly_salary'] ?? 0),
        'tin' => (string) ($row['tin'] ?? ''),
        'nida' => (string) ($row['nida'] ?? ''),
        'nssf' => (string) ($row['nssf'] ?? ''),
        'heslb' => (string) ($row['heslb'] ?? ''),
        'photo_path' => (string) ($row['photo_path'] ?? ''),
        'is_owner' => ((int) $row['id'] === $ownerId),
        'created_at' => (string) ($row['created_at'] ?? ''),
        'updated_at' => (string) ($row['updated_at'] ?? ''),
    ];
}

try {
    $currentUserId = require_user();
    $pdo = db();
    ensure_user_columns($pdo);

    $biz = get_active_business_info($pdo, $currentUserId);
    if (empty($biz)) {
        respond(400, ['ok' => false, 'message' => 'No active business selected.']);
    }

    $businessId = (int) $biz['id'];
    $ownerId = (int) ($biz['owner_user_id'] ?? 0);
    ensure_business_rbac($pdo, $businessId);

    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        require_permission($pdo, $businessId, $currentUserId, 'settings.users.manage');
        $userId = isset($_GET['id']) ? (int) $_GET['id'] : 0;
        if ($userId > 0) {
            $stmt = $pdo->prepare(
                'SELECT u.*, ur.role_id, r.name AS role_name, COALESCE(r.slug, u.role) AS role
                 FROM tbl_users u
                 LEFT JOIN tbl_user_roles ur ON ur.user_id = u.id AND ur.business_id = :bid
                 LEFT JOIN tbl_roles r ON r.id = ur.role_id
                 WHERE u.id = :id AND u.business_id = :bid
                 LIMIT 1'
            );
            $stmt->execute([':id' => $userId, ':bid' => $businessId]);
            $row = $stmt->fetch();
            if (!$row) {
                respond(404, ['ok' => false, 'message' => 'User not found in this company.']);
            }
            respond(200, ['ok' => true, 'user' => user_payload($row, $ownerId)]);
        }

        $search = trim((string) ($_GET['q'] ?? ''));
        if ($search !== '') {
            $stmt = $pdo->prepare(
                'SELECT * FROM tbl_users 
                 WHERE business_id = :bid AND id != :owner_id AND (full_name LIKE :q OR phone LIKE :q OR email LIKE :q OR role LIKE :q) 
                 ORDER BY full_name ASC'
            );
            $stmt->execute([
                ':bid' => $businessId,
                ':owner_id' => $ownerId,
                ':q' => '%' . $search . '%',
            ]);
        } else {
            $stmt = $pdo->prepare(
                'SELECT * FROM tbl_users 
                 WHERE business_id = :bid AND id != :owner_id 
                 ORDER BY full_name ASC'
            );
            $stmt->execute([':bid' => $businessId, ':owner_id' => $ownerId]);
        }

        $users = array_map(fn($r) => user_payload($r, $ownerId), $stmt->fetchAll());

        respond(200, [
            'ok' => true,
            'business_id' => $businessId,
            'business_name' => $biz['business_name'],
            'users' => $users,
            'roles' => rbac_roles_payload($pdo, $businessId),
            'permissions' => rbac_permission_catalog(),
            'total' => count($users),
        ]);
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        respond(405, ['ok' => false, 'message' => 'Method not allowed.']);
    }

    $action = trim((string) ($_POST['action'] ?? 'create'));

    if ($action === 'create') {
        require_permission($pdo, $businessId, $currentUserId, 'settings.users.manage');
        $fullName = trim((string) ($_POST['full_name'] ?? ''));
        $phone = trim((string) ($_POST['phone'] ?? ''));
        $email = trim((string) ($_POST['email'] ?? ''));
        $role = trim((string) ($_POST['role'] ?? 'viewer'));
        if ($role === 'superAdmin') {
            respond(403, ['ok' => false, 'message' => 'Super Admin is reserved for the business owner.']);
        }
        $status = trim((string) ($_POST['status'] ?? 'active'));
        $monthlySalary = money_value($_POST['monthly_salary'] ?? 0);
        $tin = trim((string) ($_POST['tin'] ?? ''));
        $nida = trim((string) ($_POST['nida'] ?? ''));
        $nssf = trim((string) ($_POST['nssf'] ?? ''));
        $heslb = trim((string) ($_POST['heslb'] ?? ''));
        $photoPath = upload_user_photo($pdo, $businessId, $currentUserId, 'photo');
        $password = (string) ($_POST['password'] ?? '');

        if ($fullName === '') {
            respond(422, ['ok' => false, 'message' => 'Full name is required.']);
        }

        if ($phone === '') {
            respond(422, ['ok' => false, 'message' => 'Phone number is required.']);
        }

        if ($password === '' || strlen($password) < 4) {
            respond(422, ['ok' => false, 'message' => 'Password must be at least 4 characters long.']);
        }

        validate_user_fields($phone, $email, $monthlySalary, $tin, $nida, $nssf);

        // Check if phone already exists
        $check = $pdo->prepare('SELECT id FROM tbl_users WHERE phone = :phone LIMIT 1');
        $check->execute([':phone' => $phone]);
        if ($check->fetchColumn()) {
            respond(422, ['ok' => false, 'message' => 'A user with phone number "' . $phone . '" already exists.']);
        }

        // Check if email already exists
        if ($email !== '') {
            $check = $pdo->prepare('SELECT id FROM tbl_users WHERE email = :email LIMIT 1');
            $check->execute([':email' => $email]);
            if ($check->fetchColumn()) {
                respond(422, ['ok' => false, 'message' => 'A user with email "' . $email . '" already exists.']);
            }
        }

        $passwordHash = password_hash($password, PASSWORD_DEFAULT);

        $stmt = $pdo->prepare(
            'INSERT INTO tbl_users (business_id, role, status, monthly_salary, tin, nida, nssf, heslb, photo_path, full_name, phone, email, business_name, business_type, region_code, district_code, password_hash)
             VALUES (:bid, :role, :status, :monthly_salary, :tin, :nida, :nssf, :heslb, :photo_path, :full_name, :phone, :email, :bname, :btype, :region, :district, :password_hash)'
        );
        $stmt->execute([
            ':bid' => $businessId,
            ':role' => $role !== '' ? $role : 'viewer',
            ':status' => in_array($status, ['active', 'inactive'], true) ? $status : 'active',
            ':monthly_salary' => $monthlySalary,
            ':tin' => $tin !== '' ? $tin : null,
            ':nida' => $nida !== '' ? $nida : null,
            ':nssf' => $nssf !== '' ? $nssf : null,
            ':heslb' => $heslb !== '' ? $heslb : null,
            ':photo_path' => $photoPath,
            ':full_name' => $fullName,
            ':phone' => $phone,
            ':email' => $email !== '' ? $email : null,
            ':bname' => $biz['business_name'] ?? 'Business',
            ':btype' => $biz['business_type'] ?? 'retail',
            ':region' => $biz['region_code'] ?? 'TZ-01',
            ':district' => $biz['district_code'] ?? 'TZ-01-01',
            ':password_hash' => $passwordHash,
        ]);

        $newId = (int) $pdo->lastInsertId();
        assign_user_role($pdo, $businessId, $newId, $role !== '' ? $role : 'viewer');
        $stmt = $pdo->prepare('SELECT * FROM tbl_users WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $newId]);
        $user = $stmt->fetch();

        respond(201, [
            'ok' => true,
            'message' => 'System user added successfully.',
            'user' => $user ? user_payload($user, $ownerId) : null,
        ]);
    }

    if ($action === 'update') {
        require_permission($pdo, $businessId, $currentUserId, 'settings.users.manage');
        $targetUserId = (int) ($_POST['user_id'] ?? 0);
        $fullName = trim((string) ($_POST['full_name'] ?? ''));
        $phone = trim((string) ($_POST['phone'] ?? ''));
        $email = trim((string) ($_POST['email'] ?? ''));
        $role = trim((string) ($_POST['role'] ?? 'viewer'));
        if ($role === 'superAdmin') {
            respond(403, ['ok' => false, 'message' => 'Super Admin is reserved for the business owner.']);
        }
        $status = trim((string) ($_POST['status'] ?? 'active'));
        $monthlySalary = money_value($_POST['monthly_salary'] ?? 0);
        $tin = trim((string) ($_POST['tin'] ?? ''));
        $nida = trim((string) ($_POST['nida'] ?? ''));
        $nssf = trim((string) ($_POST['nssf'] ?? ''));
        $heslb = trim((string) ($_POST['heslb'] ?? ''));
        $photoPath = upload_user_photo($pdo, $businessId, $currentUserId, 'photo');
        $password = (string) ($_POST['password'] ?? '');

        if ($targetUserId <= 0) {
            respond(422, ['ok' => false, 'message' => 'Invalid user ID.']);
        }

        if ($targetUserId === $ownerId) {
            respond(403, ['ok' => false, 'message' => 'Business owner account cannot be edited from this user list.']);
        }

        if ($fullName === '') {
            respond(422, ['ok' => false, 'message' => 'Full name is required.']);
        }

        if ($phone === '') {
            respond(422, ['ok' => false, 'message' => 'Phone number is required.']);
        }

        validate_user_fields($phone, $email, $monthlySalary, $tin, $nida, $nssf);

        // Verify target belongs to this business
        $stmt = $pdo->prepare('SELECT id FROM tbl_users WHERE id = :id AND business_id = :bid LIMIT 1');
        $stmt->execute([':id' => $targetUserId, ':bid' => $businessId]);
        if (!$stmt->fetchColumn()) {
            respond(404, ['ok' => false, 'message' => 'User not found in this company.']);
        }

        // Check unique phone
        $check = $pdo->prepare('SELECT id FROM tbl_users WHERE phone = :phone AND id != :id LIMIT 1');
        $check->execute([':phone' => $phone, ':id' => $targetUserId]);
        if ($check->fetchColumn()) {
            respond(422, ['ok' => false, 'message' => 'Another user with phone number "' . $phone . '" already exists.']);
        }

        // Check unique email
        if ($email !== '') {
            $check = $pdo->prepare('SELECT id FROM tbl_users WHERE email = :email AND id != :id LIMIT 1');
            $check->execute([':email' => $email, ':id' => $targetUserId]);
            if ($check->fetchColumn()) {
                respond(422, ['ok' => false, 'message' => 'Another user with email "' . $email . '" already exists.']);
            }
        }

        if ($password !== '') {
            if (strlen($password) < 4) {
                respond(422, ['ok' => false, 'message' => 'New password must be at least 4 characters long.']);
            }
            $stmt = $pdo->prepare(
                'UPDATE tbl_users 
                 SET full_name = :full_name, phone = :phone, email = :email, role = :role, status = :status, monthly_salary = :monthly_salary, tin = :tin, nida = :nida, nssf = :nssf, heslb = :heslb, photo_path = COALESCE(:photo_path, photo_path), password_hash = :pwd
                 WHERE id = :id AND business_id = :bid'
            );
            $stmt->execute([
                ':id' => $targetUserId,
                ':bid' => $businessId,
                ':full_name' => $fullName,
                ':phone' => $phone,
                ':email' => $email !== '' ? $email : null,
                ':role' => $role !== '' ? $role : 'viewer',
                ':status' => in_array($status, ['active', 'inactive'], true) ? $status : 'active',
                ':monthly_salary' => $monthlySalary,
                ':tin' => $tin !== '' ? $tin : null,
                ':nida' => $nida !== '' ? $nida : null,
                ':nssf' => $nssf !== '' ? $nssf : null,
                ':heslb' => $heslb !== '' ? $heslb : null,
                ':photo_path' => $photoPath,
                ':pwd' => password_hash($password, PASSWORD_DEFAULT),
            ]);
        } else {
            $stmt = $pdo->prepare(
                'UPDATE tbl_users 
                 SET full_name = :full_name, phone = :phone, email = :email, role = :role, status = :status, monthly_salary = :monthly_salary, tin = :tin, nida = :nida, nssf = :nssf, heslb = :heslb, photo_path = COALESCE(:photo_path, photo_path)
                 WHERE id = :id AND business_id = :bid'
            );
            $stmt->execute([
                ':id' => $targetUserId,
                ':bid' => $businessId,
                ':full_name' => $fullName,
                ':phone' => $phone,
                ':email' => $email !== '' ? $email : null,
                ':role' => $role !== '' ? $role : 'viewer',
                ':status' => in_array($status, ['active', 'inactive'], true) ? $status : 'active',
                ':monthly_salary' => $monthlySalary,
                ':tin' => $tin !== '' ? $tin : null,
                ':nida' => $nida !== '' ? $nida : null,
                ':nssf' => $nssf !== '' ? $nssf : null,
                ':heslb' => $heslb !== '' ? $heslb : null,
                ':photo_path' => $photoPath,
            ]);
        }

        assign_user_role($pdo, $businessId, $targetUserId, $role !== '' ? $role : 'viewer');

        $stmt = $pdo->prepare('SELECT * FROM tbl_users WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $targetUserId]);
        $user = $stmt->fetch();

        respond(200, [
            'ok' => true,
            'message' => 'System user updated successfully.',
            'user' => $user ? user_payload($user, $ownerId) : null,
        ]);
    }

    if ($action === 'delete') {
        require_permission($pdo, $businessId, $currentUserId, 'settings.users.manage');
        $targetUserId = (int) ($_POST['user_id'] ?? 0);
        if ($targetUserId <= 0) {
            respond(422, ['ok' => false, 'message' => 'Invalid user ID.']);
        }

        if ($targetUserId === $ownerId) {
            respond(403, ['ok' => false, 'message' => 'Cannot delete the business owner account.']);
        }

        $stmt = $pdo->prepare('DELETE FROM tbl_users WHERE id = :id AND business_id = :bid');
        $stmt->execute([':id' => $targetUserId, ':bid' => $businessId]);

        if ($stmt->rowCount() === 0) {
            respond(404, ['ok' => false, 'message' => 'User not found or already deleted.']);
        }

        respond(200, [
            'ok' => true,
            'message' => 'System user deleted successfully.',
        ]);
    }

    respond(400, ['ok' => false, 'message' => 'Unsupported action.']);
} catch (Throwable $e) {
    respond(500, ['ok' => false, 'message' => $e->getMessage()]);
}
