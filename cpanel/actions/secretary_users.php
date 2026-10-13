<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/secretary_patient.php';
require_once __DIR__ . '/../includes/user_referral.php';

require_login(['SECRETARY']);
csrf_verify();

if (post('action') === 'set_disabled') {
    $userId = trim((string) post('user_id'));
    $disabled = post('disabled') === '1' ? 1 : 0;
    $stmt = $pdo->prepare('SELECT id, name, phone, preferred_doctor_id, referral_source, role FROM users WHERE id=? AND role=? LIMIT 1');
    $stmt->execute([$userId, 'PATIENT']);
    $patient = $stmt->fetch();
    if (!$patient) {
        flash_set('error', 'این مراجعه‌کننده از اینجا قابل تغییر نیست.');
        redirect('/secretary/patients');
    }
    if ($disabled === 0) {
        $doctorIds = [];
        foreach (secretary_active_doctors($pdo) as $doctor) {
            $doctorId = (string) ($doctor['id'] ?? '');
            if ($doctorId !== '') {
                $doctorIds[] = $doctorId;
            }
        }
        if (secretary_patient_profile_gaps($patient, $doctorIds) !== []) {
            flash_set('error', 'پروفایل «' . (string) $patient['name'] . '» ناقص است. با دکمه فعال، فقط موردهای ناقص را کامل کنید.');
            redirect('/secretary/patients');
        }
    }
    $pdo->prepare('UPDATE users SET is_disabled=? WHERE id=?')->execute([$disabled, $userId]);
    flash_set('success', $disabled
        ? 'حساب «' . (string) $patient['name'] . '» غیرفعال شد.'
        : 'حساب «' . (string) $patient['name'] . '» دوباره فعال شد.');
    redirect('/secretary/patients');
}

if (post('action') === 'add_patient_note') {
    $userId = trim((string) post('user_id'));
    $body = trim((string) post('body'));
    $body = str_replace(["\r\n", "\r"], "\n", $body);
    if ($userId === '' || !preg_match('/^[a-zA-Z0-9_-]{1,64}$/', $userId)) {
        flash_set('error', 'این مراجعه‌کننده از اینجا قابل تغییر نیست.');
        redirect('/secretary/patients');
    }
    if ($body === '') {
        flash_set('error', 'متن یادداشت را بنویسید.');
        redirect('/secretary/patients?open=' . rawurlencode($userId));
    }
    if (mb_strlen($body) > 1000) {
        flash_set('error', 'یادداشت حداکثر هزار نویسه است.');
        redirect('/secretary/patients?open=' . rawurlencode($userId));
    }
    $stmt = $pdo->prepare("
      SELECT id, name FROM users
      WHERE id=? AND is_disabled=0
        AND (
          role='PATIENT'
          OR LOWER(username)='eshahabian'
          OR (name LIKE '%عماد%' AND name LIKE '%شهابیان%')
        )
      LIMIT 1
    ");
    $stmt->execute([$userId]);
    $patient = $stmt->fetch();
    if (!$patient) {
        flash_set('error', 'این مراجعه‌کننده از اینجا قابل تغییر نیست.');
        redirect('/secretary/patients');
    }
    $actor = current_user();
    $actorId = trim((string) ($actor['id'] ?? ''));
    if ($actorId === '') {
        flash_set('error', 'یادداشت ذخیره نشد.');
        redirect('/secretary/patients?open=' . rawurlencode($userId));
    }
    ensure_patient_secretary_notes_schema($pdo);
    $pdo->prepare('INSERT INTO patient_secretary_notes (id, patient_user_id, body, created_by) VALUES (?,?,?,?)')
        ->execute([cuid(), $userId, $body, $actorId]);
    flash_set('success', 'یادداشت برای «' . (string) $patient['name'] . '» ثبت شد.');
    redirect('/secretary/patients?open=' . rawurlencode($userId));
}

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
    redirect('/secretary/patients');
}
if ($doctorId === '') {
    flash_set('error', 'درمانگر را انتخاب کنید.');
    redirect('/secretary/patients');
}
$doctor = $pdo->prepare('SELECT id FROM doctor_profiles WHERE id=? AND is_active=1 AND is_approved=1 LIMIT 1');
$doctor->execute([$doctorId]);
if (!$doctor->fetch()) {
    flash_set('error', 'درمانگر معتبر نیست.');
    redirect('/secretary/patients');
}
if (!is_valid_phone($phone)) {
    flash_set('error', 'موبایل معتبر نیست.');
    redirect('/secretary/patients');
}

$referral = user_referral_normalize((string) post('referral_source'));
if ($referral !== null && !user_referral_save($pdo, $userId, $referral)) {
    flash_set('error', 'معرف ذخیره نشد.');
    redirect('/secretary/patients');
}

$pdo->prepare('UPDATE users SET preferred_doctor_id=?, phone=? WHERE id=?')
    ->execute([$doctorId, $phone, $userId]);

flash_set('success', 'تغییرات کاربر ذخیره شد.');
redirect('/secretary/patients');
