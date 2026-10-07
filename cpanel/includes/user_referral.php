<?php
declare(strict_types=1);

function ensure_user_referral_schema(PDO $pdo): void
{
    static $ready = false;
    if ($ready) {
        return;
    }
    $ready = true;
    try {
        $has = $pdo->query("SHOW COLUMNS FROM users LIKE 'referral_source'")->fetch();
        if (!$has) {
            $pdo->exec("ALTER TABLE users ADD COLUMN referral_source VARCHAR(16) NULL AFTER preferred_doctor_id");
        }
    } catch (Throwable $ignored) {
    }
    try {
        $has = $pdo->query("SHOW COLUMNS FROM payments LIKE 'clinic_share_amount'")->fetch();
        if (!$has) {
            $pdo->exec('ALTER TABLE payments ADD COLUMN clinic_share_amount INT NULL AFTER amount');
        }
    } catch (Throwable $ignored) {
    }
}

function user_referral_normalize(string $source): ?string
{
    $source = strtolower(trim($source));

    return in_array($source, ['clinic', 'therapist'], true) ? $source : null;
}

function user_referral_label(?string $source): string
{
    return match (user_referral_normalize((string) $source)) {
        'clinic' => 'ارجاع از سمت کلینیک',
        'therapist' => 'ارجاع از سمت درمانگر',
        default => '',
    };
}

function user_referral_share_amount(int $paidAmount, ?string $source): ?int
{
    if ($paidAmount <= 0) {
        return null;
    }
    $source = user_referral_normalize((string) $source);
    if ($source === null) {
        return null;
    }
    $rate = $source === 'clinic' ? 40 : 30;

    return (int) round($paidAmount * $rate / 100);
}

function user_referral_save(PDO $pdo, string $userId, string $source): bool
{
    $source = user_referral_normalize($source);
    if ($userId === '' || $source === null) {
        return false;
    }
    ensure_user_referral_schema($pdo);
    $pdo->prepare('UPDATE users SET referral_source=? WHERE id=?')->execute([$source, $userId]);
    user_referral_sync_payments($pdo, $userId);

    return true;
}

function user_referral_sync_payments(PDO $pdo, string $userId): void
{
    ensure_user_referral_schema($pdo);
    $stmt = $pdo->prepare('SELECT referral_source FROM users WHERE id=? LIMIT 1');
    $stmt->execute([$userId]);
    $source = user_referral_normalize((string) $stmt->fetchColumn());
    $rows = $pdo->prepare('
      SELECT p.id, p.amount
      FROM payments p
      JOIN appointments a ON a.id = p.appointment_id
      WHERE a.patient_id = ?
    ');
    $rows->execute([$userId]);
    $update = $pdo->prepare('UPDATE payments SET clinic_share_amount=? WHERE id=?');
    foreach ($rows->fetchAll() as $row) {
        $share = user_referral_share_amount((int) ($row['amount'] ?? 0), $source);
        $update->execute([$share, (string) $row['id']]);
    }
}

function user_referral_stamp_payment(PDO $pdo, string $paymentId, string $patientId, int $amount): void
{
    ensure_user_referral_schema($pdo);
    $stmt = $pdo->prepare('SELECT referral_source FROM users WHERE id=? LIMIT 1');
    $stmt->execute([$patientId]);
    $share = user_referral_share_amount($amount, (string) $stmt->fetchColumn());
    $pdo->prepare('UPDATE payments SET clinic_share_amount=? WHERE id=?')->execute([$share, $paymentId]);
}

function user_referral_options_html(string $name, string $id, ?string $current, bool $required = false): string
{
    $current = user_referral_normalize((string) $current) ?? '';
    $req = $required ? ' required' : '';
    $html = '<select class="input" name="' . e($name) . '" id="' . e($id) . '"' . $req . '>';
    $html .= '<option value="">انتخاب معرف</option>';
    foreach (['clinic', 'therapist'] as $key) {
        $selected = $current === $key ? ' selected' : '';
        $html .= '<option value="' . e($key) . '"' . $selected . '>' . e(user_referral_label($key)) . '</option>';
    }
    $html .= '</select>';

    return $html;
}
