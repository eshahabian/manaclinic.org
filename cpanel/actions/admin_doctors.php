<?php
declare(strict_types=1);
require_login(['ADMIN']);
$action = post('action');

if ($action === 'toggle') {
    $id = post('id');
    $row = $pdo->prepare('SELECT is_active FROM doctor_profiles WHERE id=?');
    $row->execute([$id]);
    $d = $row->fetch();
    if ($d) {
        $pdo->prepare('UPDATE doctor_profiles SET is_active=? WHERE id=?')->execute([$d['is_active'] ? 0 : 1, $id]);
        flash_set('success', 'وضعیت دکتر تغییر کرد.');
    }
} elseif ($action === 'create') {
    $name = post('name');
    $username = mb_strtolower(post('username'));
    $password = (string) ($_POST['password'] ?? '123');
    $specialty = post('specialty');
    $bio = post('bio');
    $phone = post('phone') ?: null;
    $price = (int) post('session_price', '3000000');
    if ($name && preg_match('/^[a-z0-9._-]{3,32}$/', $username) && $specialty) {
        $exists = $pdo->prepare('SELECT id FROM users WHERE username=?');
        $exists->execute([$username]);
        if ($exists->fetch()) {
            flash_set('error', 'این نام کاربری قبلاً ثبت شده.');
        } else {
            $uid = cuid();
            $did = cuid();
            $pdo->prepare('INSERT INTO users (id,username,name,email,phone,password_hash,role,must_change_password) VALUES (?,?,?,?,?,?,?,1)')
                ->execute([$uid, $username, $name, $username . '@manaclinic.local', $phone, password_hash($password, PASSWORD_DEFAULT), 'DOCTOR']);
            $pdo->prepare('INSERT INTO doctor_profiles (id,user_id,specialty,bio,session_price,is_active) VALUES (?,?,?,?,?,1)')
                ->execute([$did, $uid, $specialty, $bio, $price]);
            flash_set('success', 'دکتر ایجاد شد. رمز موقت باید در اولین ورود عوض شود.');
        }
    } else {
        flash_set('error', 'اطلاعات ناقص یا نام کاربری نامعتبر است.');
    }
}
redirect('/admin/doctors');
