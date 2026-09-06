<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/doctor_panel.php';
require_once __DIR__ . '/../includes/workshops.php';
require_once __DIR__ . '/../includes/workshop_sessions.php';

$ctx = require_doctor_profile($pdo);
ensure_workshop_schema($pdo);
ensure_workshop_session_notes_schema($pdo);

$action = post('action');
$workshopId = post('workshop_id');
$back = '/doctor/workshops?edit=' . urlencode($workshopId) . '#session-notes';

if ($workshopId === '') {
    flash_set('error', 'کارگاه یافت نشد.');
    redirect('/doctor/workshops');
}

if ($action === 'save') {
    try {
        $sessionId = trim(post('session_id'));
        $noteText = trim(post('note_text'));
        $sessionTime = trim(post('session_time')) ?: '10:00';
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
        if (!$chosen || (string) ($chosen['session_date'] ?? '') === '') {
            throw new RuntimeException('یکی از روزهای برگزاری کارگاه را انتخاب کنید.');
        }
        $sessionTitle = workshop_session_title_for_date((string) $chosen['session_date'], $chosenIndex);
        $sessionAt = workshop_datetime_from_post((string) $chosen['session_date'], $sessionTime);
        $noteId = trim(post('note_id')) ?: null;
        workshop_session_note_save(
            $pdo,
            $workshopId,
            (string) $ctx['profile']['id'],
            $sessionTitle,
            $noteText,
            $sessionAt,
            $noteId
        );
        flash_set('success', 'یادداشت جلسه ذخیره شد.');
    } catch (RuntimeException $e) {
        flash_set('error', $e->getMessage());
    }
    redirect($back);
}

if ($action === 'delete') {
    try {
        workshop_session_note_delete($pdo, post('note_id'), (string) $ctx['profile']['id']);
        flash_set('success', 'یادداشت حذف شد.');
    } catch (RuntimeException $e) {
        flash_set('error', $e->getMessage());
    }
    redirect($back);
}

flash_set('error', 'درخواست نامعتبر است.');
redirect($back);
