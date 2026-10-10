<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/accountant.php';
require_once __DIR__ . '/../includes/petty_cash.php';

$user = require_login(['ACCOUNTANT']);
csrf_verify();
ensure_outside_payments_schema($pdo);

$jy = (int) post('jy');
$jm = (int) post('jm');
$back = '/accountant/outside?jy=' . $jy . '&jm=' . $jm;
$action = (string) post('action');

if ($action === 'void') {
    $id = trim((string) post('id'));
    $reason = trim((string) post('void_reason'));
    if (!preg_match('/^[a-zA-Z0-9_-]{8,40}$/', $id) || $reason === '') {
        flash_set('error', 'برای ابطال، دلیل را بنویسید.');
        redirect($back);
    }
    if (mb_strlen($reason) > 190) {
        flash_set('error', 'دلیل ابطال طولانی است.');
        redirect($back);
    }
    $stmt = $pdo->prepare('UPDATE outside_payments SET voided_at=NOW(), voided_by=?, void_reason=? WHERE id=? AND voided_at IS NULL');
    $stmt->execute([(string) ($user['id'] ?? ''), $reason, $id]);
    if ($stmt->rowCount() < 1) {
        flash_set('error', 'این مورد پیدا نشد یا قبلاً باطل شده است.');
        redirect($back);
    }
    flash_set('success', 'مورد باطل شد و از جمع مالی خارج شد. سابقه باقی است.');
    redirect($back);
}

if ($action !== 'add') {
    flash_set('error', 'این عمل شناخته نشد.');
    redirect($back);
}

$direction = (string) post('direction') === 'OUT' ? 'OUT' : ((string) post('direction') === 'IN' ? 'IN' : '');
$method = (string) post('method');
if (!in_array($method, ['CASH', 'CARD', 'TRANSFER', 'DEPOSIT', 'OTHER'], true)) {
    $method = '';
}
$title = trim((string) post('title'));
$party = trim((string) post('party_name'));
$note = trim((string) post('note'));
$amount = parse_user_money((string) post('amount'));
$paidOn = petty_cash_parse_parts((int) post('entry_jy'), (int) post('entry_jm'), (int) post('entry_jd'));
if ($direction === '' || $method === '' || $title === '' || $amount <= 0 || $paidOn === null) {
    flash_set('error', 'نوع، روش، بابت، مبلغ و تاریخ را کامل وارد کنید.');
    redirect($back);
}
if (mb_strlen($title) > 190 || mb_strlen($party) > 190 || mb_strlen($note) > 500) {
    flash_set('error', 'یکی از متن‌ها طولانی است.');
    redirect($back);
}

$pdo->prepare('
  INSERT INTO outside_payments (id, direction, method, title, party_name, amount, paid_on, note, created_by)
  VALUES (?,?,?,?,?,?,?,?,?)
')->execute([
    cuid(),
    $direction,
    $method,
    $title,
    $party !== '' ? $party : null,
    $amount,
    $paidOn,
    $note !== '' ? $note : null,
    (string) ($user['id'] ?? ''),
]);

flash_set('success', 'مورد خارج از روال ثبت شد.');
redirect($back);
