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
