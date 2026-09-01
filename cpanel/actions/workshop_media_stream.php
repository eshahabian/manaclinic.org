<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/workshop_media.php';

$user = current_user();
if (!$user) {
    http_response_code(401);
    exit('Unauthorized');
}

ensure_workshop_media_schema($pdo);
$itemId = trim((string) ($_GET['id'] ?? ''));
if ($itemId === '') {
    http_response_code(404);
    exit('Not found');
}

$item = workshop_media_get($pdo, $itemId);
if (!$item) {
    http_response_code(404);
    exit('Not found');
}

$exp = (int) ($_GET['exp'] ?? 0);
$sig = (string) ($_GET['sig'] ?? '');

$allowed = false;
$isPatient = false;
if ($user['role'] === 'PATIENT') {
    $isPatient = true;
    if ($exp <= 0 || $sig === '' || !workshop_media_verify_stream_token($itemId, (string) $user['id'], $exp, $sig)) {
        http_response_code(403);
        exit('Link expired');
    }
    $allowed = workshop_media_patient_can_access($pdo, (string) $user['id'], $itemId);
} elseif ($user['role'] === 'DOCTOR') {
    require_once __DIR__ . '/../includes/doctor_panel.php';
    $ctx = require_doctor_profile($pdo);
    $allowed = workshop_media_doctor_owns($pdo, (string) $item['workshop_id'], $ctx['profile']['id']);
} elseif ($user['role'] === 'ADMIN') {
    $allowed = true;
}

if (!$allowed) {
    http_response_code(403);
    exit('Forbidden');
}

$path = workshop_media_stream_path($item);
if (!is_file($path)) {
    http_response_code(404);
    exit('File missing');
}

$size = filesize($path);
$mime = (string) $item['mime_type'];
$start = 0;
$end = $size - 1;
$length = $size;

if (isset($_SERVER['HTTP_RANGE']) && preg_match('/bytes=(\d*)-(\d*)/', $_SERVER['HTTP_RANGE'], $m)) {
    if ($m[1] !== '') {
        $start = (int) $m[1];
    }
    if ($m[2] !== '') {
        $end = (int) $m[2];
    }
    if ($end >= $size) {
        $end = $size - 1;
    }
    if ($start > $end || $start >= $size) {
        http_response_code(416);
        header("Content-Range: bytes */{$size}");
        exit;
    }
    $length = $end - $start + 1;
    http_response_code(206);
    header("Content-Range: bytes {$start}-{$end}/{$size}");
}

header('Content-Type: ' . $mime);
header('Accept-Ranges: bytes');
header('Content-Length: ' . $length);
if ($isPatient) {
    header('Content-Disposition: inline');
} else {
    header('Content-Disposition: inline; filename="' . basename((string) $item['original_name']) . '"');
}
header('Cache-Control: private, no-store, no-cache, must-revalidate');
header('Pragma: no-cache');
header('X-Content-Type-Options: nosniff');
header('X-Robots-Tag: noindex, nofollow');

$fp = fopen($path, 'rb');
if (!$fp) {
    http_response_code(500);
    exit('Read error');
}
if ($start > 0) {
    fseek($fp, $start);
}
$buffer = 8192;
$sent = 0;
while (!feof($fp) && $sent < $length) {
    $read = min($buffer, $length - $sent);
    $chunk = fread($fp, $read);
    if ($chunk === false) {
        break;
    }
    echo $chunk;
    $sent += strlen($chunk);
    if (connection_aborted()) {
        break;
    }
}
fclose($fp);
