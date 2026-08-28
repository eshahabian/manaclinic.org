<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/doctor_panel.php';
$ctx = require_doctor_profile($pdo);
$id = post('id');
$status = post('status');
if (in_array($status, ['CANCELLED', 'COMPLETED', 'CONFIRMED'], true)) {
    $pdo->prepare('UPDATE appointments SET status=? WHERE id=? AND doctor_id=?')
        ->execute([$status, $id, $ctx['profile']['id']]);
    flash_set('success', 'وضعیت به‌روز شد.');
}
redirect('/doctor/appointments');
