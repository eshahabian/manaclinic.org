<?php
declare(strict_types=1);
if (!workshop_can_add_member(current_user())) {
    http_response_code(403);
    exit('دسترسی ندارید.');
}
require_once __DIR__ . '/../includes/workshop_presenters.php';
try {
    workshop_presenters_save($pdo, post('id'), (array) ($_POST['presenters'] ?? []));
    $notify = ['sent' => 0, 'failed' => 0, 'skipped' => 0, 'errors' => []];
    try {
        $notify = workshop_presenters_notify($pdo, post('id'));
    } catch (Throwable $dispatchError) {
        error_log('Presenter SMS notify: ' . $dispatchError->getMessage());
        $notify['errors'][] = $dispatchError->getMessage();
    }
    staff_log_action($pdo, (string) current_user()['id'], 'workshop_presenters', 'workshop', post('id'), 'updated');
    $parts = [];
    if ((int) $notify['sent'] > 0) {
        $parts[] = to_fa_digits((string) $notify['sent']) . ' پیامک ارسال شد';
    }
    if ((int) $notify['skipped'] > 0) {
        $parts[] = to_fa_digits((string) $notify['skipped']) . ' قبلاً ارسال شده بود';
    }
    if ((int) $notify['failed'] > 0) {
        $parts[] = to_fa_digits((string) $notify['failed']) . ' ناموفق';
    }
    $msg = $parts !== [] ? ('اجراکنندگان ذخیره شد. ' . implode('؛ ', $parts) . '.') : 'اجراکنندگان ذخیره شد.';
    if ($notify['errors'] !== []) {
        $msg .= ' ' . implode(' ', array_slice($notify['errors'], 0, 3));
        flash_set((int) $notify['sent'] > 0 ? 'success' : 'error', $msg);
    } else {
        flash_set('success', $msg);
    }
} catch (RuntimeException $e) {
    flash_set('error', $e->getMessage());
}
redirect($workshopBase);
