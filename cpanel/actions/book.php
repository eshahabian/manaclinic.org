<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../includes/booking_terms.php';
require_once __DIR__ . '/../includes/availability.php';
require_once __DIR__ . '/../includes/appointment_session.php';

$user = current_user();
if (!$user || $user['role'] !== 'PATIENT') {
    http_response_code(401);
    echo json_encode(['error' => 'لطفاً با حساب مراجعه‌کننده وارد شوید.']);
    exit;
}

$doctorId = post('doctorId');
$date = post('date');
$time = post('time');
$sessionMode = appointment_normalize_session_mode(post('session_mode') ?: post('sessionMode'));
if (!booking_terms_accepted()) {
    booking_terms_not_accepted_error();
}
if ($doctorId === '' || $date === '' || $time === '') {
    http_response_code(400);
    echo json_encode(['error' => 'اطلاعات ناقص است.']);
    exit;
}

$doc = $pdo->prepare('SELECT * FROM doctor_profiles WHERE id=? AND is_active=1 AND is_approved=1');
$doc->execute([$doctorId]);
$doctor = $doc->fetch();
if (!$doctor) {
    http_response_code(404);
    echo json_encode(['error' => 'دکتر یافت نشد.']);
    exit;
}

$av = $pdo->prepare('SELECT * FROM availabilities WHERE doctor_id=? AND date=?');
$av->execute([$doctorId, $date]);
$availability = $av->fetch();
if (!$availability) {
    http_response_code(400);
    echo json_encode(['error' => 'این روز در دسترس نیست.']);
    exit;
}

$valid = appointment_slots_from_availability($availability);
if (!in_array($time, $valid, true)) {
    http_response_code(400);
    echo json_encode(['error' => 'ساعت نامعتبر است.']);
    exit;
}

$startsAt = appointment_slot_starts_at($date, $time);
$endsAt = date('Y-m-d H:i:s', strtotime($startsAt) + (appointment_slot_minutes() * 60));
if (function_exists('appointment_expire_unpaid_holds')) {
    appointment_expire_unpaid_holds($pdo);
}
if (strtotime($startsAt) <= time()) {
    http_response_code(400);
    echo json_encode(['error' => 'این زمان گذشته است.']);
    exit;
}

$conflict = $pdo->prepare("
  SELECT id FROM appointments
  WHERE doctor_id=? AND starts_at=? AND status IN ('PENDING_APPROVAL','PENDING_PAYMENT','CONFIRMED','COMPLETED')
");
$conflict->execute([$doctorId, $startsAt]);
if ($conflict->fetch()) {
    http_response_code(409);
    echo json_encode(['error' => 'این ساعت قبلاً رزرو شده است.']);
    exit;
}

$appointmentId = cuid();
$paymentId = cuid();
$amount = (int) $doctor['session_price'];

ensure_appointment_session_schema($pdo);
$pdo->beginTransaction();
try {
    $pdo->prepare('INSERT INTO appointments (id,doctor_id,patient_id,starts_at,ends_at,status,notes,session_mode,created_by_user_id,requested_at) VALUES (?,?,?,?,?,?,?,?,?,NOW())')
        ->execute([$appointmentId, $doctorId, $user['id'], $startsAt, $endsAt, 'PENDING_PAYMENT', null, $sessionMode, (string) $user['id']]);
    $pdo->prepare('INSERT INTO payments (id,appointment_id,amount,status) VALUES (?,?,?,?)')
        ->execute([$paymentId, $appointmentId, $amount, 'PENDING']);
    if (!function_exists('user_referral_stamp_payment')) {
        require_once __DIR__ . '/../includes/user_referral.php';
    }
    user_referral_stamp_payment($pdo, $paymentId, (string) $user['id'], $amount);
    $pdo->commit();

    $patientName = (string) ($user['name'] ?? 'مراجعه‌کننده');
    $when = format_fa_datetime($startsAt);
    notify_role(
        $pdo,
        'SECRETARY',
        'درخواست وقت جدید',
        "مراجعه‌کننده «{$patientName}» برای {$when} درخواست وقت داد. در لیست رزرو است؛ حتی بدون پرداخت آنلاین می‌توانید بررسی کنید و با «پرداخت شده» رزرو کنید.",
        '/secretary/appointments?tab=reservations',
        'appointment'
    );
    notify_doctor_profile(
        $pdo,
        $doctorId,
        'درخواست نوبت جدید',
        "مراجعه‌کننده «{$patientName}» برای {$when} درخواست وقت داد. تا تأیید پرداخت منشی، رزرو نهایی نیست.",
        '/doctor/appointments',
        'appointment'
    );

    echo json_encode([
        'appointmentId' => $appointmentId,
        'awaitingApproval' => true,
        'message' => 'درخواست شما ارسال شد و در حال بررسی است. تا یک ساعت این وقت برای شما می‌ماند. وقتی منشی پرداخت را تأیید کند، نوبت رزرو می‌شود.',
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    $pdo->rollBack();
    http_response_code(502);
    echo json_encode(['error' => 'ثبت نوبت الان ممکن نشد. دوباره تلاش کنید.'], JSON_UNESCAPED_UNICODE);
}
