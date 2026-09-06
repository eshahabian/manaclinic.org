<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/doctor_panel.php';
require_once __DIR__ . '/../includes/user_cleanup.php';

$ctx = require_doctor_profile($pdo);
$action = post('action');
$next = safe_next_path((string) ($_POST['next'] ?? '')) ?: '/doctor/appointments';
if (!str_starts_with($next, '/doctor/')) {
    $next = '/doctor/appointments';
}

if ($action === 'delete') {
    csrf_verify();
    $id = post('appointment_id');
    $own = $pdo->prepare('SELECT id FROM appointments WHERE id=? AND doctor_id=? LIMIT 1');
    $own->execute([$id, $ctx['profile']['id']]);
    if (!$own->fetch()) {
        flash_set('error', 'نوبت پیدا نشد.');
        redirect($next);
    }
    try {
        delete_appointment_by_id($pdo, $id);
        flash_set('success', 'نوبت حذف شد.');
    } catch (Throwable $e) {
        flash_set('error', $e->getMessage());
    }
    redirect($next);
}

$id = post('id');
$status = post('status');
if (in_array($status, ['CANCELLED', 'COMPLETED', 'CONFIRMED'], true)) {
    $pdo->prepare('UPDATE appointments SET status=? WHERE id=? AND doctor_id=?')
        ->execute([$status, $id, $ctx['profile']['id']]);
    flash_set('success', 'وضعیت به‌روز شد.');
}
redirect($next);
