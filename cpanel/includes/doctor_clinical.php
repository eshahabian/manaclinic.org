<?php
declare(strict_types=1);

/** جداول پرونده خصوصی دکتر — فقط از پنل دکتر استفاده می‌شود */
function ensure_doctor_clinical_tables(PDO $pdo): void
{
    static $ready = false;
    if ($ready) {
        return;
    }
    $pdo->exec("
      CREATE TABLE IF NOT EXISTS doctor_patient_charts (
        id VARCHAR(32) PRIMARY KEY,
        doctor_id VARCHAR(32) NOT NULL,
        patient_id VARCHAR(32) NOT NULL,
        history_text MEDIUMTEXT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uq_doctor_patient_chart (doctor_id, patient_id),
        INDEX idx_chart_doctor (doctor_id)
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    $pdo->exec("
      CREATE TABLE IF NOT EXISTS doctor_session_notes (
        id VARCHAR(32) PRIMARY KEY,
        doctor_id VARCHAR(32) NOT NULL,
        patient_id VARCHAR(32) NOT NULL,
        appointment_id VARCHAR(32) NOT NULL,
        note_text MEDIUMTEXT NOT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uq_session_note_appointment (appointment_id),
        INDEX idx_session_doctor_patient (doctor_id, patient_id)
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    $pdo->exec("
      CREATE TABLE IF NOT EXISTS doctor_highlights (
        id VARCHAR(32) PRIMARY KEY,
        doctor_id VARCHAR(32) NOT NULL,
        patient_id VARCHAR(32) NOT NULL,
        excerpt TEXT NOT NULL,
        remark TEXT NULL,
        color VARCHAR(20) NOT NULL DEFAULT 'yellow',
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_hl_doctor_patient (doctor_id, patient_id)
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    $ready = true;
}

/**
 * بیمار فقط اگر حداقل یک نوبت با همین دکتر داشته باشد قابل دسترسی است.
 * @return array{patient: array, appointments: array}
 */
function require_doctor_patient_access(PDO $pdo, array $ctx, string $patientId): array
{
    ensure_doctor_clinical_tables($pdo);
    $doctorId = $ctx['profile']['id'];

    $patientStmt = $pdo->prepare("SELECT id, username, name, phone, created_at FROM users WHERE id=? AND role='PATIENT' LIMIT 1");
    $patientStmt->execute([$patientId]);
    $patient = $patientStmt->fetch();
    if (!$patient) {
        flash_set('error', 'بیمار یافت نشد.');
        redirect('/doctor/patients');
    }

    $check = $pdo->prepare('SELECT COUNT(*) FROM appointments WHERE doctor_id=? AND patient_id=?');
    $check->execute([$doctorId, $patientId]);
    if ((int) $check->fetchColumn() < 1) {
        flash_set('error', 'دسترسی به پرونده این بیمار برای شما مجاز نیست.');
        redirect('/doctor/patients');
    }

    $apps = $pdo->prepare('SELECT * FROM appointments WHERE doctor_id=? AND patient_id=? ORDER BY starts_at DESC');
    $apps->execute([$doctorId, $patientId]);

    return [
        'patient' => $patient,
        'appointments' => $apps->fetchAll(),
    ];
}

function get_or_create_patient_chart(PDO $pdo, string $doctorId, string $patientId): array
{
    ensure_doctor_clinical_tables($pdo);
    $stmt = $pdo->prepare('SELECT * FROM doctor_patient_charts WHERE doctor_id=? AND patient_id=? LIMIT 1');
    $stmt->execute([$doctorId, $patientId]);
    $row = $stmt->fetch();
    if ($row) {
        return $row;
    }
    $id = cuid();
    $pdo->prepare('INSERT INTO doctor_patient_charts (id, doctor_id, patient_id, history_text) VALUES (?,?,?,?)')
        ->execute([$id, $doctorId, $patientId, '']);
    $stmt->execute([$doctorId, $patientId]);
    return $stmt->fetch() ?: ['id' => $id, 'doctor_id' => $doctorId, 'patient_id' => $patientId, 'history_text' => ''];
}

/** HTML امن برای ادیتور شرح حال (bold / سایز / هایلایت) */
function sanitize_clinical_html(string $html): string
{
    return sanitize_rich_html($html);
}

function history_html_for_editor(?string $raw): string
{
    return rich_html_for_display($raw);
}
