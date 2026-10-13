<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/collaboration.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    redirect('/collaborate');
}

$back = collaboration_safe_return((string) post('next', '/collaborate'));
if (trim((string) ($_POST['mana_hp'] ?? '')) !== '') {
    flash_set('success', 'درخواست همکاری ثبت شد. با شما تماس می‌گیریم.');
    redirect($back);
}

csrf_verify();

if (function_exists('throttle_too_many') && throttle_too_many('collaboration_request', 5, 600)) {
    flash_set('error', 'چند درخواست پشت‌سرهم فرستاده شده. کمی بعد دوباره تلاش کنید.');
    redirect($back);
}

$name = trim((string) post('name'));
$phone = normalize_phone((string) post('phone'));
$specialty = trim((string) post('specialty'));
$message = trim((string) post('message'));
if ($name === '' || mb_strlen($name) < 3 || mb_strlen($name) > 120) {
    flash_set('error', 'نام و نام خانوادگی را وارد کنید.');
    redirect($back);
}
if (!is_valid_phone($phone)) {
    flash_set('error', 'شماره تماس را درست وارد کنید.');
    redirect($back);
}
if ($specialty === '' || mb_strlen($specialty) > 160) {
    flash_set('error', 'تخصص یا زمینه همکاری را بنویسید.');
    redirect($back);
}
if (mb_strlen($message) < 10 || mb_strlen($message) > 2000) {
    flash_set('error', 'توضیح همکاری را کمی کامل‌تر بنویسید.');
    redirect($back);
}

ensure_collaboration_schema($pdo);
$dup = $pdo->prepare("
  SELECT id FROM collaboration_requests
  WHERE phone=? AND message=? AND created_at >= DATE_SUB(NOW(), INTERVAL 10 MINUTE)
  LIMIT 1
");
$dup->execute([$phone, $message]);
if ($dup->fetch()) {
    flash_set('success', 'این درخواست همین الان ثبت شده. با شما تماس می‌گیریم.');
    redirect($back);
}

$pdo->prepare('INSERT INTO collaboration_requests (id, name, phone, specialty, message) VALUES (?,?,?,?,?)')
    ->execute([cuid(), $name, $phone, $specialty, $message]);

if (function_exists('throttle_hit')) {
    throttle_hit('collaboration_request', 600);
}

collaboration_notify_recipients($pdo, $name, $phone, $specialty, $message);

flash_set('success', 'درخواست همکاری ثبت شد. با شما تماس می‌گیریم.');
redirect($back);
