<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/secretary_panel.php';
require_once __DIR__ . '/../includes/doctor_panel.php';

$user = require_login(['SECRETARY']);
csrf_verify();
$back = safe_next_path(post('next'));
if ($back !== '/app/therapists' && $back !== '/secretary/therapists') {
    $back = '/secretary/therapists';
}

$doctorUserId = trim((string) post('user_id'));
$active = post('active') === '1';
$stmt = $pdo->prepare("SELECT id, name, role FROM users WHERE id=? AND role='DOCTOR' LIMIT 1");
$stmt->execute([$doctorUserId]);
$doctorUser = $stmt->fetch();
if (!$doctorUser) {
    flash_set('error', 'درمانگر پیدا نشد.');
    redirect($back);
}

if ($active) {
    $pdo->prepare('UPDATE users SET is_disabled=0 WHERE id=?')->execute([$doctorUserId]);
    $profile = doctor_ensure_profile($pdo, $doctorUserId, [
        'specialty' => 'روان‌شناسی',
        'is_approved' => 1,
        'is_active' => 1,
    ]);
    if (!$profile) {
        flash_set('error', 'پروفایل درمانگر ساخته نشد.');
        redirect($back);
    }
    $pdo->prepare('UPDATE doctor_profiles SET is_active=1, is_approved=1 WHERE user_id=?')->execute([$doctorUserId]);
    flash_set('success', '«' . (string) $doctorUser['name'] . '» فعال شد و پروفایلش آماده است.');
} else {
    $pdo->prepare('UPDATE doctor_profiles SET is_active=0 WHERE user_id=?')->execute([$doctorUserId]);
    flash_set('success', '«' . (string) $doctorUser['name'] . '» غیرفعال شد.');
}

redirect($back);
