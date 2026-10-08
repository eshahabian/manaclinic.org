<?php
declare(strict_types=1);

function staff_push_b64url(string $bin): string
{
    return rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
}

function staff_push_b64url_decode(string $value): string
{
    $value = strtr(trim($value), '-_', '+/');
    $pad = strlen($value) % 4;
    if ($pad !== 0) {
        $value .= str_repeat('=', 4 - $pad);
    }
    $raw = base64_decode($value, true);

    return is_string($raw) ? $raw : '';
}

/** @return array{public_key: string, private_pem: string} */
function staff_push_keys(PDO $pdo): array
{
    staff_app_ensure_schema($pdo);
    $row = $pdo->query('SELECT public_key, private_pem FROM staff_app_vapid WHERE id = 1')->fetch();
    if (is_array($row) && (string) ($row['public_key'] ?? '') !== '' && (string) ($row['private_pem'] ?? '') !== '') {
        return [
            'public_key' => (string) $row['public_key'],
            'private_pem' => (string) $row['private_pem'],
        ];
    }
    $key = openssl_pkey_new([
        'private_key_type' => OPENSSL_KEYTYPE_EC,
        'curve_name' => 'prime256v1',
    ]);
    if ($key === false) {
        throw new RuntimeException('کلید اعلان ساخته نشد.');
    }
    $pem = '';
    if (!openssl_pkey_export($key, $pem)) {
        throw new RuntimeException('کلید اعلان ذخیره نشد.');
    }
    $details = openssl_pkey_get_details($key);
    $x = str_pad((string) ($details['ec']['x'] ?? ''), 32, "\0", STR_PAD_LEFT);
    $y = str_pad((string) ($details['ec']['y'] ?? ''), 32, "\0", STR_PAD_LEFT);
    $public = staff_push_b64url("\x04" . $x . $y);
    $pdo->prepare('INSERT IGNORE INTO staff_app_vapid (id, public_key, private_pem) VALUES (1, ?, ?)')
        ->execute([$public, $pem]);
    $row = $pdo->query('SELECT public_key, private_pem FROM staff_app_vapid WHERE id = 1')->fetch();
    if (!is_array($row)) {
        throw new RuntimeException('کلید اعلان خوانده نشد.');
    }

    return [
        'public_key' => (string) $row['public_key'],
        'private_pem' => (string) $row['private_pem'],
    ];
}

function staff_push_public(PDO $pdo): string
{
    return staff_push_keys($pdo)['public_key'];
}

function staff_push_save(PDO $pdo, string $userId, string $endpoint, string $p256dh, string $auth): void
{
    if ($userId === '' || !str_starts_with($endpoint, 'https://') || strlen($endpoint) > 2000) {
        throw new RuntimeException('نشانی اعلان معتبر نیست.');
    }
    $pub = staff_push_b64url_decode($p256dh);
    $secret = staff_push_b64url_decode($auth);
    if (strlen($pub) !== 65 || ord($pub[0]) !== 4 || strlen($secret) < 16) {
        throw new RuntimeException('کلید اعلان مرورگر معتبر نیست.');
    }
    staff_app_ensure_schema($pdo);
    $hash = hash('sha256', $endpoint);
    $pdo->prepare('
      INSERT INTO staff_app_push (id, user_id, endpoint_hash, endpoint, p256dh, auth)
      VALUES (?, ?, ?, ?, ?, ?)
      ON DUPLICATE KEY UPDATE endpoint = VALUES(endpoint), p256dh = VALUES(p256dh), auth = VALUES(auth)
    ')->execute([cuid(), $userId, $hash, $endpoint, $p256dh, $auth]);
}

function staff_push_notify_room(PDO $pdo, string $roomId, string $senderId, string $senderName, string $body): void
{
    try {
        $stmt = $pdo->prepare('SELECT user_id FROM staff_app_members WHERE room_id = ? AND user_id <> ?');
        $stmt->execute([$roomId, $senderId]);
        $ids = [];
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) ?: [] as $id) {
            $id = (string) $id;
            if ($id !== '') {
                $ids[] = $id;
            }
        }
        if ($ids === []) {
            return;
        }
        $marks = implode(',', array_fill(0, count($ids), '?'));
        $sub = $pdo->prepare("SELECT id, endpoint, p256dh, auth FROM staff_app_push WHERE user_id IN ($marks)");
        $sub->execute($ids);
        $rows = $sub->fetchAll() ?: [];
        if ($rows === []) {
            return;
        }
        $preview = trim(preg_replace('/\s+/u', ' ', $body) ?? '');
        if ($preview === '') {
            $preview = 'فایل تازه';
        }
        if (mb_strlen($preview) > 120) {
            $preview = mb_substr($preview, 0, 120) . '…';
        }
        $who = $senderName !== '' ? $senderName : 'همکار';
        $payload = json_encode([
            'title' => 'مانا کارکنان',
            'body' => $who . ': ' . $preview,
            'url' => url('/app/chat/' . $roomId),
            'roomId' => $roomId,
            'tag' => 'sapp-' . $roomId,
        ], JSON_UNESCAPED_UNICODE);
        if (!is_string($payload)) {
            return;
        }
        $keys = staff_push_keys($pdo);
        foreach ($rows as $row) {
            staff_push_send($pdo, $row, $keys, $payload);
        }
    } catch (Throwable $e) {
        error_log('staff push: ' . $e->getMessage());
    }
}

/** @param array<string, mixed> $row @param array{public_key: string, private_pem: string} $keys */
function staff_push_send(PDO $pdo, array $row, array $keys, string $payload): void
{
    $endpoint = (string) ($row['endpoint'] ?? '');
    $parts = parse_url($endpoint);
    $scheme = (string) ($parts['scheme'] ?? '');
    $host = (string) ($parts['host'] ?? '');
    if ($scheme !== 'https' || $host === '') {
        return;
    }
    $body = staff_push_encrypt(
        $payload,
        staff_push_b64url_decode((string) ($row['p256dh'] ?? '')),
        staff_push_b64url_decode((string) ($row['auth'] ?? ''))
    );
    $jwt = staff_push_jwt($keys['private_pem'], $scheme . '://' . $host);
    $header = [
        'Content-Type: application/octet-stream',
        'Content-Encoding: aes128gcm',
        'TTL: 86400',
        'Urgency: high',
        'Authorization: vapid t=' . $jwt . ', k=' . $keys['public_key'],
    ];
    $status = 0;
    if (function_exists('curl_init')) {
        $ch = curl_init($endpoint);
        if ($ch === false) {
            return;
        }
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => $header,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 8,
            CURLOPT_HEADER => true,
        ]);
        curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
    }
    if ($status === 404 || $status === 410) {
        $pdo->prepare('DELETE FROM staff_app_push WHERE id = ?')->execute([(string) ($row['id'] ?? '')]);
    }
}

function staff_push_encrypt(string $payload, string $userPublic, string $userAuth): string
{
    if (strlen($userPublic) !== 65 || strlen($userAuth) < 16) {
        throw new RuntimeException('کلید گیرنده ناقص است.');
    }
    $local = openssl_pkey_new([
        'private_key_type' => OPENSSL_KEYTYPE_EC,
        'curve_name' => 'prime256v1',
    ]);
    if ($local === false) {
        throw new RuntimeException('کلید موقت ساخته نشد.');
    }
    $details = openssl_pkey_get_details($local);
    $x = str_pad((string) ($details['ec']['x'] ?? ''), 32, "\0", STR_PAD_LEFT);
    $y = str_pad((string) ($details['ec']['y'] ?? ''), 32, "\0", STR_PAD_LEFT);
    $localPublic = "\x04" . $x . $y;
    $pem = '';
    openssl_pkey_export($local, $pem);
    $secret = openssl_pkey_derive(staff_push_public_pem($userPublic), $pem, 32);
    if (!is_string($secret) || $secret === '') {
        throw new RuntimeException('رمز مشترک ساخته نشد.');
    }
    $salt = random_bytes(16);
    $keyInfo = "WebPush: info\x00" . $userPublic . $localPublic;
    $ikm = hash_hkdf('sha256', $secret, 32, $keyInfo, $userAuth);
    $cek = hash_hkdf('sha256', $ikm, 16, "Content-Encoding: aes128gcm\x00", $salt);
    $nonce = hash_hkdf('sha256', $ikm, 12, "Content-Encoding: nonce\x00", $salt);
    $tag = '';
    $cipher = openssl_encrypt($payload . "\x02", 'aes-128-gcm', $cek, OPENSSL_RAW_DATA, $nonce, $tag, '', 16);
    if (!is_string($cipher) || !is_string($tag) || strlen($tag) !== 16) {
        throw new RuntimeException('پیام اعلان رمز نشد.');
    }

    return $salt . pack('N', 4096) . chr(strlen($localPublic)) . $localPublic . $cipher . $tag;
}

function staff_push_public_pem(string $uncompressed): string
{
    $prefix = hex2bin('3059301306072a8648ce3d020106082a8648ce3d030107034200');
    $der = $prefix . $uncompressed;
    return "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($der), 64, "\n") . "-----END PUBLIC KEY-----\n";
}

function staff_push_jwt(string $privatePem, string $audience): string
{
    $header = staff_push_b64url((string) json_encode(['typ' => 'JWT', 'alg' => 'ES256']));
    $claims = staff_push_b64url((string) json_encode([
        'aud' => $audience,
        'exp' => time() + 12 * 3600,
        'sub' => 'https://manaclinic.org',
    ]));
    $unsigned = $header . '.' . $claims;
    $der = '';
    if (!openssl_sign($unsigned, $der, $privatePem, OPENSSL_ALGO_SHA256)) {
        throw new RuntimeException('امضای اعلان ساخته نشد.');
    }

    return $unsigned . '.' . staff_push_b64url(staff_push_der_to_raw($der));
}

function staff_push_der_to_raw(string $der): string
{
    $offset = 0;
    if (ord($der[$offset++]) !== 0x30) {
        throw new RuntimeException('امضا نامعتبر است.');
    }
    $seq = ord($der[$offset++]);
    if ($seq & 0x80) {
        $offset += ($seq & 0x7f);
    }
    if (ord($der[$offset++]) !== 0x02) {
        throw new RuntimeException('امضا نامعتبر است.');
    }
    $rLen = ord($der[$offset++]);
    $r = substr($der, $offset, $rLen);
    $offset += $rLen;
    if (ord($der[$offset++]) !== 0x02) {
        throw new RuntimeException('امضا نامعتبر است.');
    }
    $sLen = ord($der[$offset++]);
    $s = substr($der, $offset, $sLen);
    $r = ltrim($r, "\x00");
    $s = ltrim($s, "\x00");

    return str_pad($r, 32, "\x00", STR_PAD_LEFT) . str_pad($s, 32, "\x00", STR_PAD_LEFT);
}
