<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/session_complaints.php';

$user = require_login(['SECRETARY']);
csrf_verify();

try {
    session_complaint_create(
        $pdo,
        (string) ($user['id'] ?? ''),
        trim((string) post('appointment_id')),
        (string) post('body')
    );
} catch (RuntimeException $e) {
    flash_set('error', $e->getMessage());
    redirect('/secretary/complaints');
} catch (Throwable $e) {
    flash_set('error', 'ثبت شکایت انجام نشد.');
    redirect('/secretary/complaints');
}

flash_set('success', 'شکایت ثبت شد.');
redirect('/secretary/complaints');
