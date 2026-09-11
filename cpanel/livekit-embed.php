<?php
declare(strict_types=1);

$configFile = __DIR__ . '/config.php';
if (!is_file($configFile)) {
    http_response_code(500);
    exit('config missing');
}
$config = require $configFile;
date_default_timezone_set($config['timezone'] ?? 'Asia/Tehran');
@ini_set('display_errors', '0');
header_remove('X-Powered-By');

session_name($config['session_name'] ?? 'mana_clinic_sess');
if (session_status() !== PHP_SESSION_ACTIVE) {
    $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || ((string) ($_SERVER['SERVER_PORT'] ?? '') === '443')
        || str_starts_with((string) ($config['app_url'] ?? ''), 'https://')
        || strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => $secure,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: strict-origin-when-cross-origin');
header('Permissions-Policy: camera=(self), microphone=(self), geolocation=()');

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/video_call.php';
require_once __DIR__ . '/includes/livekit.php';

$pdo = db_connect($config);
require __DIR__ . '/pages/video_call_embed.php';
