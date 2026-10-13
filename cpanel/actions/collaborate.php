<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/collaboration.php';

csrf_verify();
if (trim((string) ($_POST['mana_hp'] ?? '')) !== '') {
    redirect('/collaborate');
}

$name = trim((string) post('name'));
$phone = normalize_phone((string) post('phone'));
$specialty = trim((string) post('specialty'));
$message = trim((string) post('message'));
if ($name === '' || mb_strlen($name) > 120) {
    flash_set('error', 'نام را بنویسید.');
    redirect('/collaborate');
}
if (!is_valid_phone($phone)) {
    flash_set('error', 'موبایل معتبر نیست.');
    redirect('/collaborate');
}
if ($specialty === '' || mb_strlen($specialty) > 160) {
    flash_set('error', 'تخصص یا زمینه همکاری را بنویسید.');
    redirect('/collaborate');
}
if (mb_strlen($message) < 10 || mb_strlen($message) > 2000) {
    flash_set('error', 'توضیح همکاری را کمی کامل‌تر بنویسید.');
    redirect('/collaborate');
}

ensure_collaboration_schema($pdo);
$id = cuid();
$pdo->prepare('INSERT INTO collaboration_requests (id, name, phone, specialty, message) VALUES (?,?,?,?,?)')
    ->execute([$id, $name, $phone, $specialty, $message]);

if (!function_exists('notify_role')) {
    require_once __DIR__ . '/../includes/notifications.php';
}
notify_role(
    $pdo,
    'SECRETARY',
    'درخواست همکاری',
    $name . ' برای همکاری در «' . $specialty . '» درخواست داد.',
    '/secretary/therapists',
    'other'
);

flash_set('success', 'درخواست همکاری ثبت شد. با شما تماس می‌گیریم.');
redirect('/collaborate');
