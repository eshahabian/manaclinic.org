<?php
declare(strict_types=1);

$user = require_login(['SECRETARY']);
ensure_secretary_day_reports($pdo);

$body = trim((string) ($_POST['body'] ?? ''));
$date = trim((string) ($_POST['report_date'] ?? date('Y-m-d')));
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
    $date = date('Y-m-d');
}
if ($body === '') {
    flash_set('error', 'متن گزارش را بنویسید.');
    redirect('/secretary/hours');
}

staff_save_day_report($pdo, (string) $user['id'], $date, $body);
staff_log_action($pdo, (string) $user['id'], 'day_report', 'day_report', $date);
flash_set('success', 'گزارش پایان روز ذخیره شد.');
redirect('/secretary/hours');
