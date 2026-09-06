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

function staff_can_mark_patient_cancelled(string $status): bool
{
    return in_array($status, ['PENDING_PAYMENT', 'CONFIRMED'], true);
}

function secretary_mark_patient_cancelled(PDO $pdo, string $appointmentId, array $actor, string $note): array
{
    $note = trim($note);
    if ($note === '') {
        $note = 'مراجعه‌کننده کنسل کرد.';
    }

    $stmt = $pdo->prepare("
      SELECT a.*, pu.name AS patient_name, du.name AS doctor_name
      FROM appointments a
      JOIN users pu ON pu.id = a.patient_id
      JOIN doctor_profiles dp ON dp.id = a.doctor_id
      JOIN users du ON du.id = dp.user_id
      WHERE a.id = ?
      LIMIT 1
    ");
    $stmt->execute([$appointmentId]);
    $row = $stmt->fetch();
    if (!$row) {
        throw new RuntimeException('نوبت یافت نشد.');
    }
    if (!staff_can_mark_patient_cancelled((string) $row['status'])) {
        throw new RuntimeException('این نوبت قابل ثبت کنسلی نیست.');
    }

    if ((string) ($row['status'] ?? '') === 'PENDING_PAYMENT') {
        $pdo->prepare("UPDATE payments SET status='FAILED' WHERE appointment_id=? AND status='PENDING'")
            ->execute([$appointmentId]);
    }

    $pdo->prepare("
      UPDATE appointments
      SET status='CANCELLED',
          cancel_reason='patient',
          cancellation_note=?,
          cancelled_by_user_id=?,
          cancelled_at=NOW()
      WHERE id=?
    ")->execute([$note, (string) ($actor['id'] ?? ''), $appointmentId]);

    $patientName = (string) ($row['patient_name'] ?? 'مراجعه‌کننده');
    $when = format_fa_datetime((string) ($row['starts_at'] ?? ''));
    $actorName = staff_actor_label($actor);
    staff_log_action($pdo, (string) ($actor['id'] ?? ''), 'cancel_patient', 'appointment', $appointmentId, $patientName . ' — ' . $note);
    notify_role(
        $pdo,
        'SECRETARY',
        'کنسلی مراجع',
        "نوبت «{$patientName}» برای {$when} توسط {$actorName} به‌عنوان کنسلی مراجع ثبت شد.",
        '/secretary/appointments?tab=done',
        'appointment'
    );
    notify_doctor_profile(
        $pdo,
        (string) $row['doctor_id'],
        'کنسلی مراجع',
        "مراجعه‌کننده «{$patientName}» نوبت {$when} را کنسل کرد. یادداشت: {$note}",
        '/doctor/appointments?tab=done',
        'appointment'
    );

    return [
        'id' => $appointmentId,
        'patient_name' => $patientName,
        'starts_at' => (string) ($row['starts_at'] ?? ''),
        'note' => $note,
    ];
}

function appointment_notes_html(array $row): string
{
    $booking = trim((string) ($row['notes'] ?? ''));
    $cancel = trim((string) ($row['cancellation_note'] ?? ''));
    $reason = (string) ($row['cancel_reason'] ?? '');
    $status = (string) ($row['status'] ?? '');
    $html = '';
    if ($cancel !== '') {
        $html .= '<div class="appt-note appt-note-cancel"><strong>کنسلی مراجع:</strong> ' . e($cancel) . '</div>';
    } elseif ($status === 'CANCELLED' && $reason === 'patient') {
        $html .= '<div class="appt-note appt-note-cancel">مراجعه‌کننده کنسل کرد.</div>';
    }
    if ($booking !== '') {
        $html .= '<div class="appt-note"><strong>یادداشت نوبت:</strong> ' . e($booking) . '</div>';
    }

    return $html;
}

function secretary_patient_cancel_form(string $appointmentId, string $status, string $next = '/secretary/appointments'): string
{
    if ($appointmentId === '' || !staff_can_mark_patient_cancelled($status)) {
        return '';
    }
    ob_start();
    ?>
    <form class="appt-cancel-form" method="post" action="<?= e(url('/secretary/appointments')) ?>">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="cancel_patient">
      <input type="hidden" name="appointment_id" value="<?= e($appointmentId) ?>">
      <input type="hidden" name="next" value="<?= e($next) ?>">
      <label class="label" for="cancel-note-<?= e($appointmentId) ?>">یادداشت کنسلی</label>
      <textarea class="input" id="cancel-note-<?= e($appointmentId) ?>" name="note" rows="2" required placeholder="مثلاً: سر وقت آمد ولی کنسل کرد">مراجعه‌کننده کنسل کرد.</textarea>
      <button type="submit" class="btn btn-outline btn-sm">ثبت کنسلی مراجع</button>
    </form>
    <?php
    return (string) ob_get_clean();
}
