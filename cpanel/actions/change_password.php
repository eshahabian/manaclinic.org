<?php
declare(strict_types=1);

$user = require_login();
$current = (string) ($_POST['current_password'] ?? '');
$new = (string) ($_POST['new_password'] ?? '');
$confirm = (string) ($_POST['new_password_confirm'] ?? '');

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
if (!$row || !password_verify($current, $row['password_hash'])) {
    flash_set('error', 'رمز فعلی نادرست است.');
    redirect('/change-password');
}

$pdo->prepare('UPDATE users SET password_hash=?, must_change_password=0 WHERE id=?')
    ->execute([password_hash($new, PASSWORD_DEFAULT), $user['id']]);

$_SESSION['user']['must_change_password'] = 0;
flash_set('success', 'رمز عبور با موفقیت تغییر کرد.');
redirect(panel_href_for(current_user()) ?: '/');
