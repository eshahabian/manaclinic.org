<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/doctor_panel.php';
require_once __DIR__ . '/../includes/doctor_clinical.php';

$ctx = require_doctor_profile($pdo);
$patientId = (string) ($_GET['id'] ?? '');
require_doctor_patient_access($pdo, $ctx, $patientId);

$doctorId = $ctx['profile']['id'];
$action = post('action', 'create');

if ($action === 'delete') {
    $id = post('id');
    $pdo->prepare('DELETE FROM doctor_highlights WHERE id=? AND doctor_id=? AND patient_id=?')
        ->execute([$id, $doctorId, $patientId]);
    flash_set('success', 'هایلایت حذف شد.');
    redirect('/doctor/patients/' . $patientId);
}

$excerpt = trim((string) ($_POST['excerpt'] ?? ''));
$remark = trim((string) ($_POST['remark'] ?? ''));
$color = post('color', 'yellow');
$allowed = ['yellow', 'green', 'pink', 'blue'];
if (!in_array($color, $allowed, true)) {
    $color = 'yellow';
}

if ($excerpt === '') {
    flash_set('error', 'متن هایلایت خالی است.');
    redirect('/doctor/patients/' . $patientId);
}

$pdo->prepare('INSERT INTO doctor_highlights (id, doctor_id, patient_id, excerpt, remark, color) VALUES (?,?,?,?,?,?)')
    ->execute([cuid(), $doctorId, $patientId, $excerpt, $remark !== '' ? $remark : null, $color]);

flash_set('success', 'هایلایت اضافه شد.');
redirect('/doctor/patients/' . $patientId);
