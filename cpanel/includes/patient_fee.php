<?php
declare(strict_types=1);

/** مبلغ جلسهٔ سفارشی / دلاری برای هر مراجعه‌کننده */
function patient_fee_ensure_schema(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $cols = [
        'custom_fee_on' => "ALTER TABLE users ADD COLUMN custom_fee_on TINYINT(1) NOT NULL DEFAULT 0 AFTER preferred_doctor_id",
        'custom_fee_toman' => "ALTER TABLE users ADD COLUMN custom_fee_toman INT UNSIGNED NULL AFTER custom_fee_on",
        'foreign_fee_on' => "ALTER TABLE users ADD COLUMN foreign_fee_on TINYINT(1) NOT NULL DEFAULT 0 AFTER custom_fee_toman",
        'foreign_fee_usd' => "ALTER TABLE users ADD COLUMN foreign_fee_usd DECIMAL(12,2) NULL AFTER foreign_fee_on",
    ];
    foreach ($cols as $name => $sql) {
        try {
            $has = $pdo->query("SHOW COLUMNS FROM users LIKE " . $pdo->quote($name))->fetch();
            if (!$has) {
                $pdo->exec($sql);
            }
        } catch (Throwable $ignored) {
        }
    }
    $hasUsd = $pdo->query("SHOW COLUMNS FROM payments LIKE 'amount_usd'")->fetch();
    if (!$hasUsd) {
        try {
            $pdo->exec('ALTER TABLE payments ADD COLUMN amount_usd DECIMAL(12,2) NULL AFTER amount');
        } catch (Throwable $e) {
            if (!$pdo->query("SHOW COLUMNS FROM payments LIKE 'amount_usd'")->fetch()) {
                throw $e;
            }
        }
    }
    $done = true;
}

function patient_fee_resolve_usd(array $patient): ?string
{
    if ((int) ($patient['foreign_fee_on'] ?? 0) !== 1) {
        return null;
    }

    $amount = (string) ($patient['foreign_fee_usd'] ?? '');
    return (float) $amount > 0 ? $amount : null;
}

function appointment_payment_amount_label(array $row): string
{
    if (isset($row['amount_usd'])) {
        return to_fa_digits(rtrim(rtrim(number_format((float) $row['amount_usd'], 2, '.', ''), '0'), '.')) . ' دلار';
    }

    return isset($row['amount']) ? format_price((int) $row['amount']) : '';
}

/** منشی‌ها، شیوا گرانمایه‌پور، عطیه گارسچی و ادمین */
function patient_fee_can_edit(?array $user = null): bool
{
    $user = $user ?? (function_exists('current_user') ? current_user() : null);
    if (!$user) {
        return false;
    }
    $role = (string) ($user['role'] ?? '');
    if ($role === 'ADMIN' || $role === 'SECRETARY') {
        return true;
    }
    if (!function_exists('doctor_has_shiva_access')) {
        require_once __DIR__ . '/doctor_profile_fields.php';
    }

    return $role === 'DOCTOR' && doctor_has_shiva_access($user);
}

function patient_fee_parse_toman(string $raw): int
{
    $map = [
        '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4',
        '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
        '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
        '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
    ];
    $digits = preg_replace('/\D+/', '', strtr(trim($raw), $map)) ?? '';
    if ($digits === '') {
        return 0;
    }

    return max(0, (int) $digits);
}

/** @return null|string مبلغ دلار نرمال مثل 120 یا 120.50 */
function patient_fee_parse_usd(string $raw): ?string
{
    $map = [
        '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4',
        '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
        '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
        '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
        '٫' => '.', '،' => '.',
    ];
    $t = strtr(trim($raw), $map);
    $t = str_replace(',', '.', $t);
    $t = preg_replace('/[^\d.]/', '', $t) ?? '';
    if ($t === '' || !preg_match('/^\d+(\.\d{1,2})?$/', $t)) {
        return null;
    }
    $value = (float) $t;
    if ($value <= 0 || $value > 99999999) {
        return null;
    }

    return number_format($value, 2, '.', '');
}

/**
 * مبلغی که برای نوبت باید از کیف پول / فیش گرفته شود (تومان).
 * اگر مبلغ سفارشی ریالی روشن باشد همان؛ وگرنه قیمت درمانگر.
 */
function patient_fee_resolve_toman(array $patient, int $doctorSessionPrice): int
{
    $customOn = (int) ($patient['custom_fee_on'] ?? 0) === 1;
    $custom = (int) ($patient['custom_fee_toman'] ?? 0);
    if ($customOn && $custom > 0) {
        return $custom;
    }

    return max(0, $doctorSessionPrice);
}

/** A fee explicitly assigned to exactly one residence. */
function patient_fee_booking_confirmation(array $patient, string $patientId): string
{
    $domestic = (int) ($patient['custom_fee_on'] ?? 0) === 1;
    $foreign = (int) ($patient['foreign_fee_on'] ?? 0) === 1;
    if ($domestic === $foreign) {
        return '';
    }
    $amount = $foreign ? patient_fee_resolve_usd($patient) : patient_fee_resolve_toman($patient, 0);
    if ($amount === null || $amount <= 0 || (!$foreign && $amount > 2147483647)) {
        return '';
    }
    return hash('sha256', $patientId . '|' . ($foreign ? 'USD' : 'IR') . '|' . $amount);
}

function patient_fee_booking_values(string $residence, string $rawAmount): ?array
{
    $amount = normalize_input($rawAmount);
    if ($residence === 'iran' && preg_match('/^(?:[0-9]+|[0-9]{1,3}(?:[,٬][0-9]{3})+)$/uD', $amount)) {
        $toman = patient_fee_parse_toman($amount);
        // The amount must also fit the signed INT payment and wallet columns.
        return $toman > 0 && $toman <= 2147483647 ? [1, $toman, 0, null] : null;
    }
    if ($residence === 'abroad' && preg_match('/^[0-9]+(?:[.,٫،][0-9]{1,2})?$/uD', $amount)) {
        $usd = patient_fee_parse_usd($amount);
        return $usd !== null ? [0, null, 1, $usd] : null;
    }
    return null;
}

function patient_fee_load(PDO $pdo, string $patientId, bool $forUpdate = false): array
{
    patient_fee_ensure_schema($pdo);
    if ($patientId === '') {
        return [
            'custom_fee_on' => 0,
            'custom_fee_toman' => null,
            'foreign_fee_on' => 0,
            'foreign_fee_usd' => null,
        ];
    }
    $stmt = $pdo->prepare('SELECT custom_fee_on, custom_fee_toman, foreign_fee_on, foreign_fee_usd FROM users WHERE id=? LIMIT 1' . ($forUpdate ? ' FOR UPDATE' : ''));
    $stmt->execute([$patientId]);
    $row = $stmt->fetch();
    if (!$row) {
        return [
            'custom_fee_on' => 0,
            'custom_fee_toman' => null,
            'foreign_fee_on' => 0,
            'foreign_fee_usd' => null,
        ];
    }

    return [
        'custom_fee_on' => (int) ($row['custom_fee_on'] ?? 0),
        'custom_fee_toman' => $row['custom_fee_toman'] !== null ? (int) $row['custom_fee_toman'] : null,
        'foreign_fee_on' => (int) ($row['foreign_fee_on'] ?? 0),
        'foreign_fee_usd' => $row['foreign_fee_usd'] !== null ? (string) $row['foreign_fee_usd'] : null,
    ];
}

/** فیلتر نمایش: default | custom | foreign */
function patient_fee_filter_key(array $row): string
{
    if ((int) ($row['foreign_fee_on'] ?? 0) === 1) {
        return 'foreign';
    }
    if ((int) ($row['custom_fee_on'] ?? 0) === 1) {
        return 'custom';
    }

    return 'default';
}
