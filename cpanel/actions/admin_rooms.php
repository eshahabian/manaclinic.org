<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/admin_panel.php';
require_once __DIR__ . '/../includes/clinic_rooms.php';

$user = clinic_rooms_require_user();
csrf_verify();

$action = post('action');
$day = clinic_rooms_parse_day(post('day'));

try {
    if ($action === 'release') {
        $day = clinic_rooms_release($pdo, post('booking_id'));
        flash_set('success', 'ساعت اتاق آزاد شد.');
        redirect('/admin/rooms?day=' . rawurlencode($day));
    }
    if ($action === 'assign') {
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
            post('note')
        );
        flash_set('success', clinic_room_label((int) $saved['room']) . ' برای این جلسه رزرو شد.');
        redirect('/admin/rooms?day=' . rawurlencode((string) $saved['day']) . '#room-' . (int) $saved['room']);
    }
    flash_set('error', 'درخواست نامعتبر است.');
} catch (Throwable $e) {
    flash_set('error', $e->getMessage());
}

redirect('/admin/rooms?day=' . rawurlencode($day));
