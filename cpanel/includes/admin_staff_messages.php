<?php
declare(strict_types=1);

const ADMIN_STAFF_MSG_MAX_BYTES = 5242880;

function ensure_admin_staff_messages_schema(PDO $pdo): void
{
    static $ready = false;
    if ($ready) {
        return;
    }
    if ($pdo->inTransaction()) {
        return;
    }
    $pdo->exec("
      CREATE TABLE IF NOT EXISTS admin_staff_messages (
        id VARCHAR(32) PRIMARY KEY,
        from_user_id VARCHAR(32) NOT NULL,
        body TEXT NOT NULL,
        image_path VARCHAR(255) NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_admin_staff_msg_created (created_at)
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    $pdo->exec("
      CREATE TABLE IF NOT EXISTS admin_staff_message_recipients (
        id VARCHAR(32) PRIMARY KEY,
        message_id VARCHAR(32) NOT NULL,
        to_user_id VARCHAR(32) NOT NULL,
        read_at DATETIME NULL,
        UNIQUE KEY uq_admin_msg_recipient (message_id, to_user_id),
        INDEX idx_admin_msg_to_unread (to_user_id, read_at, message_id)
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    $ready = true;
}

function admin_staff_msg_root(): string
{
    return dirname(__DIR__) . '/uploads/admin_staff_messages';
}

function admin_staff_msg_allowed_images(): array
{
    return [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
    ];
}

function admin_staff_msg_save_image(array $file, string $messageId): string
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        throw new RuntimeException('عکسی انتخاب نشده است.');
    }
    if (($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
        throw new RuntimeException('آپلود عکس ناموفق بود.');
    }
    if (($file['size'] ?? 0) > ADMIN_STAFF_MSG_MAX_BYTES) {
        throw new RuntimeException('حجم عکس حداکثر ۵ مگابایت باشد.');
    }
    $tmp = (string) ($file['tmp_name'] ?? '');
    if ($tmp === '' || !is_uploaded_file($tmp)) {
        throw new RuntimeException('فایل عکس معتبر نیست.');
    }
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = (string) $finfo->file($tmp);
    $allowed = admin_staff_msg_allowed_images();
    if (!isset($allowed[$mime])) {
        throw new RuntimeException('فقط تصویر JPG، PNG یا WEBP مجاز است.');
    }
    $dir = admin_staff_msg_root();
    if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
        throw new RuntimeException('ساخت پوشه عکس‌ها ناموفق بود.');
    }
    $safeId = preg_replace('/[^a-zA-Z0-9_-]/', '', $messageId) ?: cuid();
    $relative = $safeId . '-' . bin2hex(random_bytes(4)) . '.' . $allowed[$mime];
    $dest = $dir . '/' . $relative;
    if (!move_uploaded_file($tmp, $dest)) {
        throw new RuntimeException('ذخیره عکس ناموفق بود.');
    }

    return $relative;
}

function admin_staff_msg_abs(string $relative): string
{
    $root = realpath(admin_staff_msg_root()) ?: admin_staff_msg_root();
    $abs = $root . DIRECTORY_SEPARATOR . basename($relative);
    $real = realpath($abs);
    if ($real === false || !str_starts_with($real, $root)) {
        return $root . DIRECTORY_SEPARATOR . 'missing';
    }

    return $real;
}

function admin_staff_msg_image_url(string $messageId): string
{
    return url('/staff/admin-message-image?id=' . rawurlencode($messageId));
}

function admin_staff_msg_secretaries(PDO $pdo): array
{
    return $pdo->query("
      SELECT id, name, username
      FROM users
      WHERE role = 'SECRETARY'
      ORDER BY name ASC, username ASC
    ")->fetchAll() ?: [];
}

/** اولین پیام خوانده‌نشده ادمین برای این منشی */
function admin_staff_msg_pending_for(PDO $pdo, string $userId): ?array
{
    ensure_admin_staff_messages_schema($pdo);
    $stmt = $pdo->prepare("
      SELECT r.id AS recipient_id, r.message_id, r.read_at,
             m.body, m.image_path, m.created_at, m.from_user_id,
             fu.name AS from_name, fu.username AS from_username
      FROM admin_staff_message_recipients r
      JOIN admin_staff_messages m ON m.id = r.message_id
      JOIN users fu ON fu.id = m.from_user_id
      WHERE r.to_user_id = ? AND r.read_at IS NULL
      ORDER BY m.created_at ASC
      LIMIT 1
    ");
    $stmt->execute([$userId]);
    $row = $stmt->fetch();

    return $row ?: null;
}

function admin_staff_msg_ack(PDO $pdo, string $userId, string $recipientId): void
{
    ensure_admin_staff_messages_schema($pdo);
    $pdo->prepare('
      UPDATE admin_staff_message_recipients
      SET read_at = NOW()
      WHERE id = ? AND to_user_id = ? AND read_at IS NULL
    ')->execute([$recipientId, $userId]);
}

/**
 * @param list<string> $toUserIds
 */
function admin_staff_msg_send(PDO $pdo, string $fromUserId, string $body, array $toUserIds, ?array $imageFile = null): array
{
    ensure_admin_staff_messages_schema($pdo);
    $body = function_exists('sanitize_rich_html') ? sanitize_rich_html($body) : trim(str_replace(["\r\n", "\r"], "\n", $body));
    $plain = trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($body), ENT_QUOTES | ENT_HTML5, 'UTF-8')) ?? '');
    if ($plain === '') {
        throw new RuntimeException('متن پیام را بنویسید.');
    }
    if (mb_strlen($plain) > 8000) {
        throw new RuntimeException('متن پیام خیلی طولانی است.');
    }

    $secretaries = admin_staff_msg_secretaries($pdo);
    $byId = [];
    foreach ($secretaries as $s) {
        $byId[(string) $s['id']] = $s;
    }
    $targets = [];
    foreach ($toUserIds as $id) {
        $id = trim((string) $id);
        if ($id !== '' && isset($byId[$id])) {
            $targets[$id] = $byId[$id];
        }
    }
    if (!$targets) {
        throw new RuntimeException('حداقل یک منشی را انتخاب کنید.');
    }

    $messageId = cuid();
    $imagePath = null;
    $hasUpload = is_array($imageFile)
        && (int) ($imageFile['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;
    if ($hasUpload) {
        $imagePath = admin_staff_msg_save_image($imageFile, $messageId);
    }

    $count = admin_staff_msg_insert($pdo, $messageId, $fromUserId, $body, $imagePath, array_keys($targets));

    return [
        'count' => $count,
        'message_id' => $messageId,
        'image_path' => $imagePath,
    ];
}

/**
 * ارسال پیام با مسیر عکس از قبل ذخیره‌شده (برای ارسال جدا به چند منشی بدون آپلود مجدد).
 *
 * @param list<string> $toUserIds
 * @return array{count:int,message_id:string,image_path:?string}
 */
function admin_staff_msg_send_copy(PDO $pdo, string $fromUserId, string $body, array $toUserIds, ?string $existingImagePath = null): array
{
    ensure_admin_staff_messages_schema($pdo);
    $body = function_exists('sanitize_rich_html') ? sanitize_rich_html($body) : trim(str_replace(["\r\n", "\r"], "\n", $body));
    $plain = trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($body), ENT_QUOTES | ENT_HTML5, 'UTF-8')) ?? '');
    if ($plain === '') {
        throw new RuntimeException('متن پیام را بنویسید.');
    }
    $secretaries = admin_staff_msg_secretaries($pdo);
    $byId = [];
    foreach ($secretaries as $s) {
        $byId[(string) $s['id']] = $s;
    }
    $targets = [];
    foreach ($toUserIds as $id) {
        $id = trim((string) $id);
        if ($id !== '' && isset($byId[$id])) {
            $targets[$id] = $byId[$id];
        }
    }
    if (!$targets) {
        throw new RuntimeException('حداقل یک منشی را انتخاب کنید.');
    }

    $messageId = cuid();
    $imagePath = null;
    $existingImagePath = $existingImagePath !== null ? trim($existingImagePath) : '';
    if ($existingImagePath !== '') {
        $src = admin_staff_msg_abs($existingImagePath);
        if (is_file($src)) {
            $ext = pathinfo($src, PATHINFO_EXTENSION) ?: 'jpg';
            $safeId = preg_replace('/[^a-zA-Z0-9_-]/', '', $messageId) ?: cuid();
            $relative = $safeId . '-' . bin2hex(random_bytes(4)) . '.' . $ext;
            $dest = admin_staff_msg_root() . '/' . $relative;
            if (@copy($src, $dest)) {
                $imagePath = $relative;
            }
        }
    }

    $count = admin_staff_msg_insert($pdo, $messageId, $fromUserId, $body, $imagePath, array_keys($targets));

    return [
        'count' => $count,
        'message_id' => $messageId,
        'image_path' => $imagePath,
    ];
}

/**
 * @param list<string> $targetIds
 */
function admin_staff_msg_insert(PDO $pdo, string $messageId, string $fromUserId, string $body, ?string $imagePath, array $targetIds): int
{
    $pdo->prepare('INSERT INTO admin_staff_messages (id, from_user_id, body, image_path) VALUES (?,?,?,?)')
        ->execute([$messageId, $fromUserId, $body, $imagePath]);

    $fromStmt = $pdo->prepare('SELECT name, username, role FROM users WHERE id=? LIMIT 1');
    $fromStmt->execute([$fromUserId]);
    $from = $fromStmt->fetch() ?: [];
    $fromRole = strtoupper((string) ($from['role'] ?? ''));
    $fromLabel = function_exists('staff_actor_label') ? staff_actor_label($from) : (string) ($from['name'] ?? 'کاربر');
    $title = $fromRole === 'DOCTOR' ? ('پیام درمانگر · ' . $fromLabel) : 'پیام مدیر سایت';

    $ins = $pdo->prepare('INSERT INTO admin_staff_message_recipients (id, message_id, to_user_id) VALUES (?,?,?)');
    $snippet = trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($body), ENT_QUOTES | ENT_HTML5, 'UTF-8')) ?? '');
    foreach ($targetIds as $tid) {
        $ins->execute([cuid(), $messageId, $tid]);
        notify_user(
            $pdo,
            $tid,
            $title,
            mb_substr($snippet, 0, 180),
            '/secretary/profile#admin-site-messages',
            'admin_directive',
            $fromUserId
        );
    }

    if (function_exists('mentions_capture')) {
        mentions_capture($pdo, $fromUserId, $body, 'staff_message', $messageId, '/secretary/profile#admin-site-messages');
    }

    return count($targetIds);
}

function admin_staff_msg_sent_list(PDO $pdo, int $limit = 40, ?string $fromUserId = null): array
{
    ensure_admin_staff_messages_schema($pdo);
    $limit = max(1, min(80, $limit));
    if ($fromUserId) {
        $stmt = $pdo->prepare("
          SELECT m.*, fu.name AS from_name, fu.username AS from_username, fu.role AS from_role
          FROM admin_staff_messages m
          JOIN users fu ON fu.id = m.from_user_id
          WHERE m.from_user_id = ?
          ORDER BY m.created_at DESC
          LIMIT {$limit}
        ");
        $stmt->execute([$fromUserId]);
        $msgs = $stmt->fetchAll() ?: [];
    } else {
        $msgs = $pdo->query("
          SELECT m.*, fu.name AS from_name, fu.username AS from_username, fu.role AS from_role
          FROM admin_staff_messages m
          JOIN users fu ON fu.id = m.from_user_id
          ORDER BY m.created_at DESC
          LIMIT {$limit}
        ")->fetchAll() ?: [];
    }
    if (!$msgs) {
        return [];
    }
    $ids = array_map(static fn($m) => (string) $m['id'], $msgs);
    $place = implode(',', array_fill(0, count($ids), '?'));
    $rec = $pdo->prepare("
      SELECT r.message_id, r.read_at, u.name AS to_name, u.username AS to_username
      FROM admin_staff_message_recipients r
      JOIN users u ON u.id = r.to_user_id
      WHERE r.message_id IN ({$place})
      ORDER BY u.name ASC
    ");
    $rec->execute($ids);
    $byMsg = [];
    foreach ($rec->fetchAll() as $row) {
        $byMsg[(string) $row['message_id']][] = $row;
    }
    foreach ($msgs as &$m) {
        $m['recipients'] = $byMsg[(string) $m['id']] ?? [];
        $unread = 0;
        foreach ($m['recipients'] as $r) {
            if (empty($r['read_at'])) {
                $unread++;
            }
        }
        $m['unread_count'] = $unread;
    }
    unset($m);

    return $msgs;
}

function admin_staff_msg_inbox_for(PDO $pdo, string $userId, int $limit = 40): array
{
    ensure_admin_staff_messages_schema($pdo);
    $limit = max(1, min(60, $limit));
    $stmt = $pdo->prepare("
      SELECT r.id AS recipient_id, r.read_at, r.message_id,
             m.body, m.image_path, m.created_at,
             fu.name AS from_name, fu.username AS from_username
      FROM admin_staff_message_recipients r
      JOIN admin_staff_messages m ON m.id = r.message_id
      JOIN users fu ON fu.id = m.from_user_id
      WHERE r.to_user_id = ?
      ORDER BY m.created_at DESC
      LIMIT {$limit}
    ");
    $stmt->execute([$userId]);

    return $stmt->fetchAll() ?: [];
}

function admin_staff_msg_get_for_user(PDO $pdo, string $messageId, string $userId): ?array
{
    ensure_admin_staff_messages_schema($pdo);
    $stmt = $pdo->prepare("
      SELECT m.*, r.id AS recipient_id, r.read_at
      FROM admin_staff_messages m
      JOIN admin_staff_message_recipients r ON r.message_id = m.id
      WHERE m.id = ? AND r.to_user_id = ?
      LIMIT 1
    ");
    $stmt->execute([$messageId, $userId]);
    $row = $stmt->fetch();

    return $row ?: null;
}

function admin_staff_msg_get(PDO $pdo, string $messageId): ?array
{
    ensure_admin_staff_messages_schema($pdo);
    $stmt = $pdo->prepare('SELECT * FROM admin_staff_messages WHERE id=? LIMIT 1');
    $stmt->execute([$messageId]);
    $row = $stmt->fetch();

    return $row ?: null;
}

function admin_staff_msg_user_can_view_image(PDO $pdo, array $user, string $messageId): bool
{
    $role = strtoupper((string) ($user['role'] ?? ''));
    if ($role === 'ADMIN') {
        return admin_staff_msg_get($pdo, $messageId) !== null;
    }
    if ($role === 'SECRETARY') {
        return admin_staff_msg_get_for_user($pdo, $messageId, (string) ($user['id'] ?? '')) !== null;
    }
    if ($role === 'DOCTOR') {
        $msg = admin_staff_msg_get($pdo, $messageId);
        return $msg && (string) ($msg['from_user_id'] ?? '') === (string) ($user['id'] ?? '');
    }

    return false;
}

function admin_staff_msg_update(PDO $pdo, string $messageId, string $body): void
{
    ensure_admin_staff_messages_schema($pdo);
    $msg = admin_staff_msg_get($pdo, $messageId);
    if (!$msg) {
        throw new RuntimeException('پیام پیدا نشد.');
    }
    $body = function_exists('sanitize_rich_html') ? sanitize_rich_html($body) : trim(str_replace(["\r\n", "\r"], "\n", $body));
    $plain = trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($body), ENT_QUOTES | ENT_HTML5, 'UTF-8')) ?? '');
    if ($plain === '') {
        throw new RuntimeException('متن پیام را بنویسید.');
    }
    if (mb_strlen($plain) > 8000) {
        throw new RuntimeException('متن پیام خیلی طولانی است.');
    }
    $pdo->prepare('UPDATE admin_staff_messages SET body=? WHERE id=?')->execute([$body, $messageId]);
}

function admin_staff_msg_delete(PDO $pdo, string $messageId): void
{
    ensure_admin_staff_messages_schema($pdo);
    $msg = admin_staff_msg_get($pdo, $messageId);
    if (!$msg) {
        return;
    }
    $pdo->prepare('DELETE FROM admin_staff_message_recipients WHERE message_id=?')->execute([$messageId]);
    $pdo->prepare('DELETE FROM admin_staff_messages WHERE id=?')->execute([$messageId]);
    $path = trim((string) ($msg['image_path'] ?? ''));
    if ($path !== '') {
        $abs = admin_staff_msg_abs($path);
        if (is_file($abs)) {
            @unlink($abs);
        }
    }
}
