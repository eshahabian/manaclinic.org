<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/doctor_panel.php';

$ctx = require_doctor_profile($pdo);
if (!doctor_can_view_staff_hours($ctx['user'] ?? null)) {
    flash_set('error', 'مشاهده ورود و خروج منشی‌ها فقط برای دکتر شیوا گرانمایه‌پور، دکتر عطیه گارسچی و مدیر مجاز است.');
    redirect('/doctor/notifications');
}
if (!doctor_can_message_secretaries($ctx['user'] ?? null)) {
    flash_set('error', 'تغییر نام منشی‌ها فقط برای دکتر شیوا گرانمایه‌پور، دکتر عطیه گارسچی و مدیر مجاز است.');
    redirect('/doctor/staff-hours');
}
csrf_verify();

staff_slot_set_label($pdo, 1, post('label_1'));
staff_slot_set_label($pdo, 2, post('label_2'));
flash_set('success', 'نام منشی‌ها ذخیره شد.');
redirect('/doctor/staff-hours');
