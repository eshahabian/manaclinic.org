<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/secretary_panel.php';
require_once __DIR__ . '/../../includes/secretary_daily_tasks.php';

$user = require_login(['SECRETARY']);
$username = strtolower(trim((string) ($user['username'] ?? '')));
if (!in_array($username, ['secretary1', 'secretary2'], true)) {
    flash_set('error', 'کارهای روزانه برای منشی ۱ و منشی ۲ است.');
    redirect('/secretary/messages');
}

$userId = (string) ($user['id'] ?? '');
$today = date('Y-m-d');
render_secretary_page('کارهای روزانه', secretary_daily_tasks_html(
    $pdo,
    $user,
    $today,
    secretary_daily_task_can_edit($pdo, $userId, $today, true),
    url('/secretary/daily-tasks'),
    true
));
