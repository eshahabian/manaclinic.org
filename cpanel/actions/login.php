<?php
declare(strict_types=1);

$email = mb_strtolower(post('email'));
$password = (string) ($_POST['password'] ?? '');
$next = post('next');

$stmt = $pdo->prepare('SELECT * FROM users WHERE email = ? LIMIT 1');
$stmt->execute([$email]);
$user = $stmt->fetch();

if (!$user || !password_verify($password, $user['password_hash'])) {
    flash_set('error', 'ایمیل یا رمز عبور نادرست است.');
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
