<?php
declare(strict_types=1);

$user = require_login(['PATIENT']);
require_once __DIR__ . '/../includes/patient_journal.php';
csrf_verify();

$patientId = (string) $user['id'];
ensure_patient_journal_schema($pdo);

$today = date('Y-m-d');
$entryDate = trim((string) ($_POST['entry_date'] ?? $today));
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $entryDate) || $entryDate > $today) {
    flash_set('error', 'تاریخ معتبر نیست.');
    redirect('/dashboard/journal');
}

$moodRaw = trim((string) ($_POST['mood'] ?? ''));
$mood = $moodRaw !== '' ? (int) $moodRaw : null;
if ($mood !== null && ($mood < 1 || $mood > 5)) {
    flash_set('error', 'خلق انتخاب‌شده معتبر نیست.');
    redirect('/dashboard/journal?day=' . rawurlencode($entryDate));
}

$body = trim((string) ($_POST['body'] ?? ''));
$removePhoto = !empty($_POST['remove_photo']);

try {
    $photoPath = null;
    $keepPhoto = true;
    if (!empty($_FILES['photo']) && (int) ($_FILES['photo']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
        $photoPath = patient_journal_save_photo($patientId, $_FILES['photo']);
        $keepPhoto = false;
    } elseif ($removePhoto) {
        $photoPath = null;
        $keepPhoto = false;
    }

    if ($mood === null && $body === '' && $photoPath === null && $keepPhoto) {
        $existing = patient_journal_for_date($pdo, $patientId, $entryDate);
        if (!$existing) {
            flash_set('error', 'حداقل خلق، متن یا عکس را وارد کنید.');
            redirect('/dashboard/journal?day=' . rawurlencode($entryDate));
        }
    }

    patient_journal_upsert($pdo, $patientId, $entryDate, $mood, $body !== '' ? $body : null, $photoPath, $keepPhoto);
    flash_set('success', 'یادداشت ذخیره شد.');
} catch (Throwable $e) {
    flash_set('error', $e->getMessage() !== '' ? $e->getMessage() : 'ذخیره یادداشت ناموفق بود.');
}

redirect('/dashboard/journal?day=' . rawurlencode($entryDate));
