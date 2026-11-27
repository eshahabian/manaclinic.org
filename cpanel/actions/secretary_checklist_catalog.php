<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/secretary_daily_tasks.php';

$user = require_login();
if (!secretary_checklist_can_manage($user)) {
    http_response_code(403);
    exit('دسترسی به مدیریت چک‌لیست مجاز نیست.');
}
csrf_verify();

$returnTo = post('return_to');
if (!in_array($returnTo, ['/app/checklist', '/admin/secretary-tasks', '/doctor/secretary-tasks', '/secretary/secretary-tasks'], true)) {
    $returnTo = '/app/checklist';
}
try {
    secretary_checklist_save($pdo, post('operation'), post('task_key'), trim(post('label')), trim(post('note')), post('group_index'));
    flash_set('success', 'چک‌لیست منشی‌ها به‌روزرسانی شد.');
} catch (InvalidArgumentException $e) {
    flash_set('error', $e->getMessage());
} catch (Throwable $e) {
    error_log($e->getMessage());
    flash_set('error', 'ذخیره تغییر چک‌لیست انجام نشد.');
}
redirect($returnTo);
