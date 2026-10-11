<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/appointment_session.php';
$user = require_login(['SECRETARY']);
csrf_verify();
$actorId = (string) $user['id'];
$actorName = staff_actor_label($user);
$appNext = safe_next_path(post('next'));
if ($appNext === null || !str_starts_with($appNext, '/app')) {
    $appNext = '';
}
$bookRedirect = static function (string $fallback) use ($appNext): never {
    redirect($appNext !== '' ? $appNext : $fallback);
};

$patientId = post('patient_id');
$doctorId = post('doctor_id');
$date = post('date');
$time = post('time');
$notes = post('notes') ?: null;
$sessionMode = function_exists('appointment_normalize_session_mode')
    ? appointment_normalize_session_mode(post('session_mode'))
    : 'IN_PERSON';
$roomNo = function_exists('appointment_parse_room_choice')
    ? appointment_parse_room_choice((string) post('room_no'))
    : (int) post('room_no');

if ($doctorId === '' || $date === '' || $time === '') {
    flash_set('error', 'اطلاعات نوبت ناقص است.');
    $bookRedirect('/secretary/appointments?tab=new');
}
if (!function_exists('appointment_date_within_horizon')) {
    require_once __DIR__ . '/../includes/availability.php';
}
if (!appointment_date_within_horizon($date)) {
    flash_set('error', 'نوبت فقط تا دو هفته آینده قابل ثبت است.');
    $bookRedirect('/secretary/appointments?tab=new');
}
if (!function_exists('therapist_date_is_closed')) {
    require_once __DIR__ . '/../includes/therapist_presence.php';
}
if (therapist_date_is_closed($pdo, $doctorId, $date)) {
    flash_set('error', 'در این تاریخ درمانگر حضور ندارد.');
    $bookRedirect('/secretary/appointments?tab=new');
}

if ($patientId === '') {
    $created = secretary_create_patient_from_post($pdo, $user, ['fallback_doctor_id' => $doctorId]);
    if (empty($created['ok'])) {
        flash_set('error', (string) ($created['error'] ?? 'ثبت مراجعه‌کننده ممکن نشد.'));
        $bookRedirect('/secretary/appointments?tab=new');
    }
    $patientId = (string) $created['id'];
}

$referralToSave = null;
if (post('patient_id') !== '') {
    if (!function_exists('user_referral_of')) {
        require_once __DIR__ . '/../includes/user_referral.php';
    }
    if (user_referral_of($pdo, $patientId) === null) {
        $referralToSave = user_referral_normalize((string) post('new_referral_source'));
        if ($referralToSave === null) {
            flash_set('error', 'معرف را انتخاب کنید.');
            $bookRedirect('/secretary/appointments?tab=new');
        }
    }
}

$doc = $pdo->prepare('SELECT * FROM doctor_profiles WHERE id=? AND is_active=1 AND is_approved=1');
$doc->execute([$doctorId]);
$doctor = $doc->fetch();
if (!$doctor) {
    flash_set('error', 'دکتر یافت نشد.');
    $bookRedirect('/secretary/appointments?tab=new');
}

$av = $pdo->prepare('SELECT * FROM availabilities WHERE doctor_id=? AND date=?');
$av->execute([$doctorId, $date]);
$availability = $av->fetch();
if (!$availability) {
    flash_set('error', 'این روز برای دکتر خالی نیست.');
    $bookRedirect('/secretary/appointments?tab=new');
}

if (!appointment_booking_time_allowed($availability, $time)) {
    flash_set('error', 'ساعت یا دقیقه نامعتبر است.');
    $bookRedirect('/secretary/appointments?tab=new');
}

$startsAt = appointment_slot_starts_at($date, $time);
$endsAt = date('Y-m-d H:i:s', strtotime($startsAt) + (appointment_slot_minutes() * 60));

if (appointment_has_overlap($pdo, $doctorId, $startsAt, $endsAt)) {
    flash_set('error', 'این ساعت و دقیقه با نوبت دیگری تداخل دارد.');
    $bookRedirect('/secretary/appointments?tab=new');
}

$roomDraft = [
    'id' => '',
    'starts_at' => $startsAt,
    'ends_at' => $endsAt,
    'session_mode' => $sessionMode,
    'patient_id' => $patientId,
    'doctor_id' => $doctorId,
];
try {
    $roomNo = appointment_assert_room_choice($pdo, $roomDraft, $roomNo);
} catch (Throwable $e) {
    flash_set('error', $e->getMessage());
    $bookRedirect('/secretary/appointments?tab=new');
}

$appointmentId = cuid();
$paymentId = cuid();
$pdo->beginTransaction();
try {
    $receiptPath = null;
    if (!empty($_FILES['receipt']['name'])) {
        $receiptPath = staff_save_receipt($_FILES['receipt'], $paymentId);
    }
    ensure_appointment_session_schema($pdo);
    $pdo->prepare('INSERT INTO appointments (id,doctor_id,patient_id,starts_at,ends_at,status,notes,session_mode,created_by_user_id) VALUES (?,?,?,?,?,?,?,?,?)')
        ->execute([$appointmentId, $doctorId, $patientId, $startsAt, $endsAt, 'CONFIRMED', $notes, $sessionMode, $actorId]);
    $pdo->prepare('INSERT INTO payments (id,appointment_id,amount,status,ref_id,recorded_by_user_id,receipt_path) VALUES (?,?,?,?,?,?,?)')
        ->execute([$paymentId, $appointmentId, (int)$doctor['session_price'], 'PAID', 'SECRETARY', $actorId, $receiptPath]);
    if (!function_exists('user_referral_stamp_payment')) {
        require_once __DIR__ . '/../includes/user_referral.php';
    }
    if ($referralToSave !== null && !user_referral_save($pdo, $patientId, $referralToSave)) {
        throw new RuntimeException('معرف ذخیره نشد.');
    }
    user_referral_stamp_payment($pdo, $paymentId, $patientId, (int) $doctor['session_price']);
    $roomDraft['id'] = $appointmentId;
    appointment_save_room($pdo, $user, $roomDraft, $roomNo);
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
    $roomBit = $roomNo > 0 && function_exists('clinic_room_label') ? ' در ' . clinic_room_label($roomNo) : '';
    notify_user(
        $pdo,
        $patientId,
        'نوبت شما ثبت شد',
        "نوبت شما برای {$when}{$roomBit} توسط {$actorName} ثبت شد و در پنل شما و درمانگر دیده می‌شود.",
        '/dashboard/appointments',
        'appointment',
        $actorId
    );
    notify_doctor_profile(
        $pdo,
        $doctorId,
        'نوبت جدید توسط منشی',
        "نوبت «{$patientName}» برای {$when}{$roomBit} توسط {$actorName} ثبت و تأیید شد.",
        '/doctor/appointments',
        'appointment'
    );

    flash_set('success', 'نوبت با موفقیت ثبت و تأیید شد.');
    $desk = is_admin_user($user) ? '/admin/appointments' : '/secretary/appointments';
    $bookRedirect($desk . '?tab=upcoming&booked=1');
} catch (Throwable $e) {
    $pdo->rollBack();
    flash_set('error', 'خطا در ثبت نوبت: ' . $e->getMessage());
    $desk = is_admin_user($user) ? '/admin/appointments' : '/secretary/appointments';
    $bookRedirect($desk . '?tab=new');
}
