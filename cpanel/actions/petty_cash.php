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
$cashPath = $role === 'SECRETARY' ? '/secretary/petty-cash' : '/doctor/petty-cash';
$jy = (int) post('jy');
$jm = (int) post('jm');
$back = $cashPath . '?jy=' . $jy . '&jm=' . $jm;
csrf_verify();

if (post('action') === 'delete') {
    $id = trim((string) post('id'));
    if (!preg_match('/^[a-zA-Z0-9_-]{8,40}$/', $id)) {
        flash_set('error', 'این مورد برای حذف پیدا نشد.');
        redirect($back);
    }
    ensure_petty_cash_schema($pdo);
    $stmt = $pdo->prepare('SELECT receipt_path FROM petty_cash_entries WHERE id=?');
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    if (!$row) {
        flash_set('error', 'این مورد دیگر در تنخواه نیست.');
        redirect($back);
    }
    $pdo->prepare('DELETE FROM petty_cash_entries WHERE id=?')->execute([$id]);
    $receipt = trim((string) ($row['receipt_path'] ?? ''));
    if ($receipt !== '') {
        $left = $pdo->prepare('SELECT COUNT(*) FROM petty_cash_entries WHERE receipt_path=?');
        $left->execute([$receipt]);
        if ((int) $left->fetchColumn() === 0) {
            $abs = staff_receipt_abs($receipt);
            if (is_file($abs)) {
                unlink($abs);
            }
        }
    }
    flash_set('success', 'مورد از تنخواه حذف شد.');
    redirect($back);
}

$kind = post('action') === 'in' ? 'IN' : (post('action') === 'out' ? 'OUT' : '');
$date = petty_cash_parse_parts((int) post('entry_jy'), (int) post('entry_jm'), (int) post('entry_jd'));
if ($date === null) {
    $date = parse_user_date((string) post('entry_date'));
}
$note = trim((string) post('note'));
if ($kind === '' || $date === null) {
    flash_set('error', 'مبلغ و تاریخ را درست وارد کنید.');
    redirect($back);
}
if (mb_strlen($note) > 500) {
    flash_set('error', 'توضیح طولانی است.');
    redirect($back);
}

/** @var list<array{name:string,amount:int}> $lines */
$lines = [];
if ($kind === 'OUT') {
    $names = $_POST['item_name'] ?? [];
    $amounts = $_POST['amount'] ?? [];
    if (!is_array($names)) {
        $names = [(string) $names];
    }
    if (!is_array($amounts)) {
        $amounts = [(string) $amounts];
    }
    $count = max(count($names), count($amounts));
    if ($count > 30) {
        flash_set('error', 'در هر ثبت حداکثر ۳۰ قلم می‌شود.');
        redirect($back);
    }
    for ($i = 0; $i < $count; $i++) {
        $name = trim((string) ($names[$i] ?? ''));
        $rawAmount = trim((string) ($amounts[$i] ?? ''));
        if ($name === '' && $rawAmount === '') {
            continue;
        }
        if ($name === '' || $rawAmount === '') {
            flash_set('error', 'هر قلم باید هم نام داشته باشد هم مبلغ.');
            redirect($back);
        }
        if (mb_strlen($name) > 120) {
            flash_set('error', 'نام قلم طولانی است.');
            redirect($back);
        }
        $money = parse_user_money($rawAmount);
        if ($money <= 0) {
            flash_set('error', 'مبلغ هر قلم را درست وارد کنید.');
            redirect($back);
        }
        $lines[] = ['name' => $name, 'amount' => $money];
    }
    if (!$lines) {
        flash_set('error', 'قلم هزینه و مبلغ را بنویسید.');
        redirect($back);
    }
} else {
    $money = parse_user_money((string) post('amount'));
    if ($money <= 0) {
        flash_set('error', 'مبلغ و تاریخ را درست وارد کنید.');
        redirect($back);
    }
    $lines[] = ['name' => '', 'amount' => $money];
}

ensure_petty_cash_schema($pdo);
$receipt = null;
$file = $_FILES['receipt'] ?? null;
if ($kind === 'OUT' && is_array($file) && (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
    try {
        $receipt = staff_save_receipt($file, cuid());
    } catch (RuntimeException $e) {
        flash_set('error', $e->getMessage());
        redirect($back);
    }
}

$insert = $pdo->prepare('
  INSERT INTO petty_cash_entries (id, kind, item_name, amount, entry_date, note, receipt_path, created_by)
  VALUES (?,?,?,?,?,?,?,?)
');
$createdBy = (string) ($user['id'] ?? '');
$noteValue = $note !== '' ? $note : null;
try {
    $pdo->beginTransaction();
    foreach ($lines as $line) {
        $insert->execute([
            cuid(),
            $kind,
            $line['name'],
            $line['amount'],
            $date,
            $noteValue,
            $receipt,
            $createdBy,
        ]);
    }
    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    flash_set('error', 'ثبت تنخواه انجام نشد.');
    redirect($back);
}

if ($kind === 'IN') {
    flash_set('success', 'مبلغ ورودی ثبت شد.');
} elseif (count($lines) === 1) {
    flash_set('success', 'هزینه ثبت شد.');
} else {
    flash_set('success', to_fa_digits((string) count($lines)) . ' قلم هزینه ثبت شد.');
}
redirect($back);
