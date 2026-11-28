<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../includes/booking_terms.php';
require_once __DIR__ . '/../includes/availability.php';
require_once __DIR__ . '/../includes/appointment_session.php';

$user = current_user();
if (!$user || !user_can_self_book_therapy($user)) {
    http_response_code(401);
    echo json_encode(['error' => 'برای رزرو نوبت وارد شوید (مراجعه‌کننده یا درمانگر).']);
    exit;
}

$doctorId = post('doctorId');
$date = post('date');
$time = post('time');
$repeatEvery = post('repeat_every');
$repeatCount = filter_var($repeatEvery === '' ? '1' : post('repeat_count'), FILTER_VALIDATE_INT, ['options' => ['min_range' => $repeatEvery === '' ? 1 : 2, 'max_range' => 12]]);
if ($repeatCount === false || !in_array($repeatEvery, ['', 'week', 'biweek', 'month'], true)) {
    http_response_code(400);
    echo json_encode(['error' => 'برای تکرار، فاصله و تعداد جلسات بین ۲ و ۱۲ را انتخاب کنید.'], JSON_UNESCAPED_UNICODE);
    exit;
}
$scheduledHold = $repeatCount > 1;
$sessionMode = appointment_normalize_session_mode(post('session_mode') ?: post('sessionMode'));
if (!booking_terms_accepted()) {
    booking_terms_not_accepted_error();
}
if ($doctorId === '' || $date === '' || $time === '') {
    http_response_code(400);
    echo json_encode(['error' => 'اطلاعات ناقص است.']);
    exit;
}
if (!appointment_date_within_horizon($date)) {
    http_response_code(400);
    echo json_encode(['error' => 'نوبت فقط تا دو هفته آینده باز است.']);
    exit;
}
if (!function_exists('therapist_date_is_closed')) {
    require_once __DIR__ . '/../includes/therapist_presence.php';
}
if (therapist_date_is_closed($pdo, $doctorId, $date)) {
    http_response_code(400);
    echo json_encode(['error' => 'در این تاریخ درمانگر حضور ندارد.']);
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
if ((string) ($doctor['user_id'] ?? '') === (string) ($user['id'] ?? '')) {
    http_response_code(400);
    echo json_encode(['error' => 'نمی‌توانید برای خودتان نوبت بگیرید. درمانگر دیگری را انتخاب کنید.']);
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

if (!preg_match('/^\d{2}:\d{2}$/', $time)) {
    http_response_code(400);
    echo json_encode(['error' => 'ساعت نامعتبر است.']);
    exit;
}
$dayBookings = appointment_fetch_day_bookings($pdo, $doctorId, $date);
if (!appointment_time_matches_open_chip($date, appointment_availability_hours($availability), $dayBookings, $time)) {
    http_response_code(400);
    echo json_encode(['error' => 'این ساعت برای رزرو باز نیست.']);
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

if (appointment_has_overlap($pdo, $doctorId, $startsAt, $endsAt)) {
    http_response_code(409);
    echo json_encode(['error' => 'این ساعت و دقیقه قبلاً رزرو شده است.']);
    exit;
}

$appointmentId = cuid();
$paymentId = cuid();
$cardPilot = user_uses_card_receipt_pilot($user);
$amount = (int) $doctor['session_price'];
if (!function_exists('patient_fee_resolve_toman')) {
    require_once __DIR__ . '/../includes/patient_fee.php';
}
$fee = patient_fee_load($pdo, (string) $user['id']);
$amountUsd = patient_fee_resolve_usd($fee);
$amountGbp = patient_fee_resolve_gbp($fee);
$amount = $amountUsd === null && $amountGbp === null ? patient_fee_resolve_toman($fee, $amount) : 0;

ensure_appointment_session_schema($pdo);
require_once __DIR__ . '/../includes/wallet.php';
ensure_wallet($pdo, (string) $user['id']);
require_once __DIR__ . '/../includes/user_referral.php';
ensure_user_referral_schema($pdo);
$weeklyHours = [];
if ($scheduledHold) {
    $weeklyHours = doctor_weekly_hours_map($pdo, $doctorId);
}
$pdo->beginTransaction();
try {
    $lock = $pdo->prepare('SELECT id FROM doctor_profiles WHERE id=? FOR UPDATE');
    $lock->execute([$doctorId]);
    $lock->closeCursor();
    $lastAvailableDate = '';
    if ($weeklyHours !== []) {
        $lastAvailability = $pdo->prepare('SELECT MAX(date) FROM availabilities WHERE doctor_id=?');
        $lastAvailability->execute([$doctorId]);
        $lastAvailableDate = (string) $lastAvailability->fetchColumn();
    }
    foreach (appointment_repeat_dates($date, $repeatEvery, $repeatCount) as $index => $sessionDate) {
        $sessionStart = appointment_slot_starts_at($sessionDate, $time);
        $sessionEnd = date('Y-m-d H:i:s', strtotime($sessionStart) + appointment_slot_minutes() * 60);
        $av->execute([$doctorId, $sessionDate]);
        $sessionAvailability = $av->fetch();
        $hours = $sessionAvailability ? appointment_availability_hours($sessionAvailability) : [];
        if (!$sessionAvailability && $index > 0 && $sessionDate > $lastAvailableDate) {
            $weekday = ((int) date('w', strtotime($sessionDate)) + 1) % 7;
            $hours = $weeklyHours[$weekday] ?? [];
        }
        if ($hours === [] || therapist_date_is_closed($pdo, $doctorId, $sessionDate)
            || !appointment_time_matches_open_chip($sessionDate, $hours, appointment_fetch_day_bookings($pdo, $doctorId, $sessionDate), $time)
            || appointment_has_overlap($pdo, $doctorId, $sessionStart, $sessionEnd)) {
            $pdo->rollBack();
            http_response_code(409);
            echo json_encode(['error' => 'ساعت انتخابی در ' . format_fa_datetime($sessionStart) . ' در دسترس نیست. تعداد جلسات یا زمان شروع را تغییر دهید.'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        $rowId = $index === 0 ? $appointmentId : cuid();
        $rowPayId = $index === 0 ? $paymentId : cuid();
        $pdo->prepare('INSERT INTO appointments (id,doctor_id,patient_id,starts_at,ends_at,status,notes,session_mode,created_by_user_id,requested_at,scheduled_hold) VALUES (?,?,?,?,?,?,?,?,?,NOW(),?)')
            ->execute([$rowId, $doctorId, $user['id'], $sessionStart, $sessionEnd, $cardPilot ? 'PENDING_APPROVAL' : 'PENDING_PAYMENT', null, $sessionMode, (string) $user['id'], (int) $scheduledHold]);
        $pdo->prepare('INSERT INTO payments (id,appointment_id,amount,amount_usd,amount_gbp,status,ref_id) VALUES (?,?,?,?,?,?,?)')
            ->execute([$rowPayId, $rowId, $amount, $amountUsd, $amountGbp, 'PENDING', $cardPilot ? 'CARD_PILOT' : 'LATER']);
        if ($amount > 0 && !$cardPilot) {
            wallet_charge_visit(
                $pdo,
                (string) $user['id'],
                $rowId,
                $amount,
                'بدهی حق ویزیت تا تأیید پرداخت منشی'
            );
        }
        user_referral_stamp_payment($pdo, $rowPayId, (string) $user['id'], $amount);
    }
    $pdo->commit();

    $patientName = (string) ($user['name'] ?? 'مراجعه‌کننده');
    $when = format_fa_datetime($startsAt);
    if ($scheduledHold) {
        $when .= ' (شروع ' . to_fa_digits((string) $repeatCount) . ' جلسهٔ تکرارشونده)';
    }
    notify_role(
        $pdo,
        'SECRETARY',
        'درخواست وقت جدید',
        $cardPilot ? "مراجعه‌کننده «{$patientName}» برای {$when} درخواست وقت داد. در لیست رزرو درخواست را بررسی و شماره کارت را پیامک کنید."
            : "مراجعه‌کننده «{$patientName}» برای {$when} درخواست وقت داد. در لیست رزرو است؛ حتی بدون پرداخت آنلاین می‌توانید بررسی کنید و با «پرداخت شده» رزرو کنید.",
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
        'message' => $cardPilot ? 'درخواست ثبت شد. پس از تأیید منشی، شماره کارت پیامک می‌شود.' : ($scheduledHold ? 'رزروهای موقت ثبت شدند. هر جلسه پس از تأیید پرداخت همان جلسه قطعی می‌شود.' : (($amountUsd !== null || (function_exists('patient_wallet_paused') && patient_wallet_paused()))
            ? 'درخواست شما ارسال شد. تا یک ساعت این وقت برای شما می‌ماند و پس از ثبت منشی، نوبت رزرو می‌شود.'
            : 'درخواست شما ارسال شد. تا تأیید فیش توسط منشی، حق ویزیت از کیف پول کم می‌شود و موجودی می‌تواند منفی باشد. تا یک ساعت این وقت برای شما می‌ماند.')),
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(502);
    echo json_encode(['error' => 'ثبت نوبت الان ممکن نشد. دوباره تلاش کنید.'], JSON_UNESCAPED_UNICODE);
}
