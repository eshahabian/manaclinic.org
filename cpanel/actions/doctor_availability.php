<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/doctor_panel.php';
require_once __DIR__ . '/../includes/availability.php';

$ctx = require_doctor_profile($pdo);
ensure_availability_schema($pdo);
$action = post('action');
$doctorId = (string) ($ctx['profile']['id'] ?? '');

if ($action === 'delete') {
    $id = post('id');
    $pdo->prepare('DELETE FROM availabilities WHERE id=? AND doctor_id=?')->execute([$id, $doctorId]);
    flash_set('success', 'حذف شد.');
    $backMonth = trim((string) ($_POST['month'] ?? $_GET['month'] ?? ''));
    redirect('/doctor/availability' . ($backMonth !== '' ? ('?month=' . rawurlencode($backMonth)) : ''));
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
