<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/doctor_panel.php';
require_once __DIR__ . '/../../includes/secretary_daily_tasks.php';

$ctx = require_doctor_profile($pdo);
if (!secretary_daily_tasks_can_review($ctx['user'] ?? null)) {
    flash_set('error', 'پیگیری کارهای روزانه منشی فقط برای دکتر شیوا گرانمایه‌پور، دکتر عطیه گارسچی و eshahabian است.');
    redirect('/doctor/notifications');
}
$reviewBase = '/doctor/secretary-tasks';
$renderReview = static function (string $title, string $html): void {
    render_doctor_page($title, $html);
};
require __DIR__ . '/../secretary_task_review.php';
