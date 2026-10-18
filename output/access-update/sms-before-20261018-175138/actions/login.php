<?php
declare(strict_types=1);

login_throttle_guard();

$username = mb_strtolower(normalize_input(post('username')));
$password = normalize_input((string) ($_POST['password'] ?? ''));
$next = safe_next_path(post('next'));
if ($next === null) {
    $next = safe_next_path((string) ($_GET['next'] ?? ''));
}
$loginRetry = '/login' . ($next ? ('?next=' . rawurlencode($next)) : '');

if (post('login_method') === 'otp') {
    require_once __DIR__ . '/../includes/login_otp.php';
    login_otp_schema($pdo);
    $user = login_otp_verify($pdo, (string) ($_SESSION['login_otp_id'] ?? ''), normalize_phone(post('otp_code')));
} else {
$stmt = $pdo->prepare('SELECT * FROM users WHERE username = ? LIMIT 1');
$stmt->execute([$username]);
$user = $stmt->fetch();

if (!$user || $password === '' || !password_verify($password, $user['password_hash'])) {
    $user = null;
}
}
if (!$user) {
    login_throttle_fail();
    flash_set('error', post('login_method') === 'otp' ? 'کد ورود نادرست یا منقضی شده است؛ در صورت نیاز کد جدید بگیرید.' : 'نام کاربری یا رمز عبور نادرست است.');
    redirect($loginRetry);
}

if (user_account_is_disabled($pdo, (string) $user['id'])) {
    flash_set('error', 'این حساب غیرفعال شده است.');
    redirect($loginRetry);
}

if ($user['role'] === 'DOCTOR') {
    $dp = $pdo->prepare('SELECT is_approved, is_active FROM doctor_profiles WHERE user_id=? LIMIT 1');
    $dp->execute([$user['id']]);
    $profile = $dp->fetch();
    if (!$profile || !(int) $profile['is_approved']) {
        flash_set('error', 'حساب درمانگر شما هنوز توسط مدیر سایت تأیید نشده است.');
        redirect($loginRetry);
    }
    if (!(int) $profile['is_active']) {
        flash_set('error', 'حساب درمانگر شما فعلاً غیرفعال است.');
        redirect($loginRetry);
    }
}

unset($_SESSION['login_otp_id']);
session_regenerate_id(true);
login_user($user);
login_throttle_clear();

if (!empty($user['must_change_password'])) {
    flash_set('info', 'برای ادامه، لطفاً رمز عبور خود را تغییر دهید.');
    redirect('/change-password');
}

if ($next && is_staff_app_next($next)) {
    require_once __DIR__ . '/../includes/staff_app.php';
    if (staff_app_allowed(current_user() ?? $user)) {
        redirect($next);
    }
    flash_set('error', 'این حساب اجازه ورود به برنامه کارکنان را ندارد.');
}

if (($user['role'] ?? '') === 'DOCTOR' && function_exists('doctor_must_complete_profile') && doctor_must_complete_profile($pdo, current_user() ?? $user)) {
    flash_set('info', 'برای ورود به پنل، پروفایل حرفه‌ای را کامل کنید.');
    redirect('/doctor/profile');
}

if ($next && safe_next_url_path($next) !== null) {
    redirect($next);
}

redirect(panel_href_for(current_user()) ?: '/');
