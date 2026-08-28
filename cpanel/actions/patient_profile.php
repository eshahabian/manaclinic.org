<?php
declare(strict_types=1);
$user = require_login(['PATIENT']);
$name = post('name');
$phone = post('phone') ?: null;
if ($name !== '') {
    $pdo->prepare('UPDATE users SET name=?, phone=? WHERE id=?')->execute([$name, $phone, $user['id']]);
    $_SESSION['user']['name'] = $name;
    flash_set('success', 'پروفایل ذخیره شد.');
}
redirect('/dashboard/profile');
