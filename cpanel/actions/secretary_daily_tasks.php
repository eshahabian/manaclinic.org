<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/secretary_daily_tasks.php';

$user = require_login(['SECRETARY']);
csrf_verify();

$userId = (string) ($user['id'] ?? '');
$ymd = trim((string) post('task_date'));
$key = trim((string) post('task_key'));
$mark = secretary_daily_task_posted_mark();
$ajax = secretary_daily_task_request_is_ajax();
$back = '/secretary/daily-tasks';

$fail = static function (string $message, int $status = 400) use ($ajax, $ymd, $back): never {
    if ($ajax) {
        secretary_daily_task_json_error($message, $status);
    }
    flash_set('error', $message);
    $target = $back;
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $ymd)) {
        $target .= '?date=' . rawurlencode($ymd);
    }
    redirect($target);
};

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $ymd) || $ymd > date('Y-m-d')) {
    $fail('تاریخ این فهرست معتبر نیست.');
}
if (!secretary_daily_task_can_edit($pdo, $userId, $ymd, true) || ($ymd !== date('Y-m-d') && !secretary_was_present($pdo, $userId, $ymd))) {
    $fail('فقط روزهایی که در کلینیک حضور دارید قابل تیک خوردن است.', 403);
}

try {
    $doneAt = secretary_daily_task_set($pdo, $userId, $ymd, $key, $mark === 'done', $mark);
} catch (Throwable $e) {
    $fail($e->getMessage() !== '' ? $e->getMessage() : 'ذخیره نشد.');
}

if ($ajax) {
    secretary_daily_task_save_response($pdo, $userId, $ymd, $mark, is_string($doneAt) ? $doneAt : null);
}

redirect($back . '?date=' . rawurlencode($ymd));
