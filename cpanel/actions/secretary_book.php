<?php
declare(strict_types=1);
require_login(['SECRETARY']);

$patientId = post('patient_id');
$newName = post('new_name');
$newPhone = post('new_phone') ?: null;
$newEmail = mb_strtolower(post('new_email'));
$doctorId = post('doctor_id');
$date = post('date');
$time = post('time');
$notes = post('notes') ?: null;

if ($doctorId === '' || $date === '' || $time === '') {
    flash_set('error', 'اطلاعات نوبت ناقص است.');
    redirect('/secretary/book');
}

if ($patientId === '') {
    if ($newName === '') {
        flash_set('error', 'نام بیمار جدید الزامی است.');
        redirect('/secretary/book');
    }
    if ($newEmail === '') {
        $newEmail = 'patient_' . time() . '_' . random_int(100, 999) . '@manaclinic.local';
    }
    $exists = $pdo->prepare('SELECT id FROM users WHERE email=?');
    $exists->execute([$newEmail]);
    if ($exists->fetch()) {
        flash_set('error', 'این ایمیل قبلاً ثبت شده است.');
        redirect('/secretary/book');
    }
    $patientId = cuid();
    $tempPass = bin2hex(random_bytes(4));
    $pdo->prepare('INSERT INTO users (id,name,email,phone,password_hash,role) VALUES (?,?,?,?,?,?)')
        ->execute([$patientId, $newName, $newEmail, $newPhone, password_hash($tempPass, PASSWORD_DEFAULT), 'PATIENT']);
}

$doc = $pdo->prepare('SELECT * FROM doctor_profiles WHERE id=? AND is_active=1');
$doc->execute([$doctorId]);
$doctor = $doc->fetch();
if (!$doctor) {
    flash_set('error', 'دکتر یافت نشد.');
    redirect('/secretary/book');
}

$av = $pdo->prepare('SELECT * FROM availabilities WHERE doctor_id=? AND date=?');
$av->execute([$doctorId, $date]);
$availability = $av->fetch();
if (!$availability) {
    flash_set('error', 'این روز برای دکتر خالی نیست.');
    redirect('/secretary/book');
}

$valid = generate_slots($availability['start_time'], $availability['end_time'], (int)$availability['slot_minutes']);
if (!in_array($time, $valid, true)) {
    flash_set('error', 'ساعت نامعتبر است.');
    redirect('/secretary/book');
}

$startsAt = $date . ' ' . $time . ':00';
$endsAt = date('Y-m-d H:i:s', strtotime($startsAt) + ((int)$availability['slot_minutes'] * 60));

$conflict = $pdo->prepare("
  SELECT id FROM appointments
  WHERE doctor_id=? AND starts_at=? AND status IN ('PENDING_PAYMENT','CONFIRMED','COMPLETED')
");
$conflict->execute([$doctorId, $startsAt]);
if ($conflict->fetch()) {
    flash_set('error', 'این ساعت قبلاً رزرو شده است.');
    redirect('/secretary/book');
}

$appointmentId = cuid();
$paymentId = cuid();
$pdo->beginTransaction();
try {
    $pdo->prepare('INSERT INTO appointments (id,doctor_id,patient_id,starts_at,ends_at,status,notes) VALUES (?,?,?,?,?,?,?)')
        ->execute([$appointmentId, $doctorId, $patientId, $startsAt, $endsAt, 'CONFIRMED', $notes]);
    $pdo->prepare('INSERT INTO payments (id,appointment_id,amount,status,ref_id) VALUES (?,?,?,?,?)')
        ->execute([$paymentId, $appointmentId, (int)$doctor['session_price'], 'PAID', 'SECRETARY']);
    $pdo->commit();
    flash_set('success', 'نوبت با موفقیت ثبت و تأیید شد.');
} catch (Throwable $e) {
    $pdo->rollBack();
    flash_set('error', 'خطا در ثبت نوبت: ' . $e->getMessage());
}
redirect('/secretary/appointments');
