<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/session_complaints.php';

$user = require_login(['DOCTOR']);
csrf_verify();
if (!session_feedback_can_review($user)) {
    flash_set('error', 'پیگیری پیشنهاد برای دکتر شیوا گرانمایه‌پور و دکتر عطیه گارسچی است.');
    redirect('/doctor/appointments');
}

try {
    session_feedback_save_follow($pdo, $user, trim((string) post('id')), (string) post('follow_result'));
    flash_set('success', 'پیگیری پیشنهاد ثبت شد.');
} catch (RuntimeException $e) {
    flash_set('error', $e->getMessage());
} catch (Throwable $e) {
    flash_set('error', 'ثبت پیگیری انجام نشد.');
}
redirect('/doctor/feedback');
