<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/doctor_panel.php';
require_once __DIR__ . '/../../includes/appointment_session.php';

$user = require_login(['DOCTOR']);
ensure_appointment_session_schema($pdo);
$appointmentId = trim((string) ($_GET['appointment'] ?? ''));
$ctx = appointment_shared_note_load_context($pdo, $user, $appointmentId);
if (empty($ctx['ok']) || empty($ctx['row']['_viewer_is_client'])) {
    flash_set('error', (string) ($ctx['error'] ?? 'نوبت یافت نشد.'));
    redirect('/doctor/therapy');
}

$row = $ctx['row'];
$notes = appointment_shared_notes_list($pdo, $appointmentId);
$html = appointment_shared_notes_panel_html(
    $row,
    $notes,
    '/doctor/therapy-note',
    '/doctor/therapy',
    'PATIENT'
);
render_doctor_page('یادداشت مشترک جلسه', $html);
