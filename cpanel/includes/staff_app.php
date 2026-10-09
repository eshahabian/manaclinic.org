<?php
declare(strict_types=1);

require_once __DIR__ . '/web_push.php';

function staff_app_sections(): array
{
    return [
        'home' => ['href' => '/app', 'label' => 'خانه', 'title' => 'برنامه داخلی'],
        'appointments' => ['href' => '/app/appointments', 'label' => 'وقت‌ها', 'title' => 'وقت‌ها'],
        'rooms' => ['href' => '/app/rooms', 'label' => 'اتاق‌ها', 'title' => 'شرایط اتاق‌ها'],
        'chat' => ['href' => '/app/chat', 'label' => 'چت', 'title' => 'چت کارکنان'],
        'hours' => ['href' => '/app/hours', 'label' => 'ساعت کار', 'title' => 'ساعت کار منشی‌ها'],
        'consult' => ['href' => '/app/consult', 'label' => 'مشاوره', 'title' => 'درخواست مشاوره'],
        'checklist' => ['href' => '/app/checklist', 'label' => 'کارهای روزانه', 'title' => 'لیست کارهای روزانه'],
        'complaints' => ['href' => '/app/complaints', 'label' => 'شکایت', 'title' => 'شکایت‌ها'],
    ];
}

function staff_app_allowed(array $user): bool
{
    $role = (string) ($user['role'] ?? '');
    if ($role === 'DOCTOR' || $role === 'SECRETARY') {
        return true;
    }

    return strtolower(trim((string) ($user['username'] ?? ''))) === 'eshahabian';
}

function staff_app_logout_href(): string
{
    return url('/logout?next=' . rawurlencode('/login?next=/app'));
}

function staff_app_user(): array
{
    $user = current_user();
    if (!$user) {
        $next = (string) ($GLOBALS['path'] ?? '/app');
        if (!is_staff_app_next($next)) {
            $next = '/app';
        }
        redirect('/login?next=' . rawurlencode($next));
    }
    $user = require_login();
    if (!staff_app_allowed($user)) {
        staff_app_render('home', 'برنامه داخلی', 'این برنامه فقط برای درمانگرها و منشی‌های مانا کلینیک است.', '<h1>برنامه داخلی</h1><p>این بخش فقط برای درمانگرها و منشی‌هاست.</p>', true);
        exit;
    }

    return $user;
}

function staff_app_ready(PDO $pdo): bool
{
    try {
        staff_app_ensure_schema($pdo);

        return true;
    } catch (Throwable $e) {
        error_log('staff app schema: ' . $e->getMessage());

        return false;
    }
}

function staff_app_ensure_schema(PDO $pdo): void
{
    static $ready = false;
    if ($ready) {
        return;
    }
    $pdo->exec("
      CREATE TABLE IF NOT EXISTS staff_app_rooms (
        id VARCHAR(32) PRIMARY KEY,
        title VARCHAR(120) NOT NULL,
        is_general TINYINT(1) NOT NULL DEFAULT 0,
        created_by VARCHAR(32) NOT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_staff_app_room_general (is_general)
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    $pdo->exec("
      CREATE TABLE IF NOT EXISTS staff_app_members (
        room_id VARCHAR(32) NOT NULL,
        user_id VARCHAR(32) NOT NULL,
        PRIMARY KEY (room_id, user_id),
        INDEX idx_staff_app_member_user (user_id)
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    $pdo->exec("
      CREATE TABLE IF NOT EXISTS staff_app_messages (
        id VARCHAR(32) PRIMARY KEY,
        room_id VARCHAR(32) NOT NULL,
        user_id VARCHAR(32) NOT NULL,
        body TEXT NOT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_staff_app_msg_room (room_id, created_at)
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    $pdo->exec("
      CREATE TABLE IF NOT EXISTS staff_app_files (
        id VARCHAR(32) PRIMARY KEY,
        message_id VARCHAR(32) NOT NULL,
        stored_name VARCHAR(80) NOT NULL,
        original_name VARCHAR(180) NOT NULL,
        mime VARCHAR(120) NOT NULL,
        size INT NOT NULL,
        INDEX idx_staff_app_file_msg (message_id)
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    $pdo->exec("
      CREATE TABLE IF NOT EXISTS staff_app_reads (
        user_id VARCHAR(32) NOT NULL,
        room_id VARCHAR(32) NOT NULL,
        read_at DATETIME NOT NULL,
        PRIMARY KEY (user_id, room_id)
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    $pdo->exec("
      CREATE TABLE IF NOT EXISTS staff_app_receipts (
        user_id VARCHAR(32) NOT NULL,
        room_id VARCHAR(32) NOT NULL,
        delivered_at DATETIME NOT NULL,
        PRIMARY KEY (user_id, room_id)
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    $pdo->exec("
      CREATE TABLE IF NOT EXISTS staff_app_reactions (
        message_id VARCHAR(32) NOT NULL,
        user_id VARCHAR(32) NOT NULL,
        emoji VARCHAR(32) NOT NULL,
        PRIMARY KEY (message_id, user_id)
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    $pdo->exec("
      CREATE TABLE IF NOT EXISTS staff_app_vapid (
        id TINYINT NOT NULL PRIMARY KEY,
        public_key VARCHAR(255) NOT NULL,
        private_pem TEXT NOT NULL
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    $pdo->exec("
      CREATE TABLE IF NOT EXISTS staff_app_push (
        id VARCHAR(32) NOT NULL PRIMARY KEY,
        user_id VARCHAR(32) NOT NULL,
        endpoint_hash CHAR(64) NOT NULL,
        endpoint TEXT NOT NULL,
        p256dh VARCHAR(255) NOT NULL,
        auth VARCHAR(255) NOT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_staff_push (user_id, endpoint_hash),
        INDEX idx_staff_push_user (user_id)
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    $pdo->exec("
      CREATE TABLE IF NOT EXISTS staff_app_presence (
        user_id VARCHAR(32) NOT NULL PRIMARY KEY,
        seen_at DATETIME NOT NULL,
        INDEX idx_staff_app_presence_seen (seen_at)
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    staff_app_add_column($pdo, 'staff_app_messages', 'reply_to', 'VARCHAR(32) NULL');
    staff_app_add_column($pdo, 'staff_app_messages', 'forward_from', 'VARCHAR(80) NULL');
    staff_app_add_column($pdo, 'staff_app_rooms', 'pinned_message_id', 'VARCHAR(32) NULL');
    staff_app_add_column($pdo, 'staff_app_rooms', 'is_private', 'TINYINT(1) NOT NULL DEFAULT 0');
    staff_app_add_column($pdo, 'staff_app_rooms', 'deleted_at', 'DATETIME NULL');
    staff_app_add_column($pdo, 'staff_app_rooms', 'deleted_by', 'VARCHAR(32) NULL');
    staff_app_add_column($pdo, 'staff_app_rooms', 'kept_forever', 'TINYINT(1) NOT NULL DEFAULT 0');
    $ready = true;
}

function staff_app_touch_presence(PDO $pdo, string $userId): void
{
    if ($userId === '') {
        return;
    }
    staff_app_ensure_schema($pdo);
    $pdo->prepare('
      INSERT INTO staff_app_presence (user_id, seen_at) VALUES (?, NOW())
      ON DUPLICATE KEY UPDATE seen_at = NOW()
    ')->execute([$userId]);
}

/** @return list<string> */
function staff_app_online_ids(PDO $pdo, int $seconds = 90): array
{
    staff_app_ensure_schema($pdo);
    $stmt = $pdo->prepare('
      SELECT user_id FROM staff_app_presence
      WHERE seen_at >= DATE_SUB(NOW(), INTERVAL ? SECOND)
    ');
    $stmt->execute([max(30, $seconds)]);
    $ids = [];
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) ?: [] as $id) {
        $id = (string) $id;
        if ($id !== '') {
            $ids[] = $id;
        }
    }

    return $ids;
}

/** @return array<string, list<string>> */
function staff_app_peer_map(PDO $pdo, string $userId): array
{
    $stmt = $pdo->prepare('
      SELECT m.room_id, m.user_id
      FROM staff_app_members m
      JOIN staff_app_members mine ON mine.room_id = m.room_id AND mine.user_id = ?
      WHERE m.user_id <> ?
    ');
    $stmt->execute([$userId, $userId]);
    $map = [];
    foreach ($stmt->fetchAll() ?: [] as $row) {
        $map[(string) $row['room_id']][] = (string) $row['user_id'];
    }

    return $map;
}

function staff_app_add_column(PDO $pdo, string $table, string $column, string $definition): void
{
    if (!preg_match('/^[a-z_]+$/', $table) || !preg_match('/^[a-z_]+$/', $column)) {
        return;
    }
    try {
        $pdo->query('SELECT `' . $column . '` FROM `' . $table . '` LIMIT 0');
    } catch (Throwable $e) {
        $pdo->exec('ALTER TABLE `' . $table . '` ADD COLUMN `' . $column . '` ' . $definition);
    }
}

function staff_app_upload_dir(): string
{
    return dirname(__DIR__) . '/uploads/staff-app';
}

function staff_app_role_label(string $role): string
{
    if ($role === 'SECRETARY') {
        return 'منشی';
    }
    if ($role === 'ADMIN') {
        return 'مدیر';
    }

    return 'درمانگر';
}

function staff_app_people(PDO $pdo): array
{
    return $pdo->query("
      SELECT id, name, username, role
      FROM users
      WHERE (role IN ('DOCTOR','SECRETARY') OR LOWER(username) = 'eshahabian')
        AND COALESCE(is_disabled,0)=0
      ORDER BY FIELD(role, 'SECRETARY', 'DOCTOR', 'ADMIN'), name ASC
    ")->fetchAll() ?: [];
}

function staff_app_ensure_general(PDO $pdo): string
{
    staff_app_ensure_schema($pdo);
    $pdo->prepare("
      INSERT INTO staff_app_rooms (id, title, is_general, created_by)
      SELECT ?, 'چت کلی', 1, 'system' FROM DUAL
      WHERE NOT EXISTS (SELECT 1 FROM staff_app_rooms WHERE is_general = 1)
    ")->execute([cuid()]);
    $id = (string) $pdo->query('SELECT id FROM staff_app_rooms WHERE is_general = 1 ORDER BY created_at ASC LIMIT 1')->fetchColumn();
    if ($id === '') {
        throw new RuntimeException('چت کلی ساخته نشد.');
    }
    $pdo->prepare("
      INSERT IGNORE INTO staff_app_members (room_id, user_id)
      SELECT ?, u.id FROM users u
      WHERE (u.role IN ('DOCTOR','SECRETARY') OR LOWER(u.username) = 'eshahabian')
        AND COALESCE(u.is_disabled,0)=0
    ")->execute([$id]);

    return $id;
}

function staff_app_rooms_for(PDO $pdo, string $userId): array
{
    staff_app_purge_expired_archive($pdo);
    $general = staff_app_ensure_general($pdo);
    $stmt = $pdo->prepare("
      SELECT r.id, r.title, r.is_general, r.is_private, r.kept_forever, r.created_at,
             (SELECT COUNT(*) FROM staff_app_members mc WHERE mc.room_id = r.id) AS member_count,
             (SELECT COUNT(*) FROM staff_app_messages m WHERE m.room_id = r.id) AS message_count,
             (SELECT COUNT(*) FROM staff_app_messages um
               LEFT JOIN staff_app_reads rd ON rd.room_id = r.id AND rd.user_id = ?
               WHERE um.room_id = r.id
                 AND TRIM(um.user_id) <> TRIM(?)
                 AND um.created_at > COALESCE(rd.read_at, '1970-01-01 00:00:00')
             ) AS unread_count
      FROM staff_app_rooms r
      JOIN staff_app_members mem ON mem.room_id = r.id AND mem.user_id = ?
      WHERE r.deleted_at IS NULL
      ORDER BY r.is_general DESC, r.created_at DESC
    ");
    $stmt->execute([$userId, $userId, $userId]);
    $rows = $stmt->fetchAll() ?: [];
    if ($rows === []) {
        $stmt->execute([$userId, $userId, $userId]);
        $rows = $stmt->fetchAll() ?: [];
    }
    foreach ($rows as &$row) {
        $row['observe_only'] = 0;
        if ((string) $row['id'] === $general) {
            $row['is_general'] = 1;
        }
    }
    unset($row);
    if (staff_app_group_observer(staff_app_user_brief($pdo, $userId))) {
        $extra = $pdo->prepare("
          SELECT r.id, r.title, r.is_general, r.is_private, r.kept_forever, r.created_at,
                 (SELECT COUNT(*) FROM staff_app_members mc WHERE mc.room_id = r.id) AS member_count,
                 (SELECT COUNT(*) FROM staff_app_messages m WHERE m.room_id = r.id) AS message_count,
                 0 AS unread_count
          FROM staff_app_rooms r
          WHERE r.is_general = 0
            AND r.deleted_at IS NULL
            AND NOT EXISTS (
              SELECT 1 FROM staff_app_members mem
              WHERE mem.room_id = r.id AND mem.user_id = ?
            )
          ORDER BY r.created_at DESC
        ");
        $extra->execute([$userId]);
        $added = false;
        foreach ($extra->fetchAll() ?: [] as $row) {
            $row['is_general'] = 0;
            $row['observe_only'] = 1;
            $row['unread_count'] = 0;
            $rows[] = $row;
            $added = true;
        }
        if ($added) {
            usort($rows, static function (array $a, array $b): int {
                $generalRank = ((int) !empty($b['is_general'])) <=> ((int) !empty($a['is_general']));
                if ($generalRank !== 0) {
                    return $generalRank;
                }

                return strcmp((string) ($b['created_at'] ?? ''), (string) ($a['created_at'] ?? ''));
            });
        }
    }

    return $rows;
}

function staff_app_room_for_member(PDO $pdo, string $roomId, string $userId): ?array
{
    staff_app_ensure_general($pdo);
    $stmt = $pdo->prepare("
      SELECT r.*
      FROM staff_app_rooms r
      JOIN staff_app_members mem ON mem.room_id = r.id AND mem.user_id = ?
      WHERE r.id = ?
        AND r.deleted_at IS NULL
      LIMIT 1
    ");
    $stmt->execute([$userId, $roomId]);
    $row = $stmt->fetch();

    return $row ?: null;
}

/** ناظر خاموش همه گفتگوها: فقط eshahabian. عضویت ساخته نمی‌شود و تیک خوانده‌شد نمی‌فرستد. */
function staff_app_group_observer(?array $user): bool
{
    return staff_app_is_eshahabian($user);
}

function staff_app_user_brief(PDO $pdo, string $userId): ?array
{
    static $cache = [];
    $userId = trim($userId);
    if ($userId === '') {
        return null;
    }
    if (array_key_exists($userId, $cache)) {
        return $cache[$userId];
    }
    $stmt = $pdo->prepare('SELECT id, name, username, role FROM users WHERE id = ? LIMIT 1');
    $stmt->execute([$userId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    $cache[$userId] = $row ?: null;

    return $cache[$userId];
}

function staff_app_room_has_member(PDO $pdo, string $roomId, string $userId): bool
{
    if ($roomId === '' || $userId === '') {
        return false;
    }
    $stmt = $pdo->prepare('SELECT 1 FROM staff_app_members WHERE room_id = ? AND user_id = ? LIMIT 1');
    $stmt->execute([$roomId, $userId]);

    return (bool) $stmt->fetchColumn();
}

/** گروه ساخته‌شده: اتاق غیرکلی که گفتگوی دونفره نیست. چت کلی و خصوصی ۱به۱ این‌جا نیستند. */
function staff_app_is_group_room(PDO $pdo, array $room): bool
{
    if (!empty($room['is_general'])) {
        return false;
    }
    if (!empty($room['is_private'])) {
        return true;
    }
    if (isset($room['member_count'])) {
        return (int) $room['member_count'] !== 2;
    }
    $roomId = (string) ($room['id'] ?? '');
    if ($roomId === '') {
        return false;
    }
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM staff_app_members WHERE room_id = ?');
    $stmt->execute([$roomId]);

    return (int) $stmt->fetchColumn() !== 2;
}

/** eshahabian هر گفتگوی غیرکلی را می‌بیند، از جمله دو نفره و گروه خصوصی. */
function staff_app_is_oversight_group(PDO $pdo, array $room): bool
{
    unset($pdo);

    return empty($room['is_general']);
}

function staff_app_is_eshahabian(?array $user): bool
{
    return strtolower(trim((string) ($user['username'] ?? ''))) === 'eshahabian';
}

/** عضو واقعی، یا ناظر فقط-مشاهده روی گروه. عضویت در staff_app_members ساخته نمی‌شود. */
function staff_app_room_for_viewer(PDO $pdo, string $roomId, string $userId): ?array
{
    $room = staff_app_room_for_member($pdo, $roomId, $userId);
    if ($room) {
        $room['observe_only'] = 0;

        return $room;
    }
    if (!staff_app_group_observer(staff_app_user_brief($pdo, $userId))) {
        return null;
    }
    $stmt = $pdo->prepare('SELECT * FROM staff_app_rooms WHERE id = ? AND is_general = 0 AND deleted_at IS NULL LIMIT 1');
    $stmt->execute([$roomId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row || !staff_app_is_oversight_group($pdo, $row)) {
        return null;
    }
    $row['observe_only'] = 1;

    return $row;
}

function staff_app_receipt_suppressed(PDO $pdo, string $userId, string $roomId): bool
{
    $userId = trim($userId);
    $roomId = trim($roomId);
    if ($userId === '' || $roomId === '') {
        return false;
    }
    if (!staff_app_group_observer(staff_app_user_brief($pdo, $userId))) {
        return false;
    }

    return !staff_app_room_has_member($pdo, $roomId, $userId);
}

function staff_app_assert_can_post(PDO $pdo, array $user, string $roomId): void
{
    $userId = trim((string) ($user['id'] ?? ''));
    if ($userId !== '' && staff_app_room_for_member($pdo, $roomId, $userId)) {
        return;
    }
    $viewer = staff_app_group_observer($user) ? $user : staff_app_user_brief($pdo, $userId);
    if (staff_app_group_observer($viewer)) {
        throw new RuntimeException('در این گفتگو فقط مشاهده ممکن است.');
    }
    throw new RuntimeException('به این اتاق دسترسی ندارید.');
}

function staff_app_unread_count(PDO $pdo, string $userId): int
{
    if (!staff_app_ready($pdo)) {
        return 0;
    }
    try {
        staff_app_ensure_general($pdo);
        $stmt = $pdo->prepare("
          SELECT COUNT(*)
          FROM staff_app_messages m
          JOIN staff_app_members mem ON mem.room_id = m.room_id AND mem.user_id = ?
          LEFT JOIN staff_app_reads r ON r.room_id = m.room_id AND r.user_id = ?
          WHERE TRIM(m.user_id) <> TRIM(?)
            AND m.created_at > COALESCE(r.read_at, '1970-01-01 00:00:00')
        ");
        $stmt->execute([$userId, $userId, $userId]);

        return (int) $stmt->fetchColumn();
    } catch (Throwable $e) {
        return 0;
    }
}

/** @return array<string, int> */
function staff_app_unread_by_room(PDO $pdo, string $userId): array
{
    if ($userId === '' || !staff_app_ready($pdo)) {
        return [];
    }
    try {
        $stmt = $pdo->prepare("
          SELECT m.room_id, COUNT(*) AS unread
          FROM staff_app_messages m
          JOIN staff_app_members mem ON mem.room_id = m.room_id AND mem.user_id = ?
          LEFT JOIN staff_app_reads r ON r.room_id = m.room_id AND r.user_id = ?
          WHERE TRIM(m.user_id) <> TRIM(?)
            AND m.created_at > COALESCE(r.read_at, '1970-01-01 00:00:00')
          GROUP BY m.room_id
        ");
        $stmt->execute([$userId, $userId, $userId]);
        $map = [];
        foreach ($stmt->fetchAll() ?: [] as $row) {
            $count = (int) ($row['unread'] ?? 0);
            if ($count > 0) {
                $map[(string) $row['room_id']] = $count;
            }
        }

        return $map;
    } catch (Throwable $e) {
        return [];
    }
}

function staff_app_mark_read(PDO $pdo, string $userId, string $roomId): void
{
    if (staff_app_receipt_suppressed($pdo, $userId, $roomId)) {
        return;
    }
    $pdo->prepare("
      INSERT INTO staff_app_reads (user_id, room_id, read_at)
      VALUES (?, ?, NOW())
      ON DUPLICATE KEY UPDATE read_at = NOW()
    ")->execute([$userId, $roomId]);
    staff_app_mark_delivered($pdo, $userId, $roomId);
}

function staff_app_mark_delivered(PDO $pdo, string $userId, string $roomId): void
{
    if ($userId === '' || $roomId === '' || staff_app_receipt_suppressed($pdo, $userId, $roomId)) {
        return;
    }
    $pdo->prepare("
      INSERT INTO staff_app_receipts (user_id, room_id, delivered_at)
      VALUES (?, ?, NOW())
      ON DUPLICATE KEY UPDATE delivered_at = NOW()
    ")->execute([$userId, $roomId]);
}

function staff_app_mark_delivered_all(PDO $pdo, string $userId): void
{
    if ($userId === '' || !staff_app_ready($pdo)) {
        return;
    }
    try {
        $pdo->prepare("
          INSERT INTO staff_app_receipts (user_id, room_id, delivered_at)
          SELECT ?, room_id, NOW() FROM staff_app_members WHERE user_id = ?
          ON DUPLICATE KEY UPDATE delivered_at = NOW()
        ")->execute([$userId, $userId]);
    } catch (Throwable $e) {
    }
}

function staff_app_direct_room(PDO $pdo, string $userId, string $otherId): ?string
{
    $stmt = $pdo->prepare("
      SELECT r.id
      FROM staff_app_rooms r
      JOIN staff_app_members a ON a.room_id = r.id AND a.user_id = ?
      JOIN staff_app_members b ON b.room_id = r.id AND b.user_id = ?
      WHERE r.is_general = 0
        AND r.deleted_at IS NULL
        AND COALESCE(r.is_private, 0) = 0
        AND (SELECT COUNT(*) FROM staff_app_members m WHERE m.room_id = r.id) = 2
      ORDER BY r.created_at DESC
      LIMIT 1
    ");
    $stmt->execute([$userId, $otherId]);
    $id = $stmt->fetchColumn();

    return $id ? (string) $id : null;
}

/** وضعیت تیک پیام‌های خود کاربر: sent، delivered، read */
function staff_app_own_receipts(PDO $pdo, string $roomId, string $userId): array
{
    $members = $pdo->prepare('SELECT user_id FROM staff_app_members WHERE room_id = ? AND user_id <> ?');
    $members->execute([$roomId, $userId]);
    $others = array_map(static fn(array $row): string => (string) $row['user_id'], $members->fetchAll() ?: []);
    $delivered = [];
    $reads = [];
    if ($others !== []) {
        $marks = implode(',', array_fill(0, count($others), '?'));
        $del = $pdo->prepare("SELECT user_id, delivered_at FROM staff_app_receipts WHERE room_id = ? AND user_id IN ($marks)");
        $del->execute(array_merge([$roomId], $others));
        foreach ($del->fetchAll() ?: [] as $row) {
            $delivered[(string) $row['user_id']] = strtotime((string) $row['delivered_at']) ?: 0;
        }
        $read = $pdo->prepare("SELECT user_id, read_at FROM staff_app_reads WHERE room_id = ? AND user_id IN ($marks)");
        $read->execute(array_merge([$roomId], $others));
        foreach ($read->fetchAll() ?: [] as $row) {
            $reads[(string) $row['user_id']] = strtotime((string) $row['read_at']) ?: 0;
        }
    }
    $msgs = $pdo->prepare('SELECT id, created_at FROM staff_app_messages WHERE room_id = ? AND user_id = ? ORDER BY created_at ASC');
    $msgs->execute([$roomId, $userId]);
    $states = [];
    foreach ($msgs->fetchAll() ?: [] as $row) {
        $at = strtotime((string) $row['created_at']) ?: 0;
        $state = 'sent';
        if ($others !== []) {
            $allDelivered = true;
            $allRead = true;
            foreach ($others as $otherId) {
                if (($delivered[$otherId] ?? 0) < $at) {
                    $allDelivered = false;
                }
                if (($reads[$otherId] ?? 0) < $at) {
                    $allRead = false;
                }
            }
            if ($allRead) {
                $state = 'read';
            } elseif ($allDelivered) {
                $state = 'delivered';
            }
        }
        $states[(string) $row['id']] = $state;
    }

    return $states;
}

function staff_app_ticks_html(string $state, string $messageId = ''): string
{
    $state = in_array($state, ['sent', 'delivered', 'read'], true) ? $state : 'sent';
    $one = '<svg viewBox="0 0 16 16" aria-hidden="true"><path d="M3.2 8.2 6.4 11.4 12.8 4.6" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg>';
    $two = '<svg viewBox="0 0 20 16" aria-hidden="true"><path d="M1.4 8.2 4.6 11.4 11 4.6" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/><path d="M6.2 8.2 9.4 11.4 15.8 4.6" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg>';
    $label = $state === 'read' ? 'دیده شد' : ($state === 'delivered' ? 'رسید' : 'ارسال شد');
    $attr = $messageId !== '' ? ' data-ticks="' . e($messageId) . '"' : '';

    return '<span class="sapp-ticks is-' . $state . '"' . $attr . ' aria-label="' . $label . '">' . ($state === 'sent' ? $one : $two) . '</span>';
}

/** @return array<string, list<array{name:string,role:string}>> */
function staff_app_seen_map(PDO $pdo, string $roomId): array
{
    $stmt = $pdo->prepare("
      SELECT m.id AS message_id, u.name, u.username, u.role
      FROM staff_app_messages m
      JOIN staff_app_members mem ON mem.room_id = m.room_id AND mem.user_id <> m.user_id
      JOIN staff_app_reads r ON r.room_id = m.room_id AND r.user_id = mem.user_id AND r.read_at >= m.created_at
      JOIN users u ON u.id = mem.user_id
      WHERE m.room_id = ?
      ORDER BY r.read_at ASC
    ");
    $stmt->execute([$roomId]);
    $map = [];
    foreach ($stmt->fetchAll() ?: [] as $row) {
        $name = trim((string) ($row['name'] ?? ''));
        if ($name === '') {
            $name = trim((string) ($row['username'] ?? ''));
        }
        $map[(string) $row['message_id']][] = [
            'name' => $name !== '' ? $name : 'کاربر',
            'role' => staff_app_role_label((string) ($row['role'] ?? '')),
        ];
    }

    return $map;
}

/** @return array{0: array<string, array<string, int>>, 1: array<string, string>} */
function staff_app_reaction_maps(PDO $pdo, array $messageIds, string $userId): array
{
    $messageIds = array_values(array_filter(array_map('strval', $messageIds)));
    if ($messageIds === []) {
        return [[], []];
    }
    $marks = implode(',', array_fill(0, count($messageIds), '?'));
    $counts = [];
    $stmt = $pdo->prepare("SELECT message_id, emoji, COUNT(*) AS total FROM staff_app_reactions WHERE message_id IN ($marks) GROUP BY message_id, emoji");
    $stmt->execute($messageIds);
    foreach ($stmt->fetchAll() ?: [] as $row) {
        $counts[(string) $row['message_id']][(string) $row['emoji']] = (int) $row['total'];
    }
    $mine = [];
    $own = $pdo->prepare("SELECT message_id, emoji FROM staff_app_reactions WHERE user_id = ? AND message_id IN ($marks)");
    $own->execute(array_merge([$userId], $messageIds));
    foreach ($own->fetchAll() ?: [] as $row) {
        $mine[(string) $row['message_id']] = (string) $row['emoji'];
    }

    return [$counts, $mine];
}

function staff_app_message_for_member(PDO $pdo, string $messageId, string $roomId, string $userId): ?array
{
    $stmt = $pdo->prepare("
      SELECT m.*
      FROM staff_app_messages m
      JOIN staff_app_members mem ON mem.room_id = m.room_id AND mem.user_id = ?
      WHERE m.id = ? AND m.room_id = ?
      LIMIT 1
    ");
    $stmt->execute([$userId, $messageId, $roomId]);
    $row = $stmt->fetch();

    return $row ?: null;
}

function staff_app_delete_message(PDO $pdo, array $user, string $roomId, string $messageId): void
{
    staff_app_assert_can_post($pdo, $user, $roomId);
    $userId = (string) ($user['id'] ?? '');
    $message = staff_app_message_for_member($pdo, $messageId, $roomId, $userId);
    if (!$message) {
        throw new RuntimeException('این پیام پیدا نشد.');
    }
    if ((string) ($message['user_id'] ?? '') !== $userId) {
        throw new RuntimeException('فقط پیام خودتان را می‌توانید حذف کنید.');
    }
    $files = $pdo->prepare('SELECT stored_name FROM staff_app_files WHERE message_id = ?');
    $files->execute([$messageId]);
    $stored = array_map(static fn(array $row): string => (string) $row['stored_name'], $files->fetchAll() ?: []);
    $pdo->beginTransaction();
    try {
        $pdo->prepare('DELETE FROM staff_app_reactions WHERE message_id = ?')->execute([$messageId]);
        $pdo->prepare('DELETE FROM staff_app_files WHERE message_id = ?')->execute([$messageId]);
        $pdo->prepare('UPDATE staff_app_messages SET reply_to = NULL WHERE reply_to = ?')->execute([$messageId]);
        $pdo->prepare('UPDATE staff_app_rooms SET pinned_message_id = NULL WHERE id = ? AND pinned_message_id = ?')->execute([$roomId, $messageId]);
        $pdo->prepare('DELETE FROM staff_app_messages WHERE id = ? AND user_id = ?')->execute([$messageId, $userId]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
    foreach ($stored as $name) {
        if (!preg_match('/^[a-f0-9]{32}\.[a-z0-9]{1,5}$/', $name)) {
            continue;
        }
        $left = $pdo->prepare('SELECT COUNT(*) FROM staff_app_files WHERE stored_name = ?');
        $left->execute([$name]);
        if ((int) $left->fetchColumn() === 0) {
            @unlink(staff_app_upload_dir() . '/' . $name);
        }
    }
}

function staff_app_pin_message(PDO $pdo, array $user, string $roomId, string $messageId): void
{
    staff_app_assert_can_post($pdo, $user, $roomId);
    $userId = (string) ($user['id'] ?? '');
    $current = $pdo->prepare('SELECT pinned_message_id FROM staff_app_rooms WHERE id = ?');
    $current->execute([$roomId]);
    $pinned = (string) ($current->fetchColumn() ?: '');
    if ($pinned === $messageId) {
        $pdo->prepare('UPDATE staff_app_rooms SET pinned_message_id = NULL WHERE id = ?')->execute([$roomId]);

        return;
    }
    if (!staff_app_message_for_member($pdo, $messageId, $roomId, $userId)) {
        throw new RuntimeException('این پیام پیدا نشد.');
    }
    $pdo->prepare('UPDATE staff_app_rooms SET pinned_message_id = ? WHERE id = ?')->execute([$messageId, $roomId]);
}

function staff_app_allowed_reactions(): array
{
    return ['😂', '❤️', '👍', '😍', '🙏', '👎', '🔥'];
}

function staff_app_match_emoji(string $emoji): string
{
    $emoji = trim($emoji);
    $bare = str_replace("\u{FE0F}", '', $emoji);
    foreach (staff_app_allowed_reactions() as $allowed) {
        if ($emoji === $allowed || $bare === str_replace("\u{FE0F}", '', $allowed)) {
            return $allowed;
        }
    }

    return '';
}

/** @return array{counts: array<string, int>, mine: string} */
function staff_app_toggle_reaction(PDO $pdo, array $user, string $roomId, string $messageId, string $emoji): array
{
    staff_app_assert_can_post($pdo, $user, $roomId);
    $userId = (string) ($user['id'] ?? '');
    $emoji = staff_app_match_emoji($emoji);
    if ($emoji === '') {
        throw new RuntimeException('این واکنش مجاز نیست.');
    }
    if (!preg_match('/^[a-f0-9]{24}$/', $messageId) || !staff_app_message_for_member($pdo, $messageId, $roomId, $userId)) {
        throw new RuntimeException('این پیام پیدا نشد.');
    }
    $current = $pdo->prepare('SELECT emoji FROM staff_app_reactions WHERE message_id = ? AND user_id = ?');
    $current->execute([$messageId, $userId]);
    $have = (string) ($current->fetchColumn() ?: '');
    $pdo->prepare('DELETE FROM staff_app_reactions WHERE message_id = ? AND user_id = ?')->execute([$messageId, $userId]);
    $mine = '';
    if ($have !== $emoji) {
        $pdo->prepare('INSERT INTO staff_app_reactions (message_id, user_id, emoji) VALUES (?,?,?)')->execute([$messageId, $userId, $emoji]);
        $mine = $emoji;
    }
    $counts = $pdo->prepare('SELECT emoji, COUNT(*) AS total FROM staff_app_reactions WHERE message_id = ? GROUP BY emoji');
    $counts->execute([$messageId]);
    $out = [];
    foreach ($counts->fetchAll() ?: [] as $row) {
        $out[(string) $row['emoji']] = (int) $row['total'];
    }

    return ['counts' => $out, 'mine' => $mine];
}

function staff_app_forward_message(PDO $pdo, array $user, string $fromRoom, string $toRoom, string $messageId): void
{
    staff_app_assert_can_post($pdo, $user, $fromRoom);
    staff_app_assert_can_post($pdo, $user, $toRoom);
    $userId = (string) ($user['id'] ?? '');
    $message = staff_app_message_for_member($pdo, $messageId, $fromRoom, $userId);
    if (!$message || !staff_app_room_for_member($pdo, $toRoom, $userId)) {
        throw new RuntimeException('این پیام را نمی‌توان هدایت کرد.');
    }
    $sender = $pdo->prepare('SELECT name, username FROM users WHERE id = ?');
    $sender->execute([(string) ($message['user_id'] ?? '')]);
    $who = $sender->fetch() ?: [];
    $fromName = trim((string) ($who['name'] ?? ''));
    if ($fromName === '') {
        $fromName = trim((string) ($who['username'] ?? 'کاربر'));
    }
    if (mb_strlen($fromName) > 80) {
        $fromName = mb_substr($fromName, 0, 80);
    }
    $files = $pdo->prepare('SELECT stored_name, original_name, mime, size FROM staff_app_files WHERE message_id = ?');
    $files->execute([$messageId]);
    $fileRows = $files->fetchAll() ?: [];
    $newId = cuid();
    $pdo->beginTransaction();
    try {
        $pdo->prepare('INSERT INTO staff_app_messages (id, room_id, user_id, body, forward_from) VALUES (?,?,?,?,?)')
            ->execute([$newId, $toRoom, $userId, (string) ($message['body'] ?? ''), $fromName]);
        $add = $pdo->prepare('INSERT INTO staff_app_files (id, message_id, stored_name, original_name, mime, size) VALUES (?,?,?,?,?,?)');
        foreach ($fileRows as $file) {
            $add->execute([cuid(), $newId, $file['stored_name'], $file['original_name'], $file['mime'], $file['size']]);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
    staff_app_mark_read($pdo, $userId, $toRoom);
}

function staff_app_pinned_message(PDO $pdo, string $roomId): ?array
{
    $stmt = $pdo->prepare("
      SELECT m.id, m.body, u.name
      FROM staff_app_rooms r
      JOIN staff_app_messages m ON m.id = r.pinned_message_id
      JOIN users u ON u.id = m.user_id
      WHERE r.id = ?
      LIMIT 1
    ");
    $stmt->execute([$roomId]);
    $row = $stmt->fetch();

    return $row ?: null;
}

/** @return list<array<string, mixed>> */
function staff_app_messages(PDO $pdo, string $roomId): array
{
    $stmt = $pdo->prepare("
      SELECT m.id, m.body, m.created_at, m.user_id, m.reply_to, m.forward_from,
             u.name, u.role, rm.body AS reply_body, ru.name AS reply_name
      FROM staff_app_messages m
      JOIN users u ON u.id = m.user_id
      LEFT JOIN staff_app_messages rm ON rm.id = m.reply_to
      LEFT JOIN users ru ON ru.id = rm.user_id
      WHERE m.room_id = ?
      ORDER BY m.created_at DESC
      LIMIT 150
    ");
    $stmt->execute([$roomId]);
    $rows = array_reverse($stmt->fetchAll() ?: []);
    if ($rows === []) {
        return [];
    }
    $ids = array_map(static fn(array $row): string => (string) $row['id'], $rows);
    $marks = implode(',', array_fill(0, count($ids), '?'));
    $files = $pdo->prepare("SELECT * FROM staff_app_files WHERE message_id IN ($marks)");
    $files->execute($ids);
    $byMessage = [];
    foreach ($files->fetchAll() ?: [] as $file) {
        $byMessage[(string) $file['message_id']][] = $file;
    }
    foreach ($rows as &$row) {
        $row['files'] = $byMessage[(string) $row['id']] ?? [];
    }
    unset($row);

    return $rows;
}

/** @return list<array<string, mixed>> */
function staff_app_live_messages(PDO $pdo, string $roomId, string $userId, string $afterId = ''): array
{
    if (!preg_match('/^[a-f0-9]{24}$/', $afterId)) {
        $afterId = '';
    }
    if ($afterId === '') {
        $stmt = $pdo->prepare("
          SELECT m.id, m.body, m.created_at, m.user_id, m.reply_to, m.forward_from,
                 u.name, rm.body AS reply_body, ru.name AS reply_name
          FROM staff_app_messages m
          JOIN users u ON u.id = m.user_id
          LEFT JOIN staff_app_messages rm ON rm.id = m.reply_to
          LEFT JOIN users ru ON ru.id = rm.user_id
          WHERE m.room_id = ?
          ORDER BY m.created_at DESC
          LIMIT 20
        ");
        $stmt->execute([$roomId]);

        return staff_app_message_cards($pdo, $userId, array_reverse($stmt->fetchAll() ?: []));
    }
    $stmt = $pdo->prepare("
      SELECT m.id, m.body, m.created_at, m.user_id, m.reply_to, m.forward_from,
             u.name, rm.body AS reply_body, ru.name AS reply_name
      FROM staff_app_messages m
      JOIN users u ON u.id = m.user_id
      LEFT JOIN staff_app_messages rm ON rm.id = m.reply_to
      LEFT JOIN users ru ON ru.id = rm.user_id
      JOIN staff_app_messages cursor_msg ON cursor_msg.id = ? AND cursor_msg.room_id = m.room_id
      WHERE m.room_id = ?
        AND (
          m.created_at > cursor_msg.created_at
          OR (m.created_at = cursor_msg.created_at AND m.id <> cursor_msg.id)
        )
      ORDER BY m.created_at ASC
      LIMIT 40
    ");
    $stmt->execute([$afterId, $roomId]);

    return staff_app_message_cards($pdo, $userId, $stmt->fetchAll() ?: []);
}

/** @return array<string, array{counts: array<string, int>, mine: string}> */
function staff_app_reaction_overview(PDO $pdo, string $roomId, string $userId): array
{
    $stmt = $pdo->prepare("
      SELECT r.message_id, r.emoji, COUNT(*) AS total
      FROM staff_app_reactions r
      JOIN staff_app_messages m ON m.id = r.message_id
      WHERE m.room_id = ?
      GROUP BY r.message_id, r.emoji
    ");
    $stmt->execute([$roomId]);
    $out = [];
    foreach ($stmt->fetchAll() ?: [] as $row) {
        $id = (string) $row['message_id'];
        if (!isset($out[$id])) {
            $out[$id] = ['counts' => [], 'mine' => ''];
        }
        $out[$id]['counts'][(string) $row['emoji']] = (int) $row['total'];
    }
    $mine = $pdo->prepare("
      SELECT r.message_id, r.emoji
      FROM staff_app_reactions r
      JOIN staff_app_messages m ON m.id = r.message_id
      WHERE m.room_id = ? AND r.user_id = ?
    ");
    $mine->execute([$roomId, $userId]);
    foreach ($mine->fetchAll() ?: [] as $row) {
        $id = (string) $row['message_id'];
        if (!isset($out[$id])) {
            $out[$id] = ['counts' => [], 'mine' => ''];
        }
        $out[$id]['mine'] = (string) $row['emoji'];
    }

    return $out;
}

function staff_app_message_card(PDO $pdo, string $roomId, string $userId, string $messageId): ?array
{
    $stmt = $pdo->prepare("
      SELECT m.id, m.body, m.created_at, m.user_id, m.reply_to, m.forward_from,
             u.name, rm.body AS reply_body, ru.name AS reply_name
      FROM staff_app_messages m
      JOIN users u ON u.id = m.user_id
      LEFT JOIN staff_app_messages rm ON rm.id = m.reply_to
      LEFT JOIN users ru ON ru.id = rm.user_id
      WHERE m.room_id = ? AND m.id = ?
      LIMIT 1
    ");
    $stmt->execute([$roomId, $messageId]);
    $row = $stmt->fetch();
    if (!$row) {
        return null;
    }
    $cards = staff_app_message_cards($pdo, $userId, [$row]);

    return $cards[0] ?? null;
}

/** @param list<array<string, mixed>> $rows
 *  @return list<array<string, mixed>>
 */
function staff_app_message_cards(PDO $pdo, string $userId, array $rows): array
{
    if ($rows === []) {
        return [];
    }
    $ids = array_map(static fn(array $row): string => (string) $row['id'], $rows);
    $marks = implode(',', array_fill(0, count($ids), '?'));
    $files = $pdo->prepare('SELECT id, message_id, original_name, mime FROM staff_app_files WHERE message_id IN (' . $marks . ')');
    $files->execute($ids);
    $byMessage = [];
    foreach ($files->fetchAll() ?: [] as $file) {
        $byMessage[(string) $file['message_id']][] = $file;
    }
    $cards = [];
    foreach ($rows as $row) {
        $name = trim((string) ($row['name'] ?? ''));
        if ($name === '') {
            $name = 'کاربر';
        }
        $replyTo = (string) ($row['reply_to'] ?? '');
        $replyText = trim(preg_replace('/\s+/u', ' ', (string) ($row['reply_body'] ?? '')) ?? '');
        if (mb_strlen($replyText) > 80) {
            $replyText = mb_substr($replyText, 0, 80) . '…';
        }
        $fileCards = [];
        foreach ($byMessage[(string) $row['id']] ?? [] as $file) {
            $fileCards[] = [
                'url' => url('/app/file/' . (string) $file['id']),
                'name' => (string) ($file['original_name'] ?? 'فایل'),
                'mime' => (string) ($file['mime'] ?? ''),
            ];
        }
        $authorId = trim((string) ($row['user_id'] ?? ''));
        $cards[] = [
            'id' => (string) $row['id'],
            'userId' => $authorId,
            'mine' => staff_push_same_user($authorId, $userId),
            'name' => $name,
            'body' => (string) ($row['body'] ?? ''),
            'time' => format_fa_time((string) ($row['created_at'] ?? '')),
            'created' => (string) ($row['created_at'] ?? ''),
            'forward' => trim((string) ($row['forward_from'] ?? '')),
            'replyTo' => $replyTo,
            'replyName' => trim((string) ($row['reply_name'] ?? '')),
            'replyText' => $replyTo !== '' ? ($replyText !== '' ? $replyText : 'پیام حذف‌شده') : '',
            'files' => $fileCards,
        ];
    }

    return $cards;
}

function staff_app_create_room(PDO $pdo, array $user, string $title, array $memberIds, bool $private = false): string
{
    $title = trim($title);
    $people = [];
    foreach (staff_app_people($pdo) as $person) {
        $people['id:' . trim((string) $person['id'])] = $person;
    }
    $userId = (string) ($user['id'] ?? '');
    $chosen = [];
    foreach ($memberIds as $memberId) {
        $memberId = trim((string) $memberId);
        if ($memberId === '' || $memberId === $userId) {
            continue;
        }
        if (isset($people['id:' . $memberId])) {
            $chosen[$memberId] = $memberId;
        }
    }
    if ($chosen === []) {
        $clean = [];
        foreach ($memberIds as $memberId) {
            $memberId = trim((string) $memberId);
            if ($memberId !== '' && $memberId !== $userId) {
                $clean[$memberId] = $memberId;
            }
        }
        if ($clean !== []) {
            $marks = implode(',', array_fill(0, count($clean), '?'));
            $found = $pdo->prepare("
              SELECT id, name, username
              FROM users
              WHERE id IN ($marks)
                AND (role IN ('DOCTOR','SECRETARY') OR LOWER(username) = 'eshahabian')
                AND COALESCE(is_disabled,0)=0
            ");
            $found->execute(array_values($clean));
            foreach ($found->fetchAll() ?: [] as $row) {
                $id = trim((string) ($row['id'] ?? ''));
                if ($id === '' || $id === $userId) {
                    continue;
                }
                $people['id:' . $id] = $row;
                $chosen[$id] = $id;
            }
        }
    }
    if ($chosen === []) {
        throw new RuntimeException('یک نفر را از فهرست انتخاب کنید.');
    }
    if (count($chosen) === 1) {
        $otherId = (string) array_key_first($chosen);
        $existing = staff_app_direct_room($pdo, $userId, $otherId);
        if ($existing !== null) {
            return $existing;
        }
        if ($title === '') {
            $other = $people['id:' . $otherId] ?? [];
            $title = trim((string) ($other['name'] ?? ''));
            if ($title === '') {
                $title = trim((string) ($other['username'] ?? 'گفتگو'));
            }
        }
    }
    if ($title === '') {
        throw new RuntimeException('برای اتاق گروهی یک نام بنویسید.');
    }
    if (mb_strlen($title) > 80) {
        throw new RuntimeException('نام اتاق طولانی است.');
    }
    $isPrivate = $private && count($chosen) > 1 ? 1 : 0;
    $roomId = cuid();
    $pdo->beginTransaction();
    try {
        $pdo->prepare('INSERT INTO staff_app_rooms (id, title, is_general, is_private, created_by) VALUES (?,?,0,?,?)')
            ->execute([$roomId, $title, $isPrivate, $userId]);
        $add = $pdo->prepare('INSERT INTO staff_app_members (room_id, user_id) VALUES (?,?)');
        $add->execute([$roomId, $userId]);
        foreach ($chosen as $memberId) {
            $add->execute([$roomId, $memberId]);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }

    return $roomId;
}

function staff_app_room_archived(PDO $pdo, string $roomId): ?array
{
    staff_app_ensure_schema($pdo);
    $stmt = $pdo->prepare('SELECT * FROM staff_app_rooms WHERE id = ? AND is_general = 0 AND deleted_at IS NOT NULL LIMIT 1');
    $stmt->execute([$roomId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return null;
    }
    $row['observe_only'] = 1;
    $row['archived'] = 1;

    return $row;
}

function staff_app_archive_days_left(string $deletedAt): int
{
    $start = strtotime($deletedAt);
    if ($start === false) {
        return 0;
    }
    $left = ($start + (30 * 86400)) - time();
    if ($left <= 0) {
        return 0;
    }

    return (int) ceil($left / 86400);
}

function staff_app_archive_countdown(array $room): string
{
    if (!empty($room['kept_forever'])) {
        return 'بایگانی · برای همیشه';
    }
    $days = staff_app_archive_days_left((string) ($room['deleted_at'] ?? ''));
    if ($days <= 0) {
        return 'امروز از آرشیو حذف می‌شود';
    }

    return to_fa_digits((string) $days) . ' روز مانده';
}

function staff_app_destroy_room(PDO $pdo, string $roomId): void
{
    if ($roomId === '') {
        return;
    }
    $files = $pdo->prepare('
      SELECT f.stored_name
      FROM staff_app_files f
      JOIN staff_app_messages m ON m.id = f.message_id
      WHERE m.room_id = ?
    ');
    $files->execute([$roomId]);
    $stored = array_map(static fn(array $row): string => (string) $row['stored_name'], $files->fetchAll() ?: []);
    $pdo->beginTransaction();
    try {
        $pdo->prepare('
          DELETE r FROM staff_app_reactions r
          INNER JOIN staff_app_messages m ON m.id = r.message_id
          WHERE m.room_id = ?
        ')->execute([$roomId]);
        $pdo->prepare('
          DELETE f FROM staff_app_files f
          INNER JOIN staff_app_messages m ON m.id = f.message_id
          WHERE m.room_id = ?
        ')->execute([$roomId]);
        $pdo->prepare('DELETE FROM staff_app_messages WHERE room_id = ?')->execute([$roomId]);
        $pdo->prepare('DELETE FROM staff_app_reads WHERE room_id = ?')->execute([$roomId]);
        $pdo->prepare('DELETE FROM staff_app_receipts WHERE room_id = ?')->execute([$roomId]);
        $pdo->prepare('DELETE FROM staff_app_members WHERE room_id = ?')->execute([$roomId]);
        $pdo->prepare('DELETE FROM staff_app_rooms WHERE id = ? AND is_general = 0 AND COALESCE(kept_forever, 0) = 0')->execute([$roomId]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
    $dir = staff_app_upload_dir();
    foreach ($stored as $name) {
        if (!preg_match('/^[a-f0-9]{32}\.[a-z0-9]{1,5}$/', $name)) {
            continue;
        }
        $path = $dir . '/' . $name;
        if (is_file($path)) {
            unlink($path);
        }
    }
}

function staff_app_purge_expired_archive(PDO $pdo): void
{
    staff_app_ensure_schema($pdo);
    $ids = $pdo->query("
      SELECT id FROM staff_app_rooms
      WHERE is_general = 0
        AND deleted_at IS NOT NULL
        AND COALESCE(kept_forever, 0) = 0
        AND deleted_at < DATE_SUB(NOW(), INTERVAL 30 DAY)
    ")->fetchAll(PDO::FETCH_COLUMN) ?: [];
    foreach ($ids as $id) {
        staff_app_destroy_room($pdo, (string) $id);
    }
}

function staff_app_archive_rooms(PDO $pdo): array
{
    staff_app_purge_expired_archive($pdo);
    $rows = $pdo->query("
      SELECT r.id, r.title, r.is_general, r.is_private, r.kept_forever, r.deleted_at, r.created_at,
             (SELECT COUNT(*) FROM staff_app_messages m WHERE m.room_id = r.id) AS message_count
      FROM staff_app_rooms r
      WHERE r.is_general = 0
        AND r.deleted_at IS NOT NULL
      ORDER BY r.kept_forever ASC, r.deleted_at DESC
    ")->fetchAll() ?: [];

    return $rows;
}

function staff_app_delete_room(PDO $pdo, array $user, string $roomId): void
{
    $userId = (string) ($user['id'] ?? '');
    $room = staff_app_room_for_viewer($pdo, $roomId, $userId);
    if (!$room || !empty($room['is_general'])) {
        throw new RuntimeException('این چت پیدا نشد.');
    }
    $isMember = staff_app_room_has_member($pdo, $roomId, $userId);
    if (!$isMember && !staff_app_is_eshahabian($user)) {
        throw new RuntimeException('حذف این چت فقط برای اعضایش است.');
    }
    if (!$isMember && !staff_app_is_group_room($pdo, $room)) {
        throw new RuntimeException('حذف گفتگوی دو نفره فقط برای خودشان است.');
    }
    $pdo->prepare('UPDATE staff_app_rooms SET deleted_at = NOW(), deleted_by = ? WHERE id = ? AND is_general = 0 AND deleted_at IS NULL')
        ->execute([$userId, $roomId]);
}

function staff_app_keep_room(PDO $pdo, array $user, string $roomId): void
{
    if (!staff_app_is_eshahabian($user)) {
        throw new RuntimeException('بایگانی فقط برای این حساب است.');
    }
    $room = staff_app_room_archived($pdo, $roomId);
    if (!$room || !empty($room['is_general']) || trim((string) ($room['deleted_at'] ?? '')) === '') {
        throw new RuntimeException('فقط چت حذف‌شده را می‌توان بایگانی کرد.');
    }
    $pdo->prepare('UPDATE staff_app_rooms SET kept_forever = 1 WHERE id = ? AND is_general = 0 AND deleted_at IS NOT NULL')->execute([$roomId]);
}

function staff_app_allowed_upload_types(): array
{
    return [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        'image/gif' => 'gif',
        'application/pdf' => 'pdf',
        'text/plain' => 'txt',
        'application/zip' => 'zip',
        'application/x-zip-compressed' => 'zip',
        'audio/mpeg' => 'mp3',
        'audio/mp3' => 'mp3',
        'audio/mp4' => 'm4a',
        'audio/x-m4a' => 'm4a',
        'audio/m4a' => 'm4a',
        'audio/aac' => 'm4a',
        'audio/webm' => 'webm',
        'audio/ogg' => 'ogg',
        'application/ogg' => 'ogg',
        'audio/opus' => 'ogg',
        'application/msword' => 'doc',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
        'application/vnd.ms-excel' => 'xls',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xlsx',
    ];
}

function staff_app_is_audio_mime(string $mime): bool
{
    $mime = strtolower(trim(explode(';', $mime, 2)[0]));

    return str_starts_with($mime, 'audio/')
        || in_array($mime, ['video/webm', 'application/ogg', 'video/ogg'], true);
}

/** @return array{ext:string,mime:string}|null */
function staff_app_voice_mime(string $detected, string $originalName): ?array
{
    $detected = strtolower(trim(explode(';', $detected, 2)[0]));
    $byMime = [
        'audio/webm' => ['webm', 'audio/webm'],
        'video/webm' => ['webm', 'audio/webm'],
        'audio/ogg' => ['ogg', 'audio/ogg'],
        'application/ogg' => ['ogg', 'audio/ogg'],
        'video/ogg' => ['ogg', 'audio/ogg'],
        'audio/opus' => ['ogg', 'audio/ogg'],
        'audio/mp4' => ['m4a', 'audio/mp4'],
        'audio/x-m4a' => ['m4a', 'audio/mp4'],
        'audio/m4a' => ['m4a', 'audio/mp4'],
        'audio/aac' => ['m4a', 'audio/mp4'],
        'video/mp4' => ['m4a', 'audio/mp4'],
        'application/mp4' => ['m4a', 'audio/mp4'],
        'audio/mpeg' => ['mp3', 'audio/mpeg'],
        'audio/mp3' => ['mp3', 'audio/mpeg'],
    ];
    if (isset($byMime[$detected])) {
        return ['ext' => $byMime[$detected][0], 'mime' => $byMime[$detected][1]];
    }
    if ($detected !== 'application/octet-stream') {
        return null;
    }
    $name = strtolower(pathinfo(basename(str_replace(["\0", '/', '\\'], '', $originalName)), PATHINFO_EXTENSION));
    $byExt = [
        'webm' => ['webm', 'audio/webm'],
        'ogg' => ['ogg', 'audio/ogg'],
        'oga' => ['ogg', 'audio/ogg'],
        'm4a' => ['m4a', 'audio/mp4'],
        'mp4' => ['m4a', 'audio/mp4'],
        'mp3' => ['mp3', 'audio/mpeg'],
    ];
    if (!isset($byExt[$name])) {
        return null;
    }

    return ['ext' => $byExt[$name][0], 'mime' => $byExt[$name][1]];
}

function staff_app_store_upload(array $file, bool $voice = false): ?array
{
    $error = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($error === UPLOAD_ERR_NO_FILE) {
        return null;
    }
    if ($error !== UPLOAD_ERR_OK) {
        throw new RuntimeException('فایل فرستاده نشد.');
    }
    $size = (int) ($file['size'] ?? 0);
    if ($size <= 0 || $size > 12 * 1024 * 1024) {
        throw new RuntimeException('حجم فایل باید کمتر از ۱۲ مگابایت باشد.');
    }
    $tmp = (string) ($file['tmp_name'] ?? '');
    if ($tmp === '' || !is_uploaded_file($tmp)) {
        throw new RuntimeException('فایل معتبر نیست.');
    }
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = strtolower(trim((string) $finfo->file($tmp)));
    $ext = staff_app_allowed_upload_types()[$mime] ?? '';
    if ($voice) {
        $normalized = staff_app_voice_mime($mime, (string) ($file['name'] ?? ''));
        if ($normalized !== null) {
            $ext = $normalized['ext'];
            $mime = $normalized['mime'];
        }
    }
    if ($ext === '') {
        throw new RuntimeException('این نوع فایل مجاز نیست. عکس، پی‌دی‌اف، زیپ، صوت یا فایل آفیس بفرستید.');
    }
    $dir = staff_app_upload_dir();
    if (!is_dir($dir) && !mkdir($dir, 0750, true) && !is_dir($dir)) {
        throw new RuntimeException('پوشه فایل ساخته نشد.');
    }
    $stored = bin2hex(random_bytes(16)) . '.' . $ext;
    if (!move_uploaded_file($tmp, $dir . '/' . $stored)) {
        throw new RuntimeException('ذخیره فایل انجام نشد.');
    }
    $original = basename(str_replace(["\0", '/', '\\'], '', (string) ($file['name'] ?? 'file')));
    $original = trim(preg_replace('/[\r\n\t]+/', ' ', $original) ?? '');
    if ($original === '') {
        $original = 'file.' . $ext;
    }
    if (mb_strlen($original) > 160) {
        $original = mb_substr($original, 0, 160);
    }

    return [
        'stored_name' => $stored,
        'original_name' => $original,
        'mime' => $mime,
        'size' => $size,
    ];
}

function staff_app_send_message(PDO $pdo, array $user, string $roomId, string $body, ?array $file, string $replyTo = '', bool $voice = false): string
{
    staff_app_assert_can_post($pdo, $user, $roomId);
    $body = trim($body);
    if (mb_strlen($body) > 4000) {
        throw new RuntimeException('متن پیام طولانی است.');
    }
    $stored = null;
    if (is_array($file)) {
        $stored = staff_app_store_upload($file, $voice);
    }
    if ($body === '' && $stored === null) {
        throw new RuntimeException('متن یا فایل را بفرستید.');
    }
    $replyId = null;
    if (preg_match('/^[a-f0-9]{24}$/', $replyTo) && staff_app_message_for_member($pdo, $replyTo, $roomId, (string) ($user['id'] ?? ''))) {
        $replyId = $replyTo;
    }
    $messageId = cuid();
    $pdo->beginTransaction();
    try {
        $pdo->prepare('INSERT INTO staff_app_messages (id, room_id, user_id, body, reply_to) VALUES (?,?,?,?,?)')
            ->execute([$messageId, $roomId, (string) $user['id'], $body, $replyId]);
        if ($stored) {
            $pdo->prepare('INSERT INTO staff_app_files (id, message_id, stored_name, original_name, mime, size) VALUES (?,?,?,?,?,?)')
                ->execute([cuid(), $messageId, $stored['stored_name'], $stored['original_name'], $stored['mime'], $stored['size']]);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        if ($stored) {
            @unlink(staff_app_upload_dir() . '/' . $stored['stored_name']);
        }
        throw $e;
    }
    $senderId = trim((string) ($user['id'] ?? ''));
    staff_app_mark_read($pdo, $senderId, $roomId);
    $senderName = trim((string) ($user['name'] ?? ''));
    $preview = $body;
    if ($preview === '' && is_array($stored) && staff_app_is_audio_mime((string) ($stored['mime'] ?? ''))) {
        $preview = 'پیام صوتی';
    }
    staff_push_notify_room($pdo, $roomId, $senderId, $senderName, $preview);

    return $messageId;
}

function staff_app_output_file(PDO $pdo, array $user, string $fileId): never
{
    $stmt = $pdo->prepare("
      SELECT f.stored_name, f.original_name, f.mime
      FROM staff_app_files f
      JOIN staff_app_messages m ON m.id = f.message_id
      JOIN staff_app_members mem ON mem.room_id = m.room_id AND mem.user_id = ?
      WHERE f.id = ?
      LIMIT 1
    ");
    $stmt->execute([(string) ($user['id'] ?? ''), $fileId]);
    $file = $stmt->fetch();
    if (!$file && staff_app_is_eshahabian($user)) {
        $watch = $pdo->prepare("
          SELECT f.stored_name, f.original_name, f.mime
          FROM staff_app_files f
          JOIN staff_app_messages m ON m.id = f.message_id
          JOIN staff_app_rooms r ON r.id = m.room_id AND r.is_general = 0
          WHERE f.id = ?
          LIMIT 1
        ");
        $watch->execute([$fileId]);
        $file = $watch->fetch();
    }
    $stored = (string) ($file['stored_name'] ?? '');
    $path = staff_app_upload_dir() . '/' . $stored;
    if (!$file || !preg_match('/^[a-f0-9]{32}\.[a-z0-9]{1,5}$/', $stored) || !is_file($path)) {
        http_response_code(404);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'فایل پیدا نشد.';
        exit;
    }
    $mime = (string) ($file['mime'] ?? 'application/octet-stream');
    $audio = staff_app_is_audio_mime($mime);
    $inline = !isset($_GET['download']) && (str_starts_with($mime, 'image/') || $mime === 'application/pdf' || $audio);
    $name = (string) ($file['original_name'] ?? 'file');
    $size = (int) filesize($path);
    header('Content-Type: ' . $mime);
    header('X-Content-Type-Options: nosniff');
    header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment') . "; filename*=UTF-8''" . rawurlencode($name));
    if ($audio && $size > 0) {
        header('Accept-Ranges: bytes');
        header('Cache-Control: private');
        staff_app_stream_file($path, $size, trim((string) ($_SERVER['HTTP_RANGE'] ?? '')) !== '');
        exit;
    }
    header('Content-Length: ' . (string) $size);
    readfile($path);
    exit;
}

function staff_app_stream_file(string $path, int $size, bool $ranged): void
{
    $start = 0;
    $end = $size - 1;
    $raw = trim((string) ($_SERVER['HTTP_RANGE'] ?? ''));
    if (!$ranged || !preg_match('/^bytes=(\d*)-(\d*)$/', $raw, $match) || ($match[1] === '' && $match[2] === '')) {
        header('Content-Length: ' . (string) $size);
        readfile($path);
        return;
    }
    if ($match[1] === '') {
        $suffix = (int) $match[2];
        if ($suffix < 1) {
            http_response_code(416);
            header('Content-Range: bytes */' . $size);
            return;
        }
        $start = max(0, $size - $suffix);
    } else {
        $start = (int) $match[1];
        if ($match[2] !== '') {
            $end = (int) $match[2];
        }
    }
    if ($start >= $size || $start > $end) {
        http_response_code(416);
        header('Content-Range: bytes */' . $size);
        return;
    }
    $end = min($end, $size - 1);
    $length = $end - $start + 1;
    http_response_code(206);
    header('Content-Range: bytes ' . $start . '-' . $end . '/' . $size);
    header('Content-Length: ' . (string) $length);
    $handle = fopen($path, 'rb');
    if ($handle === false) {
        return;
    }
    fseek($handle, $start);
    $left = $length;
    while ($left > 0 && !feof($handle)) {
        $chunk = fread($handle, (int) min(65536, $left));
        if (!is_string($chunk) || $chunk === '') {
            break;
        }
        echo $chunk;
        $left -= strlen($chunk);
        if (connection_aborted()) {
            break;
        }
    }
    fclose($handle);
}

function staff_app_doctor_profile_id(PDO $pdo, string $userId): string
{
    $stmt = $pdo->prepare('SELECT id FROM doctor_profiles WHERE user_id = ? LIMIT 1');
    $stmt->execute([$userId]);

    return (string) ($stmt->fetchColumn() ?: '');
}

/** منشی، ادمین و eshahabian فهرست کلینیک را می‌بینند؛ درمانگر فقط نوبت خودش را. */
function staff_app_sees_clinic_schedule(array $user): bool
{
    if ((string) ($user['role'] ?? '') !== 'DOCTOR') {
        return true;
    }

    return strtolower(trim((string) ($user['username'] ?? ''))) === 'eshahabian';
}

/**
 * همان افق رزرو سایت و میز منشی: از ابتدای امروز تا پایان روز «امروز + ۱۴».
 *
 * @return array{0:string,1:string}
 */
function staff_app_schedule_bounds(): array
{
    if (!function_exists('appointment_booking_horizon_end') && is_file(__DIR__ . '/availability.php')) {
        require_once __DIR__ . '/availability.php';
    }
    $from = date('Y-m-d 00:00:00');
    $lastDay = function_exists('appointment_booking_horizon_end')
        ? appointment_booking_horizon_end()
        : date('Y-m-d', strtotime('+14 days') ?: time());
    $to = date('Y-m-d 00:00:00', strtotime($lastDay . ' +1 day') ?: time());

    return [$from, $to];
}

function staff_app_booking_overlaps_appointment(array $booking, array $appt): bool
{
    $apptId = (string) ($appt['id'] ?? '');
    $linked = trim((string) ($booking['appointment_id'] ?? ''));
    if ($apptId !== '' && $linked === $apptId) {
        return true;
    }
    $patientId = trim((string) ($booking['patient_id'] ?? ''));
    $doctorId = trim((string) ($booking['doctor_id'] ?? ''));
    if ($patientId === '' || $doctorId === '' || $patientId !== (string) ($appt['patient_id'] ?? '') || $doctorId !== (string) ($appt['doctor_id'] ?? '')) {
        return false;
    }
    $bookStart = strtotime((string) ($booking['starts_at'] ?? '')) ?: 0;
    $bookEnd = strtotime((string) ($booking['ends_at'] ?? '')) ?: 0;
    $apptStart = strtotime((string) ($appt['starts_at'] ?? '')) ?: 0;
    $apptEnd = strtotime((string) ($appt['ends_at'] ?? '')) ?: 0;

    return $bookStart > 0 && $bookEnd > $bookStart && $apptStart > 0 && $apptEnd > $apptStart
        && $bookStart < $apptEnd && $bookEnd > $apptStart;
}

/** @return list<array<string, mixed>> */
function staff_app_appointment_rows(PDO $pdo, array $user): array
{
    if (!function_exists('clinic_rooms_between') && is_file(__DIR__ . '/clinic_rooms.php')) {
        require_once __DIR__ . '/clinic_rooms.php';
    }
    if (function_exists('ensure_clinic_rooms_schema')) {
        ensure_clinic_rooms_schema($pdo);
    }
    if (!function_exists('appointment_restore_auto_cancelled_unpaid') && is_file(__DIR__ . '/appointment_session.php')) {
        require_once __DIR__ . '/appointment_session.php';
    }
    if (function_exists('appointment_restore_auto_cancelled_unpaid')) {
        try {
            appointment_restore_auto_cancelled_unpaid($pdo);
        } catch (Throwable $ignored) {
        }
    }
    [$from, $to] = staff_app_schedule_bounds();
    $params = [$from, $to];
    $doctorSql = '';
    $profileId = '';
    $clinicWide = staff_app_sees_clinic_schedule($user);
    if (!$clinicWide) {
        $profileId = staff_app_doctor_profile_id($pdo, (string) ($user['id'] ?? ''));
        if ($profileId === '') {
            return [];
        }
        $doctorSql = ' AND a.doctor_id = ?';
        $params[] = $profileId;
    }
    $stmt = $pdo->prepare("
      SELECT a.*,
             pu.name AS patient_name, du.name AS doctor_name,
             (SELECT rb.room_no FROM clinic_room_bookings rb
               WHERE rb.appointment_id = a.id
               ORDER BY rb.starts_at DESC LIMIT 1) AS room_no
      FROM appointments a
      JOIN users pu ON pu.id = a.patient_id
      JOIN doctor_profiles dp ON dp.id = a.doctor_id
      JOIN users du ON du.id = dp.user_id
      WHERE a.starts_at >= ? AND a.starts_at < ?
        AND a.status <> 'CANCELLED'
        {$doctorSql}
      ORDER BY a.starts_at ASC
      LIMIT 300
    ");
    $stmt->execute($params);
    $rows = $stmt->fetchAll() ?: [];

    $bookings = [];
    if (function_exists('clinic_rooms_between')) {
        try {
            $bookings = clinic_rooms_between($pdo, $from, $to) ?: [];
        } catch (Throwable $ignored) {
            $bookings = [];
        }
    }
    $used = [];
    foreach ($rows as &$row) {
        $roomNo = (int) ($row['room_no'] ?? 0);
        foreach ($bookings as $booking) {
            if (!staff_app_booking_overlaps_appointment($booking, $row)) {
                continue;
            }
            $bookingId = (string) ($booking['id'] ?? '');
            if ($bookingId !== '') {
                $used[$bookingId] = true;
            }
            if ($roomNo < 1) {
                $roomNo = (int) ($booking['room_no'] ?? 0);
                $row['room_no'] = $roomNo;
            }
        }
    }
    unset($row);

    foreach ($bookings as $booking) {
        $bookingId = (string) ($booking['id'] ?? '');
        if ($bookingId === '' || isset($used[$bookingId])) {
            continue;
        }
        $bookingDoctor = trim((string) ($booking['doctor_id'] ?? ''));
        if (!$clinicWide && ($profileId === '' || $bookingDoctor !== $profileId)) {
            continue;
        }
        $label = function_exists('clinic_room_purpose') ? clinic_room_purpose($booking) : trim((string) ($booking['title'] ?? ''));
        $rows[] = [
            'id' => '',
            'starts_at' => (string) ($booking['starts_at'] ?? ''),
            'ends_at' => (string) ($booking['ends_at'] ?? ''),
            'patient_name' => (string) ($booking['patient_name'] ?? ''),
            'doctor_name' => (string) (($booking['doctor_name'] ?? '') !== '' ? $booking['doctor_name'] : ($booking['workshop_doctor_name'] ?? '')),
            'room_no' => (int) ($booking['room_no'] ?? 0),
            'session_mode' => 'IN_PERSON',
            'status' => 'CONFIRMED',
            'room_only' => 1,
            'room_label' => $label !== '' ? $label : 'رزرو اتاق',
        ];
    }
    usort($rows, static fn(array $a, array $b): int => strcmp((string) ($a['starts_at'] ?? ''), (string) ($b['starts_at'] ?? '')));

    return $rows;
}

/** @return list<array<string, mixed>> */
function staff_app_room_board(PDO $pdo, ?string $from = null, ?string $to = null): array
{
    if (!function_exists('clinic_rooms_between') && is_file(__DIR__ . '/clinic_rooms.php')) {
        require_once __DIR__ . '/clinic_rooms.php';
    }
    if (!function_exists('clinic_rooms_between')) {
        return [];
    }
    if ($from === null || $to === null) {
        [$from, $to] = staff_app_schedule_bounds();
    }

    return clinic_rooms_between($pdo, $from, $to);
}

function staff_app_pwa_head(): string
{
    $icon = e(url('/assets/img/staff-icon-180.png')) . '?v=20261008icon';
    $manifest = e(url('/assets/staff-app.webmanifest')) . '?v=20261008icon';

    return '<meta name="mobile-web-app-capable" content="yes">'
        . '<meta name="apple-mobile-web-app-capable" content="yes">'
        . '<meta name="apple-mobile-web-app-status-bar-style" content="default">'
        . '<meta name="apple-mobile-web-app-title" content="کارکنان">'
        . '<meta name="theme-color" content="#1a9a8a">'
        . '<link rel="apple-touch-icon" href="' . $icon . '">'
        . '<link rel="manifest" href="' . $manifest . '">';
}

function staff_app_pwa_script(): string
{
    global $base;
    $prefix = (is_string($base) && $base !== '' && $base !== '/') ? rtrim($base, '/') : '';
    $cfg = json_encode(
        ['src' => url('/sw-staff.js'), 'scope' => $prefix . '/'],
        JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
    );

    return '<script>(function(){'
        . 'if("serviceWorker" in navigator){var cfg=' . $cfg . ';navigator.serviceWorker.register(cfg.src,{scope:cfg.scope}).catch(function(){});}'
        . 'var box=document.getElementById("sapp-install");if(!box)return;'
        . 'var ios=/iPad|iPhone|iPod/.test(navigator.userAgent)||(navigator.platform==="MacIntel"&&navigator.maxTouchPoints>1);'
        . 'var standalone=window.matchMedia("(display-mode: standalone)").matches||window.navigator.standalone===true;'
        . 'var seen=false;try{seen=localStorage.getItem("sapp-ios-install")==="1";}catch(e){}'
        . 'if(!ios||standalone||seen)return;box.hidden=false;'
        . 'var close=document.getElementById("sapp-install-x");'
        . 'if(close)close.addEventListener("click",function(){box.hidden=true;try{localStorage.setItem("sapp-ios-install","1");}catch(e){}});'
        . '})();</script>'
        . staff_app_client_script();
}

function staff_app_client_script(): string
{
    return <<<'HTML'
<script>
(function () {
  var body = document.body;
  if (!body) return;
  var presenceUrl = body.getAttribute("data-presence") || "";
  var chatUrl = body.getAttribute("data-chat") || "/app/chat";
  var notifyBox = document.getElementById("sapp-notify");
  var notifyText = document.getElementById("sapp-notify-text");
  var yes = document.getElementById("sapp-notify-yes");
  var no = document.getElementById("sapp-notify-no");
  var ios = /iPad|iPhone|iPod/.test(navigator.userAgent) || (navigator.platform === "MacIntel" && navigator.maxTouchPoints > 1);
  var standalone = window.matchMedia("(display-mode: standalone)").matches || window.navigator.standalone === true;
  var mobile = window.matchMedia("(max-width: 900px)").matches || /Android|iPhone|iPad|iPod|Mobile/i.test(navigator.userAgent);
  var lastUnread = null;
  var me = (body.getAttribute("data-user") || "").trim().toLowerCase();

  function faCount(value) {
    return String(value).replace(/[0-9]/g, function (digit) {
      return "۰۱۲۳۴۵۶۷۸۹"[digit];
    });
  }

  function paintUnread(map) {
    if (!map || Array.isArray(map)) map = {};
    document.querySelectorAll("[data-room-unread]").forEach(function (el) {
      var id = el.getAttribute("data-room-unread") || "";
      var count = Number(map[id] || 0);
      if (count > 0) {
        el.hidden = false;
        el.textContent = faCount(count);
      } else {
        el.hidden = true;
        el.textContent = "";
      }
    });
  }

  function paintOnline(ids) {
    var on = {};
    (ids || []).forEach(function (id) { on[id] = 1; });
    document.querySelectorAll(".sapp-dot").forEach(function (dot) {
      var list = (dot.getAttribute("data-online") || "").split(",");
      var lit = false;
      var i;
      for (i = 0; i < list.length; i++) {
        if (list[i] && on[list[i]]) lit = true;
      }
      dot.hidden = !lit;
    });
  }

  function showChatNote(title, bodyText) {
    if (window.sappPushReady) return;
    if (typeof Notification === "undefined" || Notification.permission !== "granted") return;
    if (!document.hidden) return;
    try {
      var note = new Notification(title || "مانا کارکنان", {
        body: bodyText || "پیام تازه در چت دارید.",
        tag: "sapp-chat",
        lang: "fa"
      });
      note.onclick = function () {
        window.focus();
        if (chatUrl) window.location.href = chatUrl;
        note.close();
      };
    } catch (err) {}
  }

  window.sappShowChatNote = showChatNote;

  function paintAppBadge(count) {
    count = Number(count) || 0;
    if (!navigator.setAppBadge) return;
    var job = count > 0 ? navigator.setAppBadge(count) : (navigator.clearAppBadge ? navigator.clearAppBadge() : null);
    if (job && job.catch) job.catch(function () {});
  }

  function ownSendRecent() {
    var sentAt = Number(window.sappSentAt) || 0;
    return sentAt > 0 && (Date.now() - sentAt) < 25000;
  }

  function watchUnread(count) {
    count = Number(count) || 0;
    var bumped = lastUnread !== null && count > lastUnread;
    if (bumped && ownSendRecent()) {
      return;
    }
    paintAppBadge(count);
    if (bumped) {
      showChatNote("مانا کارکنان", "پیام تازه در چت دارید.");
    }
    lastUnread = count;
  }

  paintAppBadge(body.getAttribute("data-unread") || 0);

  function pullPresence() {
    if (!presenceUrl) return;
    fetch(presenceUrl + "?t=" + Date.now(), { cache: "no-store", credentials: "same-origin", headers: { Accept: "application/json" } })
      .then(function (res) { return res.ok ? res.json() : null; })
      .then(function (data) {
        if (!data || !data.ok) return;
        paintOnline(data.online || []);
        paintUnread(data.unreadRooms);
        watchUnread(data.unread);
        publishUser();
      })
      .catch(function () {});
  }

  function enablePush() {
    var vapid = body.getAttribute("data-vapid") || "";
    var pushUrl = body.getAttribute("data-push") || "";
    if (!vapid || !pushUrl || !("serviceWorker" in navigator) || !("PushManager" in window)) return;
    if (typeof Notification === "undefined" || Notification.permission !== "granted") return;
    navigator.serviceWorker.ready.then(function (reg) {
      var padding = "=".repeat((4 - vapid.length % 4) % 4);
      var raw = atob((vapid + padding).replace(/-/g, "+").replace(/_/g, "/"));
      var key = new Uint8Array(raw.length);
      var i;
      for (i = 0; i < raw.length; i++) key[i] = raw.charCodeAt(i);
      return reg.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: key }).then(function (sub) {
        var json = sub.toJSON();
        var keys = (json && json.keys) || {};
        var form = new URLSearchParams();
        var csrf = document.querySelector('meta[name="csrf-token"]');
        form.set("_csrf", csrf ? (csrf.getAttribute("content") || "") : "");
        form.set("endpoint", sub.endpoint || "");
        form.set("p256dh", keys.p256dh || "");
        form.set("auth", keys.auth || "");
        return fetch(pushUrl, {
          method: "POST",
          credentials: "same-origin",
          headers: {
            "Content-Type": "application/x-www-form-urlencoded",
            "Accept": "application/json",
            "X-Requested-With": "XMLHttpRequest"
          },
          body: form.toString()
        });
      });
    }).then(function (res) {
      if (res && res.ok) window.sappPushReady = true;
    }).catch(function () {});
  }

  function publishUser() {
    if (!("serviceWorker" in navigator) || !me) return;
    var send = function (reg) {
      var worker = (reg && reg.active) || navigator.serviceWorker.controller;
      if (worker) worker.postMessage({ type: "staff-user", userId: body.getAttribute("data-user") || "" });
    };
    navigator.serviceWorker.ready.then(send).catch(function () {});
  }

  if ("serviceWorker" in navigator) {
    navigator.serviceWorker.addEventListener("message", function (event) {
      var data = event.data || {};
      if (data.type === "staff-who" && event.ports && event.ports[0]) {
        event.ports[0].postMessage({ userId: body.getAttribute("data-user") || "" });
      }
      if (data.type === "staff-push") {
        var from = String((data.payload && data.payload.senderId) || "").trim().toLowerCase();
        if (from && me && from === me) return;
        pullPresence();
        if (window.sappOnPush) window.sappOnPush(data.payload || {});
      }
      if (data.type === "staff-push-open" && data.url) window.location.href = data.url;
    });
    publishUser();
    navigator.serviceWorker.addEventListener("controllerchange", publishUser);
  }
  if (typeof Notification !== "undefined" && Notification.permission === "granted") enablePush();

  if (presenceUrl) {
    pullPresence();
    setInterval(pullPresence, 20000);
    document.addEventListener("visibilitychange", function () {
      if (!document.hidden) pullPresence();
    });
  }

  if (!notifyBox || !mobile) return;
  var asked = false;
  try { asked = sessionStorage.getItem("sapp-notify-later") === "1"; } catch (err) {}
  if (asked) return;
  if (typeof Notification === "undefined") {
    if (ios && !standalone && notifyText) {
      notifyText.textContent = "برای اعلان پیام، برنامه را به صفحهٔ اصلی اضافه کنید و دوباره وارد شوید.";
      notifyBox.hidden = false;
      if (yes) {
        yes.textContent = "راهنمای نصب";
        yes.addEventListener("click", function () {
          var install = document.getElementById("sapp-install");
          if (install) install.hidden = false;
        });
      }
    }
    return;
  }
  if (Notification.permission === "granted" || Notification.permission === "denied") return;
  notifyBox.hidden = false;
  if (no) no.addEventListener("click", function () {
    notifyBox.hidden = true;
    try { sessionStorage.setItem("sapp-notify-later", "1"); } catch (err) {}
  });
  if (yes) yes.addEventListener("click", function () {
    if (ios && !standalone) {
      var install = document.getElementById("sapp-install");
      if (install) install.hidden = false;
      if (notifyText) notifyText.textContent = "اول برنامه را به صفحهٔ اصلی اضافه کنید. بعد از باز کردن آن، اجازه اعلان را بدهید.";
      return;
    }
    Notification.requestPermission().then(function (result) {
      notifyBox.hidden = true;
      if (result === "granted") {
        enablePush();
        pullPresence();
      }
    }).catch(function () {});
  });
})();
</script>
HTML;
}

function staff_app_render(string $active, string $title, string $description, string $html, bool $locked = false): void
{
    global $pdo;
    $user = current_user();
    $path = (string) ($GLOBALS['path'] ?? '/app');
    $GLOBALS['pageTitle'] = $title;
    $GLOBALS['pageDescription'] = $description;
    $GLOBALS['pageCanonical'] = seo_absolute_url($path);
    $GLOBALS['pageRobots'] = 'noindex,nofollow';
    $GLOBALS['pageThemeColor'] = '#1a9a8a';
    $GLOBALS['pageKeywords'] = 'برنامه داخلی مانا کلینیک, درمانگر, منشی';
    $unread = 0;
    if (!$locked && $user && $pdo instanceof PDO) {
        $uid = (string) ($user['id'] ?? '');
        staff_app_mark_delivered_all($pdo, $uid);
        $unread = staff_app_unread_count($pdo, $uid);
    }
    $flash = function_exists('flash_get') ? flash_get() : null;
    $name = trim((string) ($user['name'] ?? ''));
    $role = (string) ($user['role'] ?? '');
    $isSecretary = $role === 'SECRETARY';
    $avatarSrc = '';
    $avatarInitial = 'م';
    if ($user && !$locked && $pdo instanceof PDO) {
        if (!function_exists('user_hydrate_session_avatar')) {
            require_once __DIR__ . '/user_avatar.php';
        }
        $fresh = user_hydrate_session_avatar($pdo, $user);
        if (is_array($fresh)) {
            $user = $fresh;
            $name = trim((string) ($user['name'] ?? $name));
        }
        $avatarSrc = user_avatar_src((string) ($user['avatar_url'] ?? ''));
        $avatarInitial = user_avatar_initial($name !== '' ? $name : 'م');
    }
    $navKey = match ($active) {
        'chat' => 'chat',
        'profile' => 'profile',
        'hours' => 'hours',
        default => 'home',
    };
    $vapidKey = '';
    if (!$locked && $user && $pdo instanceof PDO) {
        try {
            staff_app_touch_presence($pdo, (string) ($user['id'] ?? ''));
            $vapidKey = staff_push_public($pdo);
        } catch (Throwable $e) {
            error_log('staff app presence: ' . $e->getMessage());
        }
    }
    header('X-Robots-Tag: noindex, nofollow');
    header('Cache-Control: no-store, no-cache, must-revalidate');
    header('Pragma: no-cache');
    ?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover, interactive-widget=resizes-content">
  <meta name="csrf-token" content="<?= e(csrf_token()) ?>">
  <?= staff_app_pwa_head() ?>
  <?= seo_render_head() ?>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Vazirmatn:wght@400;500;600;700;800&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="<?= e(url('/assets/css/style.css')) ?>?v=20261009consult">
  <?php if (!empty($GLOBALS['pageHead'])): ?>
    <?= $GLOBALS['pageHead'] ?>
  <?php endif; ?>
  <style>
    body.sapp{margin:0;max-width:100%;background:#f3f6f4;color:#1c3d36;font-family:Vazirmatn,Tahoma,sans-serif;-webkit-text-size-adjust:100%;text-size-adjust:100%}
    body.sapp,body.sapp *{box-sizing:border-box}
    .sapp-top{position:sticky;top:0;z-index:20;display:flex;align-items:center;justify-content:space-between;gap:.6rem;padding:.8rem 4vw .45rem;background:#f3f6f4;direction:ltr}
    .sapp-grid,.sapp-nav{direction:ltr}
    .sapp-brand{display:flex;align-items:center;gap:8px;color:#1c3d36;text-decoration:none;font-weight:800;font-size:1.05rem}
    .sapp-brand img{width:36px;height:36px;border-radius:12px;background:#128f84;object-fit:cover}
    .sapp-tools{display:flex;align-items:center;gap:8px}
    .sapp-avatar{width:38px;height:38px;border-radius:999px;background:#e7eeeb;display:inline-flex;align-items:center;justify-content:center;overflow:hidden;color:#1c3d36;font-weight:800;border:2px solid #fff;box-shadow:0 2px 8px rgba(20,60,50,.08);text-decoration:none}
    .sapp-avatar img{width:100%;height:100%;object-fit:cover;display:block}
    .sapp-logout{display:inline-flex;align-items:center;justify-content:center;min-height:36px;padding:0 14px;border-radius:999px;background:#1a9a8a;color:#fff;text-decoration:none;font-weight:800;font-size:.92rem}
    .sapp-main{width:100%;max-width:min(32rem,100%);margin:0 auto;padding:.5rem 4vw calc(5.4rem + env(safe-area-inset-bottom))}
    .sapp-hello h1{margin:8px 0 2px;font-size:1.28rem;font-weight:800;line-height:1.45;color:#1c3d36}
    .sapp-hello p{margin:0 0 14px;color:#8aa099;font-size:.92rem}
    .sapp-main h1{margin:0 0 .35rem;font-size:1.28rem;color:#1c3d36}
    .sapp-flash{margin:0 0 12px;padding:10px 12px;border-radius:12px;background:#e7f6f3}
    .sapp-grid{display:grid;grid-template-columns:1fr 1fr;gap:12px}
    .sapp-tile{display:flex;flex-direction:column;align-items:center;justify-content:center;gap:.5rem;min-height:clamp(6.6rem,30vw,9.25rem);padding:1rem .6rem;border-radius:1.3rem;background:#fff;text-decoration:none;color:#1c3d36;box-shadow:0 .6rem 1.6rem rgba(28,70,58,.06)}
    .sapp-tile strong{font-size:.98rem;font-weight:800;text-align:center;line-height:1.35}
    .sapp-tile em{font-style:normal;color:#9aaba4;font-size:.78rem}
    .sapp-ico{width:52px;height:52px;border-radius:16px;display:flex;align-items:center;justify-content:center}
    .sapp-ico svg{width:28px;height:28px}
    .sapp-ico-blue{background:#e7f3fb;color:#3d8fd4}
    .sapp-ico-green{background:#e7f6ee;color:#3aaa78}
    .sapp-ico-purple{background:#f3eefb;color:#8b72d6}
    .sapp-ico-amber{background:#fff4e8;color:#e0a15a}
    .sapp-ico-pink{background:#fdeef3;color:#e07a9a}
    .sapp-ico-red{background:#fdeeee;color:#e07070}
    .sapp-ico-teal{background:#e5f6f4;color:#128f84}
    .sapp-consult-ico{position:relative}
    .sapp-consult-badge{position:absolute;top:-.35rem;left:-.45rem;z-index:2;min-width:1.15rem;height:1.15rem;padding:0 .28rem;border-radius:999px;background:#e23b3b;color:#fff;font-size:.72rem;font-weight:800;line-height:1;display:inline-flex;align-items:center;justify-content:center;box-shadow:0 0 0 .12rem #fff;pointer-events:none}
    .sapp-consult-badge-inline{position:static;margin-inline-start:.45rem;vertical-align:.08rem;pointer-events:auto}
    .sapp-nav{position:fixed;right:0;left:0;bottom:0;z-index:30;display:grid;grid-template-columns:repeat(4,minmax(0,1fr));padding:.35rem 1.5vw calc(.45rem + env(safe-area-inset-bottom));background:#fff;border-top:1px solid #e6eeea}
    .sapp-nav a{display:flex;flex-direction:column;align-items:center;gap:1px;text-decoration:none;color:#8aa099;font-size:clamp(.68rem,2.8vw,.78rem);font-weight:700;min-width:0;text-align:center}
    .sapp-nav a.is-active{color:#159688}
    .sapp-nav-ico{width:46px;height:32px;display:flex;align-items:center;justify-content:center;border-radius:14px}
    .sapp-nav a.is-active .sapp-nav-ico{background:#e7f6f3}
    .sapp-nav svg{width:22px;height:22px}
    .sapp-badge{position:absolute;top:2px;left:50%;transform:translateX(-18px);min-width:1.1rem;height:1.1rem;padding:0 4px;border-radius:999px;background:#e07070;color:#fff;font-size:.68rem;display:inline-flex;align-items:center;justify-content:center}
    .sapp-nav a{position:relative}
    .sapp-nav .is-locked{display:flex;flex-direction:column;align-items:center;gap:1px;min-width:0;text-align:center;color:#b7c4be;font-size:clamp(.68rem,2.8vw,.78rem);font-weight:700;opacity:.5;cursor:default;-webkit-user-select:none;user-select:none}
    .sapp-fab{position:fixed;z-index:31;right:18px;bottom:calc(76px + env(safe-area-inset-bottom));display:flex;align-items:center;gap:8px;color:#1c3d36;text-decoration:none;font-weight:800;font-size:.92rem}
    .sapp-fab-btn{width:54px;height:54px;border-radius:999px;background:#1a9a8a;color:#fff;display:flex;align-items:center;justify-content:center;box-shadow:0 10px 22px rgba(26,154,138,.35)}
    .sapp-fab-btn svg{width:26px;height:26px}
    .sapp-profile{display:flex;flex-direction:column;align-items:center;gap:8px;margin-top:18px;padding:22px 16px;border-radius:22px;background:#fff;box-shadow:0 10px 28px rgba(28,70,58,.06)}
    .sapp-profile .sapp-avatar{width:72px;height:72px;font-size:1.6rem}
    .sapp-list{display:flex;flex-direction:column;gap:8px;margin-top:12px}
    .sapp-consult{margin-top:.75rem;padding:12px 14px;border:1px solid #e6eeea;border-radius:16px;background:#fff}
    .sapp-consult.is-new{border-color:#f3d0d0;background:#fff8f8}
    .sapp-consult header{display:flex;flex-wrap:wrap;gap:.35rem .7rem;align-items:baseline}
    .sapp-consult time,.sapp-consult .muted{color:#8aa099;font-size:.82rem}
    .sapp-consult a{display:inline-block;margin:.45rem 0;color:#128f84;font-weight:800;font-size:1.05rem;text-decoration:none}
    .sapp-consult p{margin:.2rem 0 .7rem;white-space:pre-wrap;line-height:1.75}
    .sapp-consult .consult-stamp{margin:0 0 .3rem;white-space:normal}
    .sapp-row{padding:12px;border:1px solid var(--line);border-radius:14px;background:var(--card)}
    .sapp-row small{display:block;margin-top:4px;color:var(--muted)}
    .sapp-day{margin:16px 0 0;font-size:1rem}
    .sapp-chat{display:flex;flex-direction:column;gap:.4rem;margin-top:.7rem;padding:.7rem .6rem;border-radius:1.1rem;background:#efe7dc;min-height:min(46svh,22rem)}
    html:has(body.sapp.is-thread){height:100%;overflow:hidden}
    body.sapp.is-thread{--sapp-kb:0px;position:fixed;top:0;left:0;right:0;display:flex;flex-direction:column;width:100%;height:100vh;height:calc(100svh - var(--sapp-kb));max-width:100%;overflow:hidden;padding-top:env(safe-area-inset-top);box-sizing:border-box;overscroll-behavior:none}
    body.sapp.is-thread .sapp-top,body.sapp.is-thread .sapp-install,body.sapp.is-thread .sapp-notify{flex:none}
    body.sapp.is-thread .sapp-top{gap:clamp(.35rem,2vw,.6rem);padding:clamp(.35rem,2.2vw,.7rem) 3vw clamp(.2rem,1.2vw,.4rem)}
    body.sapp.is-thread .sapp-brand{gap:clamp(.3rem,1.6vw,.5rem);font-size:clamp(.88rem,4.2vw,1.05rem)}
    body.sapp.is-thread .sapp-brand img{width:clamp(1.65rem,8vw,2.25rem);height:clamp(1.65rem,8vw,2.25rem);border-radius:clamp(.5rem,2.4vw,.75rem)}
    body.sapp.is-thread .sapp-tools{gap:clamp(.3rem,1.6vw,.5rem)}
    body.sapp.is-thread .sapp-avatar{width:clamp(1.75rem,8.4vw,2.35rem);height:clamp(1.75rem,8.4vw,2.35rem)}
    body.sapp.is-thread .sapp-logout{min-height:clamp(1.7rem,7.6vw,2.25rem);padding:0 clamp(.5rem,2.8vw,.85rem);font-size:clamp(.75rem,3.4vw,.92rem)}
    body.sapp.is-thread .sapp-main{flex:1;min-height:0;display:flex;flex-direction:column;width:100%;max-width:min(32rem,100%);margin:0 auto;padding:clamp(.2rem,1.4vw,.35rem) 3vw 0;overflow:hidden}
    body.sapp.is-thread .sapp-main>p{margin:0 0 .2rem;font-size:clamp(.72rem,3.2vw,.92rem)}
    body.sapp.is-thread .sapp-main h1,body.sapp.is-thread .sapp-thread-title{margin:0 0 .2rem;font-size:clamp(.92rem,4.4vw,1.15rem);line-height:1.3}
    body.sapp.is-thread .sapp-chat{flex:1;min-height:0;margin-top:clamp(.15rem,1.2vw,.3rem);padding:clamp(.35rem,2vw,.65rem) clamp(.3rem,1.8vw,.55rem);gap:clamp(.25rem,1.4vw,.4rem);border-radius:clamp(.7rem,3vw,1.1rem);overflow:auto;-webkit-overflow-scrolling:touch}
    body.sapp.is-thread .sapp-compose{flex:none;display:flex;flex-direction:column;align-items:stretch;gap:clamp(.25rem,1.4vw,.35rem);margin:0;padding:clamp(.25rem,1.4vw,.35rem) 0 calc(.35rem + env(safe-area-inset-bottom));background:#f3f6f4}
    body.sapp.is-thread .sapp-nav{position:static;flex:none}
    body.sapp.is-thread .sapp-msg{padding:clamp(.22rem,1.4vw,.4rem) clamp(.35rem,1.8vw,.5rem) clamp(.12rem,.8vw,.25rem);border-radius:clamp(.5rem,2.4vw,.75rem)}
    body.sapp.is-thread .sapp-msg.is-mine{border-top-right-radius:.25rem}
    body.sapp.is-thread .sapp-msg.is-theirs{border-top-left-radius:.25rem}
    body.sapp.is-thread .sapp-msg p{font-size:clamp(.88rem,3.6vw,1rem);line-height:1.45}
    body.sapp.is-thread .sapp-msg-name{margin-bottom:.1rem;font-size:clamp(.66rem,3vw,.78rem)}
    body.sapp.is-thread .sapp-msg-meta{gap:.15rem;margin-top:.1rem;font-size:clamp(.62rem,2.7vw,.72rem)}
    body.sapp.is-thread .sapp-ticks svg{width:clamp(.8rem,3.4vw,1rem);height:clamp(.7rem,3vw,.875rem)}
    body.sapp.is-thread .sapp-compose textarea.input{min-height:clamp(2.15rem,10.5vw,2.75rem);max-height:clamp(4.5rem,22svh,8rem);padding:clamp(.4rem,1.8vw,.65rem) clamp(.55rem,2.4vw,.85rem);font-size:1rem}
    body.sapp.is-thread .sapp-file,body.sapp.is-thread .sapp-send,body.sapp.is-thread .sapp-voice{width:clamp(2.15rem,10.5vw,2.75rem);height:clamp(2.15rem,10.5vw,2.75rem)}
    body.sapp.is-thread .sapp-send{font-size:clamp(.9rem,4vw,1.05rem)}
    body.sapp.is-thread .sapp-file svg{width:clamp(1.05rem,4.8vw,1.35rem);height:clamp(1.05rem,4.8vw,1.35rem)}
    @media (hover:none) and (pointer:coarse){
      body.sapp.is-thread.is-typing{--sapp-kb:clamp(12rem,50svh,28rem)}
    }
    body.sapp.is-typing .sapp-nav{display:none}
    body.sapp.is-typing .sapp-top{padding:clamp(.15rem,1.2vw,.25rem) 3vw clamp(.05rem,.6vw,.12rem)}
    body.sapp.is-typing .sapp-compose{padding-bottom:.35rem;border-top:1px solid #e6eeea}
    body.sapp.is-typing .sapp-main>p{display:none}
    .sapp-compose-row{display:flex;align-items:flex-end;gap:.45rem;width:100%;min-width:0}
    .sapp-compose-row textarea.input{flex:1 1 auto;width:auto;min-width:0;max-width:100%;font-size:1rem}
    .sapp-chat-empty{margin:auto;padding:8px 12px;border-radius:10px;background:rgba(255,255,255,.72);color:#667781;font-size:.86rem}
    .sapp-msg{width:fit-content;max-width:82%;padding:6px 8px 4px;border-radius:12px;box-shadow:0 1px 1px rgba(0,0,0,.08)}
    .sapp-msg.is-mine{margin-left:auto;margin-right:0;background:#d9fdd3;border-top-right-radius:4px}
    .sapp-msg.is-theirs{margin-right:auto;margin-left:0;background:#fff;border-top-left-radius:4px}
    .sapp-msg-name{display:block;margin-bottom:2px;color:#1a9a8a;font-size:.78rem;font-weight:800}
    .sapp-msg p{margin:0;white-space:pre-wrap;line-height:1.55}
    .sapp-msg-meta{display:flex;align-items:center;justify-content:flex-end;gap:3px;margin-top:2px;color:#667781;font-size:.72rem;line-height:1}
    .sapp-ticks{display:inline-flex;color:#8696a0}
    .sapp-ticks svg{width:16px;height:14px;display:block}
    .sapp-ticks.is-read{color:#1fa855}
    .sapp-msg img{display:block;max-width:min(100%,16rem);margin-top:6px;border-radius:8px}
    .sapp-person{display:flex;align-items:center;justify-content:space-between;gap:8px;width:100%;min-height:52px;margin:0 0 8px;padding:10px 12px;border:1px solid #e6eeea;border-radius:14px;background:#fff;color:#1c3d36;font:inherit;font-weight:700;text-align:right;cursor:pointer}
    .sapp-person-name{display:inline-flex;align-items:center;gap:.35rem;min-width:0}
    .sapp-dot{display:inline-block;width:.55rem;height:.55rem;border-radius:999px;background:#1fa855;box-shadow:0 0 0 .18rem rgba(31,168,85,.22);flex:none;vertical-align:middle}
    .sapp-unread{color:#e23b3b;font-weight:800;font-size:1rem;line-height:1;flex:none}
    .sapp-unread[hidden]{display:none !important}
    .sapp-dot[hidden]{display:none !important}
    .sapp-thread-title{display:flex;align-items:center;gap:.4rem}
    .sapp-room-name{display:flex;direction:ltr;justify-content:flex-end;align-items:center;gap:.4rem;width:100%}
    .sapp-room-label{display:inline-flex;align-items:center;gap:.35rem;min-width:0;direction:rtl}
    .sapp-notify{display:flex;flex-wrap:wrap;align-items:center;gap:.5rem;width:min(32rem,calc(100% - 1.5rem));margin:.2rem auto .4rem;padding:.7rem .85rem;border-radius:14px;background:#e7f6f3;color:#1c3d36}
    .sapp-notify[hidden]{display:none !important}
    .sapp-notify p{margin:0;flex:1 1 12rem;font-size:.92rem;line-height:1.55}
    .sapp-notify button{border:0;border-radius:999px;min-height:2.4rem;padding:0 .9rem;font:inherit;font-weight:800;cursor:pointer}
    .sapp-notify-yes{background:#1a9a8a;color:#fff}
    .sapp-notify-no{background:transparent;color:#5d746c}
    .sapp-person small{color:#8aa099;font-weight:600}
    .sapp-compose{display:flex;align-items:flex-end;gap:.5rem;margin-top:.6rem}
    .sapp-compose textarea.input{width:auto;flex:1;min-width:0;min-height:2.75rem;max-height:8rem;border-radius:1.1rem;font-size:1rem}
    .sapp-file{position:relative;display:inline-flex;align-items:center;justify-content:center;width:2.75rem;height:2.75rem;border-radius:999px;background:#fff;color:#1a9a8a;cursor:pointer;flex:none}
    .sapp-file input{position:absolute;width:1px;height:1px;overflow:hidden;clip:rect(0,0,0,0)}
    .sapp-send{flex:none;width:2.75rem;height:2.75rem;border:0;border-radius:999px;background:#1a9a8a;color:#fff;font-weight:800;font-size:1.05rem}
    .sapp-voice{flex:none;width:2.75rem;height:2.75rem;padding:0;border:0;border-radius:999px;background:#fff;color:#1a9a8a;display:inline-flex;align-items:center;justify-content:center;cursor:pointer;touch-action:none;user-select:none;-webkit-user-select:none;-webkit-touch-callout:none}
    .sapp-voice svg,.sapp-voice-mini svg,.sapp-voice-go svg,.sapp-voice-trash svg,.sapp-voice-play svg{display:block;pointer-events:none}
    .sapp-voice-hint{margin:0;padding:.15rem .35rem .25rem;color:#1a9a8a;font-size:.82rem;font-weight:800;text-align:center}
    .sapp-voice-hint[hidden]{display:none !important}
    .sapp-voice-rec{display:flex;align-items:flex-end;gap:.45rem;width:100%;min-width:0;direction:ltr}
    .sapp-voice-rec[hidden],.sapp-voice-live[hidden],.sapp-voice-paused[hidden]{display:none !important}
    .sapp-voice-live,.sapp-voice-paused{display:flex;align-items:flex-end;gap:.45rem;width:100%;min-width:0}
    .sapp-compose-row.is-recording{align-items:flex-end;overflow:visible}
    .sapp-compose-row.is-recording .sapp-file,
    .sapp-compose-row.is-recording .sapp-voice,
    .sapp-compose-row.is-recording textarea,
    .sapp-compose-row.is-recording .sapp-send{display:none !important}
    .sapp-compose-row.is-recording .sapp-voice-rec{display:flex;flex:1 1 auto}
    .sapp-voice-pill{flex:1;min-width:0;display:flex;align-items:center;gap:.55rem;min-height:2.85rem;padding:.35rem .9rem;border-radius:999px;background:#e7eeeb}
    .sapp-voice-dot{width:.62rem;height:.62rem;border-radius:999px;background:#e25b6a;flex:none;animation:sapp-voice-pulse 1s ease-in-out infinite}
    .sapp-voice-time{font-weight:800;font-variant-numeric:tabular-nums;color:#1c3d36;font-size:1rem}
    .sapp-voice-cancel{margin-inline-start:auto;border:0;background:transparent;color:#1a9a8a;font:inherit;font-weight:800;font-size:1rem;cursor:pointer}
    .sapp-voice-stack{position:relative;flex:none;width:3.45rem;height:3.45rem;z-index:5}
    .sapp-voice-go{width:3.45rem;height:3.45rem;border:0;border-radius:999px;background:#1a9a8a;color:#fff;display:inline-flex;align-items:center;justify-content:center;box-shadow:0 .4rem 1rem rgba(26,154,138,.28);cursor:pointer}
    .sapp-voice-mini{position:absolute;left:50%;bottom:calc(100% + .4rem);transform:translateX(-50%);width:2.45rem;height:2.45rem;border:0;border-radius:999px;background:#163832;color:#fff;display:inline-flex;align-items:center;justify-content:center;cursor:pointer}
    .sapp-voice-trash{flex:none;width:2.75rem;height:2.75rem;border:0;border-radius:999px;background:#fff;color:#1c3d36;display:inline-flex;align-items:center;justify-content:center;box-shadow:0 .15rem .5rem rgba(28,70,58,.08);cursor:pointer}
    .sapp-voice-preview{flex:1;min-width:0;display:flex;align-items:center;gap:.45rem;min-height:2.85rem;padding:.3rem .7rem .3rem .35rem;border-radius:999px;background:#1a9a8a;color:#fff}
    .sapp-voice-play{flex:none;width:2.15rem;height:2.15rem;border:0;border-radius:999px;background:#fff;color:#1a9a8a;display:inline-flex;align-items:center;justify-content:center;cursor:pointer}
    .sapp-voice-play .is-pause{display:none}
    .sapp-voice-play.is-on .is-play{display:none}
    .sapp-voice-play.is-on .is-pause{display:block}
    .sapp-voice-wave{flex:1;min-width:0;height:1.35rem;display:flex;align-items:center;gap:2px}
    .sapp-voice-wave i{flex:1;display:block;height:30%;border-radius:999px;background:rgba(255,255,255,.85)}
    .sapp-voice-wave.is-on i{animation:sapp-voice-bar .8s ease-in-out infinite}
    .sapp-voice-dur{flex:none;font-weight:800;font-size:.92rem;font-variant-numeric:tabular-nums}
    .sapp-audio{display:block;width:min(16rem,70vw);max-width:100%;height:2.15rem;margin-top:6px}
    body.sapp-voice-hold,body.sapp-voice-hold .sapp-chat{overflow:hidden;touch-action:none}
    @keyframes sapp-voice-pulse{50%{opacity:.35}}
    @keyframes sapp-voice-bar{50%{height:100%}}
    .sapp-install{display:flex;align-items:center;gap:.7rem;width:min(32rem,calc(100% - 1.5rem));margin:.2rem auto .4rem;padding:.7rem .85rem;border-radius:1rem;background:#fff;box-shadow:0 .35rem 1.1rem rgba(28,70,58,.08);font-size:.9rem;line-height:1.55}
    .sapp-install[hidden]{display:none !important}
    .sapp-install p{margin:0;flex:1}
    .sapp-install button{flex:none;border:0;border-radius:999px;background:#1a9a8a;color:#fff;font:inherit;font-weight:800;min-height:2.4rem;padding:0 .9rem}
    .sapp-group{margin-top:16px}
    .sapp-group summary{cursor:pointer;font-weight:800}
    .sapp-check{display:flex;gap:10px;align-items:center;min-height:44px;margin:0 0 6px}
    .sapp-check input{width:22px;height:22px;flex:none}
    .sapp-msg{-webkit-touch-callout:none;-webkit-user-select:none;user-select:none}
    .sapp-msg.is-pending{opacity:.72}
    .sapp-pin{display:block;margin:0 0 8px;padding:8px 10px;border-radius:12px;background:#fff;border-inline-start:3px solid #1a9a8a;color:inherit;text-decoration:none}
    .sapp-pin strong{display:block;color:#1a9a8a;font-size:.78rem}
    .sapp-pin span{display:block;color:#667781;font-size:.84rem}
    .sapp-quote{display:block;margin-bottom:4px;padding:4px 8px;border-radius:8px;background:rgba(0,0,0,.06);border-inline-start:3px solid #1a9a8a;color:inherit;text-decoration:none;font-size:.82rem}
    .sapp-quote strong{display:block;color:#1a9a8a}
    .sapp-forward{display:block;margin-bottom:2px;color:#1a9a8a;font-size:.75rem;font-weight:800}
    .sapp-reacts{display:flex;flex-wrap:wrap;gap:4px;margin-top:4px}
    .sapp-reacts:empty{display:none}
    .sapp-react-chip{border-radius:999px;background:rgba(255,255,255,.75);padding:1px 7px;font-size:.78rem}
    .sapp-msg.is-mine .sapp-react-chip.is-mine{background:#b7ebc6}
    .sapp-chat.is-selecting .sapp-msg{cursor:pointer}
    .sapp-msg.is-picked{box-shadow:0 0 0 2px #1a9a8a}
    body.sapp:not(.is-thread) .sapp-compose{flex-wrap:wrap}
    .sapp-reply{flex:1 0 100%;display:flex;align-items:center;gap:8px;padding:8px 10px;border-radius:12px;background:#fff;border-inline-start:3px solid #1a9a8a}
    .sapp-reply[hidden]{display:none}
    .sapp-reply strong{display:block;color:#1a9a8a;font-size:.78rem}
    .sapp-reply span{display:block;max-width:16rem;overflow:hidden;color:#667781;font-size:.8rem;white-space:nowrap;text-overflow:ellipsis}
    .sapp-reply button{border:0;background:transparent;color:#667781;font-size:1.3rem;line-height:1}
    #sapp-hold[hidden],#sapp-hold[hidden] *,.sapp-hold-people[hidden],#sapp-hold-forward[hidden],#sapp-selectbar[hidden],.sapp-hold-item[hidden],.sapp-notify[hidden],.sapp-install[hidden]{display:none !important;pointer-events:none !important}
    .sapp-nav a,.sapp-tile,.sapp-logout,.sapp-send,.sapp-voice-go,.sapp-voice-mini,.sapp-voice-cancel,.sapp-voice-trash,.sapp-voice-play,.sapp-person,.sapp-notify button,.sapp-install button{touch-action:manipulation}
    .sapp-hold-back{position:fixed;inset:0;z-index:80;border:0;padding:0;background:rgba(0,0,0,.28)}
    .sapp-hold-pop{position:fixed;z-index:81;display:flex;flex-direction:column;gap:8px;width:min(17.5rem,calc(100vw - 20px));max-height:calc(100vh - 16px);overflow:auto}
    .sapp-hold-emojis{display:flex;justify-content:space-between;gap:2px;padding:6px 8px;border-radius:999px;background:#2c2c2e}
    .sapp-hold-emojis button{border:0;background:transparent;font-size:1.25rem;line-height:1;padding:4px}
    .sapp-hold-emojis button.is-on{background:rgba(255,255,255,.16);border-radius:999px}
    .sapp-hold-menu{overflow:hidden;padding:4px 0;border-radius:16px;background:#1c1c1e;color:#fff}
    .sapp-hold-item{display:flex;align-items:center;gap:10px;width:100%;min-height:44px;padding:8px 14px;border:0;background:transparent;color:inherit;font:inherit;text-align:right}
    .sapp-hold-item svg{width:18px;height:18px;flex:none}
    .sapp-hold-item.is-danger{color:#ff6b6b}
    .sapp-hold-item.is-split{box-shadow:inset 0 1px 0 rgba(255,255,255,.12)}
    .sapp-hold-faces{display:flex;margin-inline-start:auto}
    .sapp-hold-faces i,.sapp-seen-av{display:inline-flex;align-items:center;justify-content:center;border-radius:999px;background:#3a3a3c;font-style:normal}
    .sapp-hold-faces i{width:22px;height:22px;margin-inline-start:-6px;border:1px solid #1c1c1e;font-size:.68rem}
    .sapp-hold-people{max-height:11rem;overflow:auto;border-radius:14px;background:#1c1c1e;color:#fff}
    .sapp-seen-row{display:flex;align-items:center;gap:8px;padding:8px 12px}
    .sapp-seen-av{width:28px;height:28px;flex:none;font-size:.8rem}
    .sapp-seen-row small{color:#aaa}
    .sapp-seen-check{margin-inline-start:auto;color:#1fa855;font-weight:800}
    .sapp-hold-forward-title{margin:0;padding:10px 14px 4px;color:#aaa;font-size:.78rem}
    .sapp-selectbar{position:fixed;right:16px;left:16px;bottom:calc(76px + env(safe-area-inset-bottom));z-index:40;display:flex;gap:8px}
    .sapp-selectbar button{flex:1;min-height:44px;border:0;border-radius:12px;background:#1c1c1e;color:#fff;font:inherit;font-weight:800}
    .sapp-selectbar .is-danger{background:#c44747}
    .sapp-checklist{direction:rtl}
    .sapp-check-tabs{display:flex;flex-wrap:wrap;gap:.45rem;margin:.85rem 0 .7rem}
    .sapp-check-tab{flex:1 1 7.5rem;min-width:0;min-height:2.75rem;padding:.4rem .7rem;border:1px solid #d5ebe7;border-radius:.95rem;background:#fff;color:#1a9a8a;box-shadow:0 .35rem 1rem rgba(28,70,58,.05);font-size:clamp(.88rem,3.6vw,1rem);font-weight:800;line-height:1.35;text-align:center;text-decoration:none;display:flex;align-items:center;justify-content:center;touch-action:manipulation}
    .sapp-check-tab.is-active{background:#1a9a8a;border-color:#1a9a8a;color:#fff}
    .sapp-daynav{display:flex;direction:ltr;align-items:center;justify-content:space-between;gap:.5rem;margin:0 0 .85rem}
    .sapp-daynav-btn{flex:none;width:2.75rem;height:2.75rem;border:1px solid #d5ebe7;border-radius:999px;background:#fff;color:#1a9a8a;box-shadow:0 .25rem .8rem rgba(28,70,58,.06);display:inline-flex;align-items:center;justify-content:center;text-decoration:none;touch-action:manipulation}
    .sapp-daynav-btn svg{width:1.35rem;height:1.35rem;display:block}
    .sapp-daynav-btn.is-off{opacity:.35}
    .sapp-daynav-date{flex:1;min-width:0;direction:rtl;text-align:center;color:#1c3d36;font-size:clamp(.92rem,3.8vw,1.05rem);font-weight:800;line-height:1.45}
    .sapp-check-absent{margin:0;padding:1rem 1.05rem;border-radius:1rem;background:#fff;color:#5d746c;box-shadow:0 .35rem 1rem rgba(28,70,58,.05);line-height:1.7}
    body.sapp{padding:0}
  </style>
</head>
<body class="sapp<?= !empty($GLOBALS['staffBodyClass']) ? ' ' . e((string) $GLOBALS['staffBodyClass']) : '' ?>"<?= $user ? ' data-user="' . e((string) ($user['id'] ?? '')) . '" data-session-guard="1" data-session-ping="' . e(url('/session/ping')) . '" data-logout="' . e(staff_app_logout_href()) . '"' : '' ?><?= ($user && !$locked) ? ' data-presence="' . e(url('/app/presence')) . '" data-chat="' . e(url('/app/chat')) . '" data-unread="' . e((string) (int) $unread) . '" data-vapid="' . e($vapidKey) . '" data-push="' . e(url('/app/push/subscribe')) . '"' : '' ?><?= $isSecretary ? ' data-secretary-desk="1" data-no-idle="1" data-heartbeat="' . e(url('/secretary/heartbeat')) . '"' : '' ?>>
  <header class="sapp-top">
    <a class="sapp-brand" href="<?= e(url('/app')) ?>">
      <img src="<?= e(url('/assets/img/logo.png')) ?>" alt="" width="36" height="36">
      Mana Staff
    </a>
    <?php if ($user): ?>
      <div class="sapp-tools">
        <?php if (!$locked): ?>
          <a class="sapp-avatar" href="<?= e(url('/app/profile')) ?>" aria-label="پروفایل">
            <?php if ($avatarSrc !== ''): ?>
              <img src="<?= e(url($avatarSrc)) ?>" alt="" width="38" height="38">
            <?php else: ?>
              <?= e($avatarInitial) ?>
            <?php endif; ?>
          </a>
        <?php endif; ?>
        <a class="sapp-logout" href="<?= e(staff_app_logout_href()) ?>">خروج</a>
      </div>
    <?php endif; ?>
  </header>
  <?php if ($user && !$locked): ?>
  <div class="sapp-notify" id="sapp-notify" hidden>
    <p id="sapp-notify-text">برای خبردار شدن از پیام تازه، اجازه اعلان را بدهید.</p>
    <button type="button" class="sapp-notify-yes" id="sapp-notify-yes">اجازه می‌دهم</button>
    <button type="button" class="sapp-notify-no" id="sapp-notify-no">بعداً</button>
  </div>
  <?php endif; ?>
  <div class="sapp-install" id="sapp-install" hidden>
    <p><strong>نصب روی آیفون</strong> دکمه اشتراک‌گذاری را بزنید و «افزودن به صفحهٔ اصلی» را انتخاب کنید. آیفون مثل اندروید خودش پنجره نصب نشان نمی‌دهد.</p>
    <button type="button" id="sapp-install-x">فهمیدم</button>
  </div>
  <main class="sapp-main">
    <?php if (is_array($flash) && trim((string) ($flash['message'] ?? '')) !== ''): ?>
      <p class="sapp-flash"><?= e((string) $flash['message']) ?></p>
    <?php endif; ?>
    <?= $html ?>
  </main>
  <?php if (!$locked && $active === 'home'): ?>
    <a class="sapp-fab" href="<?= e(url('/app/chat')) ?>#sapp-new-chat">
      <span class="sapp-fab-btn" aria-hidden="true">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M7 17.5 4 20V6.5A2.5 2.5 0 0 1 6.5 4h11A2.5 2.5 0 0 1 20 6.5v8A2.5 2.5 0 0 1 17.5 17H7z"/><path d="M8 9h8M8 12.5h5"/></svg>
      </span>
      <span>چت جدید</span>
    </a>
  <?php endif; ?>
  <?php if (!$locked): ?>
    <nav class="sapp-nav" aria-label="بخش‌های برنامه">
      <a class="<?= $navKey === 'home' ? 'is-active' : '' ?>" href="<?= e(url('/app')) ?>">
        <span class="sapp-nav-ico"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M4 10.5 12 4l8 6.5V20a1 1 0 0 1-1 1h-5v-6H10v6H5a1 1 0 0 1-1-1v-9.5z"/></svg></span>
        خانه
      </a>
      <a class="<?= $navKey === 'chat' ? 'is-active' : '' ?>" href="<?= e(url('/app/chat')) ?>">
        <span class="sapp-nav-ico"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M7 17.5 4 20V6.5A2.5 2.5 0 0 1 6.5 4h11A2.5 2.5 0 0 1 20 6.5v8A2.5 2.5 0 0 1 17.5 17H7z"/></svg></span>
        گفتگو
        <?php if ($unread > 0): ?><span class="sapp-badge"><?= e(to_fa_digits((string) $unread)) ?></span><?php endif; ?>
      </a>
      <?php if ($isSecretary): ?>
      <span class="is-locked" role="link" aria-disabled="true" tabindex="-1">
        <span class="sapp-nav-ico"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="8"/><path d="M12 8v4.2l2.6 1.6"/></svg></span>
        ساعت کاری
      </span>
      <?php else: ?>
      <a class="<?= $navKey === 'hours' ? 'is-active' : '' ?>" href="<?= e(url('/app/hours')) ?>">
        <span class="sapp-nav-ico"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="8"/><path d="M12 8v4.2l2.6 1.6"/></svg></span>
        ساعت کاری
      </a>
      <?php endif; ?>
      <a class="<?= $navKey === 'profile' ? 'is-active' : '' ?>" href="<?= e(url('/app/profile')) ?>">
        <span class="sapp-nav-ico"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="8" r="3.2"/><path d="M5.5 19.2a6.5 6.5 0 0 1 13 0"/></svg></span>
        پروفایل
      </a>
    </nav>
  <?php endif; ?>
  <?= staff_app_pwa_script() ?>
  <?php if ($user): ?>
    <script src="<?= e(url('/assets/js/session-guard.js')) ?>?v=20261001idle"></script>
  <?php endif; ?>
  <?php if ($isSecretary): ?>
    <script src="<?= e(url('/assets/js/secretary-idle.js')) ?>?v=20261001idle"></script>
  <?php endif; ?>
  <?php if (!empty($GLOBALS['pageScripts'])): ?>
    <?= $GLOBALS['pageScripts'] ?>
  <?php endif; ?>
</body>
</html>
    <?php
}
