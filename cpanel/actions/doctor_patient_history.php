<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/doctor_panel.php';
require_once __DIR__ . '/../includes/doctor_clinical.php';

$ctx = require_doctor_profile($pdo);
$patientId = (string) ($_GET['id'] ?? '');
$access = require_doctor_patient_access($pdo, $ctx, $patientId);
$patient = $access['patient'];

$history = sanitize_clinical_html((string) ($_POST['history_text'] ?? ''));
$firstName = trim((string) ($_POST['first_name'] ?? ''));
$lastName = trim((string) ($_POST['last_name'] ?? ''));
$therapistName = trim((string) ($_POST['therapist_name'] ?? ''));
$birthDate = trim((string) ($_POST['birth_date'] ?? ''));
$marital = trim((string) ($_POST['marital_status'] ?? ''));
$chief = trim((string) ($_POST['chief_complaint'] ?? ''));
$residence = trim((string) ($_POST['residence'] ?? ''));
$familyHistory = trim((string) ($_POST['family_history'] ?? ''));
$soapSubject = trim((string) ($_POST['soap_subject'] ?? ''));
$soapObject = trim((string) ($_POST['soap_object'] ?? ''));
$soapAssessment = trim((string) ($_POST['soap_assessment'] ?? ''));
$soapPlan = trim((string) ($_POST['soap_plan'] ?? ''));

if ($firstName === '' && $lastName === '') {
    [$firstName, $lastName] = chart_split_patient_name((string) ($patient['name'] ?? ''));
}
if ($therapistName === '') {
    $therapistName = doctor_ctx_user_name($ctx);
}
if ($birthDate !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $birthDate)) {
    flash_set('error', 'تاریخ تولد نامعتبر است.');
    redirect('/doctor/patients/' . $patientId . '?tab=chart');
}
if ($birthDate === '') {
    $birthDate = null;
}
if (!in_array($marital, ['single', 'married', 'other', ''], true)) {
    $marital = '';
}
if ($marital === '') {
    $marital = null;
}

$doctorId = $ctx['profile']['id'];
$chart = get_or_create_patient_chart($pdo, $doctorId, $patientId);

$pdo->prepare("
  UPDATE doctor_patient_charts
  SET history_text=?, first_name=?, last_name=?, therapist_name=?, birth_date=?,
      marital_status=?, chief_complaint=?, residence=?, family_history=?,
      soap_subject=?, soap_object=?, soap_assessment=?, soap_plan=?
  WHERE id=? AND doctor_id=?
")->execute([
    $history,
    $firstName !== '' ? $firstName : null,
    $lastName !== '' ? $lastName : null,
    $therapistName !== '' ? $therapistName : null,
    $birthDate,
    $marital,
    $chief !== '' ? $chief : null,
    $residence !== '' ? $residence : null,
    $familyHistory !== '' ? $familyHistory : null,
    $soapSubject !== '' ? $soapSubject : null,
    $soapObject !== '' ? $soapObject : null,
    $soapAssessment !== '' ? $soapAssessment : null,
    $soapPlan !== '' ? $soapPlan : null,
    $chart['id'],
    $doctorId,
]);

flash_set('success', 'اطلاعات پایه و شرح حال ذخیره شد.');
redirect('/doctor/patients/' . $patientId . '?tab=chart');
