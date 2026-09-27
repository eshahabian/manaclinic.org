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
        KEY idx_consult_status_created (status, created_at)
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    $ready = true;
}

function consult_request_new_count(): int
{
    $db = $GLOBALS['pdo'] ?? null;
    if (!$db instanceof PDO) {
        return 0;
    }
    try {
        ensure_consult_requests_schema($db);
        return (int) $db->query("SELECT COUNT(*) FROM consult_requests WHERE status='new'")->fetchColumn();
    } catch (Throwable $e) {
        return 0;
    }
}

function consult_request_list(PDO $pdo): array
{
    ensure_consult_requests_schema($pdo);
    return $pdo->query("
      SELECT id, name, phone, message, status, created_at, seen_at
      FROM consult_requests
      ORDER BY (status = 'new') DESC, created_at DESC
      LIMIT 150
    ")->fetchAll();
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
