<?php
declare(strict_types=1);

$authority = (string) ($_GET['Authority'] ?? $_GET['authority'] ?? '');
$status = (string) ($_GET['Status'] ?? $_GET['status'] ?? '');

if ($authority === '') {
    flash_set('error', 'اطلاعات پرداخت ناقص بود.');
    redirect('/dashboard/appointments');
}

$stmt = $pdo->prepare('SELECT * FROM payments WHERE authority = ? LIMIT 1');
$stmt->execute([$authority]);
$payment = $stmt->fetch();
if (!$payment) {
    flash_set('error', 'تراکنش یافت نشد.');
    redirect('/dashboard/appointments');
}

if ($status !== 'OK') {
    $pdo->prepare("UPDATE payments SET status='FAILED' WHERE id=?")->execute([$payment['id']]);
    $pdo->prepare("UPDATE appointments SET status='CANCELLED' WHERE id=?")->execute([$payment['appointment_id']]);
    flash_set('error', 'پرداخت لغو شد.');
    redirect('/dashboard/appointments');
}

$verified = zarinpal_verify($config, $authority, (int)$payment['amount']);
if (empty($verified['ok'])) {
    $pdo->prepare("UPDATE payments SET status='FAILED' WHERE id=?")->execute([$payment['id']]);
    $pdo->prepare("UPDATE appointments SET status='CANCELLED' WHERE id=?")->execute([$payment['appointment_id']]);
    flash_set('error', $verified['message'] ?? 'پرداخت ناموفق بود.');
    redirect('/dashboard/appointments');
}

$pdo->prepare("UPDATE payments SET status='PAID', ref_id=? WHERE id=?")
    ->execute([$verified['refId'] ?? null, $payment['id']]);
$pdo->prepare("UPDATE appointments SET status='CONFIRMED' WHERE id=?")
    ->execute([$payment['appointment_id']]);

flash_set('success', 'پرداخت با موفقیت انجام شد و نوبت تأیید شد.');
redirect('/dashboard/appointments');
