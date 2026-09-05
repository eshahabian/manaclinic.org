<?php
declare(strict_types=1);

$user = require_login(['SECRETARY']);

$paymentId = trim(post('payment_id'));
if ($paymentId === '') {
    flash_set('error', 'پرداخت یافت نشد.');
    redirect('/secretary/appointments');
}

$stmt = $pdo->prepare('
  SELECT p.id, p.receipt_path, a.starts_at, a.status
  FROM payments p
  JOIN appointments a ON a.id = p.appointment_id
  WHERE p.id=?
  LIMIT 1
');
$stmt->execute([$paymentId]);
$payment = $stmt->fetch();
if (!$payment) {
    flash_set('error', 'پرداخت یافت نشد.');
    redirect('/secretary/appointments');
}

try {
    $relative = staff_save_receipt($_FILES['receipt'] ?? [], $paymentId);
    if (!empty($payment['receipt_path'])) {
        $old = staff_receipt_abs((string) $payment['receipt_path']);
        if (is_file($old)) {
            @unlink($old);
        }
    }
    $pdo->prepare('UPDATE payments SET receipt_path=?, recorded_by_user_id=COALESCE(recorded_by_user_id, ?) WHERE id=?')
        ->execute([$relative, $user['id'], $paymentId]);
    staff_log_action($pdo, (string) $user['id'], 'receipt_upload', 'payment', $paymentId);
    flash_set('success', 'رسید پرداخت ذخیره شد.');
} catch (RuntimeException $e) {
    flash_set('error', $e->getMessage());
}

$status = (string) ($payment['status'] ?? '');
$start = strtotime((string) ($payment['starts_at'] ?? '')) ?: 0;
$tab = (!in_array($status, ['CANCELLED', 'COMPLETED'], true) && $start >= time()) ? 'upcoming' : 'done';
redirect('/secretary/appointments?tab=' . $tab);
