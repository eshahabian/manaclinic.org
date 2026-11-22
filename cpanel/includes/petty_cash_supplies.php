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
    if (!preg_match('~^(\d{4})/(\d{1,2})/(\d{1,2})$~D', normalize_input($value), $parts)) {
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

function petty_cash_supply_queue_sms(PDO $pdo, string $id, int $revision, string $name, string $dueDate): string
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
            null, null, ['entity' => 'petty_cash_supply', 'id' => $id, 'revision' => $revision, 'recipients' => $recipientIds]);
    }
    return match ($reachable) {
        2 => 'پیامک برای عطیه گارسچی و عماد شهابیان در صف ارسال قرار گرفت.',
        1 => 'پیامک برای حساب دارای شماره معتبر در صف قرار گرفت. شماره یا وضعیت حساب گیرنده دیگر را بررسی کنید.',
        default => 'برای هیچ‌یک از دو گیرنده حساب فعال با شماره معتبر پیدا نشد.',
    };
}

function petty_cash_supply_apply_post(PDO $pdo, array $user, string $action): string
{
    $id = post('supply_id');
    if (!preg_match('/^[a-f0-9]{24}$/D', $id)) {
        throw new RuntimeException('قلم مورد نظر پیدا نشد.');
    }
    $name = post('supply_name');
    $dueDate = petty_cash_supply_due_date(post('supply_due_date'));
    $urgent = post('supply_urgent') === '1';
    if ($action !== 'supply_done' && ($name === '' || mb_strlen($name) > 120 || $dueDate === null)) {
        throw new RuntimeException('نام قلم و مهلت تهیهٔ شمسی را درست وارد کنید.');
    }

    petty_cash_ensure_supplies_schema($pdo);
    if ($action !== 'supply_done' && $urgent) {
        require_once __DIR__ . '/outreach.php';
        ensure_outreach_schema($pdo);
    }
    $pdo->beginTransaction();
    try {
        $revision = 1;
        $changed = true;
        if ($action === 'supply_add') {
            $stmt = $pdo->prepare('INSERT IGNORE INTO petty_cash_supplies (id, item_name, due_date, is_urgent, created_by) VALUES (?,?,?,?,?)');
            $stmt->execute([$id, $name, $dueDate, (int) $urgent, $user['id']]);
            $changed = $stmt->rowCount() > 0;
        } else {
            $stmt = $pdo->prepare('SELECT item_name, due_date, is_urgent, revision FROM petty_cash_supplies WHERE id = ? AND completed_at IS NULL FOR UPDATE');
            $stmt->execute([$id]);
            $item = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$item) {
                throw new RuntimeException('این قلم در فهرست خریدهای باز نیست.');
            }
            if ($action === 'supply_done') {
                $pdo->prepare('UPDATE petty_cash_supplies SET completed_at = NOW() WHERE id = ?')->execute([$id]);
                $pdo->commit();
                return 'قلم به‌عنوان تهیه‌شده ثبت شد.';
            }
            $changed = $item['item_name'] !== $name || $item['due_date'] !== $dueDate || (bool) $item['is_urgent'] !== $urgent;
            if ($changed) {
                $revision = (int) $item['revision'] + 1;
                $pdo->prepare('UPDATE petty_cash_supplies SET item_name = ?, due_date = ?, is_urgent = ?, revision = ? WHERE id = ?')
                    ->execute([$name, $dueDate, (int) $urgent, $revision, $id]);
            }
        }
        $message = $changed ? 'لیست مایحتاج به‌روز شد.' : 'این قلم قبلاً با همین اطلاعات ثبت شده است.';
        if ($changed && $urgent) {
            $message .= ' ' . petty_cash_supply_queue_sms($pdo, $id, $revision, $name, $dueDate);
        }
        $pdo->commit();
        return $message;
    } catch (Throwable $exception) {
        $pdo->rollBack();
        throw $exception;
    }
}
