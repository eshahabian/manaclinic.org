<?php
declare(strict_types=1);

function ensure_consult_requests_schema(PDO $pdo): void
{
    static $ready = false;
    if ($ready) {
        return;
    }
    $pdo->exec("
      CREATE TABLE IF NOT EXISTS consult_requests (
        id VARCHAR(32) NOT NULL PRIMARY KEY,
        name VARCHAR(120) NOT NULL DEFAULT '',
        phone VARCHAR(20) NOT NULL,
        message TEXT NOT NULL,
        status VARCHAR(16) NOT NULL DEFAULT 'new',
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        seen_at DATETIME NULL,
        called_by_user_id VARCHAR(32) NULL,
        called_by_name VARCHAR(120) NOT NULL DEFAULT '',
        called_by_role VARCHAR(16) NOT NULL DEFAULT '',
        KEY idx_consult_status_created (status, created_at)
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    foreach ([
        'called_by_user_id' => 'VARCHAR(32) NULL',
        'called_by_name' => "VARCHAR(120) NOT NULL DEFAULT ''",
        'called_by_role' => "VARCHAR(16) NOT NULL DEFAULT ''",
    ] as $column => $definition) {
        try {
            $pdo->query('SELECT `' . $column . '` FROM consult_requests LIMIT 0');
        } catch (Throwable $e) {
            $pdo->exec('ALTER TABLE consult_requests ADD COLUMN `' . $column . '` ' . $definition);
        }
    }
    $ready = true;
}

function consult_request_is_open(array $row): bool
{
    $status = strtolower(trim((string) ($row['status'] ?? '')));

    return $status === '' || $status === 'new';
}

function consult_request_new_count(): int
{
    $db = $GLOBALS['pdo'] ?? null;
    if (!$db instanceof PDO) {
        return 0;
    }
    try {
        ensure_consult_requests_schema($db);
        return (int) $db->query("
          SELECT COUNT(*) FROM consult_requests
          WHERE status IS NULL OR TRIM(status) = '' OR LOWER(TRIM(status)) = 'new'
        ")->fetchColumn();
    } catch (Throwable $e) {
        return 0;
    }
}

function consult_request_list(PDO $pdo): array
{
    ensure_consult_requests_schema($pdo);
    return $pdo->query("
      SELECT id, name, phone, message, status, created_at, seen_at,
             called_by_user_id, called_by_name, called_by_role
      FROM consult_requests
      ORDER BY (status = 'new') DESC, created_at DESC
      LIMIT 150
    ")->fetchAll();
}

function consult_request_actor_label(array $row): string
{
    $name = trim((string) ($row['called_by_name'] ?? ''));
    $role = match (strtoupper(trim((string) ($row['called_by_role'] ?? '')))) {
        'SECRETARY' => 'منشی',
        'ADMIN' => 'مدیر',
        'DOCTOR' => 'درمانگر',
        default => '',
    };
    if ($name !== '' && $role !== '') {
        return $role . ' · ' . $name;
    }

    return $name !== '' ? $name : $role;
}

function consult_request_stamps_html(array $row): string
{
    $created = format_fa_datetime((string) ($row['created_at'] ?? ''));
    $html = '<p class="consult-stamp">زمان پیام: ' . e($created) . '</p>';
    if (consult_request_is_open($row)) {
        return $html;
    }
    $who = consult_request_actor_label($row);
    $when = trim((string) ($row['seen_at'] ?? ''));
    $line = 'تماس گرفته شد';
    if ($who !== '') {
        $line .= ' توسط ' . $who;
    }
    if ($when !== '') {
        $line .= ' — ' . format_fa_datetime($when);
    }
    $html .= '<p class="consult-stamp consult-stamp-call">' . e($line) . '</p>';

    return $html;
}

function consult_digits(string $value): string
{
    return strtr($value, [
        '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4',
        '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
        '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
        '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
    ]);
}

function consult_normalize_phone(string $raw): string
{
    $raw = consult_digits($raw);
    $raw = preg_replace('/[^\d+]/', '', $raw) ?? '';
    if (str_starts_with($raw, '+98')) {
        $raw = '0' . substr($raw, 3);
    } elseif (str_starts_with($raw, '0098')) {
        $raw = '0' . substr($raw, 4);
    } elseif (str_starts_with($raw, '98') && strlen($raw) === 12) {
        $raw = '0' . substr($raw, 2);
    }
    if (!preg_match('/^09\d{9}$/', $raw)) {
        return '';
    }

    return $raw;
}

function consult_safe_return(string $next): string
{
    $next = trim($next);
    if ($next === '' || !str_starts_with($next, '/') || str_starts_with($next, '//') || str_contains($next, '://') || preg_match('/[\r\n]/', $next)) {
        return '/';
    }
    $path = parse_url($next, PHP_URL_PATH);
    if (!is_string($path) || $path === '') {
        return '/';
    }

    return $next;
}

function consult_panel_path(array $user): string
{
    return match ((string) ($user['role'] ?? '')) {
        'SECRETARY' => '/secretary/consult-requests',
        'DOCTOR' => '/doctor/consult-requests',
        default => '/admin/consult-requests',
    };
}

function consult_request_form_html(string $prefix = 'consult'): string
{
    $prefix = preg_replace('/[^a-z0-9_-]/i', '', $prefix) ?: 'consult';
    $next = (string) ($GLOBALS['path'] ?? '/');
    ob_start();
    ?>
    <form class="footer-consult" method="post" action="<?= e(url('/consult-request')) ?>">
      <?= csrf_field() ?>
      <input type="hidden" name="next" value="<?= e($next) ?>">
      <input class="footer-consult-hp" type="text" name="website" tabindex="-1" autocomplete="off" aria-hidden="true">
      <label class="sr-only" for="<?= e($prefix) ?>-name">نام و نام خانوادگی، الزامی</label>
      <input class="input" id="<?= e($prefix) ?>-name" name="name" required aria-required="true" maxlength="100" placeholder="نام و نام خانوادگی *" autocomplete="name">
      <label class="sr-only" for="<?= e($prefix) ?>-phone">شماره تماس، الزامی</label>
      <input class="input input-rtl" id="<?= e($prefix) ?>-phone" name="phone" required aria-required="true" inputmode="tel" maxlength="20" dir="rtl" placeholder="شماره تماس *" autocomplete="tel">
      <label class="sr-only" for="<?= e($prefix) ?>-message">توضیح درخواست، الزامی</label>
      <textarea class="input" id="<?= e($prefix) ?>-message" name="message" required aria-required="true" maxlength="1000" rows="4" placeholder="توضیح مختصر درباره درخواست شما *"></textarea>
      <button type="submit" class="btn btn-primary">ارسال درخواست</button>
    </form>
    <?php
    return (string) ob_get_clean();
}
