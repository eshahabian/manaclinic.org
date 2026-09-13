<?php
declare(strict_types=1);

$user = require_login(['SECRETARY']);
csrf_verify();

$mode = trim((string) ($_POST['mode'] ?? 'personal'));
$title = trim((string) ($_POST['title'] ?? ''));
$body = trim((string) ($_POST['body'] ?? ''));
$patientId = trim((string) ($_POST['patient_id'] ?? ''));
$next = trim((string) ($_POST['next'] ?? '/secretary/messages?msg=patients'));
if ($next === '' || !str_starts_with($next, '/secretary')) {
    $next = '/secretary/messages?msg=patients';
}

if ($title === '' || $body === '') {
    flash_set('error', 'عنوان و متن پیام لازم است.');
    redirect($next);
}

$senderId = (string) $user['id'];

if ($mode === 'broadcast') {
    $count = notify_patients_all($pdo, $title, $body, '/dashboard/messages', 'broadcast', $senderId);
    flash_set('success', 'پیام همگانی برای ' . to_fa_digits((string) $count) . ' مراجعه‌کننده ارسال شد.');
    redirect($next);
}

if ($patientId === '') {
    flash_set('error', 'مراجعه‌کننده را انتخاب کنید.');
    redirect($next);
}

$check = $pdo->prepare("SELECT id, name FROM users WHERE id = ? AND role = 'PATIENT' LIMIT 1");
$check->execute([$patientId]);
$patient = $check->fetch();
if (!$patient) {
    flash_set('error', 'مراجعه‌کننده یافت نشد.');
    redirect($next);
}

notify_patient_personal($pdo, $patientId, $title, $body, $senderId, '/dashboard/messages');
flash_set('success', 'پیام برای «' . (string) $patient['name'] . '» ارسال شد.');
redirect($next);
