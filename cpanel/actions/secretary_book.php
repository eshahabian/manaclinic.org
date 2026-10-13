<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/appointment_session.php';
require_once __DIR__ . '/../includes/secretary_patient.php';
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
    flash_set('error', 'مراجعه‌کننده را انتخاب کنید.');
    $bookRedirect('/secretary/appointments?tab=new');
}

if (str_starts_with($patientId, 'outreach:')) {
    flash_set('error', 'این کاربر غیرفعال است. اول از فهرست مراجعه‌کنندگان فعالش کنید.');
    $bookRedirect('/secretary/appointments?tab=new');
}
if ($patientId !== '') {
    $account = $pdo->prepare('SELECT is_disabled FROM users WHERE id=? LIMIT 1');
    $account->execute([$patientId]);
    $accountRow = $account->fetch();
    if ($accountRow && (int) ($accountRow['is_disabled'] ?? 0) === 1) {
        flash_set('error', 'این کاربر غیرفعال است. اول از فهرست مراجعه‌کنندگان فعالش کنید.');
        $bookRedirect('/secretary/appointments?tab=new');
    }
}

if ($patientId !== '' && function_exists('secretary_complete_patient_profile')) {
    $setupError = secretary_complete_patient_profile($pdo, $patientId);
    if ($setupError !== null) {
        flash_set('error', $setupError);
        $bookRedirect('/secretary/appointments?tab=new');
    }
}

$referralToSave = null;
if (post('patient_id') !== '') {
    if (!function_exists('user_referral_of')) {
        require_once __DIR__ . '/../includes/user_referral.php';
    }
    if (user_referral_of($pdo, $patientId) === null) {
        $referralToSave = user_referral_normalize((string) post('legacy_referral'));
        if ($referralToSave === null) {
            $referralToSave = user_referral_normalize((string) post('new_referral_source'));
        }
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

$payMethod = (string) post('pay_method');
if (!in_array($payMethod, ['wallet', 'card', 'later'], true)) {
    $payMethod = !empty($_FILES['receipt']['name']) ? 'card' : 'later';
}
$amount = (int) $doctor['session_price'];
if ($amount <= 0) {
    flash_set('error', 'هزینه جلسه این درمانگر مشخص نیست.');
    $bookRedirect('/secretary/appointments?tab=new');
}
if ($payMethod === 'card' && empty($_FILES['receipt']['name'])) {
    flash_set('error', 'برای کارت به کارت، فیش را بارگذاری کنید.');
    $bookRedirect('/secretary/appointments?tab=new');
}
if ($payMethod === 'wallet') {
    require_once __DIR__ . '/../includes/wallet.php';
    ensure_wallet_schema($pdo);
    $wallet = ensure_wallet($pdo, $patientId);
    if ((int) ($wallet['balance'] ?? 0) < $amount) {
        flash_set('error', 'موجودی کیف پول مراجع برای این نوبت کافی نیست.');
        $bookRedirect('/secretary/appointments?tab=new');
    }
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
ensure_appointment_session_schema($pdo);
appointment_ensure_room_schema($pdo);
$pdo->beginTransaction();
try {
    $receiptPath = null;
    if ($payMethod === 'card' && !empty($_FILES['receipt']['name'])) {
        $receiptPath = staff_save_receipt($_FILES['receipt'], $paymentId);
    }
    $payStatus = $payMethod === 'later' ? 'PENDING' : 'PAID';
    $payRef = $payMethod === 'wallet' ? 'WALLET' : ($payMethod === 'card' ? 'CARD' : 'LATER');
    $payNote = $payMethod === 'later' ? 'پرداخت بعدی؛ بدهی نوبت منشی' : null;
    $pdo->prepare('INSERT INTO appointments (id,doctor_id,patient_id,starts_at,ends_at,status,notes,session_mode,room_no,created_by_user_id,staff_payment_note) VALUES (?,?,?,?,?,?,?,?,?,?,?)')
        ->execute([$appointmentId, $doctorId, $patientId, $startsAt, $endsAt, 'CONFIRMED', $notes, $sessionMode, $roomNo > 0 ? $roomNo : null, $actorId, $payNote]);
    $pdo->prepare('INSERT INTO payments (id,appointment_id,amount,status,ref_id,recorded_by_user_id,receipt_path) VALUES (?,?,?,?,?,?,?)')
        ->execute([$paymentId, $appointmentId, $amount, $payStatus, $payRef, $actorId, $receiptPath]);
    if ($payMethod === 'wallet') {
        wallet_debit_balance(
            $pdo,
            $patientId,
            $amount,
            'appointment',
            $appointmentId,
            'پرداخت نوبت از کیف پول، ثبت منشی'
        );
    }
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

    $payBit = match ($payMethod) {
        'wallet' => ' مبلغ از کیف پول کم شد.',
        'card' => ' پرداخت کارت به کارت با فیش ثبت شد.',
        default => ' پرداخت بعدی به‌عنوان بدهی ماند.',
    };
    flash_set('success', 'نوبت با موفقیت ثبت و تأیید شد.' . $payBit . ($roomNo > 0 && function_exists('clinic_room_label') ? ' ' . clinic_room_label($roomNo) . ' هم روی نوبت است.' : ''));
    $desk = is_admin_user($user) ? '/admin/appointments' : '/secretary/appointments';
    $bookRedirect($desk . '?tab=upcoming&booked=1');
} catch (Throwable $e) {
    $pdo->rollBack();
    flash_set('error', 'خطا در ثبت نوبت: ' . $e->getMessage());
    $desk = is_admin_user($user) ? '/admin/appointments' : '/secretary/appointments';
    $bookRedirect($desk . '?tab=new');
}
