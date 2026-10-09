<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/secretary_daily_tasks.php';

$user = require_login(['SECRETARY']);
csrf_verify();

$userId = (string) ($user['id'] ?? '');
$ymd = trim((string) post('task_date'));
$key = trim((string) post('task_key'));
$mark = secretary_daily_task_posted_mark();
$back = '/secretary/daily-tasks';

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $ymd) || $ymd > date('Y-m-d')) {
    flash_set('error', 'تاریخ این فهرست معتبر نیست.');
    redirect($back);
}
if (!secretary_daily_task_can_edit($pdo, $userId, $ymd, true) || ($ymd !== date('Y-m-d') && !secretary_was_present($pdo, $userId, $ymd))) {
    flash_set('error', 'فقط روزهایی که در کلینیک حضور دارید قابل تیک خوردن است.');
    redirect($back . '?date=' . rawurlencode($ymd));
}

$ajax = strtolower((string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'xmlhttprequest';
try {
    $doneAt = secretary_daily_task_set($pdo, $userId, $ymd, $key, $mark === 'done', $mark);
} catch (Throwable $e) {
    if ($ajax) {
        http_response_code(400);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
        exit;
    }
    flash_set('error', $e->getMessage());
    redirect($back . '?date=' . rawurlencode($ymd));
}

if ($ajax) {
    secretary_daily_task_save_response($pdo, $userId, $ymd, $mark, is_string($doneAt) ? $doneAt : null);
}

redirect($back . '?date=' . rawurlencode($ymd));
