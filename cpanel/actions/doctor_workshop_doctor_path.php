<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/doctor_panel.php';
require_once __DIR__ . '/../includes/workshops.php';
require_once __DIR__ . '/../includes/workshop_path.php';
require_once __DIR__ . '/../includes/workshop_media.php';
require_once __DIR__ . '/../includes/workshop_sessions.php';

$ctx = require_doctor_profile($pdo);
ensure_workshop_schema($pdo);
ensure_workshop_media_schema($pdo);
ensure_workshop_session_notes_schema($pdo);

$workshopId = post('workshop_id');
$sessionId = post('session_id');
$doctorId = (string) ($ctx['profile']['id'] ?? '');

$own = $pdo->prepare('SELECT * FROM workshops WHERE id=? AND doctor_id=? LIMIT 1');
$own->execute([$workshopId, $doctorId]);
$workshop = $own->fetch();
if (!is_array($workshop)) {
    flash_set('error', 'کارگاه یافت نشد.');
    redirect('/doctor/workshops');
}

$back = workshop_doctor_board_url($workshop, $sessionId);

$sessions = workshop_sessions_list($pdo, $workshopId);
$chosen = null;
$chosenIndex = 0;
foreach ($sessions as $i => $session) {
    if ((string) ($session['id'] ?? '') === $sessionId) {
        $chosen = $session;
        $chosenIndex = $i;
        break;
    }
}
if (!$chosen) {
    flash_set('error', 'جلسه یافت نشد.');
    redirect($back);
}

$deleteMediaId = trim((string) ($_POST['delete_media_id'] ?? ''));
if ($deleteMediaId !== '') {
    try {
        workshop_media_delete($pdo, $deleteMediaId, $doctorId);
        flash_set('success', 'فایل حذف شد.');
    } catch (RuntimeException $e) {
        flash_set('error', $e->getMessage());
    }
    redirect($back);
}

$sessionTitle = (string) ($chosen['title'] ?? '');
if ($sessionTitle === '') {
    $sessionTitle = workshop_session_title_for_date((string) ($chosen['session_date'] ?? ''), $chosenIndex);
}
$sessionDate = (string) ($chosen['session_date'] ?? '');
$sessionAt = $sessionDate !== '' ? ($sessionDate . ' 10:00:00') : null;
$noteHtml = (string) ($_POST['note_html'] ?? '');
$shareEnrollmentId = post('share_enrollment_id');
$patientMessage = trim(post('patient_message'));

try {
    workshop_session_note_save(
        $pdo,
        $workshopId,
        $doctorId,
        $sessionTitle,
        $noteHtml,
        $sessionAt,
        post('note_id') !== '' ? post('note_id') : null,
        $sessionId
    );

    $savedFiles = workshop_media_process_path_session_files(
        $pdo,
        $workshopId,
        $doctorId,
        $sessionId,
        $sessionTitle
    );

    if ($patientMessage !== '') {
        if ($shareEnrollmentId === '') {
            throw new RuntimeException('برای ارسال پیام به مراجع، یک نفر را انتخاب کنید.');
        }
        $targets = [];
        $enrollments = workshop_staff_enrollments_grouped($pdo, [$workshopId]);
        $rows = $enrollments[$workshopId] ?? [];
        if ($shareEnrollmentId === 'ALL') {
            foreach ($rows as $enr) {
                if (in_array((string) ($enr['status'] ?? ''), workshop_path_member_statuses(), true)) {
                    $targets[] = (string) ($enr['id'] ?? '');
                }
            }
        } else {
            foreach ($rows as $enr) {
                if ((string) ($enr['id'] ?? '') === $shareEnrollmentId
                    && in_array((string) ($enr['status'] ?? ''), workshop_path_member_statuses(), true)) {
                    $targets[] = $shareEnrollmentId;
                    break;
                }
            }
            if (!$targets) {
                throw new RuntimeException('مراجع انتخاب‌شده در این کارگاه نیست.');
            }
        }
        foreach ($targets as $enrollmentId) {
            if ($enrollmentId === '') {
                continue;
            }
            workshop_path_save_note(
                $pdo,
                $enrollmentId,
                $sessionId,
                'instructor',
                $patientMessage,
                doctor_ctx_user_id($ctx)
            );
        }
    }

    $bits = ['یادداشت جلسه ذخیره شد.'];
    if ($patientMessage !== '') {
        $bits[] = 'پیام خصوصی برای مراجع ارسال شد.';
    }
    if ($savedFiles > 0) {
        $bits[] = 'فایل جلسه بارگذاری شد.';
    }
    flash_set('success', implode(' ', $bits));
} catch (RuntimeException $e) {
    flash_set('error', $e->getMessage());
}

redirect($back);
