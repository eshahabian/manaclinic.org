<?php
declare(strict_types=1);

const MENTION_TEXT_COLOR = '#0d7a6a';

function ensure_mentions_schema(PDO $pdo): void
{
    static $ready = false;
    if ($ready) {
        return;
    }
    $pdo->exec("
      CREATE TABLE IF NOT EXISTS user_mentions (
        id VARCHAR(32) PRIMARY KEY,
        from_user_id VARCHAR(32) NOT NULL,
        to_user_id VARCHAR(32) NOT NULL,
        context VARCHAR(64) NOT NULL DEFAULT 'general',
        context_id VARCHAR(64) NULL,
        body_snippet VARCHAR(255) NOT NULL,
        link VARCHAR(255) NULL,
        is_read TINYINT(1) NOT NULL DEFAULT 0,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_mention_to_read (to_user_id, is_read, created_at),
        INDEX idx_mention_from (from_user_id, created_at)
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    $ready = true;
}

function mentions_panel_path(?string $role = null): string
{
    $role = strtoupper((string) ($role ?? (current_user()['role'] ?? '')));
    return match ($role) {
        'ADMIN' => '/admin/mentions',
        'DOCTOR' => '/doctor/mentions',
        'SECRETARY' => '/secretary/mentions',
        'PATIENT' => '/dashboard/mentions',
        default => '/dashboard/mentions',
    };
}

/** @return list<string> */
function mentions_suggestable_roles(string $role): array
{
    $role = strtoupper($role);
    return match ($role) {
        'ADMIN' => ['ADMIN', 'DOCTOR', 'SECRETARY', 'PATIENT'],
        'DOCTOR' => ['ADMIN', 'DOCTOR', 'SECRETARY', 'PATIENT'],
        'SECRETARY' => ['ADMIN', 'DOCTOR', 'SECRETARY', 'PATIENT'],
        'PATIENT' => ['ADMIN', 'DOCTOR', 'SECRETARY'],
        default => [],
    };
}

function mentions_role_label(string $role): string
{
    return match (strtoupper($role)) {
        'ADMIN' => 'مدیر',
        'DOCTOR' => 'درمانگر',
        'SECRETARY' => 'منشی',
        'PATIENT' => 'مراجعه‌کننده',
        default => $role,
    };
}

/**
 * پیشنهاد یوزر برای @ — فقط نقش‌های مجاز، بدون خود کاربر.
 *
 * @return list<array{id:string,name:string,username:string,role:string,label:string}>
 */
function mentions_suggest(PDO $pdo, array $user, string $query, int $limit = 12): array
{
    $roles = mentions_suggestable_roles((string) ($user['role'] ?? ''));
    if (!$roles) {
        return [];
    }
    $limit = max(1, min(20, $limit));
    $selfId = (string) ($user['id'] ?? '');
    $q = trim($query);
    $place = implode(',', array_fill(0, count($roles), '?'));
    $params = $roles;
    $sql = "
      SELECT id, name, username, role
      FROM users
      WHERE role IN ({$place})
    ";
    if ($selfId !== '') {
        $sql .= ' AND id <> ?';
        $params[] = $selfId;
    }
    if ($q !== '') {
        $sql .= ' AND (name LIKE ? OR username LIKE ? OR email LIKE ?)';
        $like = '%' . $q . '%';
        $params[] = $like;
        $params[] = $like;
        $params[] = $like;
    }
    $sql .= ' ORDER BY name ASC, username ASC LIMIT ' . $limit;
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $out = [];
    foreach ($stmt->fetchAll() ?: [] as $row) {
        $label = function_exists('staff_actor_label')
            ? staff_actor_label($row)
            : trim((string) (($row['name'] ?? '') ?: ($row['username'] ?? '')));
        $out[] = [
            'id' => (string) $row['id'],
            'name' => (string) ($row['name'] ?? ''),
            'username' => (string) ($row['username'] ?? ''),
            'role' => (string) ($row['role'] ?? ''),
            'role_label' => mentions_role_label((string) ($row['role'] ?? '')),
            'label' => $label !== '' ? $label : 'کاربر',
        ];
    }

    return $out;
}

/** @return list<string> */
function mentions_extract_ids_from_html(string $html): array
{
    $ids = [];
    if ($html === '') {
        return [];
    }
    if (preg_match_all('/data-mention-id\s*=\s*(["\'])([a-zA-Z0-9_-]+)\1/i', $html, $m)) {
        foreach ($m[2] as $id) {
            $id = trim((string) $id);
            if ($id !== '') {
                $ids[$id] = true;
            }
        }
    }

    return array_keys($ids);
}

function mentions_snippet_from_html(string $html, int $max = 160): string
{
    $plain = trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8')) ?? '');
    if ($plain === '') {
        return 'شما را منشن کرد';
    }

    return mb_substr($plain, 0, $max);
}

/**
 * از HTML منشن‌ها را ثبت و اعلان می‌فرستد.
 *
 * @return int تعداد منشن جدید
 */
function mentions_capture(
    PDO $pdo,
    string $fromUserId,
    string $html,
    string $context = 'general',
    ?string $contextId = null,
    ?string $link = null
): int {
    ensure_mentions_schema($pdo);
    $ids = mentions_extract_ids_from_html($html);
    if (!$ids || $fromUserId === '') {
        return 0;
    }

    $fromStmt = $pdo->prepare('SELECT id, name, username, role FROM users WHERE id=? LIMIT 1');
    $fromStmt->execute([$fromUserId]);
    $from = $fromStmt->fetch() ?: [];
    $allowedRoles = mentions_suggestable_roles((string) ($from['role'] ?? ''));
    if (!$allowedRoles) {
        return 0;
    }

    $place = implode(',', array_fill(0, count($ids), '?'));
    $rolePlace = implode(',', array_fill(0, count($allowedRoles), '?'));
    $stmt = $pdo->prepare("
      SELECT id, role FROM users
      WHERE id IN ({$place}) AND role IN ({$rolePlace}) AND id <> ?
    ");
    $stmt->execute([...$ids, ...$allowedRoles, $fromUserId]);
    $valid = [];
    foreach ($stmt->fetchAll() ?: [] as $row) {
        $valid[(string) $row['id']] = true;
    }
    if (!$valid) {
        return 0;
    }

    $snippet = mentions_snippet_from_html($html);
    $panelLink = mentions_panel_path_for_user($pdo, array_key_first($valid) ?: $fromUserId);
    $link = $link ?: $panelLink;
    $fromName = trim((string) (($from['name'] ?? '') ?: ($from['username'] ?? 'کاربر')));
    $ins = $pdo->prepare('
      INSERT INTO user_mentions (id, from_user_id, to_user_id, context, context_id, body_snippet, link, is_read)
      VALUES (?,?,?,?,?,?,?,0)
    ');
    $count = 0;
    foreach (array_keys($valid) as $toId) {
        $ins->execute([cuid(), $fromUserId, $toId, $context, $contextId, $snippet, $link]);
        if (function_exists('notify_user')) {
            notify_user(
                $pdo,
                $toId,
                'منشن از ' . $fromName,
                $snippet,
                mentions_panel_path_for_user($pdo, $toId),
                'mention',
                $fromUserId
            );
        }
        $count++;
    }

    return $count;
}

function mentions_panel_path_for_user(PDO $pdo, string $userId): string
{
    $stmt = $pdo->prepare('SELECT role FROM users WHERE id=? LIMIT 1');
    $stmt->execute([$userId]);
    $role = (string) ($stmt->fetchColumn() ?: '');

    return mentions_panel_path($role);
}

function mentions_inbox_for(PDO $pdo, string $userId, int $limit = 50): array
{
    ensure_mentions_schema($pdo);
    $limit = max(1, min(100, $limit));
    $stmt = $pdo->prepare("
      SELECT m.*,
             fu.name AS from_name, fu.username AS from_username, fu.role AS from_role
      FROM user_mentions m
      JOIN users fu ON fu.id = m.from_user_id
      WHERE m.to_user_id = ?
      ORDER BY m.created_at DESC
      LIMIT {$limit}
    ");
    $stmt->execute([$userId]);

    return $stmt->fetchAll() ?: [];
}

function mentions_unread_count(PDO $pdo, string $userId): int
{
    ensure_mentions_schema($pdo);
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM user_mentions WHERE to_user_id=? AND is_read=0');
    $stmt->execute([$userId]);

    return (int) $stmt->fetchColumn();
}

function mentions_mark_read(PDO $pdo, string $userId, ?string $mentionId = null): int
{
    ensure_mentions_schema($pdo);
    if ($mentionId) {
        $stmt = $pdo->prepare('UPDATE user_mentions SET is_read=1 WHERE id=? AND to_user_id=? AND is_read=0');
        $stmt->execute([$mentionId, $userId]);

        return $stmt->rowCount();
    }
    $stmt = $pdo->prepare('UPDATE user_mentions SET is_read=1 WHERE to_user_id=? AND is_read=0');
    $stmt->execute([$userId]);

    return $stmt->rowCount();
}

function mentions_nav_item(?PDO $db = null, ?array $user = null): ?array
{
    $user = $user ?? current_user();
    if (!$user) {
        return null;
    }
    if (!($db instanceof PDO)) {
        global $pdo;
        $db = ($pdo instanceof PDO) ? $pdo : null;
    }
    $badge = 0;
    if ($db instanceof PDO) {
        $badge = mentions_unread_count($db, (string) ($user['id'] ?? ''));
    }

    return [
        'href' => mentions_panel_path((string) ($user['role'] ?? '')),
        'label' => 'منشن‌ها',
        'badge' => $badge,
        'badge_tone' => 'new',
    ];
}
