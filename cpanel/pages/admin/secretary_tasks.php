<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/admin_panel.php';
require_once __DIR__ . '/../../includes/secretary_daily_tasks.php';

$user = require_login(['ADMIN']);
if (!secretary_daily_tasks_can_review($user)) {
    flash_set('error', 'پیگیری کارهای روزانه منشی فقط برای eshahabian و eemadian است.');
    redirect('/admin');
}
$reviewBase = '/admin/secretary-tasks';
$renderReview = static function (string $title, string $html): void {
    render_admin_page($title, $html);
};
require __DIR__ . '/../secretary_task_review.php';
