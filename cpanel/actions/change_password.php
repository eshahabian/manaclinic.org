<?php
declare(strict_types=1);

$user = require_login();
csrf_verify();
$forced = !empty($user['must_change_password']);
$current = normalize_input((string) ($_POST['current_password'] ?? ''));
$new = normalize_input((string) ($_POST['new_password'] ?? ''));
$confirm = normalize_input((string) ($_POST['new_password_confirm'] ?? ''));

$returnTo = trim((string) ($_POST['return_to'] ?? ''));
$allowedReturns = ['/dashboard/profile'];
if (!in_array($returnTo, $allowedReturns, true)) {
    $returnTo = '';
}
if ($returnTo === '' && ($user['role'] ?? '') === 'PATIENT' && !$forced) {
    $returnTo = '/dashboard/profile';
}
$failRedirect = $returnTo !== '' ? $returnTo . '#change-password' : '/change-password';

if ($new === '') {
    flash_set('error', 'رمز جدید را وارد کنید.');
    redirect($failRedirect);
}
if (preg_match('/[^\x00-\x7F]/', $new) || preg_match('/[^\x00-\x7F]/', $confirm)) {
    flash_set('error', 'رمز عبور را با صفحه‌کلید انگلیسی وارد کنید.');
    redirect($failRedirect);
}
if ($new !== $confirm) {
    flash_set('error', 'تکرار رمز جدید مطابقت ندارد.');
    redirect($failRedirect);
}

$stmt = $pdo->prepare('SELECT * FROM users WHERE id=?');
$stmt->execute([$user['id']]);
$row = $stmt->fetch();
if (!$row) {
    flash_set('error', 'کاربر یافت نشد. دوباره وارد شوید.');
    redirect('/login');
}

if (!$forced) {
    if ($current === '' || !password_verify($current, $row['password_hash'])) {
        flash_set('error', 'رمز فعلی نادرست است.');
        redirect($failRedirect);
    }
}

$hash = password_hash($new, PASSWORD_DEFAULT);
$pdo->prepare('UPDATE users SET password_hash=?, must_change_password=0 WHERE id=?')
    ->execute([$hash, $user['id']]);

// اطمینان از ذخیره درست
$check = $pdo->prepare('SELECT password_hash, must_change_password FROM users WHERE id=?');
$check->execute([$user['id']]);
$saved = $check->fetch();
if (!$saved || !password_verify($new, $saved['password_hash'])) {
    flash_set('error', 'ذخیره رمز ناموفق بود. دوباره تلاش کنید.');
    redirect($failRedirect);
}

$_SESSION['user']['must_change_password'] = 0;
session_regenerate_id(true);
flash_set('success', 'رمز عبور با موفقیت تغییر کرد.');
if ($returnTo !== '' && !$forced) {
    redirect($returnTo . '#change-password');
}
redirect(panel_href_for(current_user()) ?: '/');
