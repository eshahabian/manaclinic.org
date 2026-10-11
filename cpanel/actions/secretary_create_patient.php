<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/secretary_patient.php';
require_once __DIR__ . '/../includes/outreach.php';

$user = require_login(['SECRETARY']);
csrf_verify();

$action = (string) post('action');
if (in_array($action, ['add_inactive', 'import_inactive', 'delete_inactive', 'queue_inactive'], true)) {
    ensure_outreach_schema($pdo);
    $back = '/secretary/patients?tab=inactive';
    if ($action === 'add_inactive') {
        $ok = outreach_save_contact($pdo, (string) post('name'), (string) post('phone'), (string) post('note'));
        flash_set($ok ? 'success' : 'error', $ok ? 'شماره ذخیره شد.' : 'نام و شماره معتبر وارد کنید.');
        redirect($back);
    }
    if ($action === 'import_inactive') {
        $result = outreach_import_lines($pdo, (string) ($_POST['lines'] ?? ''));
        flash_set(
            'success',
            to_fa_digits((string) $result['saved']) . ' شماره ذخیره شد'
            . ($result['skipped'] ? ' و ' . to_fa_digits((string) $result['skipped']) . ' خط رد شد.' : '.')
        );
        redirect($back);
    }
    if ($action === 'queue_inactive') {
        $body = trim((string) ($_POST['body'] ?? ''));
        if ($body === '') {
            flash_set('error', 'متن پیامک را بنویسید.');
            redirect($back);
        }
        $count = outreach_queue_message($pdo, $body, 'manual');
        $tail = sms_panel_enabled()
            ? ' ارسال به پنل پیامک سپرده شد.'
            : ' در صف ماند. تا روشن شدن پنل پیامک ارسال نمی‌شود.';
        flash_set('success', to_fa_digits((string) $count) . ' پیامک' . $tail);
        redirect($back);
    }
    $id = trim((string) post('id'));
    if ($id !== '') {
        $pdo->prepare('DELETE FROM outreach_contacts WHERE id=?')->execute([$id]);
    }
    flash_set('success', 'از فهرست حذف شد. اگر حساب کاربری داشته باشد، آن حساب سر جایش می‌ماند.');
    redirect($back);
}

$result = secretary_create_patient_from_post($pdo, $user);
if (empty($result['ok'])) {
    flash_set('error', (string) ($result['error'] ?? 'ثبت مراجعه‌کننده ممکن نشد.'));
    redirect('/secretary/patients?add=1');
}

flash_set('success', 'مراجعه‌کننده «' . (string) $result['name'] . '» ثبت شد. نام کاربری: ' . (string) $result['username']);
redirect('/secretary/patients?added=1');
