<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/admin_panel.php';
require_once __DIR__ . '/../includes/outreach.php';
require_once __DIR__ . '/../includes/clinic_rooms.php';
$user = require_login(['ADMIN', 'SECRETARY', 'DOCTOR']);
csrf_verify();

if (!outreach_user_allowed($user)) {
    flash_set('error', 'این بخش برای این حساب باز نیست.');
    redirect(clinic_desk_home($user));
}

ensure_outreach_schema($pdo);
$action = post('action');

if ($action === 'add') {
    $ok = outreach_save_contact($pdo, (string) post('name'), (string) post('phone'), (string) post('note'));
    flash_set($ok ? 'success' : 'error', $ok ? 'شماره ذخیره شد.' : 'نام و شماره معتبر وارد کنید.');
    redirect('/admin/outreach');
}

if ($action === 'import') {
    $result = outreach_import_lines($pdo, (string) ($_POST['lines'] ?? ''));
    flash_set(
        'success',
        to_fa_digits((string) $result['saved']) . ' شماره ذخیره شد'
        . ($result['skipped'] ? ' و ' . to_fa_digits((string) $result['skipped']) . ' خط رد شد.' : '.')
    );
    redirect('/admin/outreach');
}

if ($action === 'queue') {
    $body = trim((string) ($_POST['body'] ?? ''));
    if ($body === '') {
        flash_set('error', 'متن پیامک را بنویسید.');
        redirect('/admin/outreach');
    }
    $count = outreach_queue_message($pdo, $body, 'manual');
    $tail = sms_panel_enabled()
        ? ' ارسال به پنل پیامک سپرده شد.'
        : ' در صف ماند. تا روشن شدن پنل پیامک ارسال نمی‌شود.';
    flash_set('success', to_fa_digits((string) $count) . ' پیامک' . $tail);
    redirect('/admin/outreach');
}

if ($action === 'delete') {
    $id = trim((string) post('id'));
    if ($id !== '') {
        $pdo->prepare('DELETE FROM outreach_contacts WHERE id=?')->execute([$id]);
    }
    flash_set('success', 'از فهرست حذف شد. اگر حساب کاربری داشته باشد، آن حساب سر جایش می‌ماند.');
    redirect('/admin/outreach');
}

flash_set('error', 'درخواست نامعتبر است.');
redirect('/admin/outreach');
