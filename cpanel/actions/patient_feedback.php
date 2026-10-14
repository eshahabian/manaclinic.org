<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/session_complaints.php';

$user = require_login(['PATIENT']);
csrf_verify();

if (post('action') === 'follow') {
    try {
        session_feedback_save_follow($pdo, $user, trim((string) post('id')), (string) post('follow_result'));
        flash_set('success', 'پیگیری پیشنهاد ثبت شد.');
    } catch (RuntimeException $e) {
        flash_set('error', $e->getMessage());
    } catch (Throwable $e) {
        flash_set('error', 'ثبت پیگیری انجام نشد.');
    }
    redirect('/dashboard/feedback');
}

try {
    session_complaint_create(
        $pdo,
        (string) ($user['id'] ?? ''),
        (string) ($user['id'] ?? ''),
        trim((string) post('kind')),
        trim(trim((string) post('session_date')) . ' ' . trim((string) post('session_time'))),
        (string) post('reason'),
        (string) post('body')
    );
} catch (RuntimeException $e) {
    flash_set('error', $e->getMessage());
    redirect('/dashboard/feedback');
} catch (Throwable $e) {
    flash_set('error', 'ثبت انجام نشد.');
    redirect('/dashboard/feedback');
}

$kind = session_feedback_kind(trim((string) post('kind')));
flash_set('success', ($kind === 'suggestion' ? 'پیشنهاد' : 'انتقاد') . ' ثبت شد.');
redirect('/dashboard/feedback');
