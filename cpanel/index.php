<?php
declare(strict_types=1);

$configFile = __DIR__ . '/config.php';
if (!is_file($configFile)) {
    http_response_code(500);
    echo 'فایل config.php یافت نشد. از config.sample.php کپی کنید.';
    exit;
}

$config = require $configFile;

date_default_timezone_set($config['timezone'] ?? 'Asia/Tehran');

session_name($config['session_name'] ?? 'mana_clinic_sess');
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/name_transliterations.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/zarinpal.php';
require_once __DIR__ . '/includes/view.php';
require_once __DIR__ . '/includes/notifications.php';
require_once __DIR__ . '/includes/psych_tests.php';
require_once __DIR__ . '/includes/wallet.php';
require_once __DIR__ . '/includes/workshops.php';
require_once __DIR__ . '/includes/workshop_media.php';
require_once __DIR__ . '/includes/assistant.php';
require_once __DIR__ . '/includes/seed_articles.php';
require_once __DIR__ . '/includes/seo.php';

$pdo = db_connect($config);
ensure_workshop_schema($pdo);
ensure_workshop_media_schema($pdo);
require_once __DIR__ . '/includes/availability.php';
ensure_availability_schema($pdo);
ensure_assistant_schema($pdo);

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

// seed مقالات فقط روی صفحات عمومی GET — نه روی API چت (جلوگیری از HTML/تایم‌اوت وسط گفتگو)
$isAssistantApi = str_starts_with($path, '/assistant/chat') || str_starts_with($path, '/assistant/send');
if ($method === 'GET' && !$isAssistantApi) {
    ensure_featured_psychology_article($pdo);
}

// مسیرهای اکشن
$routes = [
    'GET /' => 'pages/home.php',
    'GET /doctors' => 'pages/doctors.php',
    'GET /articles' => 'pages/articles.php',
    'GET /sitemap.xml' => 'pages/sitemap.php',
    'GET /tests' => 'pages/tests.php',
    'GET /about' => 'pages/about.php',
    'GET /contact' => 'pages/contact.php',
    'GET /assistant' => 'pages/assistant.php',
    'POST /assistant/chat' => 'actions/assistant_chat.php',
    'POST /assistant/send' => 'actions/assistant_send.php',
    'GET /assistant/report' => 'pages/assistant_report.php',
    'GET /login' => 'pages/login.php',
    'GET /register' => 'pages/register.php',
    'GET /logout' => 'pages/logout.php',
    'POST /login' => 'actions/login.php',
    'POST /register' => 'actions/register.php',
    'GET /change-password' => 'pages/change_password.php',
    'POST /change-password' => 'actions/change_password.php',

    'GET /dashboard' => 'pages/patient/dashboard.php',
    'GET /dashboard/appointments' => 'pages/patient/appointments.php',
    'GET /dashboard/courses' => 'pages/patient/courses.php',
    'GET /dashboard/courses/media' => 'pages/patient/course_media.php',
    'GET /dashboard/courses/offline' => 'pages/patient/offline_course.php',
    'GET /dashboard/wallet' => 'pages/patient/wallet.php',
    'GET /dashboard/profile' => 'pages/patient/profile.php',
    'POST /dashboard/profile' => 'actions/patient_profile.php',
    'POST /dashboard/pay' => 'actions/pay_appointment.php',
    'POST /enroll-workshop' => 'actions/enroll_workshop.php',
    'POST /pay-workshop' => 'actions/pay_workshop.php',
    'POST /cancel-enrollment' => 'actions/cancel_enrollment.php',
    'POST /cancel-appointment' => 'actions/cancel_appointment.php',
    'POST /book' => 'actions/book.php',

    'GET /secretary' => 'pages/secretary/dashboard.php',
    'GET /secretary/book' => 'pages/secretary/book.php',
    'POST /secretary/book' => 'actions/secretary_book.php',
    'POST /secretary/delete-patient' => 'actions/secretary_delete_patient.php',
    'GET /secretary/appointments' => 'pages/secretary/appointments.php',
    'POST /secretary/notifications/read' => 'actions/secretary_notifications.php',
    'GET /secretary/workshops' => 'pages/secretary/workshops.php',
    'POST /secretary/workshops' => 'actions/secretary_workshops.php',
    'POST /secretary/workshop-media' => 'actions/secretary_workshop_media.php',
    'GET /secretary/intakes' => 'pages/secretary/intakes.php',

    'GET /doctor' => 'pages/doctor/dashboard.php',
    'GET /doctor/profile' => 'pages/doctor/profile.php',
    'POST /doctor/profile' => 'actions/doctor_profile.php',
    'GET /doctor/availability' => 'pages/doctor/availability.php',
    'POST /doctor/availability' => 'actions/doctor_availability.php',
    'GET /doctor/appointments' => 'pages/doctor/appointments.php',
    'POST /doctor/appointments' => 'actions/doctor_appointments.php',
    'GET /doctor/workshops' => 'pages/doctor/workshops.php',
    'POST /doctor/workshops' => 'actions/doctor_workshops.php',
    'POST /doctor/workshop-media' => 'actions/doctor_workshop_media.php',
    'POST /doctor/workshop-session-note' => 'actions/doctor_workshop_session_note.php',
    'GET /doctor/workshop-export' => 'actions/doctor_workshop_export.php',
    'GET /doctor/articles' => 'pages/doctor/articles.php',
    'POST /doctor/articles' => 'actions/doctor_articles.php',
    'GET /doctor/patients' => 'pages/doctor/patients.php',
    'POST /doctor/notifications/read' => 'actions/doctor_notifications.php',

    'GET /admin' => 'pages/admin/dashboard.php',
    'GET /admin/doctors' => 'pages/admin/doctors.php',
    'POST /admin/doctors' => 'actions/admin_doctors.php',
    'GET /admin/users' => 'pages/admin/users.php',
    'POST /admin/users' => 'actions/admin_users.php',
    'GET /admin/articles' => 'pages/admin/articles.php',
    'POST /admin/articles' => 'actions/admin_articles.php',
    'GET /admin/appointments' => 'pages/admin/appointments.php',

    'GET /api/slots' => 'actions/slots.php',
    'GET /api/transliterate-name' => 'actions/transliterate_name.php',
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
if (preg_match('#^/tests/([a-zA-Z0-9_-]+)$#', $path, $m) && $method === 'GET') {
    $_GET['slug'] = $m[1];
    require __DIR__ . '/pages/test_detail.php';
    exit;
}
if (preg_match('#^/workshop-media/stream$#', $path) && $method === 'GET') {
    require __DIR__ . '/actions/workshop_media_stream.php';
    exit;
}

if (preg_match('#^/secretary/intakes/([a-zA-Z0-9_-]+)$#', $path, $m) && $method === 'GET') {
    $_GET['id'] = $m[1];
    require __DIR__ . '/pages/secretary/intake_detail.php';
    exit;
}
if (preg_match('#^/secretary/intakes/([a-zA-Z0-9_-]+)/assign$#', $path, $m) && $method === 'POST') {
    $_GET['id'] = $m[1];
    require __DIR__ . '/actions/secretary_intake_assign.php';
    exit;
}

// پرونده خصوصی بیمار — فقط دکتر
if (preg_match('#^/doctor/patients/([a-zA-Z0-9_-]+)$#', $path, $m)) {
    $_GET['id'] = $m[1];
    if ($method === 'GET') {
        require __DIR__ . '/pages/doctor/patient_chart.php';
        exit;
    }
}
if (preg_match('#^/doctor/patients/([a-zA-Z0-9_-]+)/history$#', $path, $m) && $method === 'POST') {
    $_GET['id'] = $m[1];
    require __DIR__ . '/actions/doctor_patient_history.php';
    exit;
}
if (preg_match('#^/doctor/patients/([a-zA-Z0-9_-]+)/session-note$#', $path, $m) && $method === 'POST') {
    $_GET['id'] = $m[1];
    require __DIR__ . '/actions/doctor_session_note.php';
    exit;
}
if (preg_match('#^/doctor/patients/([a-zA-Z0-9_-]+)/highlight$#', $path, $m) && $method === 'POST') {
    $_GET['id'] = $m[1];
    require __DIR__ . '/actions/doctor_highlight.php';
    exit;
}

if (isset($routes[$key])) {
    require __DIR__ . '/' . $routes[$key];
    exit;
}

http_response_code(404);
$pageTitle = 'یافت نشد';
require __DIR__ . '/pages/404.php';
