<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/admin_panel.php';
require_once __DIR__ . '/../includes/clinic_rooms.php';

$user = clinic_rooms_require_user();
csrf_verify();

$action = post('action');
$day = clinic_rooms_parse_day(post('day'));

try {
    if ($action === 'release' || $action === 'release_series') {
        $day = $action === 'release_series'
            ? clinic_rooms_release_series($pdo, post('booking_id'))
            : clinic_rooms_release($pdo, post('booking_id'));
        flash_set('success', $action === 'release_series' ? 'این رزرو و هفته‌های بعدیِ تکرار آزاد شد.' : 'ساعت اتاق آزاد شد.');
        redirect('/admin/rooms?day=' . rawurlencode($day));
    }
    if ($action === 'update') {
        $saved = clinic_rooms_update(
            $pdo,
            post('booking_id'),
            (int) post('room_no'),
            post('purpose'),
            post('start_time'),
            post('end_time'),
            post('patient_id'),
            post('doctor_id'),
            post('workshop_session_id'),
            post('block_title'),
            post('note'),
            post('apply_series') === '1'
        );
        $count = (int) ($saved['count'] ?? 1);
        $msg = $count > 1
            ? 'ویرایش روی ' . to_fa_digits((string) $count) . ' هفتهٔ این تکرار ذخیره شد.'
            : 'رزرو ویرایش شد.';
        flash_set('success', $msg);
        redirect('/admin/rooms?day=' . rawurlencode((string) $saved['day']) . '#room-' . (int) $saved['room']);
    }
    if ($action === 'assign') {
        $repeatOn = post('repeat_weekly') === '1';
        $repeatWeeks = $repeatOn ? (int) post('repeat_weeks') : 1;
        if ($repeatOn && $repeatWeeks < 2) {
            throw new RuntimeException('برای تکرار هفتگی حداقل ۲ هفته بنویسید.');
        }
        $saved = clinic_rooms_assign(
            $pdo,
            $user,
            (int) post('room_no'),
            $day,
            post('purpose'),
            post('start_time'),
            post('end_time'),
            post('patient_id'),
            post('doctor_id'),
            post('workshop_session_id'),
            post('block_title'),
            post('note'),
            $repeatWeeks
        );
        $weeks = (int) ($saved['weeks'] ?? 1);
        $msg = clinic_room_label((int) $saved['room']) . ' برای این جلسه رزرو شد.';
        if ($weeks > 1) {
            $msg = clinic_room_label((int) $saved['room']) . ' برای ' . to_fa_digits((string) $weeks) . ' هفتهٔ پشت‌سرهم رزرو شد.';
        }
        flash_set('success', $msg);
        redirect('/admin/rooms?day=' . rawurlencode((string) $saved['day']) . '#room-' . (int) $saved['room']);
    }
    flash_set('error', 'درخواست نامعتبر است.');
} catch (Throwable $e) {
    flash_set('error', $e->getMessage());
}

redirect('/admin/rooms?day=' . rawurlencode($day));
