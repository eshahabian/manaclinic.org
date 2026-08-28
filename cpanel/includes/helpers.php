<?php
declare(strict_types=1);

function e(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function redirect(string $path): never
{
    global $config, $base;
    $prefix = ($base && $base !== '/') ? $base : '';
    if (str_starts_with($path, 'http')) {
        header('Location: ' . $path);
        exit;
    }
    header('Location: ' . $prefix . $path);
    exit;
}

function url(string $path = '/'): string
{
    global $base;
    $prefix = ($base && $base !== '/') ? $base : '';
    if ($path === '/') {
        return $prefix . '/' ?: '/';
    }
    return $prefix . '/' . ltrim($path, '/');
}

function format_price(int $amount): string
{
    return number_format($amount) . ' تومان';
}

function slugify(string $text): string
{
    $text = trim(mb_strtolower($text, 'UTF-8'));
    $text = preg_replace('/\s+/u', '-', $text) ?? '';
    $text = preg_replace('/[^\p{Arabic}a-z0-9\-]/u', '', $text) ?? '';
    $text = preg_replace('/-+/', '-', $text) ?? '';
    return trim($text, '-') ?: ('article-' . time());
}

function generate_slots(string $start, string $end, int $minutes): array
{
    [$sh, $sm] = array_map('intval', explode(':', $start));
    [$eh, $em] = array_map('intval', explode(':', $end));
    $cursor = $sh * 60 + $sm;
    $endMin = $eh * 60 + $em;
    $slots = [];
    while ($cursor + $minutes <= $endMin) {
        $slots[] = sprintf('%02d:%02d', intdiv($cursor, 60), $cursor % 60);
        $cursor += $minutes;
    }
    return $slots;
}

function appointment_status_label(string $status): string
{
    return match ($status) {
        'PENDING_PAYMENT' => 'در انتظار پرداخت',
        'CONFIRMED' => 'تأیید شده',
        'CANCELLED' => 'لغو شده',
        'COMPLETED' => 'انجام شده',
        default => $status,
    };
}

function payment_status_label(string $status): string
{
    return match ($status) {
        'PENDING' => 'در انتظار',
        'PAID' => 'پرداخت شده',
        'FAILED' => 'ناموفق',
        default => $status,
    };
}

function role_label(string $role): string
{
    return match ($role) {
        'ADMIN' => 'مدیر',
        'DOCTOR' => 'دکتر',
        'PATIENT' => 'بیمار',
        default => $role,
    };
}

function format_fa_datetime(string $datetime): string
{
    $ts = strtotime($datetime);
    if (!$ts) {
        return $datetime;
    }
    return date('Y/m/d H:i', $ts);
}

function cuid(): string
{
    return bin2hex(random_bytes(12));
}

function flash_set(string $type, string $message): void
{
    $_SESSION['flash'] = ['type' => $type, 'message' => $message];
}

function flash_get(): ?array
{
    if (empty($_SESSION['flash'])) {
        return null;
    }
    $f = $_SESSION['flash'];
    unset($_SESSION['flash']);
    return $f;
}

function post(string $key, string $default = ''): string
{
    return trim((string) ($_POST[$key] ?? $default));
}
