<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/assistant.php';

$user = require_login(['SECRETARY']);
ensure_assistant_schema($pdo);

$id = trim((string) ($_GET['id'] ?? ''));
$doctorId = trim(post('doctor_id'));
$note = trim(post('note'));

$session = $id !== '' ? assistant_session_get($pdo, $id) : null;
if (!$session) {
    flash_set('error', 'گفتگو یافت نشد.');
    redirect('/secretary/intakes');
}

try {
    $signedNote = $note;
    $signLine = 'امضا: ' . staff_actor_label($user);
    $signedNote = $signedNote !== '' ? ($signedNote . "\n" . $signLine) : $signLine;
    assistant_assign_to_doctor($pdo, $session, $doctorId, $signedNote);
    try {
        $pdo->prepare('UPDATE assistant_sessions SET assigned_by_user_id=? WHERE id=?')
            ->execute([$user['id'], $id]);
    } catch (Throwable $ignored) {
    }
    staff_log_action($pdo, (string) $user['id'], 'intake_assign', 'assistant_session', $id);
    flash_set('success', 'شرح‌حال به درمانگر ارجاع و در پرونده ثبت شد.');
} catch (Throwable $e) {
    flash_set('error', $e->getMessage());
}
redirect('/secretary/intakes/' . $id);
