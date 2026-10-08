<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/secretary_panel.php';
require_once __DIR__ . '/../../includes/secretary_daily_tasks.php';

$user = require_login(['SECRETARY']);
if (!secretary_daily_tasks_can_review($user)) {
    flash_set('error', 'پیگیری کارهای روزانه منشی فقط برای دکتر شیوا گرانمایه‌پور، دکتر عطیه گارسچی و eshahabian است.');
    redirect('/secretary/messages');
}
$reviewBase = '/secretary/secretary-tasks';
$reviewTitle = 'لیست کارهای روزانه';
$renderReview = static function (string $title, string $html): void {
    render_secretary_page($title, $html);
};
require __DIR__ . '/../secretary_task_review.php';
