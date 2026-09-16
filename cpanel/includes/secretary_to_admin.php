<?php
declare(strict_types=1);

/** پیام منشی‌ها به مدیر سایت */
function ensure_secretary_to_admin_schema(PDO $pdo): void
{
    static $ready = false;
    if ($ready) {
        return;
    }
    $pdo->exec("
      CREATE TABLE IF NOT EXISTS secretary_to_admin_messages (
        id VARCHAR(32) PRIMARY KEY,
        from_user_id VARCHAR(32) NOT NULL,
        body TEXT NOT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        read_at DATETIME NULL,
        INDEX idx_sec_to_admin_unread (read_at, created_at),
        INDEX idx_sec_to_admin_from (from_user_id, created_at)
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    $ready = true;
}

function secretary_to_admin_admins(PDO $pdo): array
{
    return $pdo->query("
      SELECT id, name, username
      FROM users
      WHERE role = 'ADMIN'
      ORDER BY name ASC, username ASC
    ")->fetchAll() ?: [];
}

function secretary_to_admin_send(PDO $pdo, string $fromUserId, string $fromLabel, string $body): string
{
    ensure_secretary_to_admin_schema($pdo);
    $body = function_exists('sanitize_rich_html') ? sanitize_rich_html($body) : trim($body);
    $plain = trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($body), ENT_QUOTES | ENT_HTML5, 'UTF-8')) ?? '');
    if ($plain === '') {
        throw new RuntimeException('متن پیام را بنویسید.');
    }
    if (mb_strlen($plain) > 8000) {
        throw new RuntimeException('متن پیام خیلی طولانی است.');
    }
    $admins = secretary_to_admin_admins($pdo);
    if (!$admins) {
        throw new RuntimeException('مدیری برای دریافت پیام نیست.');
    }

    $id = cuid();
    $pdo->prepare('INSERT INTO secretary_to_admin_messages (id, from_user_id, body) VALUES (?,?,?)')
        ->execute([$id, $fromUserId, $body]);

    $title = 'پیام منشی: ' . $fromLabel;
    $snippet = mb_substr($plain, 0, 180);
    foreach ($admins as $admin) {
        notify_user(
            $pdo,
            (string) $admin['id'],
            $title,
            $snippet,
            '/admin/secretary-messages#from-secretaries',
            'admin_directive'
        );
    }

    if (function_exists('mentions_capture')) {
        mentions_capture($pdo, $fromUserId, $body, 'secretary_to_admin', $id, '/admin/secretary-messages#from-secretaries');
    }

    return $id;
}

function secretary_to_admin_sent_for(PDO $pdo, string $fromUserId, int $limit = 40): array
{
    ensure_secretary_to_admin_schema($pdo);
    $limit = max(1, min(100, $limit));
    $stmt = $pdo->prepare("
      SELECT *
      FROM secretary_to_admin_messages
      WHERE from_user_id = ?
      ORDER BY created_at DESC
      LIMIT {$limit}
    ");
    $stmt->execute([$fromUserId]);
    return $stmt->fetchAll() ?: [];
}

function secretary_to_admin_inbox(PDO $pdo, int $limit = 50): array
{
    ensure_secretary_to_admin_schema($pdo);
    $limit = max(1, min(120, $limit));
    return $pdo->query("
      SELECT m.*, fu.name AS from_name, fu.username AS from_username
      FROM secretary_to_admin_messages m
      JOIN users fu ON fu.id = m.from_user_id
      ORDER BY m.created_at DESC
      LIMIT {$limit}
    ")->fetchAll() ?: [];
}

function secretary_to_admin_unread_count(PDO $pdo): int
{
    ensure_secretary_to_admin_schema($pdo);
    return (int) $pdo->query('SELECT COUNT(*) FROM secretary_to_admin_messages WHERE read_at IS NULL')->fetchColumn();
}

function secretary_to_admin_ack(PDO $pdo, string $messageId): void
{
    ensure_secretary_to_admin_schema($pdo);
    $pdo->prepare('UPDATE secretary_to_admin_messages SET read_at=NOW() WHERE id=? AND read_at IS NULL')
        ->execute([$messageId]);
}

function secretary_to_admin_ack_all(PDO $pdo): int
{
    ensure_secretary_to_admin_schema($pdo);
    return (int) $pdo->exec('UPDATE secretary_to_admin_messages SET read_at=NOW() WHERE read_at IS NULL');
}

function secretary_to_admin_delete(PDO $pdo, string $messageId): void
{
    ensure_secretary_to_admin_schema($pdo);
    $messageId = trim($messageId);
    if ($messageId === '') {
        return;
    }
    $pdo->prepare('DELETE FROM secretary_to_admin_messages WHERE id=?')->execute([$messageId]);
}
