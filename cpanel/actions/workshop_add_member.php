<?php
declare(strict_types=1);
$user = require_login(['ADMIN', 'DOCTOR', 'SECRETARY']);
if (!workshop_can_add_member($user)) {
    http_response_code(403);
    exit('دسترسی افزودن عضو به کارگاه ندارید.');
}
$memberReturn = (string) $user['role'] === 'SECRETARY' ? '/secretary/workshops' : '/doctor/workshops';
$memberWorkshopId = post('workshop_id');
$memberPatientId = post('patient_id');
if (post('action') === 'create_profile') {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    try {
        $workshopQuery = $pdo->prepare('SELECT w.doctor_id FROM workshops w ' . workshop_active_doctor_join('w') . ' WHERE w.id=? AND ' . workshop_patient_list_sql('w') . ' LIMIT 1');
        $workshopQuery->execute([$memberWorkshopId]);
        $profileDoctorId = (string) ($workshopQuery->fetchColumn() ?: '');
        if ($profileDoctorId === '') throw new RuntimeException('کارگاه برای اضافه کردن عضو در دسترس نیست.');
        // The staff member cannot assign a different therapist through a modified request.
        unset($_POST['new_preferred_doctor_id']);
        $profileResult = secretary_create_patient_from_post($pdo, $user, ['fallback_doctor_id'=>$profileDoctorId]);
        if (empty($profileResult['ok'])) throw new RuntimeException((string) ($profileResult['error'] ?? 'ساخت پروفایل انجام نشد.'));
        $profileResult['phone'] = normalize_phone(post('new_phone'));
        echo json_encode($profileResult, JSON_UNESCAPED_UNICODE);
    } catch (RuntimeException $e) {
        http_response_code(422);
        echo json_encode(['ok'=>false, 'error'=>$e->getMessage()], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

try {
    $patient = $pdo->prepare("SELECT u.id FROM users u WHERE u.id=? AND " . workshop_member_user_sql('u'));
    $patient->execute([$memberPatientId]);
    if (!$patient->fetchColumn()) throw new RuntimeException('مراجعه‌کننده فعال و معتبر انتخاب کنید.');
    workshop_enroll_by_staff($pdo, $memberWorkshopId, $memberPatientId, (string) $user['id'], staff_actor_label($user), false, $memberSmsResult);
    staff_log_action($pdo, (string) $user['id'], 'workshop_add_member', 'workshop', $memberWorkshopId);
    if (!empty($memberSmsResult['ok'])) {
        flash_set('success', 'عضو اضافه شد؛ پیام پنل ثبت و پیامک به درگاه ارسال شد.');
    } else {
        flash_set('info', 'عضو اضافه شد و پیام پنل ثبت شد؛ ارسال پیامک موفق نبود یا هنوز در صف است. گزارش پیامک را بررسی کنید.');
    }
} catch (RuntimeException $e) {
    flash_set('error', $e->getMessage());
}
redirect($memberReturn);
