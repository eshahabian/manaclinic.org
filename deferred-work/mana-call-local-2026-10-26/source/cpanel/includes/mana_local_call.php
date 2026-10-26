<?php
declare(strict_types=1);

function mana_local_call_config(): array
{
    global $config;
    $file = __DIR__ . '/../config.call-local.php';
    $private = is_file($file) ? require $file : [];
    $urls = trim((string) (getenv('MANA_LOCAL_TURN_URLS') ?: ''));
    $urls = $urls !== '' ? explode(',', $urls) : ($private['turn_urls'] ?? []);
    $secret = (string) (getenv('MANA_LOCAL_TURN_SECRET') ?: ($private['turn_secret'] ?? ''));
    $siteHost = strtolower((string) parse_url((string) ($config['app_url'] ?? ''), PHP_URL_HOST));
    if (!is_array($urls) || $urls === [] || strlen($secret) < 32 || $siteHost === '') {
        throw new RuntimeException('سرویس تماس داخلی روی سرور سایت هنوز تنظیم نشده است.');
    }
    $clean = [];
    foreach ($urls as $url) {
        $url = trim((string) $url);
        if (!preg_match('~^turns?:([a-z0-9.-]+):([0-9]{1,5})(?:\?transport=(udp|tcp))?$~i', $url, $m)) {
            throw new RuntimeException('آدرس واسط تماس داخلی معتبر نیست.');
        }
        $host = strtolower($m[1]);
        if (($host !== $siteHost && !str_ends_with($host, '.' . $siteHost)) || (int) $m[2] < 1 || (int) $m[2] > 65535) {
            throw new RuntimeException('واسط تماس داخلی باید روی دامنهٔ همین سایت باشد.');
        }
        $clean[] = $url;
    }
    return ['urls' => array_values(array_unique($clean)), 'secret' => $secret];
}

function mana_local_call_ice(array $user): array
{
    $cfg = mana_local_call_config();
    $id = trim((string) ($user['id'] ?? ''));
    if ($id === '') throw new RuntimeException('کاربر تماس مشخص نیست.');
    $username = (time() + 7200) . ':' . substr(hash('sha256', $id), 0, 24);
    return [['urls' => $cfg['urls'], 'username' => $username,
        'credential' => base64_encode(hash_hmac('sha1', $username, $cfg['secret'], true))]];
}

function mana_local_call_schema(PDO $pdo): void
{
    static $ready = false;
    if ($ready) return;
    try {
        if ((int) $pdo->query('SELECT schema_version FROM mana_call_routing WHERE id=1')->fetchColumn() === 1) {
            $ready = true;
            return;
        }
    } catch (PDOException $e) {
        if (($e->errorInfo[0] ?? $e->getCode()) !== '42S02') throw $e;
    }
    $pdo->exec("CREATE TABLE IF NOT EXISTS mana_call_routing (
        id TINYINT PRIMARY KEY, mode VARCHAR(8) NOT NULL DEFAULT 'livekit', schema_version INT NOT NULL DEFAULT 0
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("INSERT IGNORE INTO mana_call_routing (id, mode) VALUES (1, 'livekit')");
    $pdo->exec("CREATE TABLE IF NOT EXISTS mana_call_room_routes (
        room_id VARCHAR(32) PRIMARY KEY, mode VARCHAR(8) NOT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS mana_local_signals (
        seq BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, id VARCHAR(32) NOT NULL,
        room_id VARCHAR(80) NOT NULL, sender_id VARCHAR(32) NOT NULL,
        target_id VARCHAR(32) NULL, kind VARCHAR(16) NOT NULL, payload MEDIUMTEXT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_mls_room (room_id, seq), INDEX idx_mls_target (target_id, created_at),
        INDEX idx_mls_expiry (created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec('UPDATE mana_call_routing SET schema_version=1 WHERE id=1');
    $ready = true;
}

function mana_call_mode(PDO $pdo): string
{
    mana_local_call_schema($pdo);
    $mode = (string) $pdo->query('SELECT mode FROM mana_call_routing WHERE id=1')->fetchColumn();
    if (!in_array($mode, ['local', 'livekit'], true)) throw new RuntimeException('روش تماس مشخص نیست.');
    return $mode;
}

function mana_call_set_mode(PDO $pdo, string $mode): void
{
    if (!in_array($mode, ['local', 'livekit'], true)) throw new RuntimeException('روش تماس نامعتبر است.');
    if ($mode === 'local') mana_local_call_config();
    mana_local_call_schema($pdo);
    $pdo->prepare('UPDATE mana_call_routing SET mode=? WHERE id=1')->execute([$mode]);
}

function mana_call_entry_url(PDO $pdo, array $room, string $media = 'video', bool $answer = false): string
{
    $path = mana_call_room_mode($pdo, $room) === 'local' ? '/video-call-local' : '/video-call-live';
    return $path . '?room=' . rawurlencode((string) $room['room_key']) . '&media=' . ($media === 'audio' ? 'audio' : 'video')
        . ($answer ? '&answer=1' : '');
}

function mana_call_room_mode(PDO $pdo, array $room): string
{
    mana_local_call_schema($pdo);
    $st = $pdo->prepare('SELECT mode FROM mana_call_room_routes WHERE room_id=?');
    $st->execute([(string) $room['id']]);
    $mode = $st->fetchColumn();
    return in_array($mode, ['local', 'livekit'], true) ? $mode : mana_call_mode($pdo);
}

function mana_call_bind_room(PDO $pdo, array $room, ?string $engine = null): void
{
    $mode = $engine ?? mana_call_mode($pdo);
    if (!in_array($mode, ['local', 'livekit'], true)) throw new RuntimeException('روش تماس نامعتبر است.');
    mana_local_call_schema($pdo);
    if ($mode === 'local') mana_local_call_config();
    $pdo->prepare('INSERT INTO mana_call_room_routes (room_id, mode) VALUES (?,?) ON DUPLICATE KEY UPDATE mode=VALUES(mode)')
        ->execute([(string) $room['id'], $mode]);
}

function mana_call_resolve_room(PDO $pdo, array $user): ?array
{
    $join = trim((string) ($_GET['join'] ?? ''));
    $peer = trim((string) ($_GET['peer'] ?? ''));
    $workshop = trim((string) ($_GET['workshop'] ?? ''));
    if ($join !== '') return video_call_join_via_token($pdo, $user, $join);
    if ($peer !== '') return video_call_is_clinician($user) ? video_call_ensure_direct_room($pdo, $user, $peer) : null;
    if ($workshop !== '') return video_call_ensure_workshop_room($pdo, $workshop, (string) $user['id']);
    return video_call_room_by_key($pdo, trim((string) ($_GET['room'] ?? '')));
}

/** A saved direct-room URL must not bypass current clinician assignment. */
function mana_local_call_start_allowed(PDO $pdo, array $user, array $room): bool
{
    if (($room['kind'] ?? '') !== 'direct' || !video_call_contacts_assigned_only($user)) return true;
    foreach (video_call_room_members_public($pdo, $room) as $member) {
        if ((string) $member['id'] !== (string) $user['id']) {
            return video_call_contacts($pdo, $user, '', 'PATIENT', 1, [(string) $member['id']]) !== [];
        }
    }
    return false;
}
