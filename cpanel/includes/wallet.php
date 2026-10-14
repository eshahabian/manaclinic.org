<?php
declare(strict_types=1);

function ensure_wallet_schema(PDO $pdo): void
{
    static $ready = false;
    if ($ready) {
        return;
    }
    if ($pdo->inTransaction()) {
        return;
    }
    $pdo->exec("
      CREATE TABLE IF NOT EXISTS wallets (
        id VARCHAR(32) PRIMARY KEY,
        user_id VARCHAR(32) NOT NULL UNIQUE,
        balance INT NOT NULL DEFAULT 0,
        held_balance INT NOT NULL DEFAULT 0,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        CONSTRAINT fk_wallet_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

      CREATE TABLE IF NOT EXISTS wallet_transactions (
        id VARCHAR(32) PRIMARY KEY,
        wallet_id VARCHAR(32) NOT NULL,
        kind ENUM('TOPUP','PAYMENT','REFUND','HOLD','RELEASE','SETTLE','ADJUSTMENT') NOT NULL,
        amount INT NOT NULL,
        balance_after INT NOT NULL,
        held_after INT NOT NULL DEFAULT 0,
        reference_type VARCHAR(32) NULL,
        reference_id VARCHAR(32) NULL,
        description VARCHAR(255) NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_wallet_tx (wallet_id, created_at),
        CONSTRAINT fk_wtx_wallet FOREIGN KEY (wallet_id) REFERENCES wallets(id) ON DELETE CASCADE
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");
    $ready = true;
}

function ensure_wallet(PDO $pdo, string $userId): array
{
    ensure_wallet_schema($pdo);
    $stmt = $pdo->prepare('SELECT * FROM wallets WHERE user_id = ? LIMIT 1');
    $stmt->execute([$userId]);
    $wallet = $stmt->fetch();
    if ($wallet) {
        return $wallet;
    }
    $id = cuid();
    $pdo->prepare('INSERT INTO wallets (id, user_id) VALUES (?, ?)')->execute([$id, $userId]);
    $stmt->execute([$userId]);
    return (array) $stmt->fetch();
}

function wallet_for_update(PDO $pdo, string $userId): array
{
    $stmt = $pdo->prepare('SELECT * FROM wallets WHERE user_id = ? FOR UPDATE');
    $stmt->execute([$userId]);
    $wallet = $stmt->fetch();
    if (!$wallet) {
        ensure_wallet($pdo, $userId);
        $stmt->execute([$userId]);
        $wallet = $stmt->fetch();
    }
    return (array) $wallet;
}

function wallet_log_tx(
    PDO $pdo,
    string $walletId,
    string $kind,
    int $amount,
    int $balanceAfter,
    int $heldAfter,
    ?string $referenceType,
    ?string $referenceId,
    ?string $description
): void {
    $pdo->prepare('
      INSERT INTO wallet_transactions
        (id, wallet_id, kind, amount, balance_after, held_after, reference_type, reference_id, description)
      VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
    ')->execute([
        cuid(),
        $walletId,
        $kind,
        $amount,
        $balanceAfter,
        $heldAfter,
        $referenceType,
        $referenceId,
        $description,
    ]);
}

/**
 * کسر از کیف پول حتی اگر موجودی کافی نباشد (بدهی؛ موجودی منفی می‌شود).
 */
function wallet_debit_balance_allow_negative(
    PDO $pdo,
    string $userId,
    int $amount,
    string $referenceType,
    string $referenceId,
    string $description
): int {
    if ($amount <= 0) {
        return 0;
    }
    $wallet = wallet_for_update($pdo, $userId);
    $balanceAfter = (int) $wallet['balance'] - $amount;
    $heldAfter = (int) $wallet['held_balance'];
    $pdo->prepare('UPDATE wallets SET balance = ? WHERE id = ?')->execute([$balanceAfter, $wallet['id']]);
    wallet_log_tx($pdo, $wallet['id'], 'PAYMENT', -$amount, $balanceAfter, $heldAfter, $referenceType, $referenceId, $description);

    return $balanceAfter;
}

/** پرداخت مراجعه‌کننده — از موجودی قابل برداشت */
function wallet_debit_balance(
    PDO $pdo,
    string $userId,
    int $amount,
    string $referenceType,
    string $referenceId,
    string $description
): void {
    if ($amount <= 0) {
        return;
    }
    $wallet = wallet_for_update($pdo, $userId);
    if ((int) $wallet['balance'] < $amount) {
        throw new RuntimeException('موجودی کیف پول کافی نیست.');
    }
    $balanceAfter = (int) $wallet['balance'] - $amount;
    $heldAfter = (int) $wallet['held_balance'];
    $pdo->prepare('UPDATE wallets SET balance = ? WHERE id = ?')->execute([$balanceAfter, $wallet['id']]);
    wallet_log_tx($pdo, $wallet['id'], 'PAYMENT', -$amount, $balanceAfter, $heldAfter, $referenceType, $referenceId, $description);
}

/** واریز به موجودی قابل برداشت (مثلاً بازگشت وجه) */
function wallet_credit_balance(
    PDO $pdo,
    string $userId,
    int $amount,
    string $kind,
    string $referenceType,
    string $referenceId,
    string $description
): void {
    if ($amount <= 0) {
        return;
    }
    $wallet = wallet_for_update($pdo, $userId);
    $balanceAfter = (int) $wallet['balance'] + $amount;
    $heldAfter = (int) $wallet['held_balance'];
    $pdo->prepare('UPDATE wallets SET balance = ? WHERE id = ?')->execute([$balanceAfter, $wallet['id']]);
    wallet_log_tx($pdo, $wallet['id'], $kind, $amount, $balanceAfter, $heldAfter, $referenceType, $referenceId, $description);
}

/** پرداخت آنلاین — واریز به کیف پول امانی دکتر */
function wallet_hold_for_doctor(
    PDO $pdo,
    string $doctorUserId,
    int $amount,
    string $referenceType,
    string $referenceId,
    string $description
): void {
    if ($amount <= 0) {
        return;
    }
    $wallet = wallet_for_update($pdo, $doctorUserId);
    $balanceAfter = (int) $wallet['balance'];
    $heldAfter = (int) $wallet['held_balance'] + $amount;
    $pdo->prepare('UPDATE wallets SET held_balance = ? WHERE id = ?')->execute([$heldAfter, $wallet['id']]);
    wallet_log_tx($pdo, $wallet['id'], 'HOLD', $amount, $balanceAfter, $heldAfter, $referenceType, $referenceId, $description);
}

/** لغو قبل از ۲۴ ساعت — از امانی دکتر به کیف پول مراجعه‌کننده */
function wallet_refund_from_doctor_hold(
    PDO $pdo,
    string $doctorUserId,
    string $patientUserId,
    int $amount,
    string $referenceId,
    string $description
): void {
    if ($amount <= 0) {
        return;
    }
    $doctorWallet = wallet_for_update($pdo, $doctorUserId);
    if ((int) $doctorWallet['held_balance'] < $amount) {
        throw new RuntimeException('موجودی امانی دکتر کافی نیست.');
    }
    $doctorHeldAfter = (int) $doctorWallet['held_balance'] - $amount;
    $pdo->prepare('UPDATE wallets SET held_balance = ? WHERE id = ?')->execute([$doctorHeldAfter, $doctorWallet['id']]);
    wallet_log_tx(
        $pdo,
        $doctorWallet['id'],
        'RELEASE',
        -$amount,
        (int) $doctorWallet['balance'],
        $doctorHeldAfter,
        'workshop_enrollment',
        $referenceId,
        $description
    );

    wallet_credit_balance(
        $pdo,
        $patientUserId,
        $amount,
        'REFUND',
        'workshop_enrollment',
        $referenceId,
        $description
    );
}

/** پس از برگزاری — تسویه از امانی به موجودی قابل برداشت دکتر */
function wallet_settle_doctor_hold(
    PDO $pdo,
    string $doctorUserId,
    int $amount,
    string $referenceId,
    string $description
): void {
    if ($amount <= 0) {
        return;
    }
    $wallet = wallet_for_update($pdo, $doctorUserId);
    if ((int) $wallet['held_balance'] < $amount) {
        throw new RuntimeException('موجودی امانی دکتر کافی نیست.');
    }
    $balanceAfter = (int) $wallet['balance'] + $amount;
    $heldAfter = (int) $wallet['held_balance'] - $amount;
    $pdo->prepare('UPDATE wallets SET balance = ?, held_balance = ? WHERE id = ?')
        ->execute([$balanceAfter, $heldAfter, $wallet['id']]);
    wallet_log_tx($pdo, $wallet['id'], 'SETTLE', $amount, $balanceAfter, $heldAfter, 'workshop_enrollment', $referenceId, $description);
}

/** برای هر مراجعه‌کنندهٔ فعال یک کیف پول می‌سازد تا مانده صفر هم ثبت شده باشد. */
function wallet_ensure_patient_wallets(PDO $pdo): int
{
    ensure_wallet_schema($pdo);
    if ($pdo->inTransaction()) {
        return 0;
    }
    $ids = $pdo->query("
      SELECT u.id
      FROM users u
      LEFT JOIN wallets w ON w.user_id = u.id
      WHERE w.id IS NULL
        AND COALESCE(u.is_disabled, 0) = 0
        AND (
          u.role = 'PATIENT'
          OR LOWER(u.username) = 'eshahabian'
          OR (u.name LIKE '%عماد%' AND u.name LIKE '%شهابیان%')
        )
    ")->fetchAll(PDO::FETCH_COLUMN) ?: [];
    $insert = $pdo->prepare('INSERT INTO wallets (id, user_id) VALUES (?, ?)');
    $made = 0;
    foreach ($ids as $userId) {
        $userId = (string) $userId;
        if ($userId === '') {
            continue;
        }
        $insert->execute([cuid(), $userId]);
        $made++;
    }

    return $made;
}

/** @return array<string, int> */
function wallet_balance_map(PDO $pdo): array
{
    ensure_wallet_schema($pdo);
    $rows = $pdo->query('SELECT user_id, balance FROM wallets')->fetchAll() ?: [];
    $map = [];
    foreach ($rows as $row) {
        $map[(string) ($row['user_id'] ?? '')] = (int) ($row['balance'] ?? 0);
    }

    return $map;
}

function wallet_appointment_has_tx(PDO $pdo, string $appointmentId, string $kind): bool
{
    if ($appointmentId === '') {
        return false;
    }
    ensure_wallet_schema($pdo);
    $stmt = $pdo->prepare("
      SELECT COUNT(*)
      FROM wallet_transactions
      WHERE reference_type = 'appointment' AND reference_id = ? AND kind = ?
    ");
    $stmt->execute([$appointmentId, $kind]);

    return (int) $stmt->fetchColumn() > 0;
}

/** حق ویزیت را یک بار از کیف پول کم می‌کند؛ موجودی می‌تواند منفی شود. */
function wallet_charge_visit(PDO $pdo, string $userId, string $appointmentId, int $amount, string $description): int
{
    if ($userId === '' || $appointmentId === '' || $amount <= 0) {
        return 0;
    }
    if (wallet_appointment_has_tx($pdo, $appointmentId, 'PAYMENT')) {
        $wallet = ensure_wallet($pdo, $userId);

        return (int) ($wallet['balance'] ?? 0);
    }
    ensure_wallet($pdo, $userId);

    return wallet_debit_balance_allow_negative($pdo, $userId, $amount, 'appointment', $appointmentId, $description);
}

/** بدهی همین نوبت را برمی‌گرداند؛ اگر قبلاً تسویه یا استرداد شده باشد دوباره واریز نمی‌کند. */
function wallet_release_visit_charge(PDO $pdo, string $userId, string $appointmentId, int $amount, string $description): void
{
    if ($userId === '' || $appointmentId === '' || $amount <= 0) {
        return;
    }
    if (!wallet_appointment_has_tx($pdo, $appointmentId, 'PAYMENT')) {
        return;
    }
    if (wallet_appointment_has_tx($pdo, $appointmentId, 'ADJUSTMENT') || wallet_appointment_has_tx($pdo, $appointmentId, 'REFUND')) {
        return;
    }
    wallet_credit_balance($pdo, $userId, $amount, 'ADJUSTMENT', 'appointment', $appointmentId, $description);
}

/**
 * اگر جلسه برگزار شده و هنوز از کیف پول کم نشده و پرداخت نقدی با فیش هم نبوده، حق ویزیت کسر می‌شود.
 */
function wallet_release_open_visit(PDO $pdo, string $appointmentId, string $description): void
{
    if ($appointmentId === '') {
        return;
    }
    $stmt = $pdo->prepare("
      SELECT a.patient_id, p.amount, p.status AS pay_status
      FROM appointments a
      LEFT JOIN payments p ON p.appointment_id = a.id
      WHERE a.id = ?
      LIMIT 1
    ");
    $stmt->execute([$appointmentId]);
    $row = $stmt->fetch();
    if (!$row || (string) ($row['pay_status'] ?? '') === 'PAID') {
        return;
    }
    wallet_release_visit_charge(
        $pdo,
        (string) ($row['patient_id'] ?? ''),
        $appointmentId,
        (int) ($row['amount'] ?? 0),
        $description
    );
}

function wallet_charge_held_visit(PDO $pdo, string $appointmentId): void
{
    if ($appointmentId === '') {
        return;
    }
    $stmt = $pdo->prepare("
      SELECT a.patient_id, a.starts_at, p.amount, p.status AS pay_status, p.ref_id, dp.session_price
      FROM appointments a
      LEFT JOIN payments p ON p.appointment_id = a.id
      LEFT JOIN doctor_profiles dp ON dp.id = a.doctor_id
      WHERE a.id = ?
      LIMIT 1
    ");
    $stmt->execute([$appointmentId]);
    $row = $stmt->fetch();
    if (!$row) {
        return;
    }
    if (wallet_appointment_has_tx($pdo, $appointmentId, 'PAYMENT')) {
        return;
    }
    $ref = (string) ($row['ref_id'] ?? '');
    $paidCash = (string) ($row['pay_status'] ?? '') === 'PAID' && in_array($ref, ['CARD', 'SECRETARY', 'RECEIPT'], true);
    if ($paidCash) {
        return;
    }
    $amount = (int) ($row['amount'] ?? 0);
    if ($amount <= 0) {
        $amount = (int) ($row['session_price'] ?? 0);
    }
    if ($amount <= 0) {
        return;
    }
    $when = function_exists('format_fa_datetime') ? format_fa_datetime((string) ($row['starts_at'] ?? '')) : '';
    wallet_charge_visit(
        $pdo,
        (string) ($row['patient_id'] ?? ''),
        $appointmentId,
        $amount,
        'کسر حق ویزیت پس از برگزاری جلسه' . ($when !== '' ? ' — ' . $when : '')
    );
}

/** @return list<array<string, mixed>> */
function wallet_party_rows(PDO $pdo, string $side): array
{
    wallet_ensure_patient_wallets($pdo);
    $cmp = $side === 'credit' ? '>' : '<';
    $order = $side === 'credit' ? 'DESC' : 'ASC';
    $sql = "
      SELECT u.name, u.phone, u.username, w.balance, du.name AS doctor_name
      FROM wallets w
      JOIN users u ON u.id = w.user_id
      LEFT JOIN doctor_profiles dp ON dp.id = u.preferred_doctor_id
      LEFT JOIN users du ON du.id = dp.user_id
      WHERE w.balance {$cmp} 0
        AND (
          u.role = 'PATIENT'
          OR LOWER(u.username) = 'eshahabian'
          OR (u.name LIKE '%عماد%' AND u.name LIKE '%شهابیان%')
        )
      ORDER BY w.balance {$order}, u.name ASC
    ";

    return $pdo->query($sql)->fetchAll() ?: [];
}

function wallet_kind_label(string $kind): string
{
    return match ($kind) {
        'TOPUP' => 'شارژ',
        'PAYMENT' => 'پرداخت',
        'REFUND' => 'بازگشت وجه',
        'HOLD' => 'امانی',
        'RELEASE' => 'آزادسازی امانی',
        'SETTLE' => 'تسویه',
        'ADJUSTMENT' => 'تعدیل',
        default => $kind,
    };
}
