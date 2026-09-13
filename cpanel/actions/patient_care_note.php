<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/doctor_clinical.php';

$user = require_login(['PATIENT']);
csrf_verify();

$action = post('action');
$body = trim((string) ($_POST['body'] ?? ''));
$noteId = trim((string) ($_POST['note_id'] ?? ''));
$patientId = (string) $user['id'];

if ($action === 'delete') {
    if ($noteId === '' || !patient_care_note_delete($pdo, $patientId, $noteId)) {
        flash_set('error', 'حذف یادداشت ممکن نشد.');
    } else {
        flash_set('success', 'یادداشت حذف شد.');
    }
    redirect('/dashboard/profile#my-notes');
}

if ($body === '') {
    flash_set('error', 'متن یادداشت را وارد کنید.');
    redirect('/dashboard/profile#my-notes');
}
if (function_exists('mb_strlen') ? mb_strlen($body) > 8000 : strlen($body) > 8000) {
    flash_set('error', 'یادداشت خیلی طولانی است.');
    redirect('/dashboard/profile#my-notes');
}

if (!patient_care_note_create($pdo, $patientId, $body)) {
    flash_set('error', 'ذخیره یادداشت ممکن نشد.');
    redirect('/dashboard/profile#my-notes');
}

flash_set('success', 'یادداشت شما ذخیره شد و درمانگر در پرونده می‌بیند.');
redirect('/dashboard/profile#my-notes');
