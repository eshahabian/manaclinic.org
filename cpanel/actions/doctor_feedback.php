<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/session_complaints.php';

$user = require_login(['DOCTOR']);
csrf_verify();
if (!session_feedback_can_review($user)) {
    flash_set('error', 'پیگیری پیشنهاد برای دکتر شیوا گرانمایه‌پور و دکتر عطیه گارسچی است.');
    redirect('/doctor/appointments');
}

if (post('action') !== 'follow') {
    if (session_feedback_can_submit_as_doctor($user)) {
        session_feedback_submit_as_doctor($pdo, $user, '/doctor/feedback');
    }
    flash_set('error', 'ثبت بازخورد با این حساب مجاز نیست.');
    redirect('/doctor/feedback');
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
