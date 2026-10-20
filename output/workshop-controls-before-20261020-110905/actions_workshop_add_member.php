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
    $patient = $pdo->prepare("SELECT id FROM users WHERE id=? AND role='PATIENT' AND COALESCE(is_disabled,0)=0");
    $patient->execute([$memberPatientId]);
    if (!$patient->fetchColumn()) throw new RuntimeException('مراجعه‌کننده فعال و معتبر انتخاب کنید.');
    workshop_enroll_by_staff($pdo, $memberWorkshopId, $memberPatientId, (string) $user['id'], staff_actor_label($user), false);
    staff_log_action($pdo, (string) $user['id'], 'workshop_add_member', 'workshop', $memberWorkshopId);
    flash_set('success', 'عضو به کارگاه اضافه شد. اطلاع‌رسانی پیامک و داخل پنل انجام می‌شود.');
} catch (RuntimeException $e) {
    flash_set('error', $e->getMessage());
}
redirect($memberReturn);
