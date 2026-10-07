<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/secretary_patient.php';
require_once __DIR__ . '/../includes/user_referral.php';

require_login(['SECRETARY']);
csrf_verify();

$userId = trim((string) post('user_id'));
$doctorId = trim((string) post('preferred_doctor_id'));
$phone = normalize_phone((string) post('phone'));
$allowed = false;
foreach (secretary_bookable_patients($pdo) as $row) {
    if ((string) ($row['id'] ?? '') === $userId) {
        $allowed = true;
        break;
    }
}
if (!$allowed) {
    flash_set('error', 'این کاربر از اینجا قابل ویرایش نیست.');
    redirect('/secretary/users');
}
if ($doctorId === '') {
    flash_set('error', 'درمانگر را انتخاب کنید.');
    redirect('/secretary/users');
}
$doctor = $pdo->prepare('SELECT id FROM doctor_profiles WHERE id=? AND is_active=1 AND is_approved=1 LIMIT 1');
$doctor->execute([$doctorId]);
if (!$doctor->fetch()) {
    flash_set('error', 'درمانگر معتبر نیست.');
    redirect('/secretary/users');
}
if (!is_valid_phone($phone)) {
    flash_set('error', 'موبایل معتبر نیست.');
    redirect('/secretary/users');
}

$referral = user_referral_normalize((string) post('referral_source'));
if ($referral !== null && !user_referral_save($pdo, $userId, $referral)) {
    flash_set('error', 'معرف ذخیره نشد.');
    redirect('/secretary/users');
}

$pdo->prepare('UPDATE users SET preferred_doctor_id=?, phone=? WHERE id=?')
    ->execute([$doctorId, $phone, $userId]);

flash_set('success', 'تغییرات کاربر ذخیره شد.');
redirect('/secretary/users');
