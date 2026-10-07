<?php
declare(strict_types=1);

// Lightweight connectivity probe used by the offline engine (assets/js/offline.js).
// Intentionally touches neither the session nor the database.
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');
echo json_encode(['ok' => true, 'time' => time()]);
