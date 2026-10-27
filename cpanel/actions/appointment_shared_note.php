<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/appointment_session.php';

$user = current_user();
if (!$user || !in_array(($user['role'] ?? ''), ['PATIENT', 'DOCTOR', 'ADMIN'], true)) {
    flash_set('error', 'برای ثبت یادداشت وارد شوید.');
    redirect('/login');
}

csrf_verify();
ensure_appointment_session_schema($pdo);

$appointmentId = trim((string) post('appointment_id'));
$body = (string) post('body');
$next = trim((string) post('next'));
$role = (string) ($user['role'] ?? '');

$ctx = appointment_shared_note_load_context($pdo, $user, $appointmentId);
$isClientViewer = !empty($ctx['row']['_viewer_is_client']);
if ($isClientViewer) {
    $defaultBack = $role === 'DOCTOR'
        ? '/doctor/therapy-note?appointment=' . rawurlencode($appointmentId)
        : '/dashboard/session-note?appointment=' . rawurlencode($appointmentId);
    $fallbackList = $role === 'DOCTOR' ? '/doctor/therapy' : '/dashboard/appointments';
} else {
    $defaultBack = $role === 'PATIENT'
        ? '/dashboard/session-note?appointment=' . rawurlencode($appointmentId)
        : '/doctor/session-note?appointment=' . rawurlencode($appointmentId);
    $fallbackList = $role === 'PATIENT' ? '/dashboard/appointments' : '/doctor/appointments';
}

if ($next === '' || !str_starts_with($next, '/')) {
    $next = $defaultBack;
}

if (empty($ctx['ok'])) {
    flash_set('error', (string) ($ctx['error'] ?? 'دسترسی ندارید.'));
    redirect($fallbackList);
}

if (!appointment_shared_note_add($pdo, $user, $appointmentId, $body)) {
    flash_set('error', 'متن یادداشت خالی است یا ذخیره نشد.');
    redirect($defaultBack);
}

flash_set('success', 'یادداشت مشترک ذخیره شد.');
redirect($defaultBack);
