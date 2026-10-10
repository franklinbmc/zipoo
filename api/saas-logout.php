<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';

$_SESSION = [];
session_destroy();

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
echo json_encode(['ok' => true]);
