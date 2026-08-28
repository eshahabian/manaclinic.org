<?php
declare(strict_types=1);

$configFile = __DIR__ . '/config.php';
if (!is_file($configFile)) {
    http_response_code(500);
    echo 'فایل config.php یافت نشد. از config.sample.php کپی کنید.';
    exit;
}

$config = require $configFile;

session_name($config['session_name'] ?? 'mana_clinic_sess');
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/zarinpal.php';
require_once __DIR__ . '/includes/view.php';

$pdo = db_connect($config);

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$base = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? ''), '/\\');
if ($base && $base !== '/' && str_starts_with($path, $base)) {
    $path = substr($path, strlen($base)) ?: '/';
}
$path = '/' . trim($path, '/');
if ($path !== '/') {
    $path = rtrim($path, '/');
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

// مسیرهای اکشن
$routes = [
    'GET /' => 'pages/home.php',
    'GET /doctors' => 'pages/doctors.php',
    'GET /articles' => 'pages/articles.php',
    'GET /login' => 'pages/login.php',
    'GET /register' => 'pages/register.php',
    'GET /logout' => 'pages/logout.php',
    'POST /login' => 'actions/login.php',
    'POST /register' => 'actions/register.php',

    'GET /dashboard' => 'pages/patient/dashboard.php',
    'GET /dashboard/appointments' => 'pages/patient/appointments.php',
    'GET /dashboard/profile' => 'pages/patient/profile.php',
    'POST /dashboard/profile' => 'actions/patient_profile.php',
    'POST /book' => 'actions/book.php',

    'GET /doctor' => 'pages/doctor/dashboard.php',
    'GET /doctor/profile' => 'pages/doctor/profile.php',
    'POST /doctor/profile' => 'actions/doctor_profile.php',
    'GET /doctor/availability' => 'pages/doctor/availability.php',
    'POST /doctor/availability' => 'actions/doctor_availability.php',
    'GET /doctor/appointments' => 'pages/doctor/appointments.php',
    'POST /doctor/appointments' => 'actions/doctor_appointments.php',
    'GET /doctor/articles' => 'pages/doctor/articles.php',
    'POST /doctor/articles' => 'actions/doctor_articles.php',

    'GET /admin' => 'pages/admin/dashboard.php',
    'GET /admin/doctors' => 'pages/admin/doctors.php',
    'POST /admin/doctors' => 'actions/admin_doctors.php',
    'GET /admin/users' => 'pages/admin/users.php',
    'GET /admin/articles' => 'pages/admin/articles.php',
    'POST /admin/articles' => 'actions/admin_articles.php',
    'GET /admin/appointments' => 'pages/admin/appointments.php',

    'GET /api/slots' => 'actions/slots.php',
    'GET /payments/verify' => 'actions/payment_verify.php',
    'GET /install' => 'install.php',
];

$key = $method . ' ' . $path;

// مسیرهای پویا
if (preg_match('#^/doctors/([a-zA-Z0-9_-]+)$#', $path, $m) && $method === 'GET') {
    $_GET['id'] = $m[1];
    require __DIR__ . '/pages/doctor_detail.php';
    exit;
}
if (preg_match('#^/articles/([a-zA-Z0-9_-]+)$#', $path, $m) && $method === 'GET') {
    $_GET['slug'] = $m[1];
    require __DIR__ . '/pages/article_detail.php';
    exit;
}

if (isset($routes[$key])) {
    require __DIR__ . '/' . $routes[$key];
    exit;
}

http_response_code(404);
$pageTitle = 'یافت نشد';
require __DIR__ . '/pages/404.php';
