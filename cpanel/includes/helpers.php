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
        'SECRETARY' => 'منشی',
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

/** ارقام فارسی/عربی → انگلیسی (برای رمز و نام کاربری) */
function normalize_input(string $value): string
{
    return strtr(trim($value), [
        '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4',
        '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
        '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
        '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
    ]);
}

/** تبدیل تاریخ میلادی Y-m-d به شمسی با ارقام فارسی */
function to_jalali_label(string $ymd): string
{
    $parts = explode('-', $ymd);
    if (count($parts) !== 3) {
        return $ymd;
    }
    [$gy, $gm, $gd] = array_map('intval', $parts);
    [$jy, $jm, $jd] = gregorian_to_jalali($gy, $gm, $gd);
    $months = [
        1 => 'فروردین', 2 => 'اردیبهشت', 3 => 'خرداد',
        4 => 'تیر', 5 => 'مرداد', 6 => 'شهریور',
        7 => 'مهر', 8 => 'آبان', 9 => 'آذر',
        10 => 'دی', 11 => 'بهمن', 12 => 'اسفند',
    ];
    $label = $jd . ' ' . ($months[$jm] ?? $jm) . ' ' . $jy;
    return to_fa_digits($label);
}

function to_fa_digits(string $value): string
{
    return strtr($value, [
        '0' => '۰', '1' => '۱', '2' => '۲', '3' => '۳', '4' => '۴',
        '5' => '۵', '6' => '۶', '7' => '۷', '8' => '۸', '9' => '۹',
    ]);
}

function gregorian_to_jalali(int $gy, int $gm, int $gd): array
{
    $g_d_m = [0, 31, 59, 90, 120, 151, 181, 212, 243, 273, 304, 334];
    $gy2 = ($gm > 2) ? ($gy + 1) : $gy;
    $days = 355666 + (365 * $gy) + intdiv($gy2 + 3, 4) - intdiv($gy2 + 99, 100)
        + intdiv($gy2 + 399, 400) + $gd + $g_d_m[$gm - 1];
    $jy = -1595 + (33 * intdiv($days, 12053));
    $days %= 12053;
    $jy += 4 * intdiv($days, 1461);
    $days %= 1461;
    if ($days > 365) {
        $jy += intdiv($days - 1, 365);
        $days = ($days - 1) % 365;
    }
    if ($days < 186) {
        $jm = 1 + intdiv($days, 31);
        $jd = 1 + ($days % 31);
    } else {
        $jm = 7 + intdiv($days - 186, 30);
        $jd = 1 + (($days - 186) % 30);
    }
    return [$jy, $jm, $jd];
}
