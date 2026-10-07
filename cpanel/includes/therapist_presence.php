<?php
declare(strict_types=1);

function ensure_therapist_presence_schema(PDO $pdo): void
{
    static $ready = false;
    if ($ready) {
        return;
    }
    $pdo->exec("
      CREATE TABLE IF NOT EXISTS therapist_presence (
        doctor_id VARCHAR(32) NOT NULL,
        day_date DATE NOT NULL,
        is_present TINYINT NOT NULL DEFAULT 0,
        hour_from TINYINT NULL,
        hour_to TINYINT NULL,
        updated_by VARCHAR(32) NULL,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (doctor_id, day_date)
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    $ready = true;
}

function therapist_date_is_closed(PDO $pdo, string $doctorId, string $date): bool
{
    $date = substr($date, 0, 10);
    if ($doctorId === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        return false;
    }
    ensure_therapist_presence_schema($pdo);
    $stmt = $pdo->prepare('SELECT is_present FROM therapist_presence WHERE doctor_id=? AND day_date=? LIMIT 1');
    $stmt->execute([$doctorId, $date]);
    $flag = $stmt->fetchColumn();
    if ($flag === false) {
        return false;
    }

    return (int) $flag !== 1;
}

/** @return array<string, true> doctorId|Y-m-d */
function therapist_closed_date_keys(PDO $pdo, string $from, string $to): array
{
    ensure_therapist_presence_schema($pdo);
    $stmt = $pdo->prepare('
      SELECT doctor_id, day_date
      FROM therapist_presence
      WHERE is_present=0 AND day_date BETWEEN ? AND ?
    ');
    $stmt->execute([$from, $to]);
    $set = [];
    foreach ($stmt->fetchAll() as $row) {
        $set[(string) $row['doctor_id'] . '|' . substr((string) $row['day_date'], 0, 10)] = true;
    }

    return $set;
}

/** @return array<string, array{present:int, from:?int, to:?int}> */
function therapist_presence_map(PDO $pdo, string $doctorId, string $from, string $to): array
{
    ensure_therapist_presence_schema($pdo);
    $stmt = $pdo->prepare('
      SELECT day_date, is_present, hour_from, hour_to
      FROM therapist_presence
      WHERE doctor_id=? AND day_date BETWEEN ? AND ?
    ');
    $stmt->execute([$doctorId, $from, $to]);
    $map = [];
    foreach ($stmt->fetchAll() as $row) {
        $key = substr((string) $row['day_date'], 0, 10);
        $map[$key] = [
            'present' => (int) ($row['is_present'] ?? 0),
            'from' => $row['hour_from'] === null ? null : (int) $row['hour_from'],
            'to' => $row['hour_to'] === null ? null : (int) $row['hour_to'],
        ];
    }

    return $map;
}

function therapist_presence_save_day(
    PDO $pdo,
    string $doctorId,
    string $date,
    bool $present,
    ?int $hourFrom,
    ?int $hourTo,
    string $actorId
): void {
    ensure_therapist_presence_schema($pdo);
    if (!function_exists('doctor_availability_upsert')) {
        require_once __DIR__ . '/availability.php';
    }
    $pdo->prepare('
      INSERT INTO therapist_presence (doctor_id, day_date, is_present, hour_from, hour_to, updated_by)
      VALUES (?,?,?,?,?,?)
      ON DUPLICATE KEY UPDATE
        is_present=VALUES(is_present),
        hour_from=VALUES(hour_from),
        hour_to=VALUES(hour_to),
        updated_by=VALUES(updated_by)
    ')->execute([
        $doctorId,
        $date,
        $present ? 1 : 0,
        $present ? $hourFrom : null,
        $present ? $hourTo : null,
        $actorId !== '' ? $actorId : null,
    ]);
    if ($present && $hourFrom !== null && $hourTo !== null) {
        $hours = appointment_hours_range($hourFrom, $hourTo);
        if ($hours !== []) {
            doctor_availability_upsert($pdo, $doctorId, $date, $hours);
        }
        return;
    }
    $pdo->prepare('DELETE FROM availabilities WHERE doctor_id=? AND date=?')->execute([$doctorId, $date]);
}
