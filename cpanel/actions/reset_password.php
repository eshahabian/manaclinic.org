<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/mail.php';

if (current_user()) {
    redirect(panel_href_for(current_user()) ?: '/');
}

$token = trim((string) ($_POST['token'] ?? ''));
$new = (string) ($_POST['new_password'] ?? '');
$confirm = (string) ($_POST['new_password_confirm'] ?? '');
$minPass = password_min_length();

ensure_mail_schema($pdo);
$row = password_reset_find_valid($pdo, $token);
if (!$row) {
    flash_set('error', 'لینک بازیابی نامعتبر یا منقضی است.');
    redirect('/forgot-password');
}

if (preg_match('/[^\x00-\x7F]/', $new) || preg_match('/[^\x00-\x7F]/', $confirm)) {
    flash_set('error', 'رمز عبور را با صفحه‌کلید انگلیسی وارد کنید.');
    redirect('/reset-password?token=' . rawurlencode($token));
}
if (strlen($new) < $minPass) {
    flash_set('error', 'رمز جدید حداقل ' . to_fa_digits((string) $minPass) . ' کاراکتر باشد.');
    redirect('/reset-password?token=' . rawurlencode($token));
}
if ($new !== $confirm) {
    flash_set('error', 'رمز جدید و تکرار آن یکسان نیست.');
    redirect('/reset-password?token=' . rawurlencode($token));
}

$hash = password_hash($new, PASSWORD_DEFAULT);
$pdo->prepare('UPDATE users SET password_hash=?, must_change_password=0 WHERE id=?')
    ->execute([$hash, $row['user_id']]);
user_remember_password_plain($pdo, (string) $row['user_id'], $new);
password_reset_mark_used($pdo, (string) $row['id']);
$pdo->prepare('DELETE FROM password_reset_tokens WHERE user_id=? AND id<>?')
    ->execute([(string) $row['user_id'], (string) $row['id']]);

flash_set('success', 'رمز عبور به‌روز شد. اکنون وارد شوید.');
redirect('/login');
