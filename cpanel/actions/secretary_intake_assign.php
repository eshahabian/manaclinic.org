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
    assistant_assign_to_doctor($pdo, $session, $doctorId, $note !== '' ? $note : null);
    flash_set('success', 'شرح‌حال به درمانگر ارجاع و در پرونده ثبت شد.');
} catch (Throwable $e) {
    flash_set('error', $e->getMessage());
}
redirect('/secretary/intakes/' . $id);
