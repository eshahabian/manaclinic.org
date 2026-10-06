<?php
declare(strict_types=1);

const MENTION_TEXT_COLOR = '#0d7a6a';

function ensure_mentions_schema(PDO $pdo): void
{
    static $ready = false;
    if ($ready) {
        return;
    }
    if ($pdo->inTransaction()) {
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

function mentions_post_path(?string $role = null): string
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

function mentions_panel_path(?string $role = null): string
{
    $role = strtoupper((string) ($role ?? (current_user()['role'] ?? '')));
    return match ($role) {
        'ADMIN' => '/admin/staff-messages?tab=mentions',
        'DOCTOR' => '/doctor/staff-messages?tab=mentions',
        'SECRETARY' => '/secretary/messages?msg=mentions',
        'PATIENT' => '/dashboard/messages?tab=mentions',
        default => '/dashboard/messages?tab=mentions',
    };
}

function mentions_inbox_html(PDO $pdo, string $userId, string $role): string
{
    $inbox = mentions_inbox_for($pdo, $userId, 80);
    $unread = mentions_unread_count($pdo, $userId);
    $postPath = mentions_post_path($role);
    ob_start();
    ?>
    <div class="stack" id="mentions-tab">
      <p class="muted" style="margin:0;line-height:1.8">
        وقتی کسی در پیام یا یادداشت با <strong style="color:<?= e(MENTION_TEXT_COLOR) ?>">@نام شما</strong> اشاره‌تان کند، اینجا می‌بینید.
      </p>
      <?php if ($unread > 0): ?>
        <form method="post" action="<?= e(url($postPath)) ?>" style="margin:0">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="ack_all">
          <button type="submit" class="btn btn-outline btn-sm">خواندن همه (<?= e(to_fa_digits((string) $unread)) ?>)</button>
        </form>
      <?php endif; ?>
      <?php if (!$inbox): ?>
        <p class="muted">هنوز منشنی ندارید.</p>
      <?php else: ?>
        <div class="stack">
          <?php foreach ($inbox as $row): ?>
            <?php
              $read = !empty($row['is_read']);
              $fromLabel = function_exists('staff_actor_label')
                ? staff_actor_label(['name' => $row['from_name'] ?? '', 'username' => $row['from_username'] ?? ''])
                : (string) ($row['from_name'] ?? 'کاربر');
              $roleLabel = mentions_role_label((string) ($row['from_role'] ?? ''));
              $link = trim((string) ($row['link'] ?? ''));
            ?>
            <article class="admin-site-msg-card<?= $read ? '' : ' is-unread' ?>">
              <header class="admin-site-msg-head">
                <strong><?= e($fromLabel) ?> <span class="muted" style="font-weight:500">(<?= e($roleLabel) ?>)</span></strong>
                <span class="muted" style="font-size:.8rem"><?= e(format_fa_datetime((string) ($row['created_at'] ?? ''))) ?></span>
              </header>
              <div class="admin-site-msg-body" style="line-height:1.7"><?= e((string) ($row['body_snippet'] ?? '')) ?></div>
              <footer class="muted" style="font-size:.8rem;margin-top:.45rem;display:flex;flex-wrap:wrap;gap:.45rem;align-items:center">
                <span><?= $read ? 'خوانده شد' : 'جدید' ?></span>
                <?php if ($link !== ''): ?>
                  <a class="btn btn-outline btn-sm" href="<?= e(url($link)) ?>">مشاهده منبع</a>
                <?php endif; ?>
                <?php if (!$read): ?>
                  <form method="post" action="<?= e(url($postPath)) ?>" style="margin:0">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="ack">
                    <input type="hidden" name="mention_id" value="<?= e((string) ($row['id'] ?? '')) ?>">
                    <button type="submit" class="btn btn-primary btn-sm">خواندم</button>
                  </form>
                <?php endif; ?>
              </footer>
            </article>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>
    <?php
    return (string) ob_get_clean();
}

function messages_mentions_tabs_html(string $messagesHref, string $mentionsHref, bool $mentionsActive, int $mentionCount = 0): string
{
    ob_start();
    ?>
    <nav class="panel-subtabs" aria-label="بخش پیام‌ها" style="margin:.85rem 0 1rem">
      <a class="panel-subtab<?= $mentionsActive ? '' : ' is-active' ?>" href="<?= e(url($messagesHref)) ?>">پیام‌ها</a>
      <a class="panel-subtab<?= $mentionsActive ? ' is-active' : '' ?>" href="<?= e(url($mentionsHref)) ?>">
        منشن‌ها
        <?php if ($mentionCount > 0): ?>
          <span class="panel-subtab-count"><?= e(to_fa_digits((string) $mentionCount)) ?></span>
        <?php endif; ?>
      </a>
    </nav>
    <?php
    return (string) ob_get_clean();
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
 * scope=workshop + scopeId → فقط اعضای همان کارگاه (ثبت‌نام‌شده‌ها + درمانگر).
 *
 * @return list<array{id:string,name:string,username:string,role:string,label:string}>
 */
function mentions_suggest(
    PDO $pdo,
    array $user,
    string $query,
    int $limit = 12,
    string $scope = '',
    string $scopeId = ''
): array {
    $scope = strtolower(trim($scope));
    $scopeId = trim($scopeId);
    if ($scope === 'workshop' && $scopeId !== '') {
        return mentions_suggest_workshop($pdo, $user, $scopeId, $query, $limit);
    }

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

    return mentions_map_suggest_rows($stmt->fetchAll() ?: []);
}

/** آیا کاربر به این کارگاه دسترسی منشن دارد؟ */
function mentions_can_access_workshop(PDO $pdo, array $user, string $workshopId): bool
{
    $workshopId = trim($workshopId);
    $userId = (string) ($user['id'] ?? '');
    $role = strtoupper((string) ($user['role'] ?? ''));
    if ($workshopId === '' || $userId === '') {
        return false;
    }
    if (in_array($role, ['ADMIN', 'SECRETARY'], true)) {
        $stmt = $pdo->prepare('SELECT 1 FROM workshops WHERE id=? LIMIT 1');
        $stmt->execute([$workshopId]);

        return (bool) $stmt->fetchColumn();
    }
    if ($role === 'DOCTOR') {
        $stmt = $pdo->prepare('
          SELECT 1
          FROM workshops w
          JOIN doctor_profiles dp ON dp.id = w.doctor_id
          WHERE w.id=? AND dp.user_id=?
          LIMIT 1
        ');
        $stmt->execute([$workshopId, $userId]);

        return (bool) $stmt->fetchColumn();
    }
    if ($role === 'PATIENT') {
        $statuses = function_exists('workshop_path_member_statuses')
            ? workshop_path_member_statuses()
            : ['CONFIRMED', 'COMPLETED'];
        $place = implode(',', array_fill(0, count($statuses), '?'));
        $stmt = $pdo->prepare("
          SELECT 1 FROM workshop_enrollments
          WHERE workshop_id=? AND patient_id=? AND status IN ({$place})
          LIMIT 1
        ");
        $stmt->execute([$workshopId, $userId, ...$statuses]);

        return (bool) $stmt->fetchColumn();
    }

    return false;
}

/**
 * اعضای قابل‌منشن کارگاه: شرکت‌کننده‌های ثبت‌نام‌شده + درمانگر کارگاه.
 * لیست زنده است؛ عضو جدید بعد از تأیید خودکار ظاهر می‌شود.
 *
 * @return list<array{id:string,name:string,username:string,role:string,label:string}>
 */
function mentions_suggest_workshop(
    PDO $pdo,
    array $user,
    string $workshopId,
    string $query,
    int $limit = 12
): array {
    if (!mentions_can_access_workshop($pdo, $user, $workshopId)) {
        return [];
    }
    $limit = max(1, min(30, $limit));
    $selfId = (string) ($user['id'] ?? '');
    $q = trim($query);
    $statuses = function_exists('workshop_path_member_statuses')
        ? workshop_path_member_statuses()
        : ['CONFIRMED', 'COMPLETED'];
    $statusPlace = implode(',', array_fill(0, count($statuses), '?'));

    $params = [$workshopId, ...$statuses, $workshopId];
    $sql = "
      SELECT DISTINCT u.id, u.name, u.username, u.role
      FROM users u
      WHERE u.id IN (
        SELECT e.patient_id
        FROM workshop_enrollments e
        WHERE e.workshop_id = ? AND e.status IN ({$statusPlace})
        UNION
        SELECT dp.user_id
        FROM workshops w
        JOIN doctor_profiles dp ON dp.id = w.doctor_id
        WHERE w.id = ?
      )
    ";
    if ($selfId !== '') {
        $sql .= ' AND u.id <> ?';
        $params[] = $selfId;
    }
    if ($q !== '') {
        $sql .= ' AND (u.name LIKE ? OR u.username LIKE ? OR u.email LIKE ?)';
        $like = '%' . $q . '%';
        $params[] = $like;
        $params[] = $like;
        $params[] = $like;
    }
    $sql .= ' ORDER BY u.role ASC, u.name ASC, u.username ASC LIMIT ' . $limit;
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    return mentions_map_suggest_rows($stmt->fetchAll() ?: []);
}

/**
 * @param list<array> $rows
 * @return list<array{id:string,name:string,username:string,role:string,label:string}>
 */
function mentions_map_suggest_rows(array $rows): array
{
    $out = [];
    foreach ($rows as $row) {
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
    ?string $link = null,
    ?string $workshopId = null
): int {
    $ids = mentions_extract_ids_from_html($html);
    $snippet = mentions_snippet_from_html($html);

    return mentions_capture_ids($pdo, $fromUserId, $ids, $snippet, $context, $contextId, $link, $workshopId);
}

/**
 * @param list<string> $ids
 */
function mentions_capture_ids(
    PDO $pdo,
    string $fromUserId,
    array $ids,
    string $snippet,
    string $context = 'general',
    ?string $contextId = null,
    ?string $link = null,
    ?string $workshopId = null
): int {
    ensure_mentions_schema($pdo);
    $clean = [];
    foreach ($ids as $id) {
        $id = trim((string) $id);
        if ($id !== '' && $id !== $fromUserId) {
            $clean[$id] = true;
        }
    }
    $ids = array_keys($clean);
    if (!$ids || $fromUserId === '') {
        return 0;
    }

    if ($workshopId) {
        $ids = mentions_filter_ids_for_workshop($pdo, $workshopId, $ids);
        if (!$ids) {
            return 0;
        }
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

    $snippet = trim($snippet) !== '' ? $snippet : 'شما را منشن کرد';
    $link = $link ?: mentions_panel_path_for_user($pdo, array_key_first($valid) ?: $fromUserId);
    $fromName = trim((string) (($from['name'] ?? '') ?: ($from['username'] ?? 'کاربر')));
    $ins = $pdo->prepare('
      INSERT INTO user_mentions (id, from_user_id, to_user_id, context, context_id, body_snippet, link, is_read)
      VALUES (?,?,?,?,?,?,?,0)
    ');
    $count = 0;
    foreach (array_keys($valid) as $toId) {
        $ins->execute([cuid(), $fromUserId, $toId, $context, $contextId, mb_substr($snippet, 0, 255), $link]);
        if (function_exists('notify_user')) {
            notify_user(
                $pdo,
                $toId,
                'منشن از ' . $fromName,
                mb_substr($snippet, 0, 180),
                mentions_panel_path_for_user($pdo, $toId),
                'mention',
                $fromUserId
            );
        }
        $count++;
    }

    return $count;
}

/** @param list<string> $ids @return list<string> */
function mentions_filter_ids_for_workshop(PDO $pdo, string $workshopId, array $ids): array
{
    if ($workshopId === '' || !$ids) {
        return [];
    }
    $statuses = function_exists('workshop_path_member_statuses')
        ? workshop_path_member_statuses()
        : ['CONFIRMED', 'COMPLETED'];
    $idPlace = implode(',', array_fill(0, count($ids), '?'));
    $statusPlace = implode(',', array_fill(0, count($statuses), '?'));
    $stmt = $pdo->prepare("
      SELECT u.id
      FROM users u
      WHERE u.id IN ({$idPlace})
        AND u.id IN (
          SELECT e.patient_id FROM workshop_enrollments e
          WHERE e.workshop_id = ? AND e.status IN ({$statusPlace})
          UNION
          SELECT dp.user_id FROM workshops w
          JOIN doctor_profiles dp ON dp.id = w.doctor_id
          WHERE w.id = ?
        )
    ");
    $stmt->execute([...$ids, $workshopId, ...$statuses, $workshopId]);
    $out = [];
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) ?: [] as $id) {
        $out[] = (string) $id;
    }

    return $out;
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
