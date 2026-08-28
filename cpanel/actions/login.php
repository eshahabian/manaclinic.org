<?php
declare(strict_types=1);

$username = mb_strtolower(post('username'));
$password = (string) ($_POST['password'] ?? '');
$next = post('next');

$stmt = $pdo->prepare('SELECT * FROM users WHERE username = ? LIMIT 1');
$stmt->execute([$username]);
$user = $stmt->fetch();

if (!$user || !password_verify($password, $user['password_hash'])) {
    flash_set('error', 'نام کاربری یا رمز عبور نادرست است.');
    redirect('/login');
}

login_user($user);

if (!empty($user['must_change_password'])) {
    flash_set('info', 'برای ادامه، لطفاً رمز عبور خود را تغییر دهید.');
    redirect('/change-password');
}

if ($next && str_starts_with($next, '/')) {
    redirect($next);
}

redirect(panel_href_for(current_user()) ?: '/');
