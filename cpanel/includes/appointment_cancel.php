<?php
declare(strict_types=1);

require_once __DIR__ . '/wallet.php';

/** بیش از ۲۴ ساعت مانده باشد کل مبلغ، وگرنه ۷۰٪ (۳۰٪ کسر می‌شود). */
function appointment_refund_rate(string $startsAt): float
{
    $secondsLeft = strtotime($startsAt) - time();

    return $secondsLeft >= 24 * 3600 ? 1.0 : 0.7;
}

/**
 * فقط نوبت پرداخت‌شده به کیف پول برمی‌گردد.
 * مراجع و بیش از ۲۴ ساعت: کل مبلغ. کمتر از ۲۴ ساعت: ۷۰٪.
 * منشی یا کلینیک: همیشه کل مبلغ.
 */
function appointment_cancel_refund_amount(array $row, string $side): int
{
    if ((string) ($row['pay_status'] ?? '') !== 'PAID') {
        return 0;
    }
    $amount = (int) ($row['amount'] ?? 0);
    if ($amount <= 0) {
        return 0;
    }
    if ($side === 'clinic') {
        return $amount;
    }
    $rate = appointment_refund_rate((string) ($row['starts_at'] ?? ''));

    return (int) floor($amount * $rate);
}

/** اگر هر پرداخت این نوبت تأیید شده باشد، کنسلی باید وجه را برگرداند. */
function appointment_cancel_mark_paid(PDO $pdo, array $row, string $appointmentId): array
{
    if ((string) ($row['pay_status'] ?? '') === 'PAID' || $appointmentId === '') {
        return $row;
    }
    $stmt = $pdo->prepare("SELECT amount FROM payments WHERE appointment_id = ? AND status = 'PAID' ORDER BY created_at DESC LIMIT 1");
    $stmt->execute([$appointmentId]);
    $amount = $stmt->fetchColumn();
    if ($amount === false) {
        return $row;
    }
    $row['pay_status'] = 'PAID';
    if ((int) ($row['amount'] ?? 0) <= 0) {
        $row['amount'] = (int) $amount;
    }

    return $row;
}

/** مبلغ ویزیت: اول مبلغ پرداخت، وگرنه همان مبلغی که از کیف پول کم شده است. */
function appointment_visit_fee_amount(PDO $pdo, array $row, string $appointmentId): int
{
    $amount = (int) ($row['amount'] ?? 0);
    if ($amount > 0) {
        return $amount;
    }
    if ($appointmentId === '') {
        return 0;
    }
    ensure_wallet_schema($pdo);
    $stmt = $pdo->prepare("
      SELECT ABS(amount) FROM wallet_transactions
      WHERE reference_type = 'appointment' AND reference_id = ? AND kind = 'PAYMENT' AND amount < 0
      ORDER BY created_at DESC
      LIMIT 1
    ");
    $stmt->execute([$appointmentId]);

    return (int) $stmt->fetchColumn();
}

function appointment_refund_hint(string $startsAt): string
{
    if (appointment_refund_rate($startsAt) >= 1.0) {
        return 'بیش از ۲۴ ساعت مانده است و کل مبلغ به کیف پول برمی‌گردد.';
    }

    return 'کمتر از ۲۴ ساعت مانده است؛ ۳۰٪ کسر می‌شود و ۷۰٪ به کیف پول برمی‌گردد.';
}

function patient_can_cancel_appointment(string $status): bool
{
    return in_array($status, ['PENDING_APPROVAL', 'PENDING_PAYMENT', 'CONFIRMED'], true);
}

function cancel_patient_appointment(PDO $pdo, string $appointmentId, string $patientId): array
{
    if (!function_exists('ensure_user_referral_schema')) {
        require_once __DIR__ . '/user_referral.php';
    }
    ensure_user_referral_schema($pdo);
    $stmt = $pdo->prepare("
      SELECT a.*, p.id AS payment_id, p.amount, p.status AS pay_status, p.clinic_share_amount
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
    $rate = appointment_refund_rate((string) ($row['starts_at'] ?? ''));

    if ($row['status'] === 'CONFIRMED') {
        $row = appointment_cancel_mark_paid($pdo, $row, $appointmentId);
        $row['amount'] = appointment_visit_fee_amount($pdo, $row, $appointmentId);
        $refundAmount = appointment_cancel_refund_amount($row, 'patient');
        if ($refundAmount > 0 && !appointment_refund_already($pdo, $appointmentId)) {
            wallet_credit_balance(
                $pdo,
                $patientId,
                $refundAmount,
                'REFUND',
                'appointment',
                $appointmentId,
                'بازگشت وجه جلسه انجام‌نشده — ' . appointment_refund_hint((string) $row['starts_at'])
            );
        } else {
            $refundAmount = 0;
        }
    }

    if ((string) ($row['pay_status'] ?? '') !== 'PAID') {
        wallet_release_open_visit($pdo, $appointmentId, 'برگشت بدهی؛ نوبت لغو شد');
    }
    if (in_array((string) $row['status'], ['PENDING_APPROVAL', 'PENDING_PAYMENT'], true) && !empty($row['payment_id'])) {
        $pdo->prepare("UPDATE payments SET status='FAILED' WHERE id=? AND status='PENDING'")
            ->execute([$row['payment_id']]);
    }

    $pdo->prepare("UPDATE appointments SET status='CANCELLED' WHERE id=?")->execute([$appointmentId]);
    if (function_exists('sms_notify_appointment_now')) {
        try {
            sms_notify_appointment_now($pdo, $appointmentId, 'cancelled');
        } catch (Throwable $ignored) {
        }
    }

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

function appointment_refund_already(PDO $pdo, string $appointmentId): bool
{
    if ($appointmentId === '') {
        return false;
    }
    ensure_wallet_schema($pdo);
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM wallet_transactions WHERE reference_type='appointment' AND reference_id=? AND kind='REFUND'");
    $stmt->execute([$appointmentId]);

    return (int) $stmt->fetchColumn() > 0;
}

function staff_can_mark_patient_cancelled(string $status): bool
{
    return in_array($status, ['PENDING_PAYMENT', 'CONFIRMED'], true);
}

function secretary_mark_patient_cancelled(PDO $pdo, string $appointmentId, array $actor, string $note, string $side = 'patient'): array
{
    $side = $side === 'clinic' ? 'clinic' : 'patient';
    $note = trim($note);

    if (!function_exists('ensure_user_referral_schema')) {
        require_once __DIR__ . '/user_referral.php';
    }
    ensure_user_referral_schema($pdo);

    $stmt = $pdo->prepare("
      SELECT a.*, pu.name AS patient_name, du.name AS doctor_name,
             p.amount, p.status AS pay_status, p.clinic_share_amount
      FROM appointments a
      JOIN users pu ON pu.id = a.patient_id
      JOIN doctor_profiles dp ON dp.id = a.doctor_id
      JOIN users du ON du.id = dp.user_id
      LEFT JOIN payments p ON p.appointment_id = a.id
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
    if ((string) ($row['status'] ?? '') === 'COMPLETED') {
        throw new RuntimeException('این جلسه برگزار شده و کنسلی ندارد.');
    }

    if ((string) ($row['pay_status'] ?? '') !== 'PAID') {
        wallet_release_open_visit($pdo, $appointmentId, $side === 'clinic'
            ? 'برگشت بدهی؛ کنسلی از سمت منشی'
            : 'برگشت بدهی؛ کنسلی از سمت مراجع');
    }
    $row = appointment_cancel_mark_paid($pdo, $row, $appointmentId);
    $row['amount'] = appointment_visit_fee_amount($pdo, $row, $appointmentId);
    $refundAmount = appointment_cancel_refund_amount($row, $side);
    if ($refundAmount > 0 && !appointment_refund_already($pdo, $appointmentId)) {
        $kept = max(0, (int) ($row['amount'] ?? 0) - $refundAmount);
        $noteBit = $side === 'clinic'
            ? 'بازگشت وجه جلسه انجام‌نشده — کنسلی منشی، کل مبلغ ویزیت'
            : ($kept > 0
                ? 'بازگشت وجه جلسه انجام‌نشده — ۳۰٪ کسر شد و بقیه واریز شد'
                : 'بازگشت وجه جلسه انجام‌نشده — کل مبلغ ویزیت');
        wallet_credit_balance(
            $pdo,
            (string) $row['patient_id'],
            $refundAmount,
            'REFUND',
            'appointment',
            $appointmentId,
            $noteBit
        );
    } else {
        $refundAmount = 0;
    }
    if (in_array((string) ($row['status'] ?? ''), ['PENDING_APPROVAL', 'PENDING_PAYMENT'], true) || (string) ($row['pay_status'] ?? '') === 'PENDING') {
        $pdo->prepare("UPDATE payments SET status='FAILED' WHERE appointment_id=? AND status='PENDING'")
            ->execute([$appointmentId]);
    }

    $pdo->prepare("
      UPDATE appointments
      SET status='CANCELLED',
          cancel_reason=?,
          cancellation_note=?,
          cancelled_by_user_id=?,
          cancelled_at=NOW()
      WHERE id=?
    ")->execute([$side === 'clinic' ? 'clinic' : 'patient', $note, (string) ($actor['id'] ?? ''), $appointmentId]);
    if (function_exists('clinic_rooms_ready') || is_file(__DIR__ . '/clinic_rooms.php')) {
        if (!function_exists('clinic_rooms_ready')) {
            require_once __DIR__ . '/clinic_rooms.php';
        }
        clinic_rooms_ready($pdo);
        $pdo->prepare('DELETE FROM clinic_room_bookings WHERE appointment_id=?')->execute([$appointmentId]);
    }
    try {
        $pdo->prepare('UPDATE appointments SET room_no=NULL WHERE id=?')->execute([$appointmentId]);
    } catch (Throwable $ignored) {
    }

    $patientName = (string) ($row['patient_name'] ?? 'مراجعه‌کننده');
    $when = format_fa_datetime((string) ($row['starts_at'] ?? ''));
    $actorName = staff_actor_label($actor);
    $sideLabel = $side === 'clinic' ? 'کلینیک' : 'مراجع';
    staff_log_action($pdo, (string) ($actor['id'] ?? ''), 'cancel_patient', 'appointment', $appointmentId, $patientName . ' — ' . $sideLabel . ' — ' . $note);
    notify_role(
        $pdo,
        'SECRETARY',
        'کنسلی از سمت ' . $sideLabel,
        "نوبت «{$patientName}» برای {$when} توسط {$actorName} به‌عنوان کنسلی {$sideLabel} ثبت شد.",
        '/secretary/appointments?tab=cancelled',
        'appointment'
    );
    notify_doctor_profile(
        $pdo,
        (string) $row['doctor_id'],
        'کنسلی از سمت ' . $sideLabel,
        $note !== ''
            ? "نوبت «{$patientName}» در {$when} از سمت {$sideLabel} کنسل شد. یادداشت: {$note}"
            : "نوبت «{$patientName}» در {$when} از سمت {$sideLabel} کنسل شد.",
        '/doctor/appointments?tab=cancelled',
        'appointment'
    );
    if (function_exists('sms_notify_appointment_now')) {
        try {
            sms_notify_appointment_now($pdo, $appointmentId, 'cancelled');
        } catch (Throwable $ignored) {
        }
    }
    if (!function_exists('notify_user')) {
        require_once __DIR__ . '/notifications.php';
    }
    $patientNote = $side === 'clinic'
        ? "نوبت {$when} از سمت کلینیک لغو شد."
        : "نوبت {$when} از سمت شما لغو شد.";
    if ($refundAmount > 0) {
        $patientNote .= ' ' . format_price($refundAmount) . ' به کیف پول شما برگشت.';
    }
    notify_user(
        $pdo,
        (string) $row['patient_id'],
        'نوبت لغو شد',
        $patientNote,
        '/dashboard/appointments',
        'appointment',
        (string) ($actor['id'] ?? '') ?: null
    );

    return [
        'id' => $appointmentId,
        'patient_name' => $patientName,
        'starts_at' => (string) ($row['starts_at'] ?? ''),
        'note' => $note,
        'side' => $side,
        'refundAmount' => $refundAmount,
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
        $cancelTitle = match ($reason) {
            'rejected' => 'رد درخواست:',
            'unpaid_timeout' => 'لغو مهلت:',
            'clinic' => 'کنسلی کلینیک:',
            default => 'کنسلی مراجع:',
        };
        $html .= '<div class="appt-note appt-note-cancel"><strong>' . e($cancelTitle) . '</strong> ' . e($cancel) . '</div>';
    } elseif ($status === 'CANCELLED' && $reason === 'clinic') {
        $html .= '<div class="appt-note appt-note-cancel">از سمت کلینیک کنسل شد.</div>';
    } elseif ($status === 'CANCELLED' && $reason === 'rejected') {
        $html .= '<div class="appt-note appt-note-cancel">درخواست توسط منشی رد شد.</div>';
    } elseif ($status === 'CANCELLED' && $reason === 'unpaid_timeout') {
        $html .= '<div class="appt-note appt-note-cancel">مهلت یک‌ساعته تمام شد و پرداخت تأیید نشد.</div>';
    }
    if ($booking !== '') {
        $html .= '<div class="appt-note"><strong>یادداشت نوبت:</strong> ' . e($booking) . '</div>';
    }
    $staffNote = trim((string) ($row['staff_payment_note'] ?? ''));
    if ($staffNote !== '') {
        $html .= '<div class="appt-note"><strong>یادداشت منشی:</strong> ' . e($staffNote) . '</div>';
    }
    if (function_exists('appointment_hold_times_html')) {
        $html .= appointment_hold_times_html($row);
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
      <label class="label" for="cancel-side-<?= e($appointmentId) ?>">کنسلی از کدام سمت است؟</label>
      <select class="input" id="cancel-side-<?= e($appointmentId) ?>" name="cancel_side" required>
        <option value="patient">از سمت مراجع — بیش از ۲۴ ساعت کل مبلغ، کمتر از ۲۴ ساعت ۳۰٪ کسر و بقیه به کیف پول</option>
        <option value="clinic">از سمت منشی — حتی یک دقیقه مانده باشد کل مبلغ به کیف پول برمی‌گردد</option>
      </select>
      <label class="label" for="cancel-note-<?= e($appointmentId) ?>">یادداشت کنسلی</label>
      <textarea class="input" id="cancel-note-<?= e($appointmentId) ?>" name="note" rows="2" placeholder="یادداشت کنسلی را بنویسید"></textarea>
      <button type="submit" class="btn btn-outline btn-sm">ثبت کنسلی</button>
    </form>
    <?php
    return (string) ob_get_clean();
}

/** بدهی‌های «بعداً پرداخت می‌کند» که منشی روی نوبت گذاشته است. */
function appointment_open_debts(PDO $pdo): array
{
    try {
        $rows = $pdo->query("
          SELECT p.amount, p.created_at, p.ref_id, a.starts_at, a.staff_payment_note,
                 pu.name AS patient_name, du.name AS doctor_name, cu.name AS actor_name
          FROM payments p
          JOIN appointments a ON a.id = p.appointment_id
          JOIN users pu ON pu.id = a.patient_id
          JOIN doctor_profiles dp ON dp.id = a.doctor_id
          JOIN users du ON du.id = dp.user_id
          LEFT JOIN users cu ON cu.id = p.recorded_by_user_id
          WHERE p.status = 'PENDING' AND p.ref_id = 'LATER' AND a.status <> 'CANCELLED'
          ORDER BY a.starts_at ASC
          LIMIT 80
        ")->fetchAll();

        return $rows ?: [];
    } catch (Throwable $e) {
        return [];
    }
}

function appointment_debts_html(PDO $pdo): string
{
    $rows = appointment_open_debts($pdo);
    ob_start();
    ?>
    <div class="panel" style="margin-top:1rem">
      <h2 style="margin:0 0 .4rem;font-size:1rem">بدهکاران</h2>
      <p class="muted" style="margin:0 0 .7rem;font-size:.85rem">نوبت‌هایی که منشی «بعداً پرداخت می‌کند» زده است: چه کسی، از کجا، چه تاریخی و چرا.</p>
      <?php if (!$rows): ?>
        <p class="muted" style="margin:0">بدهی بازی نیست.</p>
      <?php else: ?>
        <?php foreach ($rows as $debt): ?>
          <p style="margin:.4rem 0;line-height:1.7">
            <strong><?= e((string) ($debt['patient_name'] ?? '')) ?></strong>
            · <?= e(format_price((int) ($debt['amount'] ?? 0))) ?>
            · نوبت با <?= e((string) ($debt['doctor_name'] ?? '')) ?>
            · <?= e(format_fa_datetime((string) ($debt['starts_at'] ?? ''))) ?>
            · <?= e(trim((string) ($debt['staff_payment_note'] ?? '')) !== '' ? (string) $debt['staff_payment_note'] : 'پرداخت بعدی') ?>
            <?php if (trim((string) ($debt['actor_name'] ?? '')) !== ''): ?>
              · ثبت <?= e((string) $debt['actor_name']) ?>
            <?php endif; ?>
          </p>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>
    <?php
    return (string) ob_get_clean();
}
