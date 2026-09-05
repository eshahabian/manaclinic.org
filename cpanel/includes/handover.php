<?php
declare(strict_types=1);

function ensure_handover_schema(PDO $pdo): void
{
    static $ready = false;
    if ($ready) {
        return;
    }
    $pdo->exec("
      CREATE TABLE IF NOT EXISTS staff_handover_notes (
        id VARCHAR(32) PRIMARY KEY,
        from_user_id VARCHAR(32) NOT NULL,
        to_user_id VARCHAR(32) NOT NULL,
        body TEXT NOT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        read_at DATETIME NULL,
        INDEX idx_handover_to_unread (to_user_id, read_at, created_at)
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    $ready = true;
}

function handover_pending_for(PDO $pdo, string $userId): ?array
{
    ensure_handover_schema($pdo);
    $stmt = $pdo->prepare("
      SELECT h.*, fu.name AS from_name, fu.username AS from_username
      FROM staff_handover_notes h
      JOIN users fu ON fu.id = h.from_user_id
      WHERE h.to_user_id = ? AND h.read_at IS NULL
      ORDER BY h.created_at ASC
      LIMIT 1
    ");
    $stmt->execute([$userId]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function handover_send(PDO $pdo, string $fromUserId, string $fromLabel, string $toUserId, string $body): string
{
    ensure_handover_schema($pdo);
    $body = trim($body);
    if ($body === '') {
        throw new RuntimeException('متن پیام را بنویسید.');
    }
    if ($toUserId === '' || $toUserId === $fromUserId) {
        throw new RuntimeException('منشی گیرنده را انتخاب کنید.');
    }
    $to = $pdo->prepare("SELECT id, name FROM users WHERE id=? AND role='SECRETARY' LIMIT 1");
    $to->execute([$toUserId]);
    $target = $to->fetch();
    if (!$target) {
        throw new RuntimeException('منشی گیرنده معتبر نیست.');
    }

    $id = cuid();
    $pdo->prepare('INSERT INTO staff_handover_notes (id, from_user_id, to_user_id, body) VALUES (?,?,?,?)')
        ->execute([$id, $fromUserId, $toUserId, $body]);

    $title = 'پیام تحویل شیفت از ' . $fromLabel;
    $copy = "برای منشی «{$target['name']}»:\n\n" . $body;

    notify_user($pdo, $toUserId, $title, $body, '/secretary/messages', 'other');
    notify_role($pdo, 'DOCTOR', $title, $copy, '/doctor/notifications?kind=other', 'other');
    notify_role($pdo, 'ADMIN', $title, $copy, '/admin/messages', 'other');

    return $id;
}

function handover_ack(PDO $pdo, string $userId, string $noteId): void
{
    ensure_handover_schema($pdo);
    $pdo->prepare('UPDATE staff_handover_notes SET read_at=NOW() WHERE id=? AND to_user_id=? AND read_at IS NULL')
        ->execute([$noteId, $userId]);
}

function handover_sent_recent(PDO $pdo, string $fromUserId, int $limit = 15): array
{
    ensure_handover_schema($pdo);
    $limit = max(1, min(40, $limit));
    $stmt = $pdo->prepare("
      SELECT h.*, tu.name AS to_name
      FROM staff_handover_notes h
      JOIN users tu ON tu.id = h.to_user_id
      WHERE h.from_user_id = ?
      ORDER BY h.created_at DESC
      LIMIT {$limit}
    ");
    $stmt->execute([$fromUserId]);
    return $stmt->fetchAll();
}

function handover_other_secretaries(PDO $pdo, string $exceptUserId): array
{
    $stmt = $pdo->prepare("SELECT id, name, username FROM users WHERE role='SECRETARY' AND id<>? ORDER BY name ASC");
    $stmt->execute([$exceptUserId]);
    return $stmt->fetchAll();
}
