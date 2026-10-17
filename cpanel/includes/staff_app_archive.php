<?php
declare(strict_types=1);

function staff_app_archive_ensure(PDO $pdo): void
{
    static $ready = false;
    if ($ready) return;
    $pdo->exec("CREATE TABLE IF NOT EXISTS staff_app_chat_archive (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        snapshot_key CHAR(64) NOT NULL UNIQUE,
        message_id VARCHAR(32) NOT NULL, room_id VARCHAR(32) NOT NULL,
        user_id VARCHAR(32) NOT NULL, sender_name VARCHAR(255) NOT NULL,
        room_title VARCHAR(255) NOT NULL, body TEXT NOT NULL,
        reply_to VARCHAR(32) NULL, forward_from VARCHAR(80) NULL,
        message_created_at DATETIME NOT NULL, edited_at DATETIME NULL,
        event VARCHAR(20) NOT NULL, archived_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_chat_archive_message(message_id), INDEX idx_chat_archive_room(room_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS staff_app_archive_files (
        id VARCHAR(32) PRIMARY KEY, message_id VARCHAR(32) NOT NULL,
        stored_name VARCHAR(80) NOT NULL, original_name VARCHAR(180) NOT NULL,
        mime VARCHAR(120) NOT NULL, size INT NOT NULL,
        INDEX idx_archive_file_message(message_id), INDEX idx_archive_file_stored(stored_name)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS staff_app_archive_meta (id TINYINT PRIMARY KEY) ENGINE=InnoDB");
    if (!$pdo->query('SELECT id FROM staff_app_archive_meta WHERE id=1')->fetchColumn()) {
        $pdo->beginTransaction();
        try {
            staff_app_archive_capture($pdo);
            $pdo->exec('INSERT IGNORE INTO staff_app_archive_meta(id) VALUES(1)');
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
    }
    $ready = true;
}

/** Append-only snapshots, with no foreign keys to deletable chat/user records. */
function staff_app_archive_capture(PDO $pdo, ?string $messageId = null, ?string $roomId = null, string $event = 'saved'): void
{
    $where = $messageId !== null ? 'm.id=?' : ($roomId !== null ? 'm.room_id=?' : '1=1');
    $filter = $messageId !== null ? [$messageId] : ($roomId !== null ? [$roomId] : []);
    $pdo->prepare("INSERT IGNORE INTO staff_app_chat_archive
        (snapshot_key,message_id,room_id,user_id,sender_name,room_title,body,reply_to,forward_from,message_created_at,edited_at,event)
        SELECT SHA2(CONCAT(m.id,CHAR(0),m.body,CHAR(0),COALESCE(m.edited_at,''),CHAR(0),?),256),
        m.id,m.room_id,m.user_id,COALESCE(u.name,m.user_id),COALESCE(r.title,'چت'),m.body,m.reply_to,m.forward_from,m.created_at,m.edited_at,?
        FROM staff_app_messages m LEFT JOIN users u ON u.id=m.user_id
        LEFT JOIN staff_app_rooms r ON r.id=m.room_id WHERE $where")
        ->execute(array_merge([$event,$event],$filter));
    $pdo->prepare("INSERT IGNORE INTO staff_app_archive_files (id,message_id,stored_name,original_name,mime,size)
        SELECT f.id,f.message_id,f.stored_name,f.original_name,f.mime,f.size
        FROM staff_app_files f JOIN staff_app_messages m ON m.id=f.message_id WHERE $where")
        ->execute($filter);
}

function staff_app_archive_file_retained(PDO $pdo, string $storedName): bool
{
    $stmt = $pdo->prepare('SELECT 1 FROM staff_app_archive_files WHERE stored_name=? LIMIT 1');
    $stmt->execute([$storedName]);
    return (bool) $stmt->fetchColumn();
}

function staff_app_archive_records(PDO $pdo, array $viewer, int $before = 0): array
{
    if (!staff_app_is_eshahabian($viewer)) throw new RuntimeException('آرشیو پیام‌ها فقط برای عماد شهابیان است.');
    $stmt = $pdo->prepare('SELECT * FROM staff_app_chat_archive' . ($before > 0 ? ' WHERE id<?' : '') . ' ORDER BY id DESC LIMIT 100');
    $stmt->execute($before > 0 ? [$before] : []);
    $rows = $stmt->fetchAll() ?: [];
    if ($rows === []) return [];
    $ids = array_values(array_unique(array_column($rows, 'message_id')));
    $files = $pdo->prepare('SELECT * FROM staff_app_archive_files WHERE message_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')');
    $files->execute($ids);
    $byMessage = [];
    foreach ($files->fetchAll() ?: [] as $file) $byMessage[(string) $file['message_id']][] = $file;
    foreach ($rows as &$row) $row['files'] = $byMessage[(string) $row['message_id']] ?? [];
    unset($row);
    return $rows;
}
