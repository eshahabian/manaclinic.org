<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/doctor_panel.php';
$ctx = require_doctor_profile($pdo);
$name = post('name');
$specialty = post('specialty');
$bio = post('bio');
$price = (int) post('session_price');
if ($name && $specialty && $price > 0) {
    $pdo->prepare('UPDATE users SET name=? WHERE id=?')->execute([$name, $ctx['user']['id']]);
    $pdo->prepare('UPDATE doctor_profiles SET specialty=?, bio=?, session_price=? WHERE id=?')
        ->execute([$specialty, $bio, $price, $ctx['profile']['id']]);
    $_SESSION['user']['name'] = $name;
    flash_set('success', 'پروفایل ذخیره شد.');
}
redirect('/doctor/profile');
