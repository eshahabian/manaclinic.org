<?php
declare(strict_types=1);

login_throttle_guard();

$username = mb_strtolower(normalize_input(post('username')));
$password = normalize_input((string) ($_POST['password'] ?? ''));
$next = safe_next_path(post('next'));

$stmt = $pdo->prepare('SELECT * FROM users WHERE username = ? LIMIT 1');
$stmt->execute([$username]);
$user = $stmt->fetch();

if (!$user || $password === '' || !password_verify($password, $user['password_hash'])) {
    login_throttle_fail();
    flash_set('error', 'نام کاربری یا رمز عبور نادرست است.');
    redirect('/login');
}

if ($user['role'] === 'DOCTOR') {
    $dp = $pdo->prepare('SELECT is_approved, is_active FROM doctor_profiles WHERE user_id=? LIMIT 1');
    $dp->execute([$user['id']]);
    $profile = $dp->fetch();
    if (!$profile || !(int) $profile['is_approved']) {
        flash_set('error', 'حساب درمانگر شما هنوز توسط مدیر سایت تأیید نشده است.');
        redirect('/login');
    }
    if (!(int) $profile['is_active']) {
        flash_set('error', 'حساب درمانگر شما فعلاً غیرفعال است.');
        redirect('/login');
    }
}

session_regenerate_id(true);
login_user($user);
login_throttle_clear();

if (!empty($user['must_change_password'])) {
    flash_set('info', 'برای ادامه، لطفاً رمز عبور خود را تغییر دهید.');
    redirect('/change-password');
}

if (($user['role'] ?? '') === 'DOCTOR' && function_exists('doctor_must_complete_profile') && doctor_must_complete_profile($pdo, current_user() ?? $user)) {
    flash_set('info', 'برای ورود به پنل، پروفایل حرفه‌ای را کامل کنید.');
    redirect('/doctor/profile');
}

if ($next && str_starts_with($next, '/')) {
    redirect($next);
}

redirect(panel_href_for(current_user()) ?: '/');
