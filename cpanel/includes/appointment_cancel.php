<?php
declare(strict_types=1);

require_once __DIR__ . '/wallet.php';

/** نرخ بازگشت وجه: 1 = کامل، 0.5 = نصف، 0 = بدون بازگشت */
function appointment_refund_rate(string $startsAt): float
{
    $secondsLeft = strtotime($startsAt) - time();
    if ($secondsLeft >= 24 * 3600) {
        return 1.0;
    }
    if ($secondsLeft >= 3 * 3600) {
        return 0.5;
    }
    return 0.0;
}

function appointment_refund_hint(string $startsAt): string
{
    $rate = appointment_refund_rate($startsAt);
    if ($rate >= 1.0) {
        return 'کل مبلغ به کیف پول شما بازمی‌گردد.';
    }
    if ($rate >= 0.5) {
        return '۵۰٪ مبلغ به کیف پول شما بازمی‌گردد.';
    }
    return 'بازگشت وجه امکان‌پذیر نیست.';
}

function patient_can_cancel_appointment(string $status): bool
{
    return in_array($status, ['PENDING_PAYMENT', 'CONFIRMED'], true);
}

function cancel_patient_appointment(PDO $pdo, string $appointmentId, string $patientId): array
{
    $stmt = $pdo->prepare("
      SELECT a.*, p.id AS payment_id, p.amount, p.status AS pay_status
      FROM appointments a
      LEFT JOIN payments p ON p.appointment_id = a.id
      WHERE a.id = ? AND a.patient_id = ?
      LIMIT 1
    ");
    $stmt->execute([$appointmentId, $patientId]);
    $row = $stmt->fetch();
    if (!$row) {
        throw new RuntimeException('نوبت یافت نشد.');
    }
    if (!patient_can_cancel_appointment((string) $row['status'])) {
        throw new RuntimeException('این نوبت قابل لغو نیست.');
    }

    $refundAmount = 0;
    $rate = 0.0;

    if ($row['status'] === 'CONFIRMED' && ($row['pay_status'] ?? '') === 'PAID') {
        $rate = appointment_refund_rate((string) $row['starts_at']);
        $refundAmount = (int) round(((int) $row['amount']) * $rate);
        if ($refundAmount > 0) {
            wallet_credit_balance(
                $pdo,
                $patientId,
                $refundAmount,
                'REFUND',
                'appointment',
                $appointmentId,
                'لغو نوبت — ' . appointment_refund_hint((string) $row['starts_at'])
            );
        }
    }

    if ($row['status'] === 'PENDING_PAYMENT' && !empty($row['payment_id'])) {
        $pdo->prepare("UPDATE payments SET status='FAILED' WHERE id=? AND status='PENDING'")
            ->execute([$row['payment_id']]);
    }

    $pdo->prepare("UPDATE appointments SET status='CANCELLED' WHERE id=?")->execute([$appointmentId]);

    $message = 'نوبت لغو شد.';
    if ($refundAmount > 0) {
        $message .= ' ' . format_price($refundAmount) . ' به کیف پول شما واریز شد.';
    } elseif ($row['status'] === 'CONFIRMED' && ($row['pay_status'] ?? '') === 'PAID') {
        $message .= ' ' . appointment_refund_hint((string) $row['starts_at']);
    }

    return [
        'refundAmount' => $refundAmount,
        'refundRate' => $rate,
        'message' => $message,
    ];
}
