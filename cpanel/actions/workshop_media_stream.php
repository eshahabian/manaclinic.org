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
} elseif ($user['role'] === 'SECRETARY' || $user['role'] === 'ADMIN') {
    $allowed = true;
}

if (!$allowed) {
    http_response_code(403);
    exit('Forbidden');
}

$path = workshop_media_stream_path($item);
if ($path === '' || !is_file($path)) {
    http_response_code(404);
    exit('File missing');
}

$wantsDownload = (string) ($_GET['dl'] ?? '') === '1';
$isPpt = ($item['kind'] ?? '') === 'PPT';
if ($wantsDownload && $isPatient && ($item['kind'] ?? '') !== 'PDF' && !$isPpt) {
    http_response_code(403);
    exit('Forbidden');
}
$maskAudio = $isPatient && ($item['kind'] ?? '') === 'AUDIO';
if ($maskAudio && (string) ($_SERVER['HTTP_X_MANA_PLAYER'] ?? '') !== '1') {
    http_response_code(403);
    exit('Forbidden');
}
if ((string) ($user['role'] ?? '') === 'SECRETARY' && $wantsDownload && function_exists('workshop_media_log_secretary_action')) {
    workshop_media_log_secretary_action($pdo, $user, 'workshop_media_download', $item);
}
$tmpStamp = null;
$watermark = workshop_media_watermark_for_user($user, $pdo);
if ($isPpt && (string) ($_GET['preview'] ?? '') === '1') {
    header('Content-Type: text/html; charset=utf-8');
    header('X-Robots-Tag: noindex, nofollow');
    header('Cache-Control: private, no-store');
    echo workshop_pptx_preview_document($path, $watermark);
    exit;
}
if (($item['kind'] ?? '') === 'PDF' && $wantsDownload) {
    $safeName = basename(str_replace(['"', "\r", "\n"], '', (string) ($item['original_name'] ?? 'file.pdf')));
    header('Content-Type: text/html; charset=utf-8');
    header('X-Robots-Tag: noindex, nofollow');
    header('Cache-Control: private, no-store');
    echo workshop_pdf_client_stamp_document(
        workshop_media_stream_url((string) $item['id'], $user, false),
        $safeName,
        $watermark
    );
    exit;
}
if ($isPpt) {
    $stamped = workshop_pptx_stamp_temp($path, $watermark);
    if ($stamped) {
        $tmpStamp = $stamped;
        $path = $stamped;
    } elseif ($isPatient) {
        http_response_code(403);
        exit('Forbidden');
    }
    $wantsDownload = true;
}

$size = filesize($path);
$mime = (string) ($item['kind'] === 'PDF' ? 'application/pdf' : $item['mime_type']);
if ($maskAudio) {
    $mime = 'application/octet-stream';
}
$start = 0;
$end = $size - 1;
$length = $size;

if (!$maskAudio && isset($_SERVER['HTTP_RANGE']) && preg_match('/bytes=(\d*)-(\d*)/', $_SERVER['HTTP_RANGE'], $m)) {
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

$safeName = basename(str_replace(['"', "\r", "\n"], '', (string) ($item['original_name'] ?? 'file')));
if ($isPpt) {
    $mime = 'application/vnd.openxmlformats-officedocument.presentationml.presentation';
    if (!str_ends_with(strtolower($safeName), '.pptx')) {
        $safeName .= '.pptx';
    }
}
header('Content-Type: ' . $mime);
header($maskAudio ? 'Accept-Ranges: none' : 'Accept-Ranges: bytes');
header('Content-Length: ' . $length);
if ($maskAudio) {
    header('Content-Disposition: inline');
} elseif ($isPpt || (($item['kind'] ?? '') === 'PDF' && $wantsDownload)) {
    header('Content-Disposition: attachment; filename="' . $safeName . '"');
} else {
    header('Content-Disposition: inline; filename="' . $safeName . '"');
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
$audioMask = $maskAudio ? workshop_media_audio_mask_key($itemId, (string) $user['id']) : '';
$buffer = 8192;
$sent = 0;
while (!feof($fp) && $sent < $length) {
    $read = min($buffer, $length - $sent);
    $chunk = fread($fp, $read);
    if ($chunk === false) {
        break;
    }
    if ($audioMask !== '') {
        $chunk = workshop_media_xor_chunk($chunk, $audioMask, $start + $sent);
    }
    echo $chunk;
    $sent += strlen($chunk);
    if (connection_aborted()) {
        break;
    }
}
fclose($fp);
if ($tmpStamp && is_file($tmpStamp)) {
    @unlink($tmpStamp);
}
