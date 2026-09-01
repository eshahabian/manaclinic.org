<?php
declare(strict_types=1);
require_login(['SECRETARY']);

$patientId = post('patient_id');
$firstName = trim(post('new_first_name'));
$lastName = trim(post('new_last_name'));
$nameEn = trim(post('new_name_en'));
$surname = trim(post('new_surname'));
$newName = trim($firstName . ' ' . $lastName);
$newPhone = trim(post('new_phone'));
$newUsername = mb_strtolower(post('new_username'));
$newPassword = (string) ($_POST['new_password'] ?? '');
$newPasswordConfirm = (string) ($_POST['new_password_confirm'] ?? '');
$preferredDoctorId = post('new_preferred_doctor_id') ?: null;
$doctorId = post('doctor_id');
$date = post('date');
$time = post('time');
$notes = post('notes') ?: null;

if ($doctorId === '' || $date === '' || $time === '') {
    flash_set('error', 'اطلاعات نوبت ناقص است.');
    redirect('/secretary/book');
}

if ($patientId === '') {
    if ($firstName === '' || $lastName === '' || $nameEn === '' || $surname === '') {
        flash_set('error', 'نام، نام خانوادگی، name و surname الزامی هستند.');
        redirect('/secretary/book');
    }
    if ($preferredDoctorId === null || $preferredDoctorId === '') {
        $preferredDoctorId = $doctorId;
    }
    $prefDoc = $pdo->prepare('SELECT id FROM doctor_profiles WHERE id=? AND is_active=1 AND is_approved=1');
    $prefDoc->execute([$preferredDoctorId]);
    if (!$prefDoc->fetch()) {
        flash_set('error', 'درمانگر مربوط به مراجعه‌کننده معتبر نیست.');
        redirect('/secretary/book');
    }
    if (!preg_match('/^09[0-9]{9}$/', $newPhone)) {
        flash_set('error', 'موبایل الزامی است و باید ۱۱ رقم با ۰۹ باشد.');
        redirect('/secretary/book');
    }
    if ($newUsername === '') {
        flash_set('error', 'نام کاربری بیمار جدید الزامی است.');
        redirect('/secretary/book');
    }
    if (!preg_match('/^[a-z0-9._-]{3,32}$/', $newUsername)) {
        flash_set('error', 'نام کاربری نامعتبر است. فقط حروف انگلیسی، عدد و ._- (۳ تا ۳۲ کاراکتر).');
        redirect('/secretary/book');
    }
    if (strlen($newPassword) < 6) {
        flash_set('error', 'رمز عبور حداقل ۶ کاراکتر باشد.');
        redirect('/secretary/book');
    }
    if ($newPassword !== $newPasswordConfirm) {
        flash_set('error', 'رمز عبور و تکرار آن یکسان نیست.');
        redirect('/secretary/book');
    }
    $exists = $pdo->prepare('SELECT id FROM users WHERE username=?');
    $exists->execute([$newUsername]);
    if ($exists->fetch()) {
        flash_set('error', 'این نام کاربری قبلاً ثبت شده است.');
        redirect('/secretary/book');
    }
    $patientId = cuid();
    $pdo->prepare('INSERT INTO users (id,username,name,email,phone,password_hash,role,preferred_doctor_id,must_change_password) VALUES (?,?,?,?,?,?,?,?,0)')
        ->execute([$patientId, $newUsername, $newName, $newUsername . '@manaclinic.local', $newPhone, password_hash($newPassword, PASSWORD_DEFAULT), 'PATIENT', $preferredDoctorId]);

    $docNameStmt = $pdo->prepare('SELECT u.name FROM doctor_profiles dp JOIN users u ON u.id = dp.user_id WHERE dp.id = ?');
    $docNameStmt->execute([$preferredDoctorId]);
    $doctorName = (string) ($docNameStmt->fetchColumn() ?: 'درمانگر');
    notify_role(
        $pdo,
        'SECRETARY',
        'بیمار جدید توسط منشی',
        "بیمار «{$newName}» توسط منشی ثبت شد (درمانگر: {$doctorName}).",
        '/secretary/appointments'
    );
    notify_doctor_profile(
        $pdo,
        $preferredDoctorId,
        'بیمار جدید',
        "بیمار «{$newName}» به شما اختصاص داده شد.",
        '/doctor/patients/' . $patientId
    );
}

$doc = $pdo->prepare('SELECT * FROM doctor_profiles WHERE id=? AND is_active=1 AND is_approved=1');
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

$valid = appointment_slots_from_availability($availability);
if (!in_array($time, $valid, true)) {
    flash_set('error', 'ساعت نامعتبر است.');
    redirect('/secretary/book');
}

$startsAt = $date . ' ' . $time . ':00';
$endsAt = date('Y-m-d H:i:s', strtotime($startsAt) + (appointment_slot_minutes() * 60));

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

    $patientNameStmt = $pdo->prepare('SELECT name FROM users WHERE id = ?');
    $patientNameStmt->execute([$patientId]);
    $patientName = (string) ($patientNameStmt->fetchColumn() ?: 'بیمار');
    $when = format_fa_datetime($startsAt);
    notify_doctor_profile(
        $pdo,
        $doctorId,
        'نوبت جدید توسط منشی',
        "نوبت «{$patientName}» برای {$when} توسط منشی ثبت و تأیید شد.",
        '/doctor/appointments'
    );

    flash_set('success', 'نوبت با موفقیت ثبت و تأیید شد.');
} catch (Throwable $e) {
    $pdo->rollBack();
    flash_set('error', 'خطا در ثبت نوبت: ' . $e->getMessage());
}
redirect('/secretary/appointments');
