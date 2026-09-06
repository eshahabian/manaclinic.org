<?php
declare(strict_types=1);

function ensure_workshop_sessions_schema(PDO $pdo): void
{
    static $ready = false;
    if ($ready) {
        return;
    }
    $pdo->exec("
      CREATE TABLE IF NOT EXISTS workshop_sessions (
        id VARCHAR(32) PRIMARY KEY,
        workshop_id VARCHAR(32) NOT NULL,
        session_date DATE NOT NULL,
        title VARCHAR(255) NOT NULL,
        sort_order INT NOT NULL DEFAULT 0,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_workshop_session_date (workshop_id, session_date),
        INDEX idx_ws_workshop (workshop_id, sort_order),
        CONSTRAINT fk_ws_workshop FOREIGN KEY (workshop_id) REFERENCES workshops(id) ON DELETE CASCADE
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    $ready = true;
}

function workshop_session_dates_from_range(string $startsAt, string $endsAt): array
{
    $start = substr($startsAt, 0, 10);
    $end = substr($endsAt, 0, 10);
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $start) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $end)) {
        return [];
    }
    $from = strtotime($start . ' 12:00:00');
    $to = strtotime($end . ' 12:00:00');
    if (!$from || !$to || $to < $from) {
        return [$start];
    }
    $out = [];
    for ($ts = $from; $ts <= $to; $ts += 86400) {
        $out[] = date('Y-m-d', $ts);
        if (count($out) >= 60) {
            break;
        }
    }
    return $out;
}

function workshop_session_title_for_date(string $ymd, int $index): string
{
    return 'جلسه ' . to_fa_digits((string) ($index + 1)) . ' — ' . to_jalali_label($ymd);
}

function workshop_sessions_extra_dates_from_post(): array
{
    $raw = $_POST['extra_session_dates'] ?? [];
    if (!is_array($raw)) {
        return [];
    }
    $out = [];
    foreach ($raw as $date) {
        $date = trim((string) $date);
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            $out[$date] = $date;
        }
    }
    return array_values($out);
}

function workshop_sessions_sync(PDO $pdo, string $workshopId, string $type, string $startsAt, string $endsAt, array $extraDates = []): array
{
    ensure_workshop_sessions_schema($pdo);
    $dates = [];
    if (workshop_is_offline($type)) {
        foreach ($extraDates as $date) {
            $dates[$date] = $date;
        }
        $existing = workshop_sessions_list($pdo, $workshopId);
        foreach ($existing as $row) {
            $dates[(string) $row['session_date']] = (string) $row['session_date'];
        }
        if (!$dates) {
            $dates[date('Y-m-d')] = date('Y-m-d');
        }
    } else {
        foreach (workshop_session_dates_from_range($startsAt, $endsAt) as $date) {
            $dates[$date] = $date;
        }
        foreach ($extraDates as $date) {
            $dates[$date] = $date;
        }
    }
    ksort($dates);
    $dates = array_values($dates);

    $have = [];
    foreach (workshop_sessions_list($pdo, $workshopId) as $row) {
        $have[(string) $row['session_date']] = $row;
    }

    $keep = [];
    foreach ($dates as $i => $date) {
        if (isset($have[$date])) {
            $pdo->prepare('UPDATE workshop_sessions SET title=?, sort_order=? WHERE id=?')
                ->execute([workshop_session_title_for_date($date, $i), $i, $have[$date]['id']]);
            $keep[] = (string) $have[$date]['id'];
            continue;
        }
        $id = cuid();
        $pdo->prepare('
          INSERT INTO workshop_sessions (id, workshop_id, session_date, title, sort_order)
          VALUES (?,?,?,?,?)
        ')->execute([$id, $workshopId, $date, workshop_session_title_for_date($date, $i), $i]);
        $keep[] = $id;
    }

    $orphans = $pdo->prepare('SELECT id FROM workshop_sessions WHERE workshop_id=?');
    $orphans->execute([$workshopId]);
    foreach ($orphans->fetchAll() as $row) {
        $sid = (string) $row['id'];
        if (in_array($sid, $keep, true)) {
            continue;
        }
        $media = $pdo->prepare('SELECT COUNT(*) FROM workshop_media_items WHERE session_id=?');
        $media->execute([$sid]);
        if ((int) $media->fetchColumn() > 0) {
            continue;
        }
        $pdo->prepare('DELETE FROM workshop_sessions WHERE id=?')->execute([$sid]);
    }

    return workshop_sessions_list($pdo, $workshopId);
}

function workshop_sessions_list(PDO $pdo, string $workshopId): array
{
    ensure_workshop_sessions_schema($pdo);
    $stmt = $pdo->prepare('SELECT * FROM workshop_sessions WHERE workshop_id=? ORDER BY session_date ASC, sort_order ASC');
    $stmt->execute([$workshopId]);
    return $stmt->fetchAll();
}

function workshop_sessions_with_media(PDO $pdo, string $workshopId): array
{
    require_once __DIR__ . '/workshop_media.php';
    ensure_workshop_media_schema($pdo);
    $sessions = workshop_sessions_list($pdo, $workshopId);
    $items = workshop_media_list($pdo, $workshopId);
    $bySession = [];
    $unassigned = [];
    foreach ($items as $item) {
        $sid = trim((string) ($item['session_id'] ?? ''));
        if ($sid === '') {
            $unassigned[] = $item;
            continue;
        }
        $bySession[$sid][] = $item;
    }
    if ($unassigned && $sessions) {
        $firstId = (string) $sessions[0]['id'];
        foreach ($unassigned as $item) {
            $bySession[$firstId][] = $item;
        }
        $unassigned = [];
    }
    foreach ($sessions as &$session) {
        $sid = (string) $session['id'];
        $files = $bySession[$sid] ?? [];
        $session['files'] = [
            'PDF' => null,
            'AUDIO' => null,
            'VIDEO' => null,
        ];
        foreach ($files as $file) {
            $kind = (string) ($file['kind'] ?? '');
            if (isset($session['files'][$kind]) && $session['files'][$kind] === null) {
                $session['files'][$kind] = $file;
            }
        }
    }
    unset($session);
    if ($unassigned) {
        $sessions[] = [
            'id' => '',
            'workshop_id' => $workshopId,
            'session_date' => '',
            'title' => 'سایر فایل‌ها',
            'sort_order' => 999,
            'files' => [
                'PDF' => null,
                'AUDIO' => null,
                'VIDEO' => null,
            ],
        ];
        $last = count($sessions) - 1;
        foreach ($unassigned as $file) {
            $kind = (string) ($file['kind'] ?? '');
            if (isset($sessions[$last]['files'][$kind]) && $sessions[$last]['files'][$kind] === null) {
                $sessions[$last]['files'][$kind] = $file;
            }
        }
    }
    return $sessions;
}

function workshop_sessions_map_for_ids(PDO $pdo, array $workshopIds): array
{
    $workshopIds = array_values(array_filter(array_map('strval', $workshopIds)));
    if (!$workshopIds) {
        return [];
    }
    $out = [];
    foreach ($workshopIds as $id) {
        $out[$id] = workshop_sessions_with_media($pdo, $id);
    }
    return $out;
}
