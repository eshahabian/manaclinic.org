<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/session_complaints.php';

$user = require_login(['ADMIN']);
csrf_verify();

if (post('action') !== 'follow') {
    redirect('/admin/feedback');
}

try {
    session_feedback_save_follow($pdo, $user, trim((string) post('id')), (string) post('follow_result'));
    flash_set('success', 'پیگیری ثبت شد.');
} catch (RuntimeException $e) {
    flash_set('error', $e->getMessage());
} catch (Throwable $e) {
    flash_set('error', 'ثبت پیگیری انجام نشد.');
}

redirect('/admin/feedback');
