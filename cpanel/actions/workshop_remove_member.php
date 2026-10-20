<?php
declare(strict_types=1);
$user = require_login(['ADMIN', 'DOCTOR', 'SECRETARY']);
if (!workshop_can_add_member($user)) { http_response_code(403); exit('دسترسی حذف عضو ندارید.'); }
$memberReturn = $user['role'] === 'SECRETARY' ? '/secretary/workshops' : '/doctor/workshops';
try {
    workshop_remove_member($pdo, post('enrollment_id'), $user);
    flash_set('success', 'عضویت حذف شد؛ سوابق ثبت‌نام و پرداخت حفظ شدند.');
} catch (RuntimeException $e) { flash_set('error', $e->getMessage()); }
redirect($memberReturn);
