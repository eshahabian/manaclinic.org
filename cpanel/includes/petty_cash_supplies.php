<?php
declare(strict_types=1);

function petty_cash_ensure_supplies_schema(PDO $pdo): void
{
    $pdo->exec("
      CREATE TABLE IF NOT EXISTS petty_cash_supplies (
        id VARCHAR(32) PRIMARY KEY,
        item_name VARCHAR(120) NOT NULL,
        due_date DATE NOT NULL,
        is_urgent TINYINT NOT NULL DEFAULT 0,
        revision INT UNSIGNED NOT NULL DEFAULT 1,
        created_by VARCHAR(32) NOT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        completed_at DATETIME NULL,
        INDEX idx_petty_supplies_open (completed_at, is_urgent, due_date)
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
}

function petty_cash_supply_due_date(string $value): ?string
{
    $value = preg_replace('/\p{Cf}/u', '', $value) ?? '';
    $value = str_replace(['-', '.', '⁄', '∕'], '/', normalize_input($value));
    if (!preg_match('~^(\d{4})\h*/\h*(\d{1,2})\h*/\h*(\d{1,2})$~D', $value, $parts)) {
        return null;
    }
    return petty_cash_parse_parts((int) $parts[1], (int) $parts[2], (int) $parts[3]);
}

function petty_cash_supply_date_input(string $dueDate): string
{
    [$year, $month, $day] = array_map('intval', explode('-', $dueDate));
    [$jy, $jm, $jd] = gregorian_to_jalali($year, $month, $day);
    return to_fa_digits(sprintf('%04d/%02d/%02d', $jy, $jm, $jd));
}

function petty_cash_supply_recipients(PDO $pdo): array
{
    return $pdo->query("
      SELECT id, phone FROM users
      WHERE COALESCE(is_disabled, 0) = 0
        AND ((role = 'DOCTOR' AND id = '36dfdb042124cb0a89814c30')
          OR (role = 'ADMIN' AND LOWER(username) = 'eshahabian'))
    ")->fetchAll(PDO::FETCH_ASSOC);
}

function petty_cash_supply_queue_sms(PDO $pdo, string $id, int $revision, string $name, string $dueDate, bool $expense = false): string
{
    $phones = [];
    $reachable = 0;
    foreach (petty_cash_supply_recipients($pdo) as $recipient) {
        $phone = sms_iran_mobile((string) $recipient['phone']);
        if ($phone !== null) {
            $phones[$phone][] = (string) $recipient['id'];
            $reachable++;
        }
    }
    $body = 'مانا کلینیک: خرید ضروری «' . $name . '». مهلت تهیه ' . to_jalali_label($dueDate) . '.';
    foreach ($phones as $phone => $recipientIds) {
        sms_queue_extended($pdo, $phone, $body, 'petty_cash_urgent_supply', "petty-supply:{$id}:{$revision}:{$phone}",
            null, null, ['entity' => $expense ? 'petty_cash_urgent_expense' : 'petty_cash_supply', 'id' => $id, 'revision' => $revision, 'recipients' => $recipientIds]);
    }
    return match ($reachable) {
        2 => 'یادآوری پیامکی ثبت شد.',
        1 => 'یادآوری پیامکی برای یک گیرنده ثبت شد.',
        default => 'یادآوری پیامکی ثبت نشد.',
    };
}

function petty_cash_supply_is_buyer(array $user): bool
{
    return ($user['role'] ?? '') === 'DOCTOR' && ($user['id'] ?? '') === '36dfdb042124cb0a89814c30';
}

function petty_cash_supply_buyer_phone(PDO $pdo): ?string
{
    foreach (petty_cash_supply_recipients($pdo) as $recipient) {
        if ($recipient['id'] === '36dfdb042124cb0a89814c30') {
            return sms_iran_mobile((string) $recipient['phone']);
        }
    }
    return null;
}

function petty_cash_supply_apply_post(PDO $pdo, array $user, string $action): string
{
    $id = post('supply_id');
    if (!preg_match('/^[a-f0-9]{24}$/D', $id)) {
        throw new RuntimeException('شناسه درخواست معتبر نیست.');
    }
    if ($action === 'supply_request') {
        if (($user['role'] ?? '') !== 'SECRETARY') {
            throw new RuntimeException('ثبت درخواست خرید مخصوص منشی‌هاست.');
        }
        return petty_cash_supply_request($pdo, (string) $user['id'], $id);
    }
    if (!petty_cash_supply_is_buyer($user)) {
        throw new RuntimeException('ثبت خرید یا رد درخواست مخصوص عطیه گارسچی است.');
    }

    if ($action === 'supply_reject') {
        petty_cash_ensure_supplies_schema($pdo);
        $reject = $pdo->prepare('UPDATE petty_cash_supplies SET completed_at = NOW() WHERE id = ? AND completed_at IS NULL');
        $reject->execute([$id]);
        if ($reject->rowCount() !== 1) {
            throw new RuntimeException('این درخواست بسته شده یا پیدا نشد.');
        }
        return 'درخواست خرید رد شد.';
    }

    $amount = parse_user_money(post('amount'));
    $account = post('pay_account');
    if ($amount <= 0 || $amount > 2147483647 || !isset(petty_cash_in_accounts()[$account])) {
        throw new RuntimeException('مبلغ خرید و حساب تنخواه را درست وارد کنید.');
    }

    ensure_petty_cash_schema($pdo);
    petty_cash_ensure_supplies_schema($pdo);
    $pdo->beginTransaction();
    try {
        $complete = $pdo->prepare('UPDATE petty_cash_supplies SET completed_at = NOW() WHERE id = ? AND completed_at IS NULL');
        $complete->execute([$id]);
        if ($complete->rowCount() !== 1) {
            throw new RuntimeException('این درخواست بسته شده یا پیدا نشد.');
        }
        $pdo->prepare("
            INSERT INTO petty_cash_entries (id, kind, item_name, amount, entry_date, created_by, pay_account, approver)
            SELECT ?, 'OUT', item_name, ?, ?, ?, ?, 'garsichi' FROM petty_cash_supplies WHERE id = ?
        ")->execute([cuid(), $amount, date('Y-m-d'), $user['id'], $account, $id]);
        $pdo->commit();
        return 'خرید در ریز هزینه‌ها ثبت و از مانده تنخواه کم شد.';
    } catch (Throwable $exception) {
        $pdo->rollBack();
        throw $exception;
    }
}

function petty_cash_supply_request(PDO $pdo, string $userId, string $batchId): string
{
    $names = $_POST['supply_names'] ?? null;
    $urgencies = $_POST['supply_urgencies'] ?? [];
    $dates = $_POST['supply_dates'] ?? [];
    if (!is_array($names) || !$names || count($names) > 30 || !is_array($urgencies) || !is_array($dates)) {
        throw new RuntimeException('در هر درخواست بین ۱ تا ۳۰ قلم وارد کنید.');
    }
    // Forms already open before the per-item dates update may still post one shared date.
    $sharedDate = isset($_POST['supply_dates']) ? '' : ($_POST['supply_due_date'] ?? '');
    $items = [];
    $now = new DateTimeImmutable();
    $earliestDeadline = $now->add(new DateInterval('PT24H'));
    foreach ($names as $index => $value) {
        $name = is_string($value) ? trim($value) : '';
        if ($name === '' || mb_strlen($name) > 120) {
            throw new RuntimeException('نام هر قلم باید بین ۱ تا ۱۲۰ نویسه باشد.');
        }
        $id = substr(hash('sha256', "$userId:$batchId:$index"), 0, 24);
        $urgent = ($urgencies[$index] ?? '') === '1';
        $dueDate = null;
        if (!$urgent) {
            $rawDate = $dates[$index] ?? $sharedDate;
            $dueDate = is_string($rawDate) ? petty_cash_supply_due_date($rawDate) : null;
            if ($dueDate === null) {
                throw new RuntimeException('تاریخ تهیه «' . $name . '» را به شمسی وارد کنید، مثلاً ۱۴۰۵/۰۹/۱۰.');
            }
            if (new DateTimeImmutable($dueDate . ' 23:59:59') < $earliestDeadline) {
                throw new RuntimeException('برای اقلام عادی، مهلت تهیه باید دست‌کم ۲۴ ساعت دیگر باشد.');
            }
        }
        $items[$id] = [$name, $dueDate];
    }

    require_once __DIR__ . '/outreach.php';
    petty_cash_ensure_supplies_schema($pdo);
    ensure_outreach_schema($pdo);
    $pdo->beginTransaction();
    try {
        $insert = $pdo->prepare('INSERT IGNORE INTO petty_cash_supplies (id, item_name, due_date, is_urgent, created_by) VALUES (?,?,?,?,?)');
        $groups = [];
        foreach ($items as $id => [$name, $dueDate]) {
            $insert->execute([$id, $name, $dueDate ?? $now->format('Y-m-d'), (int) ($dueDate === null), $userId]);
            if ($insert->rowCount() === 1) {
                $groups[$dueDate ?? 'urgent'][] = $id;
            }
        }
        if (!$groups) {
            $pdo->commit();
            return 'این فهرست خرید قبلاً ثبت شده است.';
        }

        $phone = petty_cash_supply_buyer_phone($pdo);
        $allQueued = true;
        $immediate = null;
        foreach ($groups as $group => $ids) {
            $urgent = $group === 'urgent';
            $body = $urgent
                ? 'مانا کلینیک: درخواست خرید ضروری ثبت شد. تب درخواست‌های تنخواه را بررسی کنید.'
                : 'مانا کلینیک: یادآوری درخواست خرید تا ' . to_jalali_label($group) . '. تب درخواست‌های تنخواه را بررسی کنید.';
            $kind = 'petty_cash_purchase_reminder';
            $key = "petty-purchase:$userId:$batchId:$group";
            $deadline = $urgent ? $earliestDeadline : new DateTimeImmutable($group . ' 23:59:59');
            $remindAt = $urgent ? null : $deadline->sub(new DateInterval('PT24H'))->format('Y-m-d H:i:s');
            $expiresAt = $deadline->format('Y-m-d H:i:s');
            $context = ['entity' => 'petty_cash_purchase_request', 'ids' => $ids];
            $queued = $phone !== null && sms_queue_extended(
                $pdo, $phone, $body, $kind, $key, $remindAt, $expiresAt, $context
            );
            if ($urgent && $queued) {
                $immediate = [$phone, $body, $kind, $key, $expiresAt, $context];
            }
            $allQueued = $allQueued && $queued;
        }
        $pdo->commit();
    } catch (Throwable $exception) {
        $pdo->rollBack();
        throw $exception;
    }

    if ($immediate !== null) {
        try {
            $result = sms_send_immediate($pdo, ...$immediate);
            if ($allQueued && !$result['ok']) {
                return 'فهرست خرید ثبت شد. ارسال پیامک فوری تأیید نشد.';
            }
        } catch (Throwable $exception) {
            error_log('Petty cash urgent SMS dispatch failed: ' . $exception->getCode());
            return 'فهرست خرید ثبت شد. ارسال پیامک فوری تأیید نشد.';
        }
    }
    return $allQueued ? 'فهرست خرید و پیامک ثبت شد.' : 'فهرست خرید ثبت شد. ثبت پیامک کامل نشد.';
}
