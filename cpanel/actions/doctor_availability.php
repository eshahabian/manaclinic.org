<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/doctor_panel.php';
require_once __DIR__ . '/../includes/availability.php';

$ctx = require_doctor_profile($pdo);
ensure_availability_schema($pdo);
$action = post('action');

if ($action === 'delete') {
    $id = post('id');
    $pdo->prepare('DELETE FROM availabilities WHERE id=? AND doctor_id=?')->execute([$id, $ctx['profile']['id']]);
    flash_set('success', 'حذف شد.');
    redirect('/doctor/availability');
}

$date = post('date');
$hours = appointment_normalize_posted_hours($_POST['hours'] ?? []);
if ($date && $hours) {
    $start = appointment_hour_to_time(appointment_booking_hours()[0]);
    $end = appointment_hour_to_time(appointment_booking_hours()[count(appointment_booking_hours()) - 1] + 1);
    $minutes = appointment_slot_minutes();
    $encoded = appointment_hours_encode($hours);

    $exists = $pdo->prepare('SELECT id FROM availabilities WHERE doctor_id=? AND date=?');
    $exists->execute([$ctx['profile']['id'], $date]);
    $row = $exists->fetch();
    if ($row) {
        $pdo->prepare('
          UPDATE availabilities
          SET start_time=?, end_time=?, slot_minutes=?, available_hours=?
          WHERE id=?
        ')->execute([$start, $end, $minutes, $encoded, $row['id']]);
    } else {
        $pdo->prepare('
          INSERT INTO availabilities (id,doctor_id,date,start_time,end_time,slot_minutes,available_hours)
          VALUES (?,?,?,?,?,?,?)
        ')->execute([
            cuid(),
            $ctx['profile']['id'],
            $date,
            $start,
            $end,
            $minutes,
            $encoded,
        ]);
    }
    flash_set('success', 'ذخیره شد.');
} elseif ($date) {
    flash_set('error', 'حداقل یک ساعت خالی انتخاب کنید.');
}

redirect('/doctor/availability');
