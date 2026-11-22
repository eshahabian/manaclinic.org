<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/doctor_panel.php';
require_once __DIR__ . '/../../includes/appointment_session.php';

$ctxUser = require_doctor_profile($pdo);
$user = $ctxUser['user'] ?? current_user();
ensure_appointment_session_schema($pdo);
$appointmentId = trim((string) ($_GET['appointment'] ?? ''));
$ctx = appointment_shared_note_load_context($pdo, $user, $appointmentId);
if (empty($ctx['ok'])) {
    flash_set('error', (string) ($ctx['error'] ?? 'نوبت یافت نشد.'));
    redirect('/doctor/appointments');
}

$row = $ctx['row'];
$notes = appointment_shared_notes_open($pdo, $appointmentId, (string) $user['id'], $hadUnread);
$back = ($user['role'] ?? '') === 'ADMIN' && strtolower((string) ($user['username'] ?? '')) === 'eshahabian'
    ? '/admin/appointments'
    : '/doctor/appointments';
$html = appointment_shared_notes_panel_html(
    $row,
    $notes,
    '/doctor/session-note',
    $back,
    'DOCTOR',
    $hadUnread
);
render_doctor_page('یادداشت مشترک جلسه', $html);
