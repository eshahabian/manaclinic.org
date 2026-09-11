<?php
declare(strict_types=1);
$configFile = __DIR__ . '/config.php';
if (!is_file($configFile)) { http_response_code(500); exit('config missing'); }
$config = require $configFile;
date_default_timezone_set($config['timezone'] ?? 'Asia/Tehran');
@ini_set('display_errors', '0');
header_remove('X-Powered-By');
session_name($config['session_name'] ?? 'mana_clinic_sess');
if (session_status() !== PHP_SESSION_ACTIVE) {
    $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || ((string)($_SERVER['SERVER_PORT'] ?? '') === '443') || str_starts_with((string)($config['app_url'] ?? ''), 'https://') || strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
    session_set_cookie_params(['lifetime'=>0,'path'=>'/','secure'=>$secure,'httponly'=>true,'samesite'=>'Lax']);
    session_start();
}
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
header('Referrer-Policy: strict-origin-when-cross-origin');
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/view.php';
require_once __DIR__ . '/includes/notifications.php';
require_once __DIR__ . '/includes/video_call.php';
require_once __DIR__ . '/includes/video_call_engine.php';
$pdo = db_connect($config);
$base = '';
require_login(['ADMIN']);
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    csrf_verify();
    $mode = post('engine');
    if (!in_array($mode, ['livekit','legacy'], true)) {
        flash_set('error', 'مسیر تماس نامعتبر است.');
    } elseif (!mana_video_call_engine_set($mode)) {
        flash_set('error', 'ذخیره مسیر تماس ممکن نشد. سطح دسترسی پوشه storage را بررسی کنید.');
    } else {
        flash_set('success', $mode === 'legacy' ? 'مسیر تماس روی WebRTC قبلی قرار گرفت.' : 'مسیر تماس روی LiveKit قرار گرفت.');
    }
    redirect('/admin/video-call-engine');
}
require __DIR__ . '/pages/admin/video_call_engine.php';
