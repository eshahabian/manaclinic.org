<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/secretary_daily_tasks.php';

$user = require_login(['SECRETARY']);
csrf_verify();

$userId = (string) ($user['id'] ?? '');
$ymd = trim((string) post('task_date'));
$key = trim((string) post('task_key'));
$done = post('done') === '1';
$returnTo = trim((string) post('return_to'));
$returnPath = parse_url($returnTo, PHP_URL_PATH);
$returnPath = is_string($returnPath) ? $returnPath : '';
if (!preg_match('#^/(secretary|admin|change-password)(/|$)#', $returnPath) || str_contains($returnPath, 'daily-tasks')) {
    $returnPath = '/secretary/messages';
}
$back = $returnPath;

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $ymd) || $ymd > date('Y-m-d')) {
    flash_set('error', 'تاریخ این فهرست معتبر نیست.');
    redirect($back . '?open_tasks=1');
}
if (!secretary_daily_task_can_edit($pdo, $userId, $ymd, true) || ($ymd !== date('Y-m-d') && !secretary_was_present($pdo, $userId, $ymd))) {
    flash_set('error', 'فقط روزهایی که در کلینیک حضور دارید قابل تیک خوردن است.');
    redirect($back . '?open_tasks=1&task_date=' . rawurlencode($ymd));
}

try {
    secretary_daily_task_set($pdo, $userId, $ymd, $key, $done);
} catch (Throwable $e) {
    flash_set('error', $e->getMessage());
}

redirect($back . '?open_tasks=1&task_date=' . rawurlencode($ymd));
