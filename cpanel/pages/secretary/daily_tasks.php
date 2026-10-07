<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/secretary_panel.php';
require_once __DIR__ . '/../../includes/secretary_daily_tasks.php';

$user = require_login(['SECRETARY']);
if ((string) ($_GET['part'] ?? '') === '1') {
    $userId = (string) ($user['id'] ?? '');
    $today = date('Y-m-d');
    header('Content-Type: text/html; charset=utf-8');
    header('X-Robots-Tag: noindex');
    echo secretary_daily_tasks_fragment(
        $pdo,
        $user,
        $today,
        secretary_daily_task_can_edit($pdo, $userId, $today, true),
        url('/secretary/daily-tasks')
    );
    exit;
}

render_secretary_page('کارهای روزانه', '<p class="muted" style="margin:0">فهرست کارهای روزانه باز است. اگر پنجره را بستی، دوباره از منو بازش کن.</p>');
