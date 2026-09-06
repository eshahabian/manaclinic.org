<?php
declare(strict_types=1);
$user = require_login(['SECRETARY']);
csrf_verify();
$actorId = (string) $user['id'];
$actorName = staff_actor_label($user);

$patientId = post('patient_id');
$doctorId = post('doctor_id');
$date = post('date');
$time = post('time');
$notes = post('notes') ?: null;

if ($doctorId === '' || $date === '' || $time === '') {
    flash_set('error', 'اطلاعات نوبت ناقص است.');
    redirect('/secretary/appointments?tab=new');
}

if ($patientId === '') {
    $created = secretary_create_patient_from_post($pdo, $user, ['fallback_doctor_id' => $doctorId]);
    if (empty($created['ok'])) {
        flash_set('error', (string) ($created['error'] ?? 'ثبت مراجعه‌کننده ممکن نشد.'));
        redirect('/secretary/appointments?tab=new');
    }
    $patientId = (string) $created['id'];
}

$doc = $pdo->prepare('SELECT * FROM doctor_profiles WHERE id=? AND is_active=1 AND is_approved=1');
$doc->execute([$doctorId]);
$doctor = $doc->fetch();
if (!$doctor) {
    flash_set('error', 'دکتر یافت نشد.');
    redirect('/secretary/appointments?tab=new');
}

$av = $pdo->prepare('SELECT * FROM availabilities WHERE doctor_id=? AND date=?');
$av->execute([$doctorId, $date]);
$availability = $av->fetch();
if (!$availability) {
    flash_set('error', 'این روز برای دکتر خالی نیست.');
    redirect('/secretary/appointments?tab=new');
}

$valid = appointment_slots_from_availability($availability);
if (!in_array($time, $valid, true)) {
    flash_set('error', 'ساعت نامعتبر است.');
    redirect('/secretary/appointments?tab=new');
}

$startsAt = appointment_slot_starts_at($date, $time);
$endsAt = date('Y-m-d H:i:s', strtotime($startsAt) + (appointment_slot_minutes() * 60));

$conflict = $pdo->prepare("
  SELECT id FROM appointments
  WHERE doctor_id=? AND starts_at=? AND status IN ('PENDING_PAYMENT','CONFIRMED','COMPLETED')
");
$conflict->execute([$doctorId, $startsAt]);
if ($conflict->fetch()) {
    flash_set('error', 'این ساعت قبلاً رزرو شده است.');
    redirect('/secretary/appointments?tab=new');
}

$appointmentId = cuid();
$paymentId = cuid();
$pdo->beginTransaction();
try {
    $receiptPath = null;
    if (!empty($_FILES['receipt']['name'])) {
        $receiptPath = staff_save_receipt($_FILES['receipt'], $paymentId);
    }
    $pdo->prepare('INSERT INTO appointments (id,doctor_id,patient_id,starts_at,ends_at,status,notes,created_by_user_id) VALUES (?,?,?,?,?,?,?,?)')
        ->execute([$appointmentId, $doctorId, $patientId, $startsAt, $endsAt, 'CONFIRMED', $notes, $actorId]);
    $pdo->prepare('INSERT INTO payments (id,appointment_id,amount,status,ref_id,recorded_by_user_id,receipt_path) VALUES (?,?,?,?,?,?,?)')
        ->execute([$paymentId, $appointmentId, (int)$doctor['session_price'], 'PAID', 'SECRETARY', $actorId, $receiptPath]);
    $pdo->commit();

    $patientNameStmt = $pdo->prepare('SELECT name FROM users WHERE id = ?');
    $patientNameStmt->execute([$patientId]);
    $patientName = (string) ($patientNameStmt->fetchColumn() ?: 'مراجعه‌کننده');
    staff_log_action($pdo, $actorId, 'book_appointment', 'appointment', $appointmentId, $patientName);
    $when = format_fa_datetime($startsAt);
    notify_role(
        $pdo,
        'SECRETARY',
        'نوبت جدید توسط منشی',
        "نوبت «{$patientName}» برای {$when} توسط {$actorName} ثبت و تأیید شد.",
        '/secretary/appointments',
        'appointment'
    );
    notify_doctor_profile(
        $pdo,
        $doctorId,
        'نوبت جدید توسط منشی',
        "نوبت «{$patientName}» برای {$when} توسط {$actorName} ثبت و تأیید شد.",
        '/doctor/appointments',
        'appointment'
    );

    flash_set('success', 'نوبت با موفقیت ثبت و تأیید شد.');
    $desk = is_admin_user($user) ? '/admin/appointments' : '/secretary/appointments';
    redirect($desk . '?tab=upcoming&booked=1');
} catch (Throwable $e) {
    $pdo->rollBack();
    flash_set('error', 'خطا در ثبت نوبت: ' . $e->getMessage());
    $desk = is_admin_user($user) ? '/admin/appointments' : '/secretary/appointments';
    redirect($desk . '?tab=new');
}
