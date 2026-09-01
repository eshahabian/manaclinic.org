<?php
declare(strict_types=1);

require_once __DIR__ . '/wallet.php';

function ensure_workshop_schema(PDO $pdo): void
{
    ensure_wallet_schema($pdo);
    $pdo->exec("
      CREATE TABLE IF NOT EXISTS workshops (
        id VARCHAR(32) PRIMARY KEY,
        doctor_id VARCHAR(32) NOT NULL,
        title VARCHAR(255) NOT NULL,
        type ENUM('IN_PERSON','ONLINE','OFFLINE') NOT NULL,
        starts_at DATETIME NOT NULL,
        ends_at DATETIME NOT NULL,
        items_to_bring TEXT NULL,
        notes TEXT NULL,
        description TEXT NULL,
        price INT NOT NULL DEFAULT 0,
        capacity INT NULL,
        location VARCHAR(255) NULL,
        meeting_url VARCHAR(500) NULL,
        content_url VARCHAR(500) NULL,
        is_published TINYINT(1) NOT NULL DEFAULT 0,
        status ENUM('DRAFT','PUBLISHED','CANCELLED','COMPLETED') NOT NULL DEFAULT 'DRAFT',
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_workshop_doctor (doctor_id),
        INDEX idx_workshop_type (type, is_published, starts_at),
        CONSTRAINT fk_workshop_doctor FOREIGN KEY (doctor_id) REFERENCES doctor_profiles(id) ON DELETE CASCADE
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

      CREATE TABLE IF NOT EXISTS workshop_enrollments (
        id VARCHAR(32) PRIMARY KEY,
        workshop_id VARCHAR(32) NOT NULL,
        patient_id VARCHAR(32) NOT NULL,
        status ENUM('PENDING_PAYMENT','CONFIRMED','CANCELLED','REFUNDED','COMPLETED') NOT NULL DEFAULT 'PENDING_PAYMENT',
        enrolled_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uq_workshop_patient (workshop_id, patient_id),
        INDEX idx_enrollment_patient (patient_id),
        CONSTRAINT fk_enroll_workshop FOREIGN KEY (workshop_id) REFERENCES workshops(id) ON DELETE CASCADE,
        CONSTRAINT fk_enroll_patient FOREIGN KEY (patient_id) REFERENCES users(id) ON DELETE CASCADE
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

      CREATE TABLE IF NOT EXISTS workshop_payments (
        id VARCHAR(32) PRIMARY KEY,
        enrollment_id VARCHAR(32) NOT NULL UNIQUE,
        amount INT NOT NULL,
        wallet_amount INT NOT NULL DEFAULT 0,
        authority VARCHAR(100) NULL,
        ref_id VARCHAR(100) NULL,
        status ENUM('PENDING','PAID','FAILED','REFUNDED') NOT NULL DEFAULT 'PENDING',
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        CONSTRAINT fk_wpay_enrollment FOREIGN KEY (enrollment_id) REFERENCES workshop_enrollments(id) ON DELETE CASCADE
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");
}

function workshop_type_from_tab(string $tab): string
{
    return match ($tab) {
        'online' => 'ONLINE',
        'offline' => 'OFFLINE',
        default => 'IN_PERSON',
    };
}

function workshop_tab_from_type(string $type): string
{
    return match ($type) {
        'ONLINE' => 'online',
        'OFFLINE' => 'offline',
        default => 'in-person',
    };
}

function workshop_type_label(string $type): string
{
    return match ($type) {
        'ONLINE' => 'آنلاین',
        'OFFLINE' => 'آفلاین',
        default => 'حضوری',
    };
}

function enrollment_status_label(string $status): string
{
    return match ($status) {
        'PENDING_PAYMENT' => 'در انتظار پرداخت',
        'CONFIRMED' => 'تأیید شده',
        'CANCELLED' => 'لغو شده',
        'REFUNDED' => 'بازپرداخت شده',
        'COMPLETED' => 'برگزار شده',
        default => $status,
    };
}

function workshop_refund_allowed(string $startsAt): bool
{
    return strtotime($startsAt) - time() >= 24 * 3600;
}

function workshop_enrollment_count(PDO $pdo, string $workshopId): int
{
    $stmt = $pdo->prepare("
      SELECT COUNT(*) FROM workshop_enrollments
      WHERE workshop_id = ? AND status IN ('PENDING_PAYMENT','CONFIRMED','COMPLETED')
    ");
    $stmt->execute([$workshopId]);
    return (int) $stmt->fetchColumn();
}

function workshop_has_capacity(PDO $pdo, array $workshop): bool
{
    if ($workshop['capacity'] === null || $workshop['capacity'] === '') {
        return true;
    }
    return workshop_enrollment_count($pdo, $workshop['id']) < (int) $workshop['capacity'];
}

function confirm_workshop_payment(PDO $pdo, array $paymentRow): void
{
    $pdo->prepare("UPDATE workshop_payments SET status='PAID', ref_id=? WHERE id=?")
        ->execute([$paymentRow['ref_id'] ?? null, $paymentRow['id']]);
    $pdo->prepare("UPDATE workshop_enrollments SET status='CONFIRMED' WHERE id=?")
        ->execute([$paymentRow['enrollment_id']]);

    $info = $pdo->prepare("
      SELECT w.title, w.starts_at, w.doctor_id, dp.user_id AS doctor_user_id, u.name AS patient_name
      FROM workshop_enrollments e
      JOIN workshops w ON w.id = e.workshop_id
      JOIN doctor_profiles dp ON dp.id = w.doctor_id
      JOIN users u ON u.id = e.patient_id
      WHERE e.id = ?
      LIMIT 1
    ");
    $info->execute([$paymentRow['enrollment_id']]);
    $row = $info->fetch();
    if (!$row) {
        return;
    }

    $totalAmount = (int) $paymentRow['amount'];
    if ($totalAmount > 0) {
        $netAmount = $totalAmount - (int) ($paymentRow['wallet_amount'] ?? 0);
        if ($netAmount > 0) {
            wallet_hold_for_doctor(
                $pdo,
                (string) $row['doctor_user_id'],
                $netAmount,
                'workshop_enrollment',
                (string) $paymentRow['enrollment_id'],
                'پرداخت کارگاه: ' . $row['title']
            );
        }
        $walletAmount = (int) ($paymentRow['wallet_amount'] ?? 0);
        if ($walletAmount > 0) {
            wallet_hold_for_doctor(
                $pdo,
                (string) $row['doctor_user_id'],
                $walletAmount,
                'workshop_enrollment',
                (string) $paymentRow['enrollment_id'],
                'پرداخت از کیف پول — کارگاه: ' . $row['title']
            );
        }
    }

    $when = format_fa_datetime((string) $row['starts_at']);
    $patientName = (string) $row['patient_name'];
    notify_role(
        $pdo,
        'SECRETARY',
        'ثبت‌نام کارگاه',
        "«{$patientName}» در کارگاه «{$row['title']}» ({$when}) ثبت‌نام و پرداخت کرد.",
        '/secretary/appointments'
    );
    notify_doctor_profile(
        $pdo,
        (string) $row['doctor_id'],
        'ثبت‌نام کارگاه',
        "«{$patientName}» در کارگاه «{$row['title']}» ({$when}) ثبت‌نام کرد.",
        '/doctor/workshops'
    );
}

function cancel_workshop_enrollment(PDO $pdo, string $enrollmentId, bool $forceNoRefund = false): array
{
    $stmt = $pdo->prepare("
      SELECT e.*, w.starts_at, w.title, w.doctor_id, dp.user_id AS doctor_user_id, wp.amount, wp.wallet_amount, wp.status AS pay_status
      FROM workshop_enrollments e
      JOIN workshops w ON w.id = e.workshop_id
      JOIN doctor_profiles dp ON dp.id = w.doctor_id
      LEFT JOIN workshop_payments wp ON wp.enrollment_id = e.id
      WHERE e.id = ?
      LIMIT 1
    ");
    $stmt->execute([$enrollmentId]);
    $row = $stmt->fetch();
    if (!$row) {
        throw new RuntimeException('ثبت‌نام یافت نشد.');
    }
    if (!in_array($row['status'], ['PENDING_PAYMENT', 'CONFIRMED'], true)) {
        throw new RuntimeException('این ثبت‌نام قابل لغو نیست.');
    }

    $refundable = !$forceNoRefund
        && $row['status'] === 'CONFIRMED'
        && ($row['pay_status'] ?? '') === 'PAID'
        && workshop_refund_allowed((string) $row['starts_at']);

    if ($refundable) {
        $paidAmount = (int) $row['amount'];
        wallet_refund_from_doctor_hold(
            $pdo,
            (string) $row['doctor_user_id'],
            (string) $row['patient_id'],
            $paidAmount,
            $enrollmentId,
            'لغو کارگاه: ' . $row['title']
        );
        $pdo->prepare("UPDATE workshop_payments SET status='REFUNDED' WHERE enrollment_id=?")->execute([$enrollmentId]);
        $pdo->prepare("UPDATE workshop_enrollments SET status='REFUNDED' WHERE id=?")->execute([$enrollmentId]);
        return ['status' => 'REFUNDED', 'refunded' => true, 'amount' => $paidAmount];
    }

    if ($row['status'] === 'PENDING_PAYMENT') {
        $pdo->prepare("UPDATE workshop_payments SET status='FAILED' WHERE enrollment_id=? AND status='PENDING'")
            ->execute([$enrollmentId]);
    }
    $pdo->prepare("UPDATE workshop_enrollments SET status='CANCELLED' WHERE id=?")->execute([$enrollmentId]);
    return ['status' => 'CANCELLED', 'refunded' => false, 'amount' => 0];
}

function complete_workshop(PDO $pdo, string $workshopId, string $doctorProfileId): int
{
    $stmt = $pdo->prepare('SELECT * FROM workshops WHERE id=? AND doctor_id=? LIMIT 1');
    $stmt->execute([$workshopId, $doctorProfileId]);
    $workshop = $stmt->fetch();
    if (!$workshop) {
        throw new RuntimeException('کارگاه یافت نشد.');
    }

    $docUser = $pdo->prepare('SELECT user_id FROM doctor_profiles WHERE id=?');
    $docUser->execute([$doctorProfileId]);
    $doctorUserId = (string) $docUser->fetchColumn();

    $enrollments = $pdo->prepare("
      SELECT e.id, wp.amount
      FROM workshop_enrollments e
      JOIN workshop_payments wp ON wp.enrollment_id = e.id AND wp.status = 'PAID'
      WHERE e.workshop_id = ? AND e.status = 'CONFIRMED'
    ");
    $enrollments->execute([$workshopId]);
    $settled = 0;

    foreach ($enrollments->fetchAll() as $enrollment) {
        wallet_settle_doctor_hold(
            $pdo,
            $doctorUserId,
            (int) $enrollment['amount'],
            (string) $enrollment['id'],
            'تسویه کارگاه: ' . $workshop['title']
        );
        $pdo->prepare("UPDATE workshop_enrollments SET status='COMPLETED' WHERE id=?")
            ->execute([$enrollment['id']]);
        $settled++;
    }

    $pdo->prepare("UPDATE workshops SET status='COMPLETED' WHERE id=?")->execute([$workshopId]);
    return $settled;
}
