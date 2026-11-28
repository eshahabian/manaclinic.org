<?php
declare(strict_types=1);

/** مبلغ جلسهٔ سفارشی یا ارزی برای هر مراجعه‌کننده */
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
        'fee_abroad_toman' => 'ALTER TABLE users ADD COLUMN fee_abroad_toman TINYINT(1) NOT NULL DEFAULT 0',
        'foreign_fee_gbp' => 'ALTER TABLE users ADD COLUMN foreign_fee_gbp DECIMAL(12,2) NULL',
    ];
    $paymentColumns = [
        'amount_usd' => 'ALTER TABLE payments ADD COLUMN amount_usd DECIMAL(12,2) NULL',
        'amount_gbp' => 'ALTER TABLE payments ADD COLUMN amount_gbp DECIMAL(12,2) NULL',
    ];
    foreach (['users' => $cols, 'payments' => $paymentColumns] as $table => $columns) {
        foreach ($columns as $name => $sql) {
            $check = "SHOW COLUMNS FROM $table LIKE " . $pdo->quote($name);
            if (!$pdo->query($check)->fetch()) {
                try {
                    $pdo->exec($sql);
                } catch (Throwable $e) {
                    if (!$pdo->query($check)->fetch()) {
                        throw $e;
                    }
                }
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
    foreach (['amount_gbp' => 'پوند', 'amount_usd' => 'دلار'] as $column => $unit) {
        if (isset($row[$column])) {
            $amount = rtrim(rtrim(number_format((float) $row[$column], 2, '.', ''), '0'), '.');
            return to_fa_digits($amount) . ' ' . $unit;
        }
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

function patient_fee_resolve_gbp(array $patient): ?string
{
    $amount = (string) ($patient['foreign_fee_gbp'] ?? '');
    return (int) ($patient['foreign_fee_on'] ?? 0) === 1 && (float) $amount > 0 ? $amount : null;
}

function patient_fee_options(): array
{
    return [
        'iran' => 'داخل ایران (تومان)',
        'abroad_toman' => 'خارج از ایران (تومان)',
        'abroad' => 'خارج از ایران (دلار)',
        'abroad_gbp' => 'خارج از ایران (پوند)',
    ];
}

/** @return array{0:string,1:string} */
function patient_fee_booking_details(array $patient): array
{
    $domestic = (int) ($patient['custom_fee_on'] ?? 0) === 1;
    $foreign = (int) ($patient['foreign_fee_on'] ?? 0) === 1;
    if ($domestic === $foreign) {
        return ['', ''];
    }
    if ($domestic) {
        $amount = patient_fee_resolve_toman($patient, 0);
        if ($amount > 0 && $amount <= 2147483647) {
            return [empty($patient['fee_abroad_toman']) ? 'iran' : 'abroad_toman', (string) $amount];
        }
        return ['', ''];
    }
    $usd = patient_fee_resolve_usd($patient);
    $gbp = patient_fee_resolve_gbp($patient);
    if (($usd === null) === ($gbp === null)) {
        return ['', ''];
    }
    return $gbp !== null ? ['abroad_gbp', $gbp] : ['abroad', $usd];
}

function patient_fee_booking_confirmation(array $patient, string $patientId): string
{
    [$residence, $amount] = patient_fee_booking_details($patient);
    return $residence !== '' ? hash('sha256', $patientId . '|' . $residence . '|' . $amount) : '';
}

function patient_fee_booking_values(string $residence, string $rawAmount): ?array
{
    $amount = normalize_input($rawAmount);
    if (in_array($residence, ['iran', 'abroad_toman'], true) && preg_match('/^(?:[0-9]+|[0-9]{1,3}(?:[,٬][0-9]{3})+)$/uD', $amount)) {
        $toman = patient_fee_parse_toman($amount);
        // Payments and wallet entries use signed INT columns.
        return $toman > 0 && $toman <= 2147483647 ? [1, $toman, 0, null, (int) ($residence === 'abroad_toman'), null] : null;
    }
    if (in_array($residence, ['abroad', 'abroad_gbp'], true) && preg_match('/^[0-9]+(?:[.,٫،][0-9]{1,2})?$/uD', $amount)) {
        $foreign = patient_fee_parse_usd($amount);
        if ($foreign !== null) {
            return [0, null, 1, $residence === 'abroad' ? $foreign : null, 0, $residence === 'abroad_gbp' ? $foreign : null];
        }
    }
    return null;
}

function patient_fee_load(PDO $pdo, string $patientId, bool $forUpdate = false): array
{
    patient_fee_ensure_schema($pdo);
    $stmt = $pdo->prepare('SELECT custom_fee_on, custom_fee_toman, foreign_fee_on, foreign_fee_usd, fee_abroad_toman, foreign_fee_gbp FROM users WHERE id=? LIMIT 1' . ($forUpdate ? ' FOR UPDATE' : ''));
    $stmt->execute([$patientId]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
}
