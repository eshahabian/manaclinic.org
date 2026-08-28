<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/doctor_panel.php';
require_once __DIR__ . '/../includes/doctor_clinical.php';

$ctx = require_doctor_profile($pdo);
$patientId = (string) ($_GET['id'] ?? '');
require_doctor_patient_access($pdo, $ctx, $patientId);

$history = sanitize_clinical_html((string) ($_POST['history_text'] ?? ''));
$doctorId = $ctx['profile']['id'];
$chart = get_or_create_patient_chart($pdo, $doctorId, $patientId);

$pdo->prepare('UPDATE doctor_patient_charts SET history_text=? WHERE id=? AND doctor_id=?')
    ->execute([$history, $chart['id'], $doctorId]);

flash_set('success', 'شرح حال ذخیره شد.');
redirect('/doctor/patients/' . $patientId);
