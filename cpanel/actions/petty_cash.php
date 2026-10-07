<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/petty_cash.php';
require_once __DIR__ . '/../includes/doctor_profile_fields.php';
require_once __DIR__ . '/../includes/staff_desk.php';

$user = require_login();
if (!petty_cash_user_allowed($user)) {
    flash_set('error', 'دسترسی به تنخواه مجاز نیست.');
    redirect('/');
}

$role = (string) ($user['role'] ?? '');
$base = $role === 'SECRETARY' ? '/secretary/petty-cash' : '/doctor/petty-cash';
$jy = (int) post('jy');
$jm = (int) post('jm');
$back = $base . '?jy=' . $jy . '&jm=' . $jm;
csrf_verify();

$kind = post('action') === 'in' ? 'IN' : (post('action') === 'out' ? 'OUT' : '');
$amount = parse_user_money((string) post('amount'));
$date = parse_user_date((string) post('entry_date'));
$note = trim((string) post('note'));
$item = trim((string) post('item_name'));
if ($kind === '' || $amount <= 0 || $date === null) {
    flash_set('error', 'مبلغ و تاریخ را درست وارد کنید.');
    redirect($back);
}
if (mb_strlen($note) > 500) {
    flash_set('error', 'توضیح طولانی است.');
    redirect($back);
}
if ($kind === 'OUT') {
    if ($item === '') {
        flash_set('error', 'قلم هزینه را بنویسید.');
        redirect($back);
    }
    if (mb_strlen($item) > 120) {
        flash_set('error', 'نام قلم طولانی است.');
        redirect($back);
    }
} else {
    $item = '';
}

ensure_petty_cash_schema($pdo);
$id = cuid();
$receipt = null;
$file = $_FILES['receipt'] ?? null;
if ($kind === 'OUT' && is_array($file) && (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
    try {
        $receipt = staff_save_receipt($file, $id);
    } catch (RuntimeException $e) {
        flash_set('error', $e->getMessage());
        redirect($back);
    }
}

$pdo->prepare('
  INSERT INTO petty_cash_entries (id, kind, item_name, amount, entry_date, note, receipt_path, created_by)
  VALUES (?,?,?,?,?,?,?,?)
')->execute([
    $id,
    $kind,
    $item,
    $amount,
    $date,
    $note !== '' ? $note : null,
    $receipt,
    (string) ($user['id'] ?? ''),
]);

flash_set('success', $kind === 'IN' ? 'مبلغ ورودی ثبت شد.' : 'هزینه ثبت شد.');
redirect($back);
