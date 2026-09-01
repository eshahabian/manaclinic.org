<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/workshops.php';

$authority = (string) ($_GET['Authority'] ?? $_GET['authority'] ?? '');
$status = (string) ($_GET['Status'] ?? $_GET['status'] ?? '');
$kind = (string) ($_GET['kind'] ?? '');

if ($authority === '') {
    flash_set('error', 'اطلاعات پرداخت ناقص بود.');
    redirect('/dashboard/appointments');
}

// پرداخت کارگاه
if ($kind === 'workshop') {
    $stmt = $pdo->prepare('SELECT * FROM workshop_payments WHERE authority = ? LIMIT 1');
    $stmt->execute([$authority]);
    $payment = $stmt->fetch();
    if (!$payment) {
        flash_set('error', 'تراکنش کارگاه یافت نشد.');
        redirect('/dashboard/courses');
    }

    if ($status !== 'OK') {
        $pdo->prepare("UPDATE workshop_payments SET status='FAILED' WHERE id=?")->execute([$payment['id']]);
        $pdo->prepare("UPDATE workshop_enrollments SET status='CANCELLED' WHERE id=?")->execute([$payment['enrollment_id']]);
        flash_set('error', 'پرداخت لغو شد.');
        redirect('/dashboard/courses');
    }

    $onlineAmount = (int) $payment['amount'] - (int) ($payment['wallet_amount'] ?? 0);
    $verified = zarinpal_verify($config, $authority, $onlineAmount);
    if (empty($verified['ok'])) {
        $pdo->prepare("UPDATE workshop_payments SET status='FAILED' WHERE id=?")->execute([$payment['id']]);
        $pdo->prepare("UPDATE workshop_enrollments SET status='CANCELLED' WHERE id=?")->execute([$payment['enrollment_id']]);
        flash_set('error', $verified['message'] ?? 'پرداخت ناموفق بود.');
        redirect('/dashboard/courses');
    }

    $pdo->beginTransaction();
    try {
        $payment['ref_id'] = $verified['refId'] ?? null;
        confirm_workshop_payment($pdo, $payment);
        $pdo->commit();
        flash_set('success', 'پرداخت کارگاه با موفقیت انجام شد.');
    } catch (Throwable $e) {
        $pdo->rollBack();
        flash_set('error', $e->getMessage());
    }
    redirect('/dashboard/courses');
}

// پرداخت نوبت (قبلی)
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

$appStmt = $pdo->prepare("
  SELECT a.starts_at, a.doctor_id, u.name AS patient_name
  FROM appointments a
  JOIN users u ON u.id = a.patient_id
  WHERE a.id = ?
  LIMIT 1
");
$appStmt->execute([$payment['appointment_id']]);
$appInfo = $appStmt->fetch();
if ($appInfo) {
    $patientName = (string) $appInfo['patient_name'];
    $when = format_fa_datetime((string) $appInfo['starts_at']);
    notify_role(
        $pdo,
        'SECRETARY',
        'تأیید نوبت پس از پرداخت',
        "نوبت «{$patientName}» برای {$when} پرداخت و تأیید شد.",
        '/secretary/appointments'
    );
    notify_doctor_profile(
        $pdo,
        (string) $appInfo['doctor_id'],
        'تأیید نوبت',
        "نوبت «{$patientName}» برای {$when} پرداخت و تأیید شد.",
        '/doctor/appointments'
    );
}

flash_set('success', 'پرداخت با موفقیت انجام شد و نوبت تأیید شد.');
redirect('/dashboard/appointments');
