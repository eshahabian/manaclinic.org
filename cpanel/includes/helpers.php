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
        'DOCTOR' => 'درمانگر',
        'SECRETARY' => 'منشی',
        'PATIENT' => 'مراجع',
        default => $role,
    };
}

function online_payment_enabled(array $config): bool
{
    return !empty($config['online_payment_enabled']);
}

function online_payment_disabled_message(): string
{
    return 'پرداخت آنلاین فعلاً فعال نیست.';
}

function format_fa_datetime(string $datetime): string
{
    $ts = strtotime($datetime);
    if (!$ts) {
        return $datetime;
    }
    return date('Y/m/d H:i', $ts);
}

/** تاریخ و ساعت کارگاه با تقویم شمسی */
function format_workshop_datetime_fa(string $datetime): string
{
    $ts = strtotime($datetime);
    if (!$ts) {
        return $datetime;
    }
    $ymd = date('Y-m-d', $ts);
    $time = date('H:i', $ts);
    return to_jalali_label($ymd) . ' — ' . to_fa_digits($time);
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

/** HTML امن برای ادیتور غنی (bold / سایز / هایلایت) */
function sanitize_rich_html(string $html): string
{
    $html = trim($html);
    if ($html === '' || $html === '<br>' || $html === '<div><br></div>') {
        return '';
    }

    $html = preg_replace('#<(script|style|iframe|object|embed|link|meta)[^>]*>.*?</\1>#is', '', $html) ?? $html;
    $html = preg_replace('#<(script|style|iframe|object|embed|link|meta)[^>]*/?>#is', '', $html) ?? $html;
    $html = strip_tags($html, '<p><br><div><span><b><strong><i><em><u><mark>');

    $html = preg_replace_callback('/<([a-z0-9]+)(\s[^>]*)?>/i', static function (array $m): string {
        $tag = strtolower($m[1]);
        if ($tag === 'br') {
            return '<br>';
        }
        $attrs = $m[2] ?? '';
        $safe = '';
        if (preg_match('/style\s*=\s*(["\'])(.*?)\1/i', $attrs, $sm)) {
            $styles = [];
            foreach (explode(';', $sm[2]) as $part) {
                $part = trim($part);
                if ($part === '' || !str_contains($part, ':')) {
                    continue;
                }
                [$prop, $val] = array_map('trim', explode(':', $part, 2));
                $propL = strtolower($prop);
                $valCompact = preg_replace('/\s+/', '', $val) ?? '';
                if ($propL === 'background-color' && preg_match('/^#([0-9a-f]{3}|[0-9a-f]{6})$/i', $valCompact)) {
                    $styles[] = 'background-color:' . $valCompact;
                } elseif ($propL === 'font-size' && preg_match('/^(\d+(\.\d+)?)(px|rem|em)$/i', $valCompact, $fm)) {
                    $size = (float) $fm[1];
                    if ($size >= 10 && $size <= 36) {
                        $styles[] = 'font-size:' . $valCompact;
                    }
                } elseif ($propL === 'font-weight' && in_array(strtolower($valCompact), ['bold', '700', '600'], true)) {
                    $styles[] = 'font-weight:700';
                }
            }
            if ($styles) {
                $safe .= ' style="' . implode(';', $styles) . '"';
            }
        }
        if ($tag === 'span' && preg_match('/data-hl\s*=\s*(["\'])([a-z]+)\1/i', $attrs, $hm)) {
            $safe .= ' data-hl="' . $hm[2] . '"';
        }
        return '<' . $tag . $safe . '>';
    }, $html) ?? $html;

    return trim($html);
}

function rich_html_for_display(?string $raw): string
{
    $raw = (string) $raw;
    if (trim($raw) === '') {
        return '';
    }
    if (!preg_match('/<[^>]+>/', $raw)) {
        return nl2br(e($raw), false);
    }
    return sanitize_rich_html($raw);
}

/** نام ماه‌های شمسی */
function jalali_month_names(): array
{
    return [
        1 => 'فروردین', 2 => 'اردیبهشت', 3 => 'خرداد',
        4 => 'تیر', 5 => 'مرداد', 6 => 'شهریور',
        7 => 'مهر', 8 => 'آبان', 9 => 'آذر',
        10 => 'دی', 11 => 'بهمن', 12 => 'اسفند',
    ];
}

/** رنگ تب ماه — چرخش بین همان پالت کارگاه‌ها */
function binder_month_tab_meta(int $month): array
{
    $cycle = [
        ['class' => 'binder-tab-in-person', 'tone' => 'in-person'],
        ['class' => 'binder-tab-online', 'tone' => 'online'],
        ['class' => 'binder-tab-offline', 'tone' => 'offline'],
        ['class' => 'binder-tab-archive', 'tone' => 'archive'],
        ['class' => 'binder-tab-new', 'tone' => 'new'],
    ];
    $index = (($month < 1 ? 1 : $month) - 1) % count($cycle);
    return $cycle[$index];
}

/** سال و ماه شمسی از شماره ماه */
function jalali_month_meta_from_parts(int $jy, int $jm): array
{
    $months = jalali_month_names();
    $style = binder_month_tab_meta($jm);
    return [
        'key' => sprintf('%04d-%02d', $jy, $jm),
        'id' => sprintf('m-%04d-%02d', $jy, $jm),
        'label' => ($months[$jm] ?? (string) $jm) . ' ' . to_fa_digits((string) $jy),
        'short' => $months[$jm] ?? (string) $jm,
        'tab_label' => $months[$jm] ?? (string) $jm,
        'sort' => ($jy * 100) + $jm,
        'year' => $jy,
        'month' => $jm,
        'class' => $style['class'],
        'tone' => $style['tone'],
    ];
}

/** سال و ماه شمسی از یک تاریخ میلادی */
function jalali_month_meta_from_datetime(string $datetime): ?array
{
    $ts = strtotime($datetime);
    if (!$ts) {
        return null;
    }
    [$jy, $jm] = gregorian_to_jalali((int) date('Y', $ts), (int) date('n', $ts), (int) date('j', $ts));
    return jalali_month_meta_from_parts($jy, $jm);
}

function jalali_current_month_meta(): array
{
    return jalali_month_meta_from_datetime(date('Y-m-d H:i:s')) ?? [
        'key' => '',
        'id' => 'm-current',
        'label' => 'این ماه',
        'short' => 'این ماه',
        'tab_label' => 'این ماه',
        'sort' => 0,
        'year' => 0,
        'month' => 1,
        'class' => 'binder-tab-in-person',
        'tone' => 'in-person',
    ];
}

/**
 * گروه‌بندی نوبت‌ها بر اساس ماه شمسی.
 * از ماه جاری تا اسفند همان سال همیشه تب هست.
 *
 * @param array<int, array<string, mixed>> $appointments
 * @return array{months: array<string, array<string, mixed>>, default_id: string}
 */
function group_appointments_by_jalali_month(array $appointments, bool $fillRestOfYear = true): array
{
    $current = jalali_current_month_meta();
    $months = [];
    foreach ($appointments as $appointment) {
        $meta = jalali_month_meta_from_datetime((string) ($appointment['starts_at'] ?? ''));
        if (!$meta) {
            continue;
        }
        $id = $meta['id'];
        if (!isset($months[$id])) {
            $months[$id] = $meta + ['items' => [], 'open_slots' => []];
        }
        $months[$id]['items'][] = $appointment;
    }
    if ($fillRestOfYear) {
        $jy = (int) ($current['year'] ?? 0);
        $from = (int) ($current['month'] ?? 1);
        for ($jm = $from; $jm <= 12; $jm++) {
            $meta = jalali_month_meta_from_parts($jy, $jm);
            if (!isset($months[$meta['id']])) {
                $months[$meta['id']] = $meta + ['items' => [], 'open_slots' => []];
            } elseif (!isset($months[$meta['id']]['open_slots'])) {
                $months[$meta['id']]['open_slots'] = [];
            }
        }
    } elseif (!isset($months[$current['id']])) {
        $months[$current['id']] = $current + ['items' => [], 'open_slots' => []];
    }
    foreach ($months as $id => $bucket) {
        if (!isset($months[$id]['open_slots'])) {
            $months[$id]['open_slots'] = [];
        }
    }
    uasort($months, static function (array $a, array $b): int {
        return ((int) $a['sort']) <=> ((int) $b['sort']);
    });

    $nameCounts = [];
    foreach ($months as $bucket) {
        $short = (string) ($bucket['short'] ?? '');
        $nameCounts[$short] = ($nameCounts[$short] ?? 0) + 1;
    }
    foreach ($months as $id => $bucket) {
        $short = (string) ($bucket['short'] ?? '');
        $months[$id]['tab_label'] = ($nameCounts[$short] > 1)
            ? (string) ($bucket['label'] ?? $short)
            : $short;
    }

    $defaultId = $current['id'];
    if (!isset($months[$defaultId]) && $months) {
        $defaultId = (string) array_key_first($months);
    }

    return ['months' => $months, 'default_id' => $defaultId];
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
    $months = jalali_month_names();
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

/** تبدیل حروف فارسی به لاتین (برای نام کاربری و فیلدهای انگلیسی) */
function persian_to_latin(string $text): string
{
    static $map = [
        'آ' => 'a', 'ا' => 'a', 'أ' => 'a', 'إ' => 'a', 'ب' => 'b', 'پ' => 'p',
        'ت' => 't', 'ث' => 's', 'ج' => 'j', 'چ' => 'ch', 'ح' => 'h', 'خ' => 'kh',
        'د' => 'd', 'ذ' => 'z', 'ر' => 'r', 'ز' => 'z', 'ژ' => 'zh', 'س' => 's',
        'ش' => 'sh', 'ص' => 's', 'ض' => 'z', 'ط' => 't', 'ظ' => 'z', 'ع' => 'a',
        'غ' => 'gh', 'ف' => 'f', 'ق' => 'gh', 'ک' => 'k', 'ك' => 'k', 'گ' => 'g',
        'ل' => 'l', 'م' => 'm', 'ن' => 'n', 'و' => 'o', 'ؤ' => 'o', 'ه' => 'h',
        'ۀ' => 'e', 'ة' => 'e', 'ی' => 'i', 'ي' => 'i', 'ئ' => 'i', 'ء' => '',
        '‌' => '', ' ' => '',
    ];

    $out = '';
    $len = mb_strlen($text);
    for ($i = 0; $i < $len; $i++) {
        $ch = mb_substr($text, $i, 1);
        if (isset($map[$ch])) {
            $out .= $map[$ch];
        } elseif (preg_match('/[a-zA-Z]/', $ch)) {
            $out .= strtolower($ch);
        }
    }

    return $out;
}

function latin_word(string $value): string
{
    return preg_replace('/[^a-z]/', '', strtolower(persian_to_latin($value)));
}

function username_base_from_names(string $nameEn, string $surname, string $firstName = '', string $lastName = ''): string
{
    $first = latin_word($nameEn) ?: latin_word($firstName);
    $last = latin_word($surname) ?: latin_word($lastName);
    if ($first === '' && $last === '') {
        return '';
    }
    if ($last === '') {
        return mb_substr($first, 0, 32);
    }
    if ($first === '') {
        return mb_substr($last, 0, 32);
    }

    return mb_substr($first[0] . $last, 0, 32);
}

function unique_username(PDO $pdo, string $base): string
{
    if ($base === '' || mb_strlen($base) < 3) {
        return '';
    }
    $candidate = $base;
    $n = 1;
    $stmt = $pdo->prepare('SELECT id FROM users WHERE username = ? LIMIT 1');
    while (true) {
        $stmt->execute([$candidate]);
        if (!$stmt->fetch()) {
            return $candidate;
        }
        $suffix = (string) $n++;
        $candidate = mb_substr($base, 0, max(3, 32 - mb_strlen($suffix))) . $suffix;
        if ($n > 999) {
            return '';
        }
    }
}

/** ترجمه نام فارسی به انگلیسی با سرویس آنلاین (با fallback محلی) */
function fetch_online_translation(string $text, string $from = 'fa', string $to = 'en'): ?string
{
    $url = 'https://translate.googleapis.com/translate_a/single?client=gtx&sl='
        . rawurlencode($from) . '&tl=' . rawurlencode($to) . '&dt=t&q=' . rawurlencode($text);

    $ctx = stream_context_create([
        'http' => [
            'timeout' => 2,
            'header' => "User-Agent: ManaClinic/1.0\r\n",
        ],
        'ssl' => [
            'verify_peer' => true,
            'verify_peer_name' => true,
        ],
    ]);

    $raw = @file_get_contents($url, false, $ctx);
    if ($raw === false) {
        return null;
    }

    $data = json_decode($raw, true);
    if (!is_array($data) || !isset($data[0][0][0])) {
        return null;
    }

    $translated = trim((string) $data[0][0][0]);
    return $translated !== '' ? $translated : null;
}

function clean_latin_name(string $value): string
{
    $value = trim(preg_replace('/\s+/', ' ', $value) ?? '');
    $value = preg_replace("/[^a-zA-Z\s'-]/", '', $value) ?? '';
    return trim($value);
}

function format_latin_name(string $value): string
{
    $value = clean_latin_name($value);
    if ($value === '') {
        return '';
    }

    $parts = preg_split('/\s+/', $value) ?: [];
    $parts = array_map(static function (string $part): string {
        $part = strtolower($part);
        return mb_strtoupper(mb_substr($part, 0, 1)) . mb_substr($part, 1);
    }, $parts);

    return implode(' ', $parts);
}

function transliterate_persian_name(PDO $pdo, string $name, string $part = 'first'): string
{
    static $cache = [];

    $name = trim($name);
    if ($name === '') {
        return '';
    }

    $key = $part . '|' . normalize_persian_name_part($name);
    if (isset($cache[$key])) {
        return $cache[$key];
    }

    $fromDictionary = lookup_name_transliteration($pdo, $name, $part);
    if ($fromDictionary !== null && $fromDictionary !== '') {
        $cache[$key] = $fromDictionary;
        return $fromDictionary;
    }

    $translated = fetch_online_translation($name);
    if ($translated !== null) {
        $clean = clean_latin_name($translated);
        if ($clean !== '') {
            $result = format_latin_name($clean);
            upsert_name_transliteration($pdo, $name, $result, $part, 'online', 4, false);
            $cache[$key] = $result;
            return $result;
        }
    }

    $fallback = format_latin_name(clean_latin_name(persian_to_latin($name)));
    $cache[$key] = $fallback;
    return $fallback;
}

function lookup_latin_from_registered_users(PDO $pdo, string $persianPart, string $part = 'first'): ?string
{
    $persianPart = trim($persianPart);
    if ($persianPart === '') {
        return null;
    }

    if ($part === 'first') {
        $stmt = $pdo->prepare("
            SELECT username, name FROM users
            WHERE username IS NOT NULL AND username <> ''
              AND SUBSTRING_INDEX(TRIM(name), ' ', 1) = ?
            ORDER BY created_at DESC
            LIMIT 20
        ");
    } else {
        $stmt = $pdo->prepare("
            SELECT username, name FROM users
            WHERE username IS NOT NULL AND username <> ''
              AND SUBSTRING_INDEX(TRIM(name), ' ', -1) = ?
            ORDER BY created_at DESC
            LIMIT 20
        ");
    }
    $stmt->execute([$persianPart]);
    $rows = $stmt->fetchAll();
    if (!$rows) {
        return null;
    }

    foreach ($rows as $row) {
        $username = strtolower((string) $row['username']);
        $fullName = trim((string) $row['name']);
        $nameParts = preg_split('/\s+/u', $fullName) ?: [];

        if (!preg_match('/^[a-z][a-z0-9._-]{1,31}$/', $username)) {
            continue;
        }

        if ($part === 'first') {
            if (($nameParts[0] ?? '') !== $persianPart) {
                continue;
            }
            $surnameLatin = count($nameParts) > 1 ? latin_suffix_from_username($username) : null;
            if (count($nameParts) === 1 || $surnameLatin === null || !str_ends_with($username, $surnameLatin)) {
                return format_latin_name($username);
            }
            continue;
        }

        if (($nameParts[count($nameParts) - 1] ?? '') !== $persianPart) {
            continue;
        }

        $surnameLatin = latin_suffix_from_username($username);
        if ($surnameLatin !== null) {
            return format_latin_name($surnameLatin);
        }
    }

    return null;
}

function latin_suffix_from_username(string $username): ?string
{
    if (strlen($username) < 4) {
        return null;
    }
    $suffix = substr($username, 1);
    if ($suffix === '' || !preg_match('/^[a-z][a-z0-9._-]{2,30}$/', $suffix)) {
        return null;
    }

    return $suffix;
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

function jalali_to_gregorian(int $jy, int $jm, int $jd): array
{
    $jy += 1595;
    $days = -355668 + (365 * $jy) + (intdiv($jy, 33) * 8) + intdiv((($jy % 33) + 3), 4)
        + $jd + (($jm < 7) ? (($jm - 1) * 31) : ((($jm - 7) * 30) + 186));
    $gy = 400 * intdiv($days, 146097);
    $days %= 146097;
    if ($days > 36524) {
        $gy += 100 * intdiv(--$days, 36524);
        $days %= 36524;
        if ($days >= 365) {
            $days++;
        }
    }
    $gy += 4 * intdiv($days, 1461);
    $days %= 1461;
    if ($days > 365) {
        $gy += intdiv($days - 1, 365);
        $days = ($days - 1) % 365;
    }
    $gd = $days + 1;
    $leap = (($gy % 4 === 0 && $gy % 100 !== 0) || ($gy % 400 === 0)) ? 29 : 28;
    $salA = [0, 31, $leap, 31, 30, 31, 30, 31, 31, 30, 31, 30, 31];
    $gm = 1;
    while ($gm < 13 && $gd > $salA[$gm]) {
        $gd -= $salA[$gm];
        $gm++;
    }
    return [$gy, $gm, $gd];
}

function jalali_month_length(int $jy, int $jm): int
{
    if ($jm <= 6) {
        return 31;
    }
    if ($jm <= 11) {
        return 30;
    }
    [$gy, $gm, $gd] = jalali_to_gregorian($jy, 12, 30);
    [$backY, $backM, $backD] = gregorian_to_jalali($gy, $gm, $gd);
    return ($backY === $jy && $backM === 12 && $backD === 30) ? 30 : 29;
}

function jalali_ymd(int $jy, int $jm, int $jd): string
{
    [$gy, $gm, $gd] = jalali_to_gregorian($jy, $jm, $jd);
    return sprintf('%04d-%02d-%02d', $gy, $gm, $gd);
}

/** بازه میلادی از اول ماه جاری شمسی تا آخر اسفند همان سال */
function jalali_remaining_year_gregorian_range(): array
{
    $current = jalali_current_month_meta();
    $jy = (int) ($current['year'] ?? 0);
    $jm = (int) ($current['month'] ?? 1);
    if ($jy < 1) {
        $today = date('Y-m-d');
        return ['start' => $today, 'end' => $today];
    }
    return [
        'start' => jalali_ymd($jy, $jm, 1),
        'end' => jalali_ymd($jy, 12, jalali_month_length($jy, 12)),
    ];
}
