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
        appointment_id VARCHAR(32) NOT NULL,
        patient_id VARCHAR(32) NOT NULL,
        doctor_id VARCHAR(32) NOT NULL,
        body TEXT NOT NULL,
        created_by VARCHAR(32) NOT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_session_complaint_created (created_at)
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    $ready = true;
}

/** @return list<array<string, mixed>> */
function session_complaint_appointments(PDO $pdo): array
{
    ensure_session_complaints_schema($pdo);

    return $pdo->query("
      SELECT a.id, a.starts_at, a.doctor_id, a.patient_id,
             p.name AS patient_name,
             d.name AS doctor_name
      FROM appointments a
      JOIN users p ON p.id = a.patient_id
      JOIN doctor_profiles dp ON dp.id = a.doctor_id
      JOIN users d ON d.id = dp.user_id
      WHERE a.status <> 'CANCELLED'
        AND a.starts_at <= NOW()
      ORDER BY a.starts_at DESC
      LIMIT 200
    ")->fetchAll() ?: [];
}

function session_complaint_create(PDO $pdo, string $secretaryId, string $appointmentId, string $body): void
{
    ensure_session_complaints_schema($pdo);
    $body = trim($body);
    if ($body === '') {
        throw new RuntimeException('متن شکایت را بنویسید.');
    }
    if (mb_strlen($body) > 4000) {
        throw new RuntimeException('متن شکایت طولانی است.');
    }
    $stmt = $pdo->prepare("
      SELECT id, patient_id, doctor_id
      FROM appointments
      WHERE id = ? AND status <> 'CANCELLED' AND starts_at <= NOW()
      LIMIT 1
    ");
    $stmt->execute([$appointmentId]);
    $appointment = $stmt->fetch();
    if (!$appointment) {
        throw new RuntimeException('این جلسه برای ثبت شکایت پیدا نشد.');
    }
    $pdo->prepare('
      INSERT INTO session_complaints (id, appointment_id, patient_id, doctor_id, body, created_by)
      VALUES (?,?,?,?,?,?)
    ')->execute([
        cuid(),
        (string) $appointment['id'],
        (string) $appointment['patient_id'],
        (string) $appointment['doctor_id'],
        $body,
        $secretaryId,
    ]);
}

/** @return list<array<string, mixed>> */
function session_complaint_list(PDO $pdo): array
{
    ensure_session_complaints_schema($pdo);

    return $pdo->query("
      SELECT c.id, c.body, c.created_at, a.starts_at,
             p.name AS patient_name,
             d.name AS doctor_name,
             s.name AS secretary_name
      FROM session_complaints c
      JOIN appointments a ON a.id = c.appointment_id
      JOIN users p ON p.id = c.patient_id
      JOIN doctor_profiles dp ON dp.id = c.doctor_id
      JOIN users d ON d.id = dp.user_id
      JOIN users s ON s.id = c.created_by
      ORDER BY c.created_at DESC
      LIMIT 80
    ")->fetchAll() ?: [];
}
