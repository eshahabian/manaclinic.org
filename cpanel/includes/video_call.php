<?php
declare(strict_types=1);

function video_call_usernames(): array
{
    return ['eshahabian', 'mbabei'];
}

function video_call_is_clinician(?array $user): bool
{
    if (!$user) {
        return false;
    }
    $username = strtolower(trim((string) ($user['username'] ?? '')));
    if ($username === 'eshahabian') {
        return false;
    }
    if ($username === 'mbabei') {
        return true;
    }

    return ($user['role'] ?? '') === 'DOCTOR';
}

function video_call_allowed(?array $user): bool
{
    if (!$user) {
        return false;
    }
    $username = strtolower(trim((string) ($user['username'] ?? '')));

    return in_array($username, video_call_usernames(), true);
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
        room_id VARCHAR(64) NOT NULL,
        sender_id VARCHAR(32) NOT NULL,
        kind VARCHAR(16) NOT NULL,
        payload MEDIUMTEXT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_vcs_room_time (room_id, created_at)
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    $pdo->exec("
      CREATE TABLE IF NOT EXISTS video_call_presence (
        user_id VARCHAR(32) PRIMARY KEY,
        last_seen DATETIME NOT NULL,
        INDEX idx_vcp_seen (last_seen)
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    $ready = true;
}

function video_call_room_id(): string
{
    return 'test-eshahabian-mbabei';
}

function video_call_peer(PDO $pdo, array $user): ?array
{
    $me = strtolower(trim((string) ($user['username'] ?? '')));
    $other = $me === 'eshahabian' ? 'mbabei' : 'eshahabian';
    $stmt = $pdo->prepare('SELECT id, name, username, role FROM users WHERE username=? LIMIT 1');
    $stmt->execute([$other]);
    $row = $stmt->fetch();

    return is_array($row) ? $row : null;
}

function video_call_touch(PDO $pdo, string $userId): void
{
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

    return $ts !== false && (time() - $ts) < 12;
}

function video_call_nav_link(bool $withType = false): ?array
{
    if (!video_call_allowed(current_user())) {
        return null;
    }
    $item = ['href' => '/video-call', 'label' => 'تماس تصویری مانا'];
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
    global $pdo;
    if (!$pdo instanceof PDO) {
        return null;
    }
    $peer = video_call_peer($pdo, $user);

    return [
        'signalUrl' => url('/video-signal'),
        'callUrl' => url('/video-call'),
        'peerName' => (string) ($peer['name'] ?? 'طرف مقابل'),
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
