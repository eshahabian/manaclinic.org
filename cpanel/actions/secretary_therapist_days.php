<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/secretary_patient.php';
require_once __DIR__ . '/../includes/availability.php';
require_once __DIR__ . '/../includes/therapist_presence.php';

$user = require_login(['SECRETARY']);
csrf_verify();

$doctorId = trim((string) post('doctor_id'));
$action = (string) post('action');
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

ensure_availability_schema($pdo);
ensure_doctor_weekly_hours_schema($pdo);
ensure_therapist_presence_schema($pdo);

if ($action === 'save_weekday') {
    $weekday = (int) post('weekday');
    $fromHour = (int) post('hour_from');
    $toHour = (int) post('hour_to');
    $weeks = (int) post('weeks');
    if ($weeks < 1) {
        $weeks = 12;
    }
    if ($weeks > 26) {
        $weeks = 26;
    }
    $labels = doctor_weekdays_sat_first();
    if (!isset($labels[$weekday])) {
        flash_set('error', 'روز هفته نامعتبر است.');
        redirect($back);
    }
    $hours = appointment_hours_range($fromHour, $toHour);
    if ($hours === []) {
        flash_set('error', 'بازه ساعت را درست انتخاب کنید (از ساعت باید قبل از تا ساعت باشد).');
        redirect($back . '#weekday-' . $weekday);
    }
    try {
        $n = doctor_availability_apply_weekday($pdo, $doctorId, $weekday, $hours, $weeks);
        $pdo->prepare('DELETE FROM therapist_presence WHERE doctor_id=? AND day_date>=?')->execute([$doctorId, date('Y-m-d')]);
    } catch (Throwable $e) {
        flash_set('error', 'ذخیره انجام نشد. دوباره تلاش کنید.');
        redirect($back . '#weekday-' . $weekday);
    }
    $slotList = implode('، ', array_map(
        static fn (int $h): string => to_fa_digits((string) $h) . ':۰۰',
        $hours
    ));
    flash_set(
        'success',
        'حضور «' . $labels[$weekday] . '» ذخیره شد: ' . to_fa_digits((string) count($hours))
        . ' اسلات یک‌ساعته (' . $slotList . ') برای ' . to_fa_digits((string) $n) . ' هفته آینده.'
    );
    redirect($back . '#weekday-' . $weekday);
}

if ($action === 'clear_weekday') {
    $weekday = (int) post('weekday');
    $labels = doctor_weekdays_sat_first();
    if (!isset($labels[$weekday])) {
        flash_set('error', 'روز هفته نامعتبر است.');
        redirect($back);
    }
    $n = doctor_availability_clear_weekday($pdo, $doctorId, $weekday);
    $pdo->prepare('DELETE FROM therapist_presence WHERE doctor_id=? AND day_date>=?')->execute([$doctorId, date('Y-m-d')]);
    flash_set('success', 'ساعت‌های «' . $labels[$weekday] . '» پاک شد' . ($n > 0 ? ' (' . to_fa_digits((string) $n) . ' روز آینده)' : '') . '.');
    redirect($back);
}

flash_set('error', 'درخواست نامعتبر است.');
redirect($back);
