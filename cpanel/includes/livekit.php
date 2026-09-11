<?php
declare(strict_types=1);

/**
 * LiveKit V2 bridge for Mana Clinic.
 *
 * Required server environment variables:
 *   LIVEKIT_URL=wss://<project>.livekit.cloud
 *   LIVEKIT_API_KEY=...
 *   LIVEKIT_API_SECRET=...
 *
 * Secrets are never sent to the browser. Only short-lived room access tokens are.
 */

function mana_livekit_config(): array
{
    return [
        'url' => trim((string) (getenv('LIVEKIT_URL') ?: '')),
        'key' => trim((string) (getenv('LIVEKIT_API_KEY') ?: '')),
        'secret' => (string) (getenv('LIVEKIT_API_SECRET') ?: ''),
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

function mana_livekit_room_name(string $roomKey): string
{
    // LiveKit advises against putting PII in room names. Existing room keys can
    // contain user identifiers, so use a deterministic opaque room name instead.
    return 'mana-' . substr(hash('sha256', 'mana-livekit-room|' . $roomKey), 0, 32);
}

function mana_livekit_identity(string $userId): string
{
    return 'u-' . substr(hash('sha256', 'mana-livekit-user|' . $userId), 0, 24);
}

function mana_livekit_token(array $user, string $roomKey, int $ttl = 7200): array
{
    $cfg = mana_livekit_config();
    if ($cfg['url'] === '' || $cfg['key'] === '' || $cfg['secret'] === '') {
        throw new RuntimeException('LiveKit هنوز روی سرور تنظیم نشده است.');
    }

    $userId = trim((string) ($user['id'] ?? ''));
    if ($userId === '' || $roomKey === '') {
        throw new RuntimeException('اطلاعات کاربر یا اتاق نامعتبر است.');
    }

    $now = time();
    $header = [
        'alg' => 'HS256',
        'typ' => 'JWT',
    ];
    $payload = [
        'iss' => $cfg['key'],
        'sub' => mana_livekit_identity($userId),
        'nbf' => $now - 5,
        'iat' => $now,
        'exp' => $now + max(300, min($ttl, 21600)),
        'metadata' => json_encode([
            'role' => (string) ($user['role'] ?? ''),
            'manaUserId' => $userId,
        ], JSON_UNESCAPED_UNICODE),
        'video' => [
            'roomJoin' => true,
            'room' => mana_livekit_room_name($roomKey),
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
        'roomName' => mana_livekit_room_name($roomKey),
        'identity' => mana_livekit_identity($userId),
        'expiresAt' => $payload['exp'],
    ];
}
