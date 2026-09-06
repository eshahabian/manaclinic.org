<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/appointment_cancel.php';

$user = require_login(['SECRETARY']);
csrf_verify();

$action = post('action');
$next = safe_next_path(post('next')) ?? '/secretary/appointments';

if ($action === 'cancel_patient') {
    $appointmentId = post('appointment_id');
    $note = post('note');
    try {
        $result = secretary_mark_patient_cancelled($pdo, $appointmentId, $user, $note);
        if (!str_contains($next, 'tab=')) {
            $next .= (str_contains($next, '?') ? '&' : '?') . 'tab=done';
        }
        flash_set('success', 'کنسلی مراجع برای «' . (string) $result['patient_name'] . '» ثبت شد.');
    } catch (Throwable $e) {
        flash_set('error', $e->getMessage());
        if (!str_contains($next, 'tab=')) {
            $next .= (str_contains($next, '?') ? '&' : '?') . 'tab=upcoming';
        }
    }
    redirect($next);
}

flash_set('error', 'درخواست نامعتبر است.');
redirect('/secretary/appointments');
