<?php
declare(strict_types=1);

function ensure_session_complaints_schema(PDO $pdo): void
{
    static $ready = false;
    if ($ready) {
        return;
    }
    $pdo->exec("
      CREATE TABLE IF NOT EXISTS session_complaints (
        id VARCHAR(32) PRIMARY KEY,
        appointment_id VARCHAR(32) NULL,
        patient_id VARCHAR(32) NOT NULL,
        doctor_id VARCHAR(32) NOT NULL,
        session_at DATETIME NULL,
        reason VARCHAR(80) NOT NULL DEFAULT '',
        body TEXT NOT NULL,
        created_by VARCHAR(32) NOT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_session_complaint_created (created_at)
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    foreach ([
        'session_at' => 'ALTER TABLE session_complaints ADD COLUMN session_at DATETIME NULL AFTER doctor_id',
        'reason' => "ALTER TABLE session_complaints ADD COLUMN reason VARCHAR(80) NOT NULL DEFAULT '' AFTER session_at",
    ] as $column => $sql) {
        $has = $pdo->query("SHOW COLUMNS FROM session_complaints LIKE " . $pdo->quote($column))->fetch();
        if (!$has) {
            $pdo->exec($sql);
        }
    }
    try {
        $pdo->exec('ALTER TABLE session_complaints MODIFY appointment_id VARCHAR(32) NULL');
    } catch (Throwable $ignored) {
    }
    $ready = true;
}

/** @return list<string> */
function session_complaint_reasons(): array
{
    return [
        'تأخیر در شروع جلسه',
        'برخورد نامناسب',
        'کوتاه شدن زمان جلسه',
        'بی‌توجهی به صحبت مراجع',
        'لغو یا جابه‌جایی ناگهانی',
        'نارضایتی از روند درمان',
        'سایر',
    ];
}

function session_complaint_create(
    PDO $pdo,
    string $secretaryId,
    string $patientId,
    string $doctorId,
    string $sessionAt,
    string $reason,
    string $body
): void {
    ensure_session_complaints_schema($pdo);
    $body = trim($body);
    $reason = trim($reason);
    if (!in_array($reason, session_complaint_reasons(), true)) {
        throw new RuntimeException('علت را انتخاب کنید.');
    }
    if ($body === '') {
        throw new RuntimeException('توضیحات بیشتر را بنویسید.');
    }
    if (mb_strlen($body) > 4000) {
        throw new RuntimeException('توضیحات طولانی است.');
    }
    $sessionTs = strtotime($sessionAt);
    if ($sessionTs === false) {
        throw new RuntimeException('زمان جلسه معتبر نیست.');
    }
    $patient = $pdo->prepare("
      SELECT id FROM users
      WHERE id = ? AND is_disabled = 0
        AND (
          role = 'PATIENT'
          OR LOWER(username) = 'eshahabian'
          OR (name LIKE '%عماد%' AND name LIKE '%شهابیان%')
        )
      LIMIT 1
    ");
    $patient->execute([$patientId]);
    if (!$patient->fetch()) {
        throw new RuntimeException('مراجعه‌کننده را انتخاب کنید.');
    }
    $doctor = $pdo->prepare('SELECT id FROM doctor_profiles WHERE id = ? AND is_active = 1 AND is_approved = 1 LIMIT 1');
    $doctor->execute([$doctorId]);
    if (!$doctor->fetch()) {
        throw new RuntimeException('درمانگر را انتخاب کنید.');
    }
    $pdo->prepare('
      INSERT INTO session_complaints (id, appointment_id, patient_id, doctor_id, session_at, reason, body, created_by)
      VALUES (?,?,?,?,?,?,?,?)
    ')->execute([
        cuid(),
        null,
        $patientId,
        $doctorId,
        date('Y-m-d H:i:s', $sessionTs),
        $reason,
        $body,
        $secretaryId,
    ]);
}

/** @return list<array<string, mixed>> */
function session_complaint_list(PDO $pdo): array
{
    ensure_session_complaints_schema($pdo);

    return $pdo->query("
      SELECT c.id, c.body, c.reason, c.created_at,
             COALESCE(c.session_at, a.starts_at) AS session_at,
             p.name AS patient_name,
             d.name AS doctor_name,
             s.name AS secretary_name
      FROM session_complaints c
      JOIN users p ON p.id = c.patient_id
      JOIN doctor_profiles dp ON dp.id = c.doctor_id
      JOIN users d ON d.id = dp.user_id
      JOIN users s ON s.id = c.created_by
      LEFT JOIN appointments a ON a.id = c.appointment_id
      ORDER BY c.created_at DESC
      LIMIT 80
    ")->fetchAll() ?: [];
}
