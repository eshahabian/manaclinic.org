<?php
declare(strict_types=1);

$user = require_login(['SECRETARY']);

$paymentId = trim(post('payment_id'));
if ($paymentId === '') {
    flash_set('error', 'پرداخت یافت نشد.');
    redirect('/secretary/appointments');
}

$stmt = $pdo->prepare('SELECT id, receipt_path FROM payments WHERE id=? LIMIT 1');
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
    flash_set('success', 'فیش واریزی ذخیره شد.');
} catch (RuntimeException $e) {
    flash_set('error', $e->getMessage());
}

redirect('/secretary/appointments');
