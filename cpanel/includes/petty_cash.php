<?php
declare(strict_types=1);

function ensure_petty_cash_schema(PDO $pdo): void
{
    static $ready = false;
    if ($ready) {
        return;
    }
    $pdo->exec("
      CREATE TABLE IF NOT EXISTS petty_cash_entries (
        id VARCHAR(32) PRIMARY KEY,
        kind ENUM('IN','OUT') NOT NULL,
        item_name VARCHAR(120) NOT NULL DEFAULT '',
        amount INT NOT NULL,
        entry_date DATE NOT NULL,
        note VARCHAR(500) NULL,
        receipt_path VARCHAR(255) NULL,
        created_by VARCHAR(32) NOT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_petty_date (entry_date),
        INDEX idx_petty_item (item_name)
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    $ready = true;
}

function petty_cash_user_allowed(?array $user = null): bool
{
    $user = $user ?? (function_exists('current_user') ? current_user() : null);
    if (!$user) {
        return false;
    }
    if ((string) ($user['role'] ?? '') === 'SECRETARY') {
        return true;
    }
    if ((string) ($user['role'] ?? '') === 'DOCTOR' && function_exists('doctor_has_shiva_access') && doctor_has_shiva_access($user)) {
        return true;
    }

    return false;
}

/** @return array{start:string,end:string,year:int,month:int,label:string} */
function petty_cash_month_range(int $jy, int $jm): array
{
    if ($jy < 1300 || $jm < 1 || $jm > 12) {
        $meta = jalali_current_month_meta();
        $jy = (int) ($meta['year'] ?? 0);
        $jm = (int) ($meta['month'] ?? 1);
    }
    $len = function_exists('jalali_month_length') ? jalali_month_length($jy, $jm) : 31;
    $start = jalali_ymd($jy, $jm, 1);
    $end = jalali_ymd($jy, $jm, $len);
    $months = [1 => 'فروردین', 2 => 'اردیبهشت', 3 => 'خرداد', 4 => 'تیر', 5 => 'مرداد', 6 => 'شهریور', 7 => 'مهر', 8 => 'آبان', 9 => 'آذر', 10 => 'دی', 11 => 'بهمن', 12 => 'اسفند'];

    return [
        'start' => $start,
        'end' => $end,
        'year' => $jy,
        'month' => $jm,
        'label' => ($months[$jm] ?? (string) $jm) . ' ' . to_fa_digits((string) $jy),
    ];
}

/** @return array{in:int,out:int,items:list<array<string,mixed>>,entries:list<array<string,mixed>>} */
function petty_cash_month_report(PDO $pdo, string $start, string $end): array
{
    ensure_petty_cash_schema($pdo);
    $sum = $pdo->prepare("SELECT kind, COALESCE(SUM(amount),0) AS total FROM petty_cash_entries WHERE entry_date BETWEEN ? AND ? GROUP BY kind");
    $sum->execute([$start, $end]);
    $in = 0;
    $out = 0;
    foreach ($sum->fetchAll() as $row) {
        if (($row['kind'] ?? '') === 'IN') {
            $in = (int) $row['total'];
        } elseif (($row['kind'] ?? '') === 'OUT') {
            $out = (int) $row['total'];
        }
    }
    $items = $pdo->prepare("
      SELECT item_name, COUNT(*) AS n, COALESCE(SUM(amount),0) AS total
      FROM petty_cash_entries
      WHERE kind='OUT' AND entry_date BETWEEN ? AND ?
      GROUP BY item_name
      ORDER BY total DESC, item_name ASC
    ");
    $items->execute([$start, $end]);
    $entries = $pdo->prepare("
      SELECT e.*, u.name AS created_by_name
      FROM petty_cash_entries e
      JOIN users u ON u.id = e.created_by
      WHERE e.entry_date BETWEEN ? AND ?
      ORDER BY e.entry_date DESC, e.created_at DESC
    ");
    $entries->execute([$start, $end]);

    return [
        'in' => $in,
        'out' => $out,
        'items' => $items->fetchAll() ?: [],
        'entries' => $entries->fetchAll() ?: [],
    ];
}
