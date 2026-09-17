<?php
declare(strict_types=1);

$user = require_login(['PATIENT']);
require_once __DIR__ . '/../../includes/patient_panel.php';
require_once __DIR__ . '/../../includes/appointment_session.php';

ensure_appointment_session_schema($pdo);
$appointmentId = trim((string) ($_GET['appointment'] ?? ''));
$ctx = appointment_shared_note_load_context($pdo, $user, $appointmentId);
if (empty($ctx['ok'])) {
    flash_set('error', (string) ($ctx['error'] ?? 'نوبت یافت نشد.'));
    redirect('/dashboard/appointments');
}

$row = $ctx['row'];
$notes = appointment_shared_notes_list($pdo, $appointmentId);
$html = appointment_shared_notes_panel_html(
    $row,
    $notes,
    '/dashboard/session-note',
    '/dashboard/appointments',
    'PATIENT'
);
render_patient_page('یادداشت مشترک جلسه', $html);
