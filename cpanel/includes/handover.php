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
        group_id VARCHAR(32) NULL,
        from_user_id VARCHAR(32) NOT NULL,
        to_user_id VARCHAR(32) NOT NULL,
        body TEXT NOT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        read_at DATETIME NULL,
        INDEX idx_handover_to_unread (to_user_id, read_at, created_at)
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    try {
        $hasGroup = $pdo->query("SHOW COLUMNS FROM staff_handover_notes LIKE 'group_id'")->fetch();
        if (!$hasGroup) {
            $pdo->exec('ALTER TABLE staff_handover_notes ADD COLUMN group_id VARCHAR(32) NULL AFTER id');
        }
    } catch (Throwable $ignored) {
    }
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

function handover_send(PDO $pdo, string $fromUserId, string $fromLabel, string $body): int
{
    ensure_handover_schema($pdo);
    $body = trim($body);
    if ($body === '') {
        throw new RuntimeException('متن پیام را بنویسید.');
    }
    $peers = handover_other_secretaries($pdo, $fromUserId);
    if (!$peers) {
        throw new RuntimeException('منشی دیگری برای ارسال نیست.');
    }

    $groupId = cuid();
    $title = 'پیام همکار از ' . $fromLabel;
    $insert = $pdo->prepare('INSERT INTO staff_handover_notes (id, group_id, from_user_id, to_user_id, body) VALUES (?,?,?,?,?)');
    foreach ($peers as $target) {
        $insert->execute([cuid(), $groupId, $fromUserId, (string) $target['id'], $body]);
        notify_user($pdo, (string) $target['id'], $title, $body, '/secretary/colleague', 'other');
    }
    notify_role($pdo, 'DOCTOR', $title, $body, '/doctor/notifications?kind=other', 'other');
    notify_role($pdo, 'ADMIN', $title, $body, '/admin/messages', 'other');

    return count($peers);
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
      SELECT
        COALESCE(h.group_id, h.id) AS group_id,
        h.body,
        MIN(h.created_at) AS created_at,
        SUM(CASE WHEN h.read_at IS NULL THEN 1 ELSE 0 END) AS unread_count,
        COUNT(*) AS recipient_count
      FROM staff_handover_notes h
      WHERE h.from_user_id = ?
      GROUP BY COALESCE(h.group_id, h.id), h.body
      ORDER BY MIN(h.created_at) DESC
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
