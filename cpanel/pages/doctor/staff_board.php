<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/doctor_panel.php';

$ctx = require_doctor_profile($pdo);
if (!doctor_can_message_secretaries($ctx['user'] ?? null)) {
    flash_set('error', 'پیام به منشی‌ها فقط برای دکتر شیوا گرانمایه‌پور، دکتر عطیه گارسچی و مدیر سایت مجاز است.');
    redirect('/doctor/notifications');
}
// یادداشت مشترک برای درمانگر به «پیام به منشی‌ها» منتقل شد
redirect('/doctor/secretary-messages');
