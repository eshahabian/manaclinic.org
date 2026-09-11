<?php
declare(strict_types=1);

/**
 * LiveKit bridge for Mana Clinic.
 *
 * Credentials can be provided either as environment variables:
 *   LIVEKIT_URL=wss://<project>.livekit.cloud
 *   LIVEKIT_API_KEY=...
 *   LIVEKIT_API_SECRET=...
 *
 * or in the private cpanel/config.php file as:
 *   'livekit_url' => 'wss://<project>.livekit.cloud',
 *   'livekit_api_key' => '...',
 *   'livekit_api_secret' => '...',
 *
 * Secrets are never sent to the browser. Only short-lived room access tokens are.
 */

function mana_livekit_config(): array
{
    $private = [];
    $configFile = __DIR__ . '/../config.php';
    if (is_file($configFile)) {
        $loaded = require $configFile;
        if (is_array($loaded)) {
            $private = $loaded;
        }
    }

    $envUrl = trim((string) (getenv('LIVEKIT_URL') ?: ''));
    $envKey = trim((string) (getenv('LIVEKIT_API_KEY') ?: ''));
    $envSecret = (string) (getenv('LIVEKIT_API_SECRET') ?: '');

    return [
        'url' => $envUrl !== '' ? $envUrl : trim((string) ($private['livekit_url'] ?? '')),
        'key' => $envKey !== '' ? $envKey : trim((string) ($private['livekit_api_key'] ?? '')),
        'secret' => $envSecret !== '' ? $envSecret : (string) ($private['livekit_api_secret'] ?? ''),
    ];
}

function mana_livekit_ready(): bool
{
    $cfg = mana_livekit_config();
    return $cfg['url'] !== '' && $cfg['key'] !== '' && $cfg['secret'] !== '';
}

function mana_livekit_b64url(string $data): string
{
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

function mana_livekit_room_name(string $roomRef): string
{
    return 'mana-' . substr(hash('sha256', 'mana-livekit-room|' . $roomRef), 0, 32);
}

function mana_livekit_identity(string $userId): string
{
    return 'u-' . substr(hash('sha256', 'mana-livekit-user|' . $userId), 0, 24);
}

function mana_livekit_token(array $user, string $roomRef, int $ttl = 7200): array
{
    $cfg = mana_livekit_config();
    if ($cfg['url'] === '' || $cfg['key'] === '' || $cfg['secret'] === '') {
        throw new RuntimeException('LiveKit هنوز روی سرور تنظیم نشده است.');
    }

    $userId = trim((string) ($user['id'] ?? ''));
    if ($userId === '' || $roomRef === '') {
        throw new RuntimeException('اطلاعات کاربر یا اتاق نامعتبر است.');
    }

    $now = time();
    $displayName = trim((string) ($user['name'] ?? ''));
    if ($displayName === '') {
        $displayName = 'کاربر مانا';
    }
    $header = [
        'alg' => 'HS256',
        'typ' => 'JWT',
    ];
    $payload = [
        'iss' => $cfg['key'],
        'sub' => mana_livekit_identity($userId),
        'name' => $displayName,
        'nbf' => $now - 5,
        'iat' => $now,
        'exp' => $now + max(300, min($ttl, 21600)),
        'video' => [
            'roomJoin' => true,
            'room' => mana_livekit_room_name($roomRef),
            'roomCreate' => true,
            'canPublish' => true,
            'canSubscribe' => true,
            'canPublishData' => true,
        ],
    ];

    $h = mana_livekit_b64url((string) json_encode($header, JSON_UNESCAPED_SLASHES));
    $p = mana_livekit_b64url((string) json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    $sig = hash_hmac('sha256', $h . '.' . $p, $cfg['secret'], true);

    return [
        'serverUrl' => $cfg['url'],
        'participantToken' => $h . '.' . $p . '.' . mana_livekit_b64url($sig),
        'roomName' => mana_livekit_room_name($roomRef),
        'identity' => mana_livekit_identity($userId),
        'expiresAt' => $payload['exp'],
    ];
}
