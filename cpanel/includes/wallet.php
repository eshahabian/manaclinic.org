<?php
declare(strict_types=1);

function ensure_wallet_schema(PDO $pdo): void
{
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

/** پرداخت مراجع — از موجودی قابل برداشت */
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

/** لغو قبل از ۲۴ ساعت — از امانی دکتر به کیف پول مراجع */
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
