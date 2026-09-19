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
        '/secretary/appointments?tab=upcoming',
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
    array $file = []
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
    if ((string) ($row['status'] ?? '') !== 'PENDING_PAYMENT') {
        throw new RuntimeException('این نوبت قابل تأیید پرداخت نیست.');
    }
    if ((string) ($row['pay_status'] ?? '') === 'PAID') {
        throw new RuntimeException('پرداخت این نوبت قبلاً ثبت شده است.');
    }

    $paymentId = (string) $row['payment_id'];
    $staffUserId = (string) ($actor['id'] ?? '');
    $hasFile = (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE);

    if ($hasFile) {
        $relative = staff_save_receipt($file, $paymentId);
        if (!empty($row['receipt_path'])) {
            $old = staff_receipt_abs((string) $row['receipt_path']);
            if (is_file($old)) {
                @unlink($old);
            }
        }
        $pdo->prepare('UPDATE payments SET receipt_path=?, status=?, ref_id=?, recorded_by_user_id=? WHERE id=?')
            ->execute([$relative, 'PAID', 'SECRETARY', $staffUserId, $paymentId]);
    } else {
        $pdo->prepare('UPDATE payments SET status=?, ref_id=COALESCE(NULLIF(ref_id,\'\'),\'SECRETARY\'), recorded_by_user_id=? WHERE id=?')
            ->execute(['PAID', $staffUserId, $paymentId]);
    }

    $pdo->prepare("UPDATE appointments SET status='CONFIRMED' WHERE id=?")->execute([$appointmentId]);

    $patientName = (string) ($row['patient_name'] ?? 'مراجعه‌کننده');
    $when = format_fa_datetime((string) ($row['starts_at'] ?? ''));
    $actorLabel = function_exists('staff_actor_label') ? staff_actor_label($actor) : (string) ($actor['name'] ?? 'منشی');

    if (function_exists('staff_log_action')) {
        staff_log_action($pdo, $staffUserId, 'appointment_confirm_payment', 'appointment', $appointmentId, $patientName);
    }

    notify_user(
        $pdo,
        (string) ($row['patient_user_id'] ?? ''),
        'نوبت تأیید شد',
        "پرداخت نوبت {$when} تأیید شد و نوبت شما ثبت گردید.",
        '/dashboard/appointments',
        'appointment',
        $staffUserId !== '' ? $staffUserId : null
    );
    notify_doctor_profile(
        $pdo,
        (string) $row['doctor_id'],
        'نوبت تأیید شد',
        "پرداخت نوبت «{$patientName}» ({$when}) توسط {$actorLabel} تأیید و ثبت شد.",
        '/doctor/appointments?tab=upcoming',
        'appointment'
    );

    return [
        'appointment_id' => $appointmentId,
        'patient_name' => $patientName,
        'starts_at' => (string) ($row['starts_at'] ?? ''),
    ];
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

    return payment_status_label((string) ($row['pay_status'] ?? ''));
}
