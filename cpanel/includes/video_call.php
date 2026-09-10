<?php
declare(strict_types=1);

function video_call_is_clinician(?array $user): bool
{
    if (!$user) {
        return false;
    }
    $role = (string) ($user['role'] ?? '');

    return $role === 'DOCTOR' || $role === 'ADMIN';
}

function video_call_allowed(?array $user): bool
{
    if (!$user) {
        return false;
    }
    $role = (string) ($user['role'] ?? '');

    return in_array($role, ['DOCTOR', 'PATIENT', 'ADMIN'], true);
}

function ensure_video_call_schema(PDO $pdo): void
{
    static $ready = false;
    if ($ready) {
        return;
    }
    $pdo->exec("
      CREATE TABLE IF NOT EXISTS video_call_signals (
        id VARCHAR(32) PRIMARY KEY,
        room_id VARCHAR(80) NOT NULL,
        sender_id VARCHAR(32) NOT NULL,
        target_id VARCHAR(32) NULL,
        kind VARCHAR(16) NOT NULL,
        payload MEDIUMTEXT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_vcs_room_time (room_id, created_at),
        INDEX idx_vcs_target (target_id, created_at)
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    $pdo->exec("
      CREATE TABLE IF NOT EXISTS video_call_presence (
        user_id VARCHAR(32) PRIMARY KEY,
        last_seen DATETIME NOT NULL,
        INDEX idx_vcp_seen (last_seen)
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    $pdo->exec("
      CREATE TABLE IF NOT EXISTS video_call_rooms (
        id VARCHAR(32) PRIMARY KEY,
        room_key VARCHAR(80) NOT NULL,
        kind ENUM('direct','group','workshop') NOT NULL,
        title VARCHAR(255) NOT NULL,
        host_user_id VARCHAR(32) NOT NULL,
        workshop_id VARCHAR(32) NULL,
        share_token VARCHAR(32) NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_vcr_key (room_key),
        UNIQUE KEY uq_vcr_token (share_token),
        INDEX idx_vcr_host (host_user_id),
        INDEX idx_vcr_workshop (workshop_id)
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    $pdo->exec("
      CREATE TABLE IF NOT EXISTS video_call_room_members (
        room_id VARCHAR(32) NOT NULL,
        user_id VARCHAR(32) NOT NULL,
        PRIMARY KEY (room_id, user_id),
        INDEX idx_vcrm_user (user_id)
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    $pdo->exec("
      CREATE TABLE IF NOT EXISTS video_call_contact_stats (
        host_user_id VARCHAR(32) NOT NULL,
        peer_user_id VARCHAR(32) NOT NULL,
        call_count INT NOT NULL DEFAULT 0,
        last_at DATETIME NOT NULL,
        PRIMARY KEY (host_user_id, peer_user_id),
        INDEX idx_vccs_host (host_user_id, call_count, last_at)
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    video_call_purge_mbsr_groups($pdo);
    $ready = true;
}

function video_call_touch(PDO $pdo, string $userId): void
{
    if ($userId === '') {
        return;
    }
    $pdo->prepare('
      INSERT INTO video_call_presence (user_id, last_seen) VALUES (?, NOW())
      ON DUPLICATE KEY UPDATE last_seen = NOW()
    ')->execute([$userId]);
}

function video_call_is_online(PDO $pdo, string $userId): bool
{
    $stmt = $pdo->prepare('SELECT last_seen FROM video_call_presence WHERE user_id=? LIMIT 1');
    $stmt->execute([$userId]);
    $seen = (string) ($stmt->fetchColumn() ?: '');
    if ($seen === '') {
        return false;
    }
    $ts = strtotime($seen);

    return $ts !== false && (time() - $ts) < 28;
}

function video_call_online_map(PDO $pdo, array $userIds): array
{
    $userIds = array_values(array_unique(array_filter(array_map('strval', $userIds))));
    if ($userIds === []) {
        return [];
    }
    $in = implode(',', array_fill(0, count($userIds), '?'));
    $stmt = $pdo->prepare("SELECT user_id, last_seen FROM video_call_presence WHERE user_id IN ($in)");
    $stmt->execute($userIds);
    $map = [];
    $now = time();
    foreach ($stmt->fetchAll() as $row) {
        $ts = strtotime((string) ($row['last_seen'] ?? ''));
        $map[(string) $row['user_id']] = $ts !== false && ($now - $ts) < 28;
    }
    foreach ($userIds as $id) {
        if (!isset($map[$id])) {
            $map[$id] = false;
        }
    }

    return $map;
}

function video_call_nav_link(bool $withType = false): ?array
{
    if (!video_call_allowed(current_user())) {
        return null;
    }
    $item = [
        'href' => '/video-call',
        'label' => 'تماس مانا',
        'icon' => url('/assets/img/mana-call.png'),
    ];
    if ($withType) {
        $item['type'] = 'link';
    }

    return $item;
}

function video_call_watch_config(?array $user): ?array
{
    if (!video_call_allowed($user)) {
        return null;
    }

    return [
        'signalUrl' => url('/video-signal'),
        'callUrl' => url('/video-call'),
        'peerName' => 'درمانگر',
        'ringUrl' => url('/assets/audio/incoming-call.ogg'),
    ];
}

function video_call_json(array $data, int $code = 200): void
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function video_call_new_token(): string
{
    return bin2hex(random_bytes(8));
}

function video_call_direct_key(string $a, string $b): string
{
    $ids = [$a, $b];
    sort($ids, SORT_STRING);

    return 'dm-' . $ids[0] . '-' . $ids[1];
}

function video_call_workshop_key(string $workshopId): string
{
    return 'ws-' . $workshopId;
}

function video_call_public_name(array $person, ?bool $full = null): string
{
    $name = trim((string) ($person['name'] ?? ''));
    $role = (string) ($person['role'] ?? '');
    if ($full === null) {
        $full = video_call_is_clinician(current_user());
    }
    if ($full || $role !== 'PATIENT') {
        if ($name !== '') {
            return $name;
        }
        return $role === 'PATIENT' ? 'مراجعه‌کننده' : 'کاربر';
    }
    $parts = preg_split('/\s+/u', $name) ?: [];
    $parts = array_values(array_filter($parts, static fn ($p) => $p !== ''));
    if ($parts === []) {
        return 'مراجعه‌کننده';
    }
    $first = $parts[0];
    if (count($parts) === 1) {
        $ch = function_exists('mb_substr') ? mb_substr($first, 0, 1) : substr($first, 0, 1);

        return $ch . '***';
    }
    $last = $parts[count($parts) - 1];
    $ini = function_exists('mb_substr') ? mb_substr($last, 0, 1) : substr($last, 0, 1);

    return $first . ' ' . $ini . '.';
}

function video_call_avatar_html(array $person, bool $online, string $size = 'md'): string
{
    $name = video_call_public_name($person);
    $src = '';
    if ((string) ($person['role'] ?? '') !== 'PATIENT' && function_exists('doctor_avatar_src')) {
        $src = doctor_avatar_src((string) ($person['avatar_url'] ?? ''));
    }
    $cls = 'vc-avatar vc-avatar-' . preg_replace('/[^a-z]/', '', $size);
    ob_start();
    ?>
<span class="<?= e($cls) ?>">
  <?php if ($src !== ''): ?>
    <img src="<?= e($src) ?>" alt="">
  <?php else: ?>
    <span class="vc-avatar-fallback"><?= e(function_exists('mb_substr') ? mb_substr($name, 0, 1) : substr($name, 0, 1)) ?></span>
  <?php endif; ?>
  <span class="vc-dot<?= $online ? ' is-online' : ' is-offline' ?>" title="<?= $online ? 'آنلاین' : 'آفلاین' ?>"></span>
</span>
    <?php
    return (string) ob_get_clean();
}

function video_call_person_row_html(array $c, bool $clinician, bool $pick = false, string $mode = 'buttons'): string
{
    $roleKey = (string) ($c['role'] ?? '');
    $isPatient = $roleKey === 'PATIENT';
    $label = video_call_public_name($c);
    $roleLabel = match ($roleKey) {
        'DOCTOR' => 'درمانگر',
        'ADMIN' => 'مدیر',
        'SECRETARY' => 'منشی',
        default => 'مراجعه‌کننده',
    };
    $payload = htmlspecialchars(json_encode(video_call_contact_payload($c), JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8');
    ob_start();
    ?>
            <div class="vc-person" data-user-id="<?= e((string) ($c['id'] ?? '')) ?>"<?php if ($mode === 'select'): ?> data-vc-select="<?= $payload ?>"<?php endif; ?>>
              <?php if ($clinician && $pick && $isPatient): ?>
                <label class="vc-pick-wrap" title="انتخاب برای گروه">
                  <input class="vc-pick" type="checkbox" value="<?= e((string) $c['id']) ?>">
                </label>
              <?php endif; ?>
              <?= video_call_avatar_html($c, !empty($c['online']), 'sm') ?>
              <span class="vc-person-meta">
                <strong><?= e($label) ?></strong>
                <span class="muted"><?= !empty($c['online']) ? 'آنلاین' : 'آفلاین' ?> · <?= e($roleLabel) ?></span>
              </span>
              <?php if ($clinician && $mode === 'buttons'): ?>
                <span class="vc-person-calls">
                  <button type="button" class="btn btn-primary btn-sm" data-vc-call data-peer="<?= e((string) $c['id']) ?>" data-media="video">تصویری</button>
                  <button type="button" class="btn btn-outline btn-sm" data-vc-call data-peer="<?= e((string) $c['id']) ?>" data-media="audio">صوتی</button>
                </span>
              <?php endif; ?>
            </div>
    <?php
    return (string) ob_get_clean();
}

function video_call_contact_payload(array $c): array
{
    $name = video_call_public_name($c);

    return [
        'id' => (string) ($c['id'] ?? ''),
        'name' => $name,
        'online' => !empty($c['online']),
        'role' => (string) ($c['role'] ?? ''),
        'videoUrl' => video_call_direct_url((string) ($c['id'] ?? ''), 'video'),
        'audioUrl' => video_call_direct_url((string) ($c['id'] ?? ''), 'audio'),
        'letter' => function_exists('mb_substr') ? mb_substr($name, 0, 1) : substr($name, 0, 1),
        'peer' => (string) ($c['id'] ?? ''),
    ];
}

function video_call_normalize_search(string $q): string
{
    $q = function_exists('normalize_input') ? normalize_input($q) : trim($q);
    $q = str_replace(['ي', 'ك', '‌'], ['ی', 'ک', ''], $q);
    $q = preg_replace('/\s+/u', ' ', $q) ?? $q;

    return trim($q);
}

/** ارقام قابل جستجو در موبایل (۰۹۱۰، +98، فاصله و خط تیره) */
function video_call_search_digits(string $q): string
{
    $q = function_exists('normalize_phone') ? normalize_phone($q) : video_call_normalize_search($q);
    $q = preg_replace('/\D+/', '', $q) ?? '';

    return $q;
}

function video_call_phone_like_needles(string $digits): array
{
    if (strlen($digits) < 3) {
        return [];
    }
    $out = ['%' . $digits . '%'];
    if (str_starts_with($digits, '98') && strlen($digits) >= 10) {
        $nat = substr($digits, 2);
        $out[] = '%' . $nat . '%';
        $out[] = '%0' . $nat . '%';
    }
    if (str_starts_with($digits, '0') && strlen($digits) >= 10) {
        $nat = substr($digits, 1);
        $out[] = '%' . $nat . '%';
        $out[] = '%98' . $nat . '%';
    }
    if (!str_starts_with($digits, '0') && !str_starts_with($digits, '98') && strlen($digits) >= 10) {
        $out[] = '%0' . $digits . '%';
        $out[] = '%98' . $digits . '%';
    }

    return array_values(array_unique($out));
}

function video_call_bump_contact(PDO $pdo, string $hostId, string $peerId): void
{
    $hostId = trim($hostId);
    $peerId = trim($peerId);
    if ($hostId === '' || $peerId === '' || $hostId === $peerId) {
        return;
    }
    ensure_video_call_schema($pdo);
    $pdo->prepare("
      INSERT INTO video_call_contact_stats (host_user_id, peer_user_id, call_count, last_at)
      VALUES (?,?,1,NOW())
      ON DUPLICATE KEY UPDATE call_count = call_count + 1, last_at = NOW()
    ")->execute([$hostId, $peerId]);
}

function video_call_bump_room_contacts(PDO $pdo, array $user, array $room): void
{
    $me = (string) ($user['id'] ?? '');
    if ($me === '' || !video_call_is_clinician($user)) {
        return;
    }
    foreach (video_call_room_members_public($pdo, $room) as $m) {
        $id = (string) ($m['id'] ?? '');
        if ($id !== '' && $id !== $me) {
            video_call_bump_contact($pdo, $me, $id);
        }
    }
}

function video_call_contact_freq_sql(): string
{
    return "
      LEFT JOIN (
        SELECT peer_id, SUM(score) AS hits, MAX(last_at) AS last_at
        FROM (
          SELECT peer_user_id AS peer_id, call_count * 10 AS score, last_at
          FROM video_call_contact_stats
          WHERE host_user_id = ?
          UNION ALL
          SELECT m.user_id AS peer_id, COUNT(*) * 4 AS score, MAX(r.created_at) AS last_at
          FROM video_call_room_members m
          JOIN video_call_rooms r ON r.id = m.room_id
          WHERE r.host_user_id = ? AND m.user_id <> ?
          GROUP BY m.user_id
          UNION ALL
          SELECT IF(
              SUBSTRING_INDEX(SUBSTRING(r.room_key, 4), '-', 1) = ?,
              SUBSTRING_INDEX(SUBSTRING(r.room_key, 4), '-', -1),
              SUBSTRING_INDEX(SUBSTRING(r.room_key, 4), '-', 1)
            ) AS peer_id,
            8 AS score,
            r.created_at AS last_at
          FROM video_call_rooms r
          WHERE r.kind = 'direct'
            AND (
              r.host_user_id = ?
              OR r.room_key LIKE CONCAT('dm-', ?, '-%')
              OR r.room_key LIKE CONCAT('dm-%-', ?)
            )
          UNION ALL
          SELECT a.patient_id AS peer_id, COUNT(*) AS score, MAX(a.starts_at) AS last_at
          FROM appointments a
          JOIN doctor_profiles me ON me.id = a.doctor_id
          WHERE me.user_id = ? AND a.status <> 'CANCELLED'
          GROUP BY a.patient_id
        ) ranked
        GROUP BY peer_id
      ) freq ON freq.peer_id = u.id
    ";
}

function video_call_contacts(PDO $pdo, array $user, string $q = '', string $onlyRole = '', int $limit = 40, array $onlyIds = []): array
{
    $me = (string) ($user['id'] ?? '');
    $role = (string) ($user['role'] ?? '');
    $q = video_call_normalize_search($q);
    $like = '%' . $q . '%';
    $digits = video_call_search_digits($q);
    $phoneNeedles = video_call_phone_like_needles($digits);
    $onlyRole = strtoupper(trim($onlyRole));
    $onlyIds = array_values(array_unique(array_filter(array_map('strval', $onlyIds))));
    $limit = max(1, min(80, $onlyIds ? count($onlyIds) : $limit));
    $rows = [];
    $freqSql = video_call_contact_freq_sql();
    $freqParams = [$me, $me, $me, $me, $me, $me, $me, $me];
    $searchSql = '';
    $searchParams = [];
    if ($q !== '') {
        $nameNorm = "REPLACE(REPLACE(REPLACE(LOWER(u.name),'ي','ی'),'ك','ک'),'‌','')";
        $userNorm = 'LOWER(u.username)';
        $searchSql = "
          AND (
            $nameNorm LIKE LOWER(?)
            OR $userNorm LIKE LOWER(?)
            OR LOWER(IFNULL(u.email,'')) LIKE LOWER(?)
            OR IFNULL(u.phone,'') LIKE ?
        ";
        $searchParams = [$like, $like, $like, '%' . $q . '%'];
        if ($digits !== '') {
            $searchSql .= " OR REPLACE(REPLACE(REPLACE(REPLACE(IFNULL(u.phone,''),' ',''),'-',''),'+',''),'.','') LIKE ? ";
            $searchParams[] = '%' . $digits . '%';
        }
        foreach ($phoneNeedles as $needle) {
            $searchSql .= " OR IFNULL(u.phone,'') LIKE ? ";
            $searchParams[] = $needle;
        }
        $searchSql .= '
          )
        ';
    }
    if ($role === 'DOCTOR' || $role === 'ADMIN') {
        $allowedRoles = ['PATIENT', 'DOCTOR', 'ADMIN', 'SECRETARY'];
        $roles = in_array($onlyRole, $allowedRoles, true) ? [$onlyRole] : $allowedRoles;
        $inRoles = implode(',', array_fill(0, count($roles), '?'));
        $sql = "
          SELECT u.id, u.name, u.username, u.role, MAX(dp.avatar_url) AS avatar_url
          FROM users u
          LEFT JOIN doctor_profiles dp ON dp.user_id = u.id
          $freqSql
          WHERE u.id <> ?
            AND u.role IN ($inRoles)
        ";
        $params = array_merge($freqParams, [$me], $roles);
        if ($onlyIds) {
            $sql .= ' AND u.id IN (' . implode(',', array_fill(0, count($onlyIds), '?')) . ')';
            $params = array_merge($params, $onlyIds);
        }
        $sql .= $searchSql;
        $params = array_merge($params, $searchParams);
        $orderPrefix = '';
        if ($q !== '') {
            $orderPrefix = "CASE
              WHEN LOWER(u.username) = LOWER(?) THEN 0
              WHEN LOWER(u.username) LIKE LOWER(?) THEN 1
              WHEN REPLACE(REPLACE(REPLACE(LOWER(u.name),'ي','ی'),'ك','ک'),'‌','') LIKE LOWER(?) THEN 2
              ELSE 3 END, ";
            $params[] = $q;
            $params[] = $q . '%';
            $params[] = $q . '%';
        }
        $sql .= '
          GROUP BY u.id, u.name, u.username, u.role
          ORDER BY ' . $orderPrefix . 'COALESCE(MAX(freq.hits), 0) DESC, MAX(freq.last_at) DESC, u.name ASC
          LIMIT ' . $limit;
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll();
    } else {
        $sql = "
          SELECT u.id, u.name, u.username, u.role, dp.avatar_url,
                 COALESCE(st.call_count, 0) AS contact_hits
          FROM users u
          JOIN doctor_profiles dp ON dp.user_id = u.id
          LEFT JOIN video_call_contact_stats st ON st.host_user_id = u.id AND st.peer_user_id = ?
          WHERE u.id <> ? AND u.role = 'DOCTOR'
            AND (
              dp.id IN (SELECT doctor_id FROM appointments WHERE patient_id = ?)
              OR dp.id IN (
                SELECT w.doctor_id FROM workshops w
                JOIN workshop_enrollments e ON e.workshop_id = w.id
                WHERE e.patient_id = ? AND e.status IN ('CONFIRMED','COMPLETED')
              )
            )
        ";
        $params = [$me, $me, $me, $me];
        $sql .= $searchSql;
        $params = array_merge($params, $searchParams);
        $sql .= ' ORDER BY contact_hits DESC, u.name ASC LIMIT 40';
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll();
    }
    $ids = array_map(static fn ($r) => (string) ($r['id'] ?? ''), $rows);
    $online = video_call_online_map($pdo, $ids);
    foreach ($rows as &$row) {
        $row['online'] = !empty($online[(string) ($row['id'] ?? '')]);
    }
    unset($row);

    return $rows;
}

function video_call_saved_rooms(PDO $pdo, array $user): array
{
    $me = (string) ($user['id'] ?? '');
    $stmt = $pdo->prepare("
      SELECT DISTINCT r.*
      FROM video_call_rooms r
      LEFT JOIN video_call_room_members m ON m.room_id = r.id AND m.user_id = ?
      LEFT JOIN workshop_enrollments e
        ON e.workshop_id = r.workshop_id
       AND e.patient_id = ?
       AND e.status IN ('CONFIRMED','COMPLETED')
      WHERE r.kind IN ('group','workshop')
        AND (r.host_user_id = ? OR m.user_id IS NOT NULL OR e.id IS NOT NULL)
      ORDER BY r.created_at DESC
      LIMIT 40
    ");
    $stmt->execute([$me, $me, $me]);

    return $stmt->fetchAll();
}

function video_call_room_by_key(PDO $pdo, string $key): ?array
{
    $stmt = $pdo->prepare('SELECT * FROM video_call_rooms WHERE room_key=? LIMIT 1');
    $stmt->execute([$key]);
    $row = $stmt->fetch();

    return is_array($row) ? $row : null;
}

function video_call_room_by_token(PDO $pdo, string $token): ?array
{
    $token = trim($token);
    if ($token === '') {
        return null;
    }
    $stmt = $pdo->prepare('SELECT * FROM video_call_rooms WHERE share_token=? LIMIT 1');
    $stmt->execute([$token]);
    $row = $stmt->fetch();

    return is_array($row) ? $row : null;
}

function video_call_user_can_access_room(PDO $pdo, array $user, array $room): bool
{
    $me = (string) ($user['id'] ?? '');
    if ($me === '') {
        return false;
    }
    if ((string) ($room['host_user_id'] ?? '') === $me) {
        return true;
    }
    $kind = (string) ($room['kind'] ?? '');
    if ($kind === 'direct') {
        $key = (string) ($room['room_key'] ?? '');
        return str_contains($key, $me);
    }
    if ($kind === 'workshop') {
        $wid = (string) ($room['workshop_id'] ?? '');
        if ($wid === '') {
            return false;
        }
        $en = $pdo->prepare("
          SELECT e.id FROM workshop_enrollments e
          WHERE e.workshop_id=? AND e.patient_id=? AND e.status IN ('CONFIRMED','COMPLETED')
          LIMIT 1
        ");
        $en->execute([$wid, $me]);
        if ($en->fetch()) {
            return true;
        }
        $memWs = $pdo->prepare('SELECT user_id FROM video_call_room_members WHERE room_id=? AND user_id=? LIMIT 1');
        $memWs->execute([(string) $room['id'], $me]);
        if ($memWs->fetch()) {
            return true;
        }
        $doc = $pdo->prepare('
          SELECT dp.user_id FROM workshops w
          JOIN doctor_profiles dp ON dp.id = w.doctor_id
          WHERE w.id=? LIMIT 1
        ');
        $doc->execute([$wid]);
        return (string) ($doc->fetchColumn() ?: '') === $me;
    }
    $mem = $pdo->prepare('SELECT user_id FROM video_call_room_members WHERE room_id=? AND user_id=? LIMIT 1');
    $mem->execute([(string) $room['id'], $me]);

    return (bool) $mem->fetch();
}

function video_call_upsert_room(
    PDO $pdo,
    string $roomKey,
    string $kind,
    string $title,
    string $hostUserId,
    ?string $workshopId = null
): array {
    ensure_video_call_schema($pdo);
    $existing = video_call_room_by_key($pdo, $roomKey);
    if ($existing) {
        return $existing;
    }
    $id = cuid();
    $token = video_call_new_token();
    $pdo->prepare('
      INSERT INTO video_call_rooms (id, room_key, kind, title, host_user_id, workshop_id, share_token)
      VALUES (?,?,?,?,?,?,?)
    ')->execute([$id, $roomKey, $kind, $title, $hostUserId, $workshopId, $token]);
    $row = video_call_room_by_key($pdo, $roomKey);

    return is_array($row) ? $row : ['id' => $id, 'room_key' => $roomKey, 'kind' => $kind, 'title' => $title, 'host_user_id' => $hostUserId, 'workshop_id' => $workshopId, 'share_token' => $token];
}

function video_call_ensure_direct_room(PDO $pdo, array $host, string $peerId): ?array
{
    $me = (string) ($host['id'] ?? '');
    if ($me === '' || $peerId === '' || $me === $peerId) {
        return null;
    }
    $peer = $pdo->prepare('SELECT id, name, role FROM users WHERE id=? LIMIT 1');
    $peer->execute([$peerId]);
    $p = $peer->fetch();
    if (!is_array($p)) {
        return null;
    }
    $hostId = video_call_is_clinician($host) ? $me : ((string) ($p['role'] ?? '') === 'DOCTOR' || (string) ($p['role'] ?? '') === 'ADMIN' ? $peerId : $me);
    $title = trim((string) ($p['name'] ?? 'تماس'));

    return video_call_upsert_room($pdo, video_call_direct_key($me, $peerId), 'direct', $title, $hostId);
}

function video_call_ensure_workshop_room(PDO $pdo, string $workshopId, string $actorUserId): ?array
{
    $stmt = $pdo->prepare('
      SELECT w.id, w.title, w.type, dp.user_id AS doctor_user_id
      FROM workshops w
      JOIN doctor_profiles dp ON dp.id = w.doctor_id
      WHERE w.id=? LIMIT 1
    ');
    $stmt->execute([$workshopId]);
    $w = $stmt->fetch();
    if (!is_array($w)) {
        return null;
    }
    $host = (string) ($w['doctor_user_id'] ?? '');
    $room = video_call_upsert_room(
        $pdo,
        video_call_workshop_key($workshopId),
        'workshop',
        (string) ($w['title'] ?? 'جلسه آنلاین کارگاه'),
        $host,
        $workshopId
    );
    $user = ['id' => $actorUserId];
    if (!video_call_user_can_access_room($pdo, $user + ['role' => 'PATIENT'], $room) && $actorUserId !== $host) {
        $full = current_user() ?: $user;
        if (!video_call_user_can_access_room($pdo, $full, $room)) {
            return null;
        }
    }

    return $room;
}

function video_call_erase_room_rows(PDO $pdo, array $room): void
{
    $id = (string) ($room['id'] ?? '');
    $key = (string) ($room['room_key'] ?? '');
    if ($id === '') {
        return;
    }
    $pdo->prepare('DELETE FROM video_call_signals WHERE room_id=? OR room_id=?')->execute([$key !== '' ? $key : $id, $id]);
    $pdo->prepare('DELETE FROM video_call_room_members WHERE room_id=?')->execute([$id]);
    $pdo->prepare('DELETE FROM video_call_rooms WHERE id=?')->execute([$id]);
}

/** یک‌بار گروه آزمایشی MBSR را پاک می‌کند؛ ساخت گروه بعدی با این نام را مسدود نمی‌کند */
function video_call_purge_mbsr_groups(PDO $pdo): void
{
    try {
        $pdo->exec("
          CREATE TABLE IF NOT EXISTS video_call_meta (
            k VARCHAR(64) NOT NULL,
            v VARCHAR(255) NOT NULL,
            PRIMARY KEY (k)
          ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
        $done = $pdo->query("SELECT v FROM video_call_meta WHERE k='mbsr_purged' LIMIT 1");
        if ($done && (string) ($done->fetchColumn() ?: '') === '1') {
            return;
        }
        $rows = $pdo->query("
          SELECT id, room_key, title
          FROM video_call_rooms
          WHERE kind = 'group'
            AND (
              LOWER(title) LIKE '%mbsr%'
              OR LOWER(room_key) LIKE '%mbsr%'
            )
        ")->fetchAll();
        foreach ($rows as $row) {
            if (is_array($row)) {
                video_call_erase_room_rows($pdo, $row);
            }
        }
        $pdo->prepare("INSERT INTO video_call_meta (k, v) VALUES ('mbsr_purged','1') ON DUPLICATE KEY UPDATE v='1'")
            ->execute();
    } catch (Throwable $ignored) {
    }
}

function video_call_delete_hosted_room(PDO $pdo, array $user, string $roomKey): void
{
    if (!video_call_is_clinician($user)) {
        throw new RuntimeException('فقط درمانگر می‌تواند گروه را حذف کند.');
    }
    $room = video_call_room_by_key($pdo, $roomKey);
    if (!$room) {
        throw new RuntimeException('گروه یافت نشد.');
    }
    if ((string) ($room['kind'] ?? '') !== 'group') {
        throw new RuntimeException('فقط گروه ذخیره‌شده قابل حذف است.');
    }
    $host = (string) ($room['host_user_id'] ?? '');
    $me = (string) ($user['id'] ?? '');
    $admin = (string) ($user['role'] ?? '') === 'ADMIN';
    if ($host !== $me && !$admin) {
        throw new RuntimeException('فقط سازنده گروه می‌تواند آن را حذف کند.');
    }
    video_call_erase_room_rows($pdo, $room);
}

function video_call_create_group(PDO $pdo, array $user, string $title, array $memberIds = [], string $workshopId = ''): array
{
    if (!video_call_is_clinician($user)) {
        throw new RuntimeException('فقط درمانگر می‌تواند گروه بسازد.');
    }
    $title = trim($title);
    $workshopId = trim($workshopId);
    $add = [];
    $memberIds = array_values(array_unique(array_filter(array_map('strval', $memberIds))));
    if ($memberIds) {
        $in = implode(',', array_fill(0, count($memberIds), '?'));
        $stmt = $pdo->prepare("
          SELECT id FROM users
          WHERE id IN ($in)
            AND id <> ?
            AND role IN ('PATIENT','DOCTOR','ADMIN','SECRETARY')
        ");
        $stmt->execute(array_merge($memberIds, [(string) ($user['id'] ?? '')]));
        foreach ($stmt->fetchAll() as $c) {
            $add[(string) ($c['id'] ?? '')] = true;
        }
    }
    if ($workshopId !== '') {
        $room = video_call_ensure_workshop_room($pdo, $workshopId, (string) $user['id']);
        if (!$room) {
            throw new RuntimeException('این کارگاه برای شما در دسترس نیست.');
        }
        if ($title !== '') {
            $pdo->prepare('UPDATE video_call_rooms SET title=? WHERE id=?')->execute([$title, (string) $room['id']]);
            $room['title'] = $title;
        }
        $ins = $pdo->prepare('INSERT IGNORE INTO video_call_room_members (room_id, user_id) VALUES (?,?)');
        $ins->execute([(string) $room['id'], (string) $user['id']]);
        foreach (array_keys($add) as $uid) {
            $ins->execute([(string) $room['id'], $uid]);
        }

        return $room;
    }
    if ($title === '') {
        $title = 'جلسه گروهی';
    }
    $key = 'grp-' . cuid();
    $room = video_call_upsert_room($pdo, $key, 'group', $title, (string) $user['id']);
    $ins = $pdo->prepare('INSERT IGNORE INTO video_call_room_members (room_id, user_id) VALUES (?,?)');
    $ins->execute([(string) $room['id'], (string) $user['id']]);
    foreach (array_keys($add) as $uid) {
        $ins->execute([(string) $room['id'], $uid]);
    }

    return $room;
}

/** کارگاه‌های درمانگر برای اتصال گروه تماس */
function video_call_host_workshops(PDO $pdo, array $user): array
{
    $uid = (string) ($user['id'] ?? '');
    if ($uid === '') {
        return [];
    }
    $sql = "
      SELECT w.id, w.title, w.type, w.status
      FROM workshops w
      JOIN doctor_profiles dp ON dp.id = w.doctor_id
      WHERE w.status NOT IN ('CANCELLED')
    ";
    $params = [];
    if ((string) ($user['role'] ?? '') === 'DOCTOR') {
        $sql .= ' AND dp.user_id = ?';
        $params[] = $uid;
    }
    $sql .= ' ORDER BY w.created_at DESC LIMIT 80';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    return $stmt->fetchAll() ?: [];
}

function video_call_room_url(array $room, array $extra = []): string
{
    $q = array_merge(['room' => (string) ($room['room_key'] ?? '')], $extra);

    return url('/video-call?' . http_build_query($q));
}

function video_call_share_url(array $room): string
{
    $token = (string) ($room['share_token'] ?? '');

    return url('/video-call?join=' . rawurlencode($token));
}

function video_call_workshop_enter_url(string $workshopId): string
{
    return url('/video-call?workshop=' . rawurlencode($workshopId));
}

function video_call_direct_url(string $peerId, string $media = 'video'): string
{
    return url('/video-call?peer=' . rawurlencode($peerId) . '&media=' . rawurlencode($media));
}

function video_call_session_payload(PDO $pdo, array $user, array $room, string $media = 'video'): array
{
    $media = $media === 'audio' ? 'audio' : 'video';
    $members = video_call_room_members_public($pdo, $room);
    $peerName = (string) ($room['title'] ?? 'جلسه');
    $me = (string) ($user['id'] ?? '');
    if ((string) ($room['kind'] ?? '') === 'direct') {
        foreach ($members as $m) {
            if ((string) ($m['id'] ?? '') !== $me) {
                $peerName = trim((string) ($m['name'] ?? $peerName));
                break;
            }
        }
    }
    $clinician = video_call_is_clinician($user);

    return [
        'ok' => true,
        'room' => (string) ($room['room_key'] ?? ''),
        'title' => $peerName,
        'kind' => (string) ($room['kind'] ?? ''),
        'group' => (string) ($room['kind'] ?? '') !== 'direct',
        'media' => $media,
        'shareUrl' => $clinician ? video_call_share_url($room) : '',
        'canStart' => $clinician,
    ];
}

function video_call_room_members_public(PDO $pdo, array $room): array
{
    $ids = [];
    $kind = (string) ($room['kind'] ?? '');
    if ($kind === 'direct') {
        $key = (string) ($room['room_key'] ?? '');
        if (preg_match('/^dm-([a-zA-Z0-9_-]+)-([a-zA-Z0-9_-]+)$/', $key, $m)) {
            $ids = [$m[1], $m[2]];
        }
    } elseif ($kind === 'workshop') {
        $ids[] = (string) ($room['host_user_id'] ?? '');
        $st = $pdo->prepare("
          SELECT e.patient_id FROM workshop_enrollments e
          WHERE e.workshop_id=? AND e.status IN ('CONFIRMED','COMPLETED')
        ");
        $st->execute([(string) ($room['workshop_id'] ?? '')]);
        foreach ($st->fetchAll() as $row) {
            $ids[] = (string) ($row['patient_id'] ?? '');
        }
    } else {
        $ids[] = (string) ($room['host_user_id'] ?? '');
        $st = $pdo->prepare('SELECT user_id FROM video_call_room_members WHERE room_id=?');
        $st->execute([(string) $room['id']]);
        foreach ($st->fetchAll() as $row) {
            $ids[] = (string) ($row['user_id'] ?? '');
        }
    }
    $ids = array_values(array_unique(array_filter($ids)));
    if ($ids === []) {
        return [];
    }
    $in = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $pdo->prepare("
      SELECT u.id, u.name, u.role, dp.avatar_url
      FROM users u
      LEFT JOIN doctor_profiles dp ON dp.user_id = u.id
      WHERE u.id IN ($in)
    ");
    $stmt->execute($ids);
    $people = $stmt->fetchAll();
    $online = video_call_online_map($pdo, $ids);
    foreach ($people as &$p) {
        $p['online'] = !empty($online[(string) $p['id']]);
    }
    unset($p);

    return $people;
}

function video_call_join_via_token(PDO $pdo, array $user, string $token): ?array
{
    $room = video_call_room_by_token($pdo, $token);
    if (!$room) {
        return null;
    }
    $me = (string) ($user['id'] ?? '');
    if ($me !== '' && (string) ($room['kind'] ?? '') === 'group') {
        $pdo->prepare('INSERT IGNORE INTO video_call_room_members (room_id, user_id) VALUES (?,?)')
            ->execute([(string) $room['id'], $me]);
    }
    if (!video_call_user_can_access_room($pdo, $user, $room) && (string) ($room['kind'] ?? '') === 'group') {
        return $room;
    }
    if (!video_call_user_can_access_room($pdo, $user, $room)) {
        return null;
    }

    return $room;
}
