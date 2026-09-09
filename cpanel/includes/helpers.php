<?php
declare(strict_types=1);

function e(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function password_min_length(): int
{
    return 7;
}

function password_field_html(string $id, string $name, array $opts = []): string
{
    $label = (string) ($opts['label'] ?? 'رمز عبور');
    $autocomplete = (string) ($opts['autocomplete'] ?? 'current-password');
    $required = !array_key_exists('required', $opts) || !empty($opts['required']);
    $min = array_key_exists('minlength', $opts) ? max(0, (int) $opts['minlength']) : 0;
    $value = (string) ($opts['value'] ?? '');
    $pair = (string) ($opts['pair'] ?? '');
    $isConfirm = !empty($opts['confirm']);
    $showRules = array_key_exists('rules', $opts) ? !empty($opts['rules']) : (!$isConfirm && $min > 0);
    $placeholder = (string) ($opts['placeholder'] ?? ($isConfirm ? 'تکرار رمز' : ($min > 0 ? 'حداقل ' . to_fa_digits((string) $min) . ' کاراکتر' : '')));

    ob_start();
    ?>
    <div class="password-field" data-password-field>
      <label class="label" for="<?= e($id) ?>"><?= e($label) ?></label>
      <div class="password-field-box">
        <input
          class="input"
          id="<?= e($id) ?>"
          name="<?= e($name) ?>"
          type="password"
          <?= $required ? 'required' : '' ?>
          <?= $min > 0 ? 'minlength="' . $min . '"' : '' ?>
          dir="ltr"
          lang="en"
          autocomplete="<?= e($autocomplete) ?>"
          placeholder="<?= e($placeholder) ?>"
          value="<?= e($value) ?>"
          data-password-input
          <?= $min > 0 ? 'data-password-min="' . $min . '"' : '' ?>
          <?= $isConfirm ? 'data-password-confirm="1"' : '' ?>
          <?= $pair !== '' ? 'data-password-pair="' . e($pair) . '"' : '' ?>
        >
        <button type="button" class="password-toggle" data-password-toggle aria-label="نمایش رمز" title="نمایش رمز">
          <svg class="password-toggle-show" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M2.4 12S6 5.8 12 5.8 21.6 12 21.6 12 18 18.2 12 18.2 2.4 12 2.4 12Z"/><circle cx="12" cy="12" r="3.1"/></svg>
          <svg class="password-toggle-hide" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M3 3l18 18M10.6 10.6A3.1 3.1 0 0 0 12 15.1a3.1 3.1 0 0 0 3.1-3.1M6.5 6.7C4.4 8.2 2.8 10.4 2.4 12c0 0 3.6 6.2 9.6 6.2 1.7 0 3.2-.4 4.5-1M17.5 8.2C19.4 9.6 20.8 11.3 21.6 12c0 0-1.3 2.2-3.6 4"/></svg>
        </button>
      </div>
      <?php if ($showRules && $min > 0): ?>
        <p class="password-rules muted">حداقل <?= e(to_fa_digits((string) $min)) ?> کاراکتر، با حروف و اعداد انگلیسی.</p>
      <?php endif; ?>
      <p class="password-lang-warn" data-password-lang hidden>زبان صفحه‌کلید را انگلیسی کنید.</p>
      <?php if ($isConfirm): ?>
        <p class="password-match-warn" data-password-match hidden>تکرار رمز با رمز اول یکسان نیست.</p>
      <?php endif; ?>
    </div>
    <?php

    return (string) ob_get_clean();
}

function redirect(string $path): never
{
    global $config, $base;
    $prefix = ($base && $base !== '/') ? $base : '';
    if (preg_match('/[\r\n]/', $path) || str_starts_with($path, '//')) {
        $path = '/';
    }
    if (str_starts_with($path, 'http://') || str_starts_with($path, 'https://')) {
        $app = rtrim((string) ($config['app_url'] ?? ''), '/');
        if ($app !== '' && ($path === $app || str_starts_with($path, $app . '/'))) {
            header('Location: ' . $path);
            exit;
        }
        $path = '/';
    }
    header('Location: ' . $prefix . $path);
    exit;
}

function url(string $path = '/'): string
{
    global $base;
    $prefix = ($base && $base !== '/') ? (string) $base : '';
    if ($prefix !== '' && (str_contains($prefix, '?') || str_contains($prefix, '#'))) {
        $prefix = '';
    }
    if ($path === '/') {
        return $prefix . '/' ?: '/';
    }
    return $prefix . '/' . ltrim($path, '/');
}

function format_price(int $amount): string
{
    return to_fa_digits(number_format($amount)) . ' تومان';
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

function appointment_status_label(string $status, ?string $cancelReason = null): string
{
    if ($status === 'CANCELLED' && $cancelReason === 'patient') {
        return 'لغو توسط مراجع';
    }

    return match ($status) {
        'PENDING_PAYMENT' => 'در انتظار پرداخت',
        'CONFIRMED' => 'تأیید شده',
        'CANCELLED' => 'لغو شده',
        'COMPLETED' => 'انجام شده',
        default => $status,
    };
}

function appointment_row_status_label(array $row): string
{
    return appointment_status_label((string) ($row['status'] ?? ''), isset($row['cancel_reason']) ? (string) $row['cancel_reason'] : null);
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
        'PATIENT' => 'مراجعه‌کننده',
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
        return to_fa_digits($datetime);
    }
    $parts = explode('-', date('Y-m-d', $ts));
    if (count($parts) !== 3) {
        return to_fa_digits(date('Y/m/d H:i', $ts));
    }
    [$gy, $gm, $gd] = array_map('intval', $parts);
    [$jy, $jm, $jd] = gregorian_to_jalali($gy, $gm, $gd);
    return to_fa_digits(sprintf('%04d/%02d/%02d %s', $jy, $jm, $jd, date('H:i', $ts)));
}

function format_fa_time(string $datetime): string
{
    $ts = strtotime($datetime);
    if (!$ts) {
        return to_fa_digits($datetime);
    }

    return to_fa_digits(date('H:i', $ts));
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

function csrf_token(): string
{
    if (empty($_SESSION['_csrf']) || !is_string($_SESSION['_csrf'])) {
        $_SESSION['_csrf'] = bin2hex(random_bytes(16));
    }
    return $_SESSION['_csrf'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="_csrf" value="' . e(csrf_token()) . '">';
}

function csrf_request_token(): string
{
    $fromPost = (string) ($_POST['_csrf'] ?? '');
    if ($fromPost !== '') {
        return $fromPost;
    }

    return trim((string) ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''));
}

function request_expects_json(): bool
{
    $accept = strtolower((string) ($_SERVER['HTTP_ACCEPT'] ?? ''));
    $content = strtolower((string) ($_SERVER['CONTENT_TYPE'] ?? ''));
    $xhr = strtolower((string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? ''));
    if (str_contains($accept, 'application/json') || str_contains($content, 'application/json') || $xhr === 'xmlhttprequest') {
        return true;
    }
    $path = (string) ($GLOBALS['path'] ?? '');
    return in_array($path, [
        '/book',
        '/assistant/chat',
        '/assistant/send',
        '/secretary/heartbeat',
        '/dashboard/pay',
        '/enroll-workshop',
        '/pay-workshop',
        '/cancel-enrollment',
        '/cancel-appointment',
        '/video-signal',
    ], true);
}

function csrf_verify(): void
{
    $sent = csrf_request_token();
    if ($sent === '' || !hash_equals(csrf_token(), $sent)) {
        if (request_expects_json()) {
            http_response_code(403);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['error' => 'نشست منقضی شد. صفحه را تازه کنید و دوباره تلاش کنید.'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        flash_set('error', 'نشست منقضی شد. صفحه را تازه کنید و دوباره تلاش کنید.');
        $back = $_SERVER['HTTP_REFERER'] ?? '';
        $path = parse_url($back, PHP_URL_PATH);
        redirect(is_string($path) && str_starts_with($path, '/') && !str_starts_with($path, '//') ? $path : '/');
    }
}

function request_is_https(array $config = []): bool
{
    if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
        return true;
    }
    if ((string) ($_SERVER['SERVER_PORT'] ?? '') === '443') {
        return true;
    }
    $app = (string) ($config['app_url'] ?? '');
    if ($app === '' && isset($GLOBALS['config']) && is_array($GLOBALS['config'])) {
        $app = (string) ($GLOBALS['config']['app_url'] ?? '');
    }
    if (str_starts_with($app, 'https://')) {
        return true;
    }
    return strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
}

/** فقط مسیر داخلی؛ جلوگیری از //evil.com */
function safe_next_path(?string $next): ?string
{
    $next = trim((string) $next);
    if ($next === '' || !str_starts_with($next, '/') || str_starts_with($next, '//') || str_contains($next, '\\')) {
        return null;
    }
    if (preg_match('/[\r\n]/', $next)) {
        return null;
    }
    return $next;
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

function normalize_phone(string $phone): string
{
    $phone = normalize_input($phone);
    $phone = preg_replace('/[\s\-\.\(\)]+/', '', $phone) ?? $phone;

    return $phone;
}

/** موبایل ایران یا شماره بین‌المللی (بدون اجبار به ۰۹) */
function is_valid_phone(string $phone): bool
{
    $phone = normalize_phone($phone);
    if ($phone === '') {
        return false;
    }
    if (preg_match('/^\+[1-9][0-9]{7,14}$/', $phone)) {
        return true;
    }
    if (preg_match('/^00[1-9][0-9]{7,14}$/', $phone)) {
        return true;
    }

    return (bool) preg_match('/^[0-9]{8,15}$/', $phone);
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

/** فقط ماه‌هایی که آیتم دارند + گروه‌بندی روز داخل ماه */
function attach_jalali_days_to_month_groups(array $monthGroups, string $datetimeKey = 'starts_at'): array
{
    foreach ($monthGroups as $id => $bucket) {
        $items = $bucket['items'] ?? [];
        if (!$items) {
            unset($monthGroups[$id]);
            continue;
        }
        usort($items, static fn(array $a, array $b): int => strcmp((string) ($a[$datetimeKey] ?? ''), (string) ($b[$datetimeKey] ?? '')));
        $days = [];
        foreach ($items as $row) {
            $dt = (string) ($row[$datetimeKey] ?? '');
            $ts = strtotime($dt) ?: 0;
            $gkey = $ts ? date('Y-m-d', $ts) : 'other';
            $day = jalali_day_parts($dt);
            if (!isset($days[$gkey])) {
                $days[$gkey] = [
                    'label' => $day['label'] ?? ($dt !== '' ? format_fa_datetime($dt) : 'بدون تاریخ'),
                    'items' => [],
                ];
            }
            $days[$gkey]['items'][] = $row;
        }
        ksort($days);
        $monthGroups[$id]['items'] = $items;
        $monthGroups[$id]['days'] = $days;
    }
    return $monthGroups;
}

function appointment_counts_as_visit(array $row): bool
{
    return (string) ($row['status'] ?? '') !== 'CANCELLED';
}

/** تعداد مراجعه‌کنندگان یکتا (بدون نوبت لغوشده) */
function appointment_unique_patient_count(array $items): int
{
    $ids = [];
    foreach ($items as $row) {
        if (!is_array($row) || !appointment_counts_as_visit($row)) {
            continue;
        }
        $pid = (string) ($row['patient_id'] ?? '');
        if ($pid !== '') {
            $ids[$pid] = true;
        }
    }
    return count($ids);
}

/** @return array<string, string> patient_id => name */
function appointment_unique_patient_names(array $items): array
{
    $names = [];
    foreach ($items as $row) {
        if (!is_array($row) || !appointment_counts_as_visit($row)) {
            continue;
        }
        $pid = (string) ($row['patient_id'] ?? '');
        if ($pid === '' || isset($names[$pid])) {
            continue;
        }
        $name = trim((string) ($row['patient_name'] ?? ''));
        $names[$pid] = $name !== '' ? $name : 'مراجعه‌کننده';
    }
    return $names;
}

function jalali_shift_month(int $jy, int $jm, int $delta): array
{
    $jm += $delta;
    while ($jm < 1) {
        $jm += 12;
        $jy--;
    }
    while ($jm > 12) {
        $jm -= 12;
        $jy++;
    }
    return [$jy, $jm];
}

/** @param array<int, array<string, mixed>> $items */
function appointments_in_jalali_month(array $items, int $jy, int $jm): array
{
    $out = [];
    foreach ($items as $row) {
        if (!is_array($row)) {
            continue;
        }
        $meta = jalali_month_meta_from_datetime((string) ($row['starts_at'] ?? ''));
        if ($meta && (int) ($meta['year'] ?? 0) === $jy && (int) ($meta['month'] ?? 0) === $jm) {
            $out[] = $row;
        }
    }
    return $out;
}

/**
 * نوبت‌ها بر اساس سال، ماه و روز شمسی.
 * prefer=current ماه جاری؛ latest آخرین ماهی که نوبت دارد (برای انجام‌شده‌ها).
 *
 * @param array<int, array<string, mixed>> $appointments
 * @return array{years: array<string, array<string, mixed>>, default_year_id: string, default_month_id: string, empty: bool}
 */
function group_appointments_by_jalali_ymd(array $appointments, string $prefix = 'ymd', string $prefer = 'current'): array
{
    $prefix = preg_replace('/[^a-zA-Z0-9_-]/', '', $prefix) ?: 'ymd';
    $today = date('Y-m-d');
    $current = jalali_current_month_meta();
    $currentJy = (int) ($current['year'] ?? 0);
    $currentJm = (int) ($current['month'] ?? 1);
    $years = [];

    foreach ($appointments as $row) {
        if (!is_array($row)) {
            continue;
        }
        $meta = jalali_month_meta_from_datetime((string) ($row['starts_at'] ?? ''));
        if (!$meta) {
            continue;
        }
        $jy = (int) $meta['year'];
        $jm = (int) $meta['month'];
        $yearId = $prefix . '-y-' . $jy;
        $monthId = $prefix . '-m-' . sprintf('%04d-%02d', $jy, $jm);
        $ts = strtotime((string) ($row['starts_at'] ?? '')) ?: 0;
        $gkey = $ts ? date('Y-m-d', $ts) : 'other';
        $dayParts = jalali_day_parts((string) ($row['starts_at'] ?? ''));

        if (!isset($years[$yearId])) {
            $years[$yearId] = [
                'id' => $yearId,
                'year' => $jy,
                'label' => to_fa_digits((string) $jy),
                'class' => $jy % 2 === 0 ? 'binder-tab-online' : 'binder-tab-in-person',
                'tone' => $jy % 2 === 0 ? 'online' : 'in-person',
                'items' => [],
                'months' => [],
            ];
        }
        $years[$yearId]['items'][] = $row;

        if (!isset($years[$yearId]['months'][$monthId])) {
            $years[$yearId]['months'][$monthId] = array_merge($meta, [
                'id' => $monthId,
                'all_id' => $monthId . '-all',
                'items' => [],
                'days' => [],
            ]);
        }
        $years[$yearId]['months'][$monthId]['items'][] = $row;

        $dayId = $prefix . '-d-' . $gkey;
        if (!isset($years[$yearId]['months'][$monthId]['days'][$dayId])) {
            $years[$yearId]['months'][$monthId]['days'][$dayId] = [
                'id' => $dayId,
                'date' => $gkey,
                'sort' => $gkey,
                'label' => (string) ($dayParts['label'] ?? ($gkey !== 'other' ? to_jalali_label($gkey) : 'بدون تاریخ')),
                'tab_label' => $gkey === $today ? 'امروز' : (string) ($dayParts['day_fa'] ?? to_fa_digits((string) ($dayParts['day'] ?? ''))),
                'is_today' => $gkey === $today,
                'items' => [],
            ];
        }
        $years[$yearId]['months'][$monthId]['days'][$dayId]['items'][] = $row;
    }

    foreach ($years as $yearId => $year) {
        $months = $year['months'];
        uasort($months, static fn(array $a, array $b): int => ((int) ($a['sort'] ?? 0)) <=> ((int) ($b['sort'] ?? 0)));
        foreach ($months as $monthId => $month) {
            $days = $month['days'] ?? [];
            uasort($days, static fn(array $a, array $b): int => strcmp((string) ($a['sort'] ?? ''), (string) ($b['sort'] ?? '')));
            foreach ($days as $dayId => $day) {
                $dayItems = $day['items'] ?? [];
                usort($dayItems, static fn(array $a, array $b): int => strcmp((string) ($a['starts_at'] ?? ''), (string) ($b['starts_at'] ?? '')));
                $days[$dayId]['items'] = $dayItems;
                $days[$dayId]['count'] = count($dayItems);
                $days[$dayId]['people'] = appointment_unique_patient_count($dayItems);
            }
            $monthItems = $month['items'] ?? [];
            $months[$monthId]['days'] = $days;
            $months[$monthId]['count'] = count($monthItems);
            $months[$monthId]['people'] = appointment_unique_patient_count($monthItems);
            $months[$monthId]['people_names'] = appointment_unique_patient_names($monthItems);
        }
        $years[$yearId]['months'] = $months;
        $years[$yearId]['count'] = count($year['items'] ?? []);
        $years[$yearId]['people'] = appointment_unique_patient_count($year['items'] ?? []);
    }

    uasort($years, static fn(array $a, array $b): int => ((int) ($b['year'] ?? 0)) <=> ((int) ($a['year'] ?? 0)));

    $currentYearId = $prefix . '-y-' . $currentJy;
    $currentMonthId = $prefix . '-m-' . sprintf('%04d-%02d', $currentJy, $currentJm);
    $defaultYearId = '';
    $defaultMonthId = '';

    if ($prefer === 'latest') {
        $defaultYearId = (string) (array_key_first($years) ?? '');
        $yearMonths = is_array($years[$defaultYearId]['months'] ?? null) ? $years[$defaultYearId]['months'] : [];
        if ($yearMonths) {
            $monthKeys = array_keys($yearMonths);
            $defaultMonthId = (string) end($monthKeys);
        }
    } else {
        if (isset($years[$currentYearId])) {
            $defaultYearId = $currentYearId;
        } else {
            $defaultYearId = (string) (array_key_first($years) ?? '');
        }
        $yearMonths = is_array($years[$defaultYearId]['months'] ?? null) ? $years[$defaultYearId]['months'] : [];
        if (isset($yearMonths[$currentMonthId])) {
            $defaultMonthId = $currentMonthId;
        } else {
            $defaultMonthId = (string) (array_key_first($yearMonths) ?? '');
        }
    }

    return [
        'years' => $years,
        'default_year_id' => $defaultYearId,
        'default_month_id' => $defaultMonthId,
        'empty' => $years === [],
        'prefix' => $prefix,
        'current_year' => $currentJy,
        'current_month' => $currentJm,
        'prefer' => $prefer,
    ];
}

/** روز و ساعت شمسی یک تاریخ میلادی — برای کارت جلسه */
function jalali_day_parts(string $datetime): ?array
{
    $ts = strtotime($datetime);
    if (!$ts) {
        return null;
    }
    [$jy, $jm, $jd] = gregorian_to_jalali((int) date('Y', $ts), (int) date('n', $ts), (int) date('j', $ts));
    $months = jalali_month_names();
    $month = $months[$jm] ?? (string) $jm;
    return [
        'day' => $jd,
        'day_fa' => to_fa_digits((string) $jd),
        'month' => $month,
        'year' => $jy,
        'time_fa' => to_fa_digits(date('H:i', $ts)),
        'label' => to_fa_digits((string) $jd) . ' ' . $month,
    ];
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
