<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/secretary_patient.php';
require_once __DIR__ . '/../includes/availability.php';
require_once __DIR__ . '/../includes/therapist_presence.php';

$user = require_login(['SECRETARY']);
csrf_verify();

$doctorId = trim((string) post('doctor_id'));
$back = '/secretary/therapist-days' . ($doctorId !== '' ? '?doctor=' . rawurlencode($doctorId) : '');
$allowed = false;
foreach (secretary_active_doctors($pdo) as $doctor) {
    if ((string) ($doctor['id'] ?? '') === $doctorId) {
        $allowed = true;
        break;
    }
}
if (!$allowed) {
    flash_set('error', 'درمانگر را انتخاب کنید.');
    redirect('/secretary/therapist-days');
}

$today = date('Y-m-d');
$end = appointment_booking_horizon_end($today);
$days = $_POST['days'] ?? [];
$present = $_POST['present'] ?? [];
$fromHours = $_POST['hour_from'] ?? [];
$toHours = $_POST['hour_to'] ?? [];
if (!is_array($days) || !is_array($present) || !is_array($fromHours) || !is_array($toHours)) {
    flash_set('error', 'اطلاعات روزها ناقص است.');
    redirect($back);
}

$saved = 0;
foreach ($days as $date) {
    $date = substr((string) $date, 0, 10);
    if (!appointment_date_within_horizon($date)) {
        continue;
    }
    $on = !empty($present[$date]);
    $from = (int) ($fromHours[$date] ?? 9);
    $to = (int) ($toHours[$date] ?? 17);
    if ($on && appointment_hours_range($from, $to) === []) {
        flash_set('error', 'بازه ساعت ' . to_jalali_label($date) . ' درست نیست.');
        redirect($back);
    }
    therapist_presence_save_day($pdo, $doctorId, $date, $on, $from, $to, (string) ($user['id'] ?? ''));
    $saved++;
}

flash_set('success', 'روزهای حضور ذخیره شد (' . to_fa_digits((string) $saved) . ' روز).');
redirect($back);
