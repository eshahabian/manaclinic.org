<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/consult_requests.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    redirect('/');
}

$back = consult_safe_return(post('next', '/'));
if (trim((string) ($_POST['website'] ?? '')) !== '') {
    flash_set('success', 'درخواست شما ثبت شد. به‌زودی با شما تماس می‌گیریم.');
    redirect($back);
}

csrf_verify();

if (function_exists('throttle_too_many') && throttle_too_many('consult_request', 5, 600)) {
    flash_set('error', 'چند درخواست پشت‌سرهم فرستاده شده. کمی بعد دوباره تلاش کنید.');
    redirect($back);
}

$name = mb_substr(post('name'), 0, 100);
$phone = consult_normalize_phone(post('phone'));
$message = mb_substr(post('message'), 0, 1000);

if (mb_strlen($name) < 3) {
    flash_set('error', 'نام و نام خانوادگی را وارد کنید.');
    redirect($back);
}
if ($phone === '') {
    flash_set('error', 'شماره تماس را درست وارد کنید. مثلاً ۰۹۱۲۱۲۳۴۵۶۷');
    redirect($back);
}
if (mb_strlen($message) < 3) {
    flash_set('error', 'یک توضیح کوتاه درباره درخواست بنویسید.');
    redirect($back);
}

ensure_consult_requests_schema($pdo);
$dup = $pdo->prepare("
  SELECT id FROM consult_requests
  WHERE phone=? AND message=? AND created_at >= DATE_SUB(NOW(), INTERVAL 10 MINUTE)
  LIMIT 1
");
$dup->execute([$phone, $message]);
if ($dup->fetch()) {
    flash_set('success', 'این درخواست همین الان ثبت شده. به‌زودی با شما تماس می‌گیریم.');
    redirect($back);
}

$pdo->prepare('INSERT INTO consult_requests (id, name, phone, message, status) VALUES (?,?,?,?,?)')
    ->execute([cuid(), $name, $phone, $message, 'new']);

if (function_exists('throttle_hit')) {
    throttle_hit('consult_request', 600);
}

flash_set('success', 'درخواست شما ثبت شد. به‌زودی با شما تماس می‌گیریم.');
redirect($back);
