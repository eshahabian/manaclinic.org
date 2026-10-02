<?php
declare(strict_types=1);

require_once __DIR__ . '/notifications.php';
require_once __DIR__ . '/staff_desk.php';

/**
 * بیمار فیش را آپلود می‌کند؛ نوبت هنوز PENDING می‌ماند تا منشی تأیید کند.
 * @return array{payment_id:string,receipt_path:string}
 */
function patient_upload_appointment_receipt(PDO $pdo, string $appointmentId, string $patientId, array $file): array
{
    $stmt = $pdo->prepare("
      SELECT a.*, p.id AS payment_id, p.status AS pay_status, p.receipt_path, p.amount,
             du.name AS doctor_name
      FROM appointments a
      JOIN payments p ON p.appointment_id = a.id
      JOIN doctor_profiles dp ON dp.id = a.doctor_id
      JOIN users du ON du.id = dp.user_id
      WHERE a.id = ? AND a.patient_id = ?
      LIMIT 1
    ");
    $stmt->execute([$appointmentId, $patientId]);
    $row = $stmt->fetch();
    if (!$row) {
        throw new RuntimeException('نوبت یافت نشد.');
    }
    if ((string) ($row['status'] ?? '') !== 'PENDING_PAYMENT' || (string) ($row['pay_status'] ?? '') !== 'PENDING') {
        throw new RuntimeException('برای این نوبت دیگر نمی‌توان فیش بارگذاری کرد.');
    }

    $paymentId = (string) $row['payment_id'];
    $relative = staff_save_receipt($file, $paymentId);
    if (!empty($row['receipt_path'])) {
        $old = staff_receipt_abs((string) $row['receipt_path']);
        if (is_file($old)) {
            @unlink($old);
        }
    }
    $pdo->prepare("UPDATE payments SET receipt_path=?, ref_id=COALESCE(NULLIF(ref_id,''),'RECEIPT') WHERE id=?")
        ->execute([$relative, $paymentId]);

    $patient = $pdo->prepare('SELECT name FROM users WHERE id=? LIMIT 1');
    $patient->execute([$patientId]);
    $patientName = (string) ($patient->fetchColumn() ?: 'مراجعه‌کننده');
    $when = format_fa_datetime((string) ($row['starts_at'] ?? ''));

    notify_role(
        $pdo,
        'SECRETARY',
        'فیش پرداخت نوبت',
        "مراجعه‌کننده «{$patientName}» برای نوبت {$when} (درمانگر: {$row['doctor_name']}) فیش آپلود کرد. پس از بررسی، پرداخت را تأیید کنید.",
        '/secretary/appointments?tab=reservations',
        'appointment',
        $patientId
    );
    notify_role(
        $pdo,
        'ADMIN',
        'فیش پرداخت نوبت',
        "مراجعه‌کننده «{$patientName}» برای نوبت {$when} (درمانگر: {$row['doctor_name']}) فیش آپلود کرد.",
        '/admin/appointments?tab=upcoming',
        'appointment',
        $patientId
    );
    notify_doctor_profile(
        $pdo,
        (string) $row['doctor_id'],
        'فیش پرداخت نوبت',
        "مراجعه‌کننده «{$patientName}» برای نوبت {$when} فیش ارسال کرد — در انتظار تأیید منشی.",
        '/doctor/appointments?tab=upcoming',
        'appointment'
    );

    return ['payment_id' => $paymentId, 'receipt_path' => $relative];
}

/**
 * منشی/ادمین پرداخت را تأیید و نوبت را ثبت می‌کند.
 * اگر فیش جداگانه روی موبایل آمده باشد، بدون فایل هم قابل تأیید است.
 * @return array{appointment_id:string,patient_name:string,starts_at:string}
 */
function staff_confirm_appointment_payment(
    PDO $pdo,
    string $appointmentId,
    array $actor,
    array $file = [],
    string $note = ''
): array {
    $stmt = $pdo->prepare("
      SELECT a.*, p.id AS payment_id, p.status AS pay_status, p.receipt_path, p.amount,
             pu.name AS patient_name, pu.id AS patient_user_id
      FROM appointments a
      JOIN payments p ON p.appointment_id = a.id
      JOIN users pu ON pu.id = a.patient_id
      WHERE a.id = ?
      LIMIT 1
    ");
    $stmt->execute([$appointmentId]);
    $row = $stmt->fetch();
    if (!$row) {
        throw new RuntimeException('نوبت یافت نشد.');
    }
    $holdStatus = (string) ($row['status'] ?? '');
    if (!in_array($holdStatus, ['PENDING_APPROVAL', 'PENDING_PAYMENT'], true)) {
        throw new RuntimeException('این نوبت قابل تأیید پرداخت نیست.');
    }
    $alreadyPaid = (string) ($row['pay_status'] ?? '') === 'PAID';

    $paymentId = (string) $row['payment_id'];
    $staffUserId = (string) ($actor['id'] ?? '');
    $hasFile = (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE);

    if ($alreadyPaid && !$hasFile) {
        $pdo->prepare('UPDATE payments SET recorded_by_user_id=? WHERE id=?')
            ->execute([$staffUserId, $paymentId]);
    } elseif ($hasFile) {
        $relative = staff_save_receipt($file, $paymentId);
        if (!empty($row['receipt_path'])) {
            $old = staff_receipt_abs((string) $row['receipt_path']);
            if (is_file($old)) {
                @unlink($old);
            }
        }
        if ($alreadyPaid) {
            $pdo->prepare('UPDATE payments SET receipt_path=?, recorded_by_user_id=? WHERE id=?')
                ->execute([$relative, $staffUserId, $paymentId]);
        } else {
            $pdo->prepare('UPDATE payments SET receipt_path=?, status=?, ref_id=?, recorded_by_user_id=? WHERE id=?')
                ->execute([$relative, 'PAID', 'SECRETARY', $staffUserId, $paymentId]);
        }
    } else {
        $pdo->prepare('UPDATE payments SET status=?, ref_id=COALESCE(NULLIF(ref_id,\'\'),\'SECRETARY\'), recorded_by_user_id=? WHERE id=?')
            ->execute(['PAID', $staffUserId, $paymentId]);
    }

    if (function_exists('ensure_appointment_hold_columns')) {
        ensure_appointment_hold_columns($pdo);
    }
    $note = trim($note);
    if ($note !== '') {
        $pdo->prepare("
          UPDATE appointments
          SET status='CONFIRMED',
              payment_confirmed_at=NOW(),
              reviewed_at=COALESCE(reviewed_at, NOW()),
              requested_at=COALESCE(requested_at, created_at),
              staff_payment_note=?
          WHERE id=?
        ")->execute([$note, $appointmentId]);
    } else {
        $pdo->prepare("
          UPDATE appointments
          SET status='CONFIRMED',
              payment_confirmed_at=NOW(),
              reviewed_at=COALESCE(reviewed_at, NOW()),
              requested_at=COALESCE(requested_at, created_at)
          WHERE id=?
        ")->execute([$appointmentId]);
    }

    $patientName = (string) ($row['patient_name'] ?? 'مراجعه‌کننده');
    $when = format_fa_datetime((string) ($row['starts_at'] ?? ''));
    $actorLabel = function_exists('staff_actor_label') ? staff_actor_label($actor) : (string) ($actor['name'] ?? 'منشی');

    if (function_exists('staff_log_action')) {
        staff_log_action($pdo, $staffUserId, 'appointment_confirm_payment', 'appointment', $appointmentId, $patientName);
    }

    notify_user(
        $pdo,
        (string) ($row['patient_user_id'] ?? ''),
        'نوبت رزرو شد',
        "پرداخت نوبت {$when} تأیید شد و وقت شما رزرو گردید.",
        '/dashboard/appointments',
        'appointment',
        $staffUserId !== '' ? $staffUserId : null
    );
    notify_doctor_profile(
        $pdo,
        (string) $row['doctor_id'],
        'نوبت تأیید شد',
        "پرداخت نوبت «{$patientName}» ({$when}) توسط {$actorLabel} تأیید شد و وقت رزرو گردید.",
        '/doctor/appointments?tab=upcoming',
        'appointment'
    );

    return [
        'appointment_id' => $appointmentId,
        'patient_name' => $patientName,
        'starts_at' => (string) ($row['starts_at'] ?? ''),
    ];
}

function appointment_awaiting_secretary_approval(array $row): bool
{
    return (string) ($row['status'] ?? '') === 'PENDING_APPROVAL';
}

/**
 * منشی درخواست رزرو سایت را تأیید می‌کند تا مراجع بتواند پرداخت کند.
 * پیامک تأیید وقتی پنل پیامک وصل شد از همین نقطه اضافه می‌شود.
 * @return array{appointment_id:string,patient_name:string,starts_at:string}
 */
function staff_approve_appointment_booking(PDO $pdo, string $appointmentId, array $actor): array
{
    if (function_exists('ensure_appointment_approval_status')) {
        ensure_appointment_approval_status($pdo);
    }

    $stmt = $pdo->prepare("
      SELECT a.*, pu.name AS patient_name, pu.id AS patient_user_id, pu.phone AS patient_phone
      FROM appointments a
      JOIN users pu ON pu.id = a.patient_id
      WHERE a.id = ?
      LIMIT 1
    ");
    $stmt->execute([$appointmentId]);
    $row = $stmt->fetch();
    if (!$row) {
        throw new RuntimeException('نوبت یافت نشد.');
    }
    if ((string) ($row['status'] ?? '') !== 'PENDING_APPROVAL') {
        throw new RuntimeException('این درخواست دیگر در انتظار تأیید نیست.');
    }

    if (function_exists('ensure_appointment_hold_columns')) {
        ensure_appointment_hold_columns($pdo);
    }
    $updated = $pdo->prepare("
      UPDATE appointments
      SET status='PENDING_PAYMENT',
          reviewed_at=NOW(),
          requested_at=COALESCE(requested_at, created_at)
      WHERE id=? AND status='PENDING_APPROVAL'
    ");
    $updated->execute([$appointmentId]);
    if ($updated->rowCount() < 1) {
        throw new RuntimeException('تأیید درخواست انجام نشد.');
    }

    $patientName = (string) ($row['patient_name'] ?? 'مراجعه‌کننده');
    $when = format_fa_datetime((string) ($row['starts_at'] ?? ''));
    $staffUserId = (string) ($actor['id'] ?? '');
    $actorLabel = function_exists('staff_actor_label') ? staff_actor_label($actor) : (string) ($actor['name'] ?? 'منشی');

    if (function_exists('staff_log_action')) {
        staff_log_action($pdo, $staffUserId, 'appointment_approve_booking', 'appointment', $appointmentId, $patientName);
    }

    notify_user(
        $pdo,
        (string) ($row['patient_user_id'] ?? ''),
        'درخواست نوبت در حال بررسی است',
        "درخواست نوبت {$when} بدون پرداخت آنلاین پذیرفته شد و در حال بررسی است. تا تأیید پرداخت توسط منشی، رزرو نهایی نمی‌شود.",
        '/dashboard/appointments',
        'appointment',
        $staffUserId !== '' ? $staffUserId : null
    );
    notify_doctor_profile(
        $pdo,
        (string) $row['doctor_id'],
        'درخواست نوبت تأیید شد',
        "درخواست نوبت «{$patientName}» ({$when}) توسط {$actorLabel} بدون پرداخت پذیرفته شد و در حال بررسی است.",
        '/doctor/appointments?tab=upcoming',
        'appointment'
    );

    if (function_exists('sms_notify_kind_enabled') && sms_notify_kind_enabled($pdo, 'approval') && function_exists('sms_queue')) {
        sms_queue(
            $pdo,
            (string) ($row['patient_phone'] ?? ''),
            'مانا کلینیک: درخواست نوبت شما تأیید شد. برای پرداخت وارد سایت شوید.',
            'approval'
        );
    }

    return [
        'appointment_id' => $appointmentId,
        'patient_name' => $patientName,
        'starts_at' => (string) ($row['starts_at'] ?? ''),
    ];
}

/**
 * منشی درخواست رزرو سایت را رد می‌کند و ساعت را آزاد می‌کند.
 * @return array{appointment_id:string,patient_name:string,starts_at:string}
 */
function staff_reject_appointment_booking(PDO $pdo, string $appointmentId, array $actor, string $note = ''): array
{
    if (function_exists('ensure_appointment_approval_status')) {
        ensure_appointment_approval_status($pdo);
    }

    $note = trim($note);
    if ($note === '') {
        $note = 'درخواست نوبت توسط منشی تأیید نشد.';
    }

    $stmt = $pdo->prepare("
      SELECT a.*, pu.name AS patient_name, pu.id AS patient_user_id
      FROM appointments a
      JOIN users pu ON pu.id = a.patient_id
      WHERE a.id = ?
      LIMIT 1
    ");
    $stmt->execute([$appointmentId]);
    $row = $stmt->fetch();
    if (!$row) {
        throw new RuntimeException('نوبت یافت نشد.');
    }
    if (!in_array((string) ($row['status'] ?? ''), ['PENDING_APPROVAL', 'PENDING_PAYMENT'], true)) {
        throw new RuntimeException('این درخواست دیگر در انتظار بررسی نیست.');
    }

    $pdo->prepare("UPDATE payments SET status='FAILED' WHERE appointment_id=? AND status='PENDING'")
        ->execute([$appointmentId]);
    $updated = $pdo->prepare("
      UPDATE appointments
      SET status='CANCELLED',
          cancel_reason='rejected',
          cancellation_note=?,
          cancelled_by_user_id=?,
          cancelled_at=NOW()
      WHERE id=? AND status IN ('PENDING_APPROVAL','PENDING_PAYMENT')
    ");
    $updated->execute([$note, (string) ($actor['id'] ?? ''), $appointmentId]);
    if ($updated->rowCount() < 1) {
        throw new RuntimeException('رد درخواست انجام نشد.');
    }

    $patientName = (string) ($row['patient_name'] ?? 'مراجعه‌کننده');
    $when = format_fa_datetime((string) ($row['starts_at'] ?? ''));
    $staffUserId = (string) ($actor['id'] ?? '');
    $actorLabel = function_exists('staff_actor_label') ? staff_actor_label($actor) : (string) ($actor['name'] ?? 'منشی');

    if (function_exists('staff_log_action')) {
        staff_log_action($pdo, $staffUserId, 'appointment_reject_booking', 'appointment', $appointmentId, $patientName);
    }

    notify_user(
        $pdo,
        (string) ($row['patient_user_id'] ?? ''),
        'درخواست نوبت تأیید نشد',
        "درخواست نوبت {$when} توسط منشی تأیید نشد. در صورت نیاز ساعت دیگری انتخاب کنید.",
        '/dashboard/appointments',
        'appointment',
        $staffUserId !== '' ? $staffUserId : null
    );
    notify_doctor_profile(
        $pdo,
        (string) $row['doctor_id'],
        'درخواست نوبت رد شد',
        "درخواست نوبت «{$patientName}» ({$when}) توسط {$actorLabel} رد شد و ساعت آزاد گردید.",
        '/doctor/appointments?tab=cancelled',
        'appointment'
    );

    return [
        'appointment_id' => $appointmentId,
        'patient_name' => $patientName,
        'starts_at' => (string) ($row['starts_at'] ?? ''),
    ];
}

function appointment_approval_actions_html(array $row, string $next): string
{
    if (!appointment_awaiting_secretary_approval($row)) {
        return '';
    }
    $id = (string) ($row['id'] ?? '');
    ob_start();
    ?>
    <form method="post" action="<?= e(url('/secretary/appointments')) ?>" style="display:flex;flex-wrap:wrap;gap:.4rem;align-items:center;margin:0">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="approve_booking">
      <input type="hidden" name="appointment_id" value="<?= e($id) ?>">
      <input type="hidden" name="next" value="<?= e($next) ?>">
      <button type="submit" class="btn btn-outline btn-sm" onclick="return confirm('درخواست بدون پرداخت پذیرفته شود و در حال بررسی بماند؟');">تأیید بدون پرداخت</button>
    </form>
    <?= appointment_hold_reject_form_html($row, $next) ?>
    <p class="muted" style="font-size:.75rem;margin:0;flex-basis:100%">تأیید بدون پرداخت فقط درخواست را در حال بررسی نگه می‌دارد. رزرو وقتی است که «پرداخت شده» را بزنید.</p>
    <?php
    return (string) ob_get_clean();
}

function appointment_payment_awaiting_receipt_review(array $row): bool
{
    return (string) ($row['status'] ?? '') === 'PENDING_PAYMENT'
        && (string) ($row['pay_status'] ?? '') === 'PENDING'
        && trim((string) ($row['receipt_path'] ?? '')) !== '';
}

function appointment_payment_can_upload_receipt(array $row): bool
{
    return (string) ($row['status'] ?? '') === 'PENDING_PAYMENT'
        && (string) ($row['pay_status'] ?? '') === 'PENDING';
}

function appointment_pay_status_display(array $row): string
{
    if (appointment_payment_awaiting_receipt_review($row)) {
        return 'فیش ارسال شد — منتظر تأیید منشی';
    }
    if (appointment_awaiting_secretary_approval($row)) {
        return 'ارسال درخواست — پرداخت هنوز تأیید نشده';
    }
    if ((string) ($row['status'] ?? '') === 'PENDING_PAYMENT' && (string) ($row['pay_status'] ?? '') !== 'PAID') {
        return 'در حال بررسی — پرداخت هنوز تأیید نشده';
    }

    return payment_status_label((string) ($row['pay_status'] ?? ''));
}

function appointment_staff_can_mark_paid(array $row): bool
{
    return in_array((string) ($row['status'] ?? ''), ['PENDING_APPROVAL', 'PENDING_PAYMENT'], true);
}

function appointment_hold_requested_at(array $row): string
{
    $requested = trim((string) ($row['requested_at'] ?? ''));
    if ($requested !== '') {
        return $requested;
    }

    return trim((string) ($row['created_at'] ?? ''));
}

function appointment_hold_times_html(array $row): string
{
    $requested = trim((string) ($row['requested_at'] ?? ''));
    $confirmed = trim((string) ($row['payment_confirmed_at'] ?? ''));
    $reviewed = trim((string) ($row['reviewed_at'] ?? ''));
    if ($requested === '' && $confirmed === '' && $reviewed === '') {
        return '';
    }
    $parts = [];
    if ($requested !== '') {
        $parts[] = 'درخواست: ' . format_fa_datetime($requested);
    }
    if ($reviewed !== '' && $confirmed === '') {
        $parts[] = 'بررسی منشی: ' . format_fa_datetime($reviewed);
    }
    if ($confirmed !== '') {
        $parts[] = 'تأیید پرداخت: ' . format_fa_datetime($confirmed);
    }

    return '<div class="appt-hold-times muted" style="font-size:.8rem;margin-top:.35rem">' . e(implode(' · ', $parts)) . '</div>';
}

function appointment_patient_phase_html(array $row): string
{
    $status = (string) ($row['status'] ?? '');
    $requestedRaw = trim((string) ($row['requested_at'] ?? ''));
    $confirmed = trim((string) ($row['payment_confirmed_at'] ?? ''));
    $reserved = in_array($status, ['CONFIRMED', 'COMPLETED'], true);
    $isHold = in_array($status, ['PENDING_APPROVAL', 'PENDING_PAYMENT'], true);
    if (!$isHold && !($reserved && ($requestedRaw !== '' || $confirmed !== ''))) {
        return '';
    }
    $requested = $requestedRaw !== '' ? $requestedRaw : appointment_hold_requested_at($row);
    $reviewing = $status === 'PENDING_PAYMENT' || $reserved;
    $sent = $requested !== '' || $status === 'PENDING_APPROVAL' || $reviewing;
    $items = [
        [$sent, $status === 'PENDING_APPROVAL' && !$reviewing, 'ارسال درخواست', $requested !== '' ? format_fa_time($requested) : ''],
        [$reviewing, $status === 'PENDING_PAYMENT', 'در حال بررسی', ''],
        [$reserved, false, 'رزرو شده', $confirmed !== '' ? format_fa_time($confirmed) : ''],
    ];
    $html = '<ol class="appt-phases">';
    foreach ($items as [$done, $current, $label, $time]) {
        $cls = $done ? 'is-done' : ($current ? 'is-current' : '');
        if (!$done && $current) {
            $cls = 'is-current';
        } elseif ($done && $current) {
            $cls = 'is-current';
        } elseif ($done) {
            $cls = 'is-done';
        }
        $text = $label . ($time !== '' ? ' · ' . $time : '');
        $html .= '<li class="' . e($cls) . '">' . e($text) . '</li>';
    }
    $html .= '</ol>';

    return $html;
}

function appointment_hold_reject_form_html(array $row, string $next): string
{
    if (!appointment_staff_can_mark_paid($row) && !appointment_awaiting_secretary_approval($row)) {
        return '';
    }
    if (!in_array((string) ($row['status'] ?? ''), ['PENDING_APPROVAL', 'PENDING_PAYMENT'], true)) {
        return '';
    }
    $id = (string) ($row['id'] ?? '');
    ob_start();
    ?>
    <form method="post" action="<?= e(url('/secretary/appointments')) ?>" style="display:flex;flex-wrap:wrap;gap:.4rem;align-items:center;margin:0">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="reject_booking">
      <input type="hidden" name="appointment_id" value="<?= e($id) ?>">
      <input type="hidden" name="next" value="<?= e($next) ?>">
      <button type="submit" class="btn btn-outline btn-sm" onclick="return confirm('این درخواست رد شود و ساعت دوباره آزاد گردد؟');">رد درخواست</button>
    </form>
    <?php
    return (string) ob_get_clean();
}

function appointment_staff_mark_paid_form_html(array $row, string $next): string
{
    if (!appointment_staff_can_mark_paid($row)) {
        return '';
    }
    $id = (string) ($row['id'] ?? '');
    ob_start();
    ?>
    <form method="post" action="<?= e(url('/secretary/appointments')) ?>" enctype="multipart/form-data" class="appt-confirm-pay-form" style="display:flex;flex-wrap:wrap;gap:.45rem;align-items:end;margin:0;flex-basis:100%">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="confirm_payment">
      <input type="hidden" name="appointment_id" value="<?= e($id) ?>">
      <input type="hidden" name="next" value="<?= e($next) ?>">
      <label style="flex:1;min-width:12rem;margin:0">
        <span class="label" style="font-size:.75rem">یادداشت منشی</span>
        <textarea class="input" name="note" rows="2" placeholder="مثلاً کارت‌به‌کارت شد، یا پرداخت نقدی در کلینیک"><?= e(trim((string) ($row['staff_payment_note'] ?? ''))) ?></textarea>
      </label>
      <label class="btn btn-outline btn-sm staff-receipt-pick" title="اختیاری">
        فیش پرداخت (اختیاری)
        <input type="file" name="receipt" accept="image/jpeg,image/png,image/webp,application/pdf">
      </label>
      <button type="submit" class="btn btn-primary btn-sm" onclick="return confirm('پرداخت شده ثبت شود و این وقت رزرو گردد؟');">پرداخت شده</button>
    </form>
    <p class="muted" style="font-size:.75rem;margin:0;flex-basis:100%">بدون آپلود فیش هم می‌توانید «پرداخت شده» را بزنید. اگر تا یک ساعت زده نشود، این وقت برای مراجعه‌کننده آزاد می‌شود.</p>
    <?php
    return (string) ob_get_clean();
}
