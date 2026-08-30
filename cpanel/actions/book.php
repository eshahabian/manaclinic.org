<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

$user = current_user();
if (!$user || $user['role'] !== 'PATIENT') {
    http_response_code(401);
    echo json_encode(['error' => 'لطفاً با حساب بیمار وارد شوید.']);
    exit;
}

$doctorId = post('doctorId');
$date = post('date');
$time = post('time');
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

$valid = generate_slots($availability['start_time'], $availability['end_time'], (int)$availability['slot_minutes']);
if (!in_array($time, $valid, true)) {
    http_response_code(400);
    echo json_encode(['error' => 'ساعت نامعتبر است.']);
    exit;
}

$startsAt = $date . ' ' . $time . ':00';
$endsAt = date('Y-m-d H:i:s', strtotime($startsAt) + ((int)$availability['slot_minutes'] * 60));
if (strtotime($startsAt) <= time()) {
    http_response_code(400);
    echo json_encode(['error' => 'این زمان گذشته است.']);
    exit;
}

$conflict = $pdo->prepare("
  SELECT id FROM appointments
  WHERE doctor_id=? AND starts_at=? AND status IN ('PENDING_PAYMENT','CONFIRMED','COMPLETED')
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

$pdo->beginTransaction();
try {
    $pdo->prepare('INSERT INTO appointments (id,doctor_id,patient_id,starts_at,ends_at,status) VALUES (?,?,?,?,?,?)')
        ->execute([$appointmentId, $doctorId, $user['id'], $startsAt, $endsAt, 'PENDING_PAYMENT']);
    $pdo->prepare('INSERT INTO payments (id,appointment_id,amount,status) VALUES (?,?,?,?)')
        ->execute([$paymentId, $appointmentId, $amount, 'PENDING']);

    $callback = rtrim($config['app_url'], '/') . '/payments/verify';
    $pay = zarinpal_request($config, $amount, 'پرداخت نوبت مانا کلینیک - ' . $appointmentId, $callback, $user['email'] ?? null);

    $pdo->prepare('UPDATE payments SET authority=? WHERE id=?')->execute([$pay['authority'], $paymentId]);
    $pdo->commit();

    echo json_encode([
        'appointmentId' => $appointmentId,
        'paymentUrl' => $pay['paymentUrl'],
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    $pdo->rollBack();
    http_response_code(502);
    echo json_encode(['error' => $e->getMessage()]);
}
