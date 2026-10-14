<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/doctor_panel.php';
require_once __DIR__ . '/../includes/availability.php';

$ctx = require_doctor_profile($pdo);
ensure_availability_schema($pdo);
ensure_doctor_weekly_hours_schema($pdo);
$action = post('action');
$doctorId = (string) ($ctx['profile']['id'] ?? '');
$back = '/doctor/availability';

if ($action === 'save_week' || $action === 'save_weekday') {
    $weeks = (int) post('weeks');
    if ($weeks < 1) {
        $weeks = 12;
    }
    if ($weeks > 26) {
        $weeks = 26;
    }
    $labels = doctor_weekdays_sat_first();
    $draft = [];
    if ($action === 'save_weekday') {
        $weekday = (int) post('weekday');
        $draft[(string) $weekday] = [
            'from' => (int) post('hour_from'),
            'to' => (int) post('hour_to'),
            'clear' => 0,
        ];
    } else {
        $raw = trim((string) post('draft_json'));
        $parsed = json_decode($raw, true);
        if (!is_array($parsed) || $parsed === []) {
            flash_set('error', 'اول حداقل یک روز را تنظیم کنید، بعد ذخیره تغییرات را بزنید.');
            redirect($back);
        }
        $draft = $parsed;
    }

    $savedNames = [];
    $clearedNames = [];
    $errors = [];
    $appliedWeeks = 0;
    try {
        foreach ($draft as $key => $item) {
            if (!is_array($item)) {
                continue;
            }
            $weekday = (int) $key;
            if (!isset($labels[$weekday])) {
                continue;
            }
            if (!empty($item['clear'])) {
                doctor_availability_clear_weekday($pdo, $doctorId, $weekday);
                $clearedNames[] = $labels[$weekday];
                continue;
            }
            $from = (int) ($item['from'] ?? -1);
            $to = (int) ($item['to'] ?? -1);
            $allowed = appointment_presence_hours();
            if (!in_array($from, $allowed, true) || !in_array($to, $allowed, true)) {
                $errors[] = $labels[$weekday];
                continue;
            }
            $hours = appointment_hours_range($from, $to);
            if ($hours === []) {
                $errors[] = $labels[$weekday];
                continue;
            }
            $appliedWeeks = doctor_availability_apply_weekday($pdo, $doctorId, $weekday, $hours, $weeks);
            $savedNames[] = $labels[$weekday];
        }
    } catch (Throwable $e) {
        flash_set('error', 'ذخیره انجام نشد. دوباره تلاش کنید.');
        redirect($back);
    }
    if ($savedNames === [] && $clearedNames === []) {
        flash_set('error', $errors !== []
            ? 'بازه ساعت این روزها درست نبود: ' . implode('، ', $errors)
            : 'تغییری برای ذخیره نبود.');
        redirect($back);
    }
    $parts = [];
    if ($savedNames !== []) {
        $parts[] = 'روزهای «' . implode('، ', $savedNames) . '» برای ' . to_fa_digits((string) max(1, $appliedWeeks)) . ' هفته ذخیره شد';
    }
    if ($clearedNames !== []) {
        $parts[] = 'روزهای «' . implode('، ', $clearedNames) . '» پاک شد';
    }
    if ($errors !== []) {
        $parts[] = 'این روزها به‌خاطر بازه ساعت رد شد: ' . implode('، ', $errors);
    }
    flash_set('success', implode('. ', $parts) . '.');
    redirect($back);
}

if ($action === 'clear_weekday') {
    $weekday = (int) post('weekday');
    $labels = doctor_weekdays_sat_first();
    if (!isset($labels[$weekday])) {
        flash_set('error', 'روز هفته نامعتبر است.');
        redirect($back);
    }
    $n = doctor_availability_clear_weekday($pdo, $doctorId, $weekday);
    flash_set('success', 'ساعت‌های «' . $labels[$weekday] . '» پاک شد' . ($n > 0 ? ' (' . to_fa_digits((string) $n) . ' روز آینده)' : '') . '.');
    redirect($back);
}

flash_set('error', 'درخواست نامعتبر است.');
redirect($back);
