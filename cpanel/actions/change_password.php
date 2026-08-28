<?php
declare(strict_types=1);

$user = require_login();
$forced = !empty($user['must_change_password']);
$current = normalize_input((string) ($_POST['current_password'] ?? ''));
$new = normalize_input((string) ($_POST['new_password'] ?? ''));
$confirm = normalize_input((string) ($_POST['new_password_confirm'] ?? ''));

if (strlen($new) < 6) {
    flash_set('error', 'رمز جدید حداقل ۶ کاراکتر باشد.');
    redirect('/change-password');
}
if ($new !== $confirm) {
    flash_set('error', 'تکرار رمز جدید مطابقت ندارد.');
    redirect('/change-password');
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
        redirect('/change-password');
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
    redirect('/change-password');
}

$_SESSION['user']['must_change_password'] = 0;
flash_set('success', 'رمز عبور با موفقیت تغییر کرد. با همین رمز جدید وارد شوید.');
redirect(panel_href_for(current_user()) ?: '/');
