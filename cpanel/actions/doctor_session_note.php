<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/doctor_panel.php';
require_once __DIR__ . '/../includes/doctor_clinical.php';

$ctx = require_doctor_profile($pdo);
$patientId = (string) ($_GET['id'] ?? '');
require_doctor_patient_access($pdo, $ctx, $patientId);

$doctorId = $ctx['profile']['id'];
$appointmentId = post('appointment_id');
$noteText = (string) ($_POST['note_text'] ?? '');

if ($appointmentId === '') {
    flash_set('error', 'نوبت مشخص نیست.');
    redirect('/doctor/patients/' . $patientId . '#chart');
}

$app = $pdo->prepare('SELECT id FROM appointments WHERE id=? AND doctor_id=? AND patient_id=? LIMIT 1');
$app->execute([$appointmentId, $doctorId, $patientId]);
if (!$app->fetch()) {
    flash_set('error', 'این نوبت متعلق به پرونده شما نیست.');
    redirect('/doctor/patients/' . $patientId . '#chart');
}

$existing = $pdo->prepare('SELECT id FROM doctor_session_notes WHERE appointment_id=? AND doctor_id=? LIMIT 1');
$existing->execute([$appointmentId, $doctorId]);
$row = $existing->fetch();

if ($row) {
    $pdo->prepare('UPDATE doctor_session_notes SET note_text=? WHERE id=? AND doctor_id=?')
        ->execute([$noteText, $row['id'], $doctorId]);
} else {
    $pdo->prepare('INSERT INTO doctor_session_notes (id, doctor_id, patient_id, appointment_id, note_text) VALUES (?,?,?,?,?)')
        ->execute([cuid(), $doctorId, $patientId, $appointmentId, $noteText]);
}

flash_set('success', 'یادداشت جلسه ذخیره شد.');
redirect('/doctor/patients/' . $patientId . '#chart');
