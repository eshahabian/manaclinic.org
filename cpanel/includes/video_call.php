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
        'label' => 'تماس تصویری مانا',
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

function video_call_public_name(array $person): string
{
    $name = trim((string) ($person['name'] ?? ''));
    $role = (string) ($person['role'] ?? '');
    if ($role !== 'PATIENT') {
        return $name !== '' ? $name : 'کاربر';
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

function video_call_person_row_html(array $c, bool $clinician, bool $pick = false): string
{
    $isPatient = (string) ($c['role'] ?? '') === 'PATIENT';
    $label = video_call_public_name($c);
    $roleLabel = $isPatient ? 'مراجعه‌کننده' : 'درمانگر';
    ob_start();
    ?>
            <div class="vc-person" data-user-id="<?= e((string) ($c['id'] ?? '')) ?>">
              <?php if ($clinician && $pick && $isPatient): ?>
                <label class="vc-pick-wrap" title="انتخاب برای گروه">
                  <input class="vc-pick" type="checkbox" form="vc-group-form" name="members[]" value="<?= e((string) $c['id']) ?>">
                </label>
              <?php endif; ?>
              <?= video_call_avatar_html($c, !empty($c['online'])) ?>
              <span class="vc-person-meta">
                <strong><?= e($label) ?></strong>
                <span class="muted"><?= !empty($c['online']) ? 'آنلاین' : 'آفلاین' ?> · <?= e($roleLabel) ?></span>
              </span>
              <?php if ($clinician): ?>
                <span class="vc-person-calls">
                  <a class="btn btn-primary btn-sm" href="<?= e(video_call_direct_url((string) $c['id'], 'video')) ?>">تصویری</a>
                  <a class="btn btn-outline btn-sm" href="<?= e(video_call_direct_url((string) $c['id'], 'audio')) ?>">صوتی</a>
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
    ];
}

function video_call_contacts(PDO $pdo, array $user, string $q = '', string $onlyRole = '', int $limit = 40, array $onlyIds = []): array
{
    $me = (string) ($user['id'] ?? '');
    $role = (string) ($user['role'] ?? '');
    $q = trim($q);
    $like = '%' . $q . '%';
    $onlyRole = strtoupper(trim($onlyRole));
    $onlyIds = array_values(array_unique(array_filter(array_map('strval', $onlyIds))));
    $limit = max(1, min(80, $onlyIds ? count($onlyIds) : $limit));
    $rows = [];
    if ($role === 'DOCTOR' || $role === 'ADMIN') {
        $roles = in_array($onlyRole, ['PATIENT', 'DOCTOR'], true) ? [$onlyRole] : ['PATIENT', 'DOCTOR'];
        $inRoles = implode(',', array_fill(0, count($roles), '?'));
        $sql = "
          SELECT DISTINCT u.id, u.name, u.username, u.role, dp.avatar_url
          FROM users u
          LEFT JOIN doctor_profiles dp ON dp.user_id = u.id
          WHERE u.id <> ?
            AND u.role IN ($inRoles)
        ";
        $params = array_merge([$me], $roles);
        if ($role === 'DOCTOR') {
            $sql .= "
              AND (
                u.role = 'DOCTOR'
                OR u.preferred_doctor_id IN (SELECT id FROM doctor_profiles WHERE user_id = ?)
                OR u.id IN (
                  SELECT a.patient_id FROM appointments a
                  JOIN doctor_profiles me ON me.id = a.doctor_id
                  WHERE me.user_id = ?
                )
                OR u.id IN (
                  SELECT e.patient_id FROM workshop_enrollments e
                  JOIN workshops w ON w.id = e.workshop_id
                  JOIN doctor_profiles me ON me.id = w.doctor_id
                  WHERE me.user_id = ? AND e.status IN ('CONFIRMED','COMPLETED')
                )
              )
            ";
            $params[] = $me;
            $params[] = $me;
            $params[] = $me;
        }
        if ($onlyIds) {
            $sql .= ' AND u.id IN (' . implode(',', array_fill(0, count($onlyIds), '?')) . ')';
            $params = array_merge($params, $onlyIds);
        }
        if ($q !== '') {
            $sql .= ' AND (u.name LIKE ? OR u.username LIKE ?)';
            $params[] = $like;
            $params[] = $like;
        }
        $sql .= ' ORDER BY u.name ASC LIMIT ' . $limit;
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll();
    } else {
        $sql = "
          SELECT DISTINCT u.id, u.name, u.username, u.role, dp.avatar_url
          FROM users u
          JOIN doctor_profiles dp ON dp.user_id = u.id
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
        $params = [$me, $me, $me];
        if ($q !== '') {
            $sql .= ' AND (u.name LIKE ? OR u.username LIKE ?)';
            $params[] = $like;
            $params[] = $like;
        }
        $sql .= ' ORDER BY u.name ASC LIMIT 40';
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

function video_call_create_group(PDO $pdo, array $user, string $title, array $memberIds = []): array
{
    if (!video_call_is_clinician($user)) {
        throw new RuntimeException('فقط درمانگر می‌تواند گروه بسازد.');
    }
    $title = trim($title);
    if ($title === '') {
        $title = 'جلسه گروهی';
    }
    $add = [];
    $memberIds = array_values(array_unique(array_filter(array_map('strval', $memberIds))));
    if ($memberIds) {
        foreach (video_call_contacts($pdo, $user, '', 'PATIENT', count($memberIds), $memberIds) as $c) {
            $add[(string) ($c['id'] ?? '')] = true;
        }
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
