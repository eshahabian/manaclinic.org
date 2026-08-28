<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/doctor_panel.php';
$ctx = require_doctor_profile($pdo);
$action = post('action');

if ($action === 'delete') {
    $id = post('id');
    $pdo->prepare('DELETE FROM availabilities WHERE id=? AND doctor_id=?')->execute([$id, $ctx['profile']['id']]);
    flash_set('success', 'حذف شد.');
    redirect('/doctor/availability');
}

$date = post('date');
$start = post('start_time');
$end = post('end_time');
$minutes = (int) post('slot_minutes', '50');
if ($date && $start && $end) {
    $exists = $pdo->prepare('SELECT id FROM availabilities WHERE doctor_id=? AND date=?');
    $exists->execute([$ctx['profile']['id'], $date]);
    $row = $exists->fetch();
    if ($row) {
        $pdo->prepare('UPDATE availabilities SET start_time=?, end_time=?, slot_minutes=? WHERE id=?')
            ->execute([$start, $end, $minutes, $row['id']]);
    } else {
        $pdo->prepare('INSERT INTO availabilities (id,doctor_id,date,start_time,end_time,slot_minutes) VALUES (?,?,?,?,?,?)')
            ->execute([cuid(), $ctx['profile']['id'], $date, $start, $end, $minutes]);
    }
    flash_set('success', 'ذخیره شد.');
}
redirect('/doctor/availability');
