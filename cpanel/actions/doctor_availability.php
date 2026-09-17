<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/doctor_panel.php';
require_once __DIR__ . '/../includes/availability.php';

$ctx = require_doctor_profile($pdo);
ensure_availability_schema($pdo);
ensure_doctor_weekly_hours_schema($pdo);
$action = post('action');
$doctorId = (string) ($ctx['profile']['id'] ?? '');

if ($action === 'delete') {
    $id = post('id');
    $pdo->prepare('DELETE FROM availabilities WHERE id=? AND doctor_id=?')->execute([$id, $doctorId]);
    flash_set('success', 'حذف شد.');
    $backMonth = trim((string) ($_POST['month'] ?? $_GET['month'] ?? ''));
    redirect('/doctor/availability' . ($backMonth !== '' ? ('?month=' . rawurlencode($backMonth)) : ''));
}

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
        redirect('/doctor/availability');
    }
    $hours = appointment_hours_range($fromHour, $toHour);
    if ($hours === []) {
        flash_set('error', 'بازه ساعت را درست انتخاب کنید (از ساعت باید قبل از تا ساعت باشد).');
        redirect('/doctor/availability#weekday-' . $weekday);
    }
    try {
        $n = doctor_availability_apply_weekday($pdo, $doctorId, $weekday, $hours, $weeks);
    } catch (Throwable $e) {
        flash_set('error', 'ذخیره انجام نشد. دوباره تلاش کنید.');
        redirect('/doctor/availability#weekday-' . $weekday);
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
    redirect('/doctor/availability#weekday-' . $weekday);
}

if ($action === 'clear_weekday') {
    $weekday = (int) post('weekday');
    $labels = doctor_weekdays_sat_first();
    if (!isset($labels[$weekday])) {
        flash_set('error', 'روز هفته نامعتبر است.');
        redirect('/doctor/availability');
    }
    $n = doctor_availability_clear_weekday($pdo, $doctorId, $weekday);
    flash_set('success', 'ساعت‌های «' . $labels[$weekday] . '» پاک شد' . ($n > 0 ? ' (' . to_fa_digits((string) $n) . ' روز آینده)' : '') . '.');
    redirect('/doctor/availability');
}

if ($action === 'save_month') {
    $parts = jalali_month_key_parts(post('month_key'));
    $hours = appointment_normalize_posted_hours($_POST['hours'] ?? []);
    if (!$parts) {
        flash_set('error', 'ماه را انتخاب کنید.');
        redirect('/doctor/availability');
    }
    if (!$hours) {
        flash_set('error', 'حداقل یک ساعت خالی انتخاب کنید.');
        redirect('/doctor/availability?month=' . rawurlencode($parts['id']));
    }
    $n = 0;
    try {
        $n = doctor_availability_apply_month($pdo, $doctorId, $parts['year'], $parts['month'], $hours);
    } catch (Throwable $e) {
        flash_set('error', 'ذخیرهٔ ماه انجام نشد. دوباره تلاش کنید.');
        redirect('/doctor/availability?month=' . rawurlencode($parts['id']));
    }
    $names = jalali_month_names();
    $short = (string) ($names[$parts['month']] ?? 'ماه');
    if ($n < 1) {
        flash_set('error', 'روز باقی‌مانده‌ای در «' . $short . '» نیست.');
    } else {
        flash_set('success', 'ساعت‌ها برای ' . to_fa_digits((string) $n) . ' روز باقی‌مانده «' . $short . '» ذخیره شد.');
    }
    redirect('/doctor/availability?month=' . rawurlencode($parts['id']));
}

$date = post('date');
$hours = appointment_normalize_posted_hours($_POST['hours'] ?? []);
$monthRedirect = '';
if ($date) {
    $meta = jalali_month_meta_from_datetime($date . ' 12:00:00');
    if ($meta) {
        $monthRedirect = '?month=' . rawurlencode((string) $meta['id']);
    }
}
if ($date && $hours) {
    doctor_availability_upsert($pdo, $doctorId, $date, $hours);
    flash_set('success', 'ذخیره شد.');
} elseif ($date) {
    flash_set('error', 'حداقل یک ساعت خالی انتخاب کنید.');
}

redirect('/doctor/availability' . $monthRedirect);
