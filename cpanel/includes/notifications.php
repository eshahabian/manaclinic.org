<?php
declare(strict_types=1);

function ensure_notifications_table(PDO $pdo): void
{
    static $ready = false;
    if ($ready) {
        return;
    }
    $pdo->exec("
      CREATE TABLE IF NOT EXISTS notifications (
        id VARCHAR(32) PRIMARY KEY,
        recipient_user_id VARCHAR(32) NOT NULL,
        title VARCHAR(255) NOT NULL,
        body TEXT NOT NULL,
        link VARCHAR(255) NULL,
        is_read TINYINT(1) NOT NULL DEFAULT 0,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_notif_user_read (recipient_user_id, is_read, created_at)
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    $ready = true;
}

function notify_user(PDO $pdo, string $userId, string $title, string $body, ?string $link = null): void
{
    ensure_notifications_table($pdo);
    $pdo->prepare('INSERT INTO notifications (id, recipient_user_id, title, body, link, is_read) VALUES (?,?,?,?,?,0)')
        ->execute([cuid(), $userId, $title, $body, $link]);
}

/** اطلاع به همه کاربران با نقش مشخص */
function notify_role(PDO $pdo, string $role, string $title, string $body, ?string $link = null): void
{
    ensure_notifications_table($pdo);
    $stmt = $pdo->prepare('SELECT id FROM users WHERE role = ?');
    $stmt->execute([$role]);
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $userId) {
        notify_user($pdo, (string) $userId, $title, $body, $link);
    }
}

/** اطلاع به کاربر درمانگر از روی doctor_profiles.id */
function notify_doctor_profile(PDO $pdo, string $doctorProfileId, string $title, string $body, ?string $link = null): void
{
    $stmt = $pdo->prepare('SELECT user_id FROM doctor_profiles WHERE id = ? LIMIT 1');
    $stmt->execute([$doctorProfileId]);
    $userId = $stmt->fetchColumn();
    if ($userId) {
        notify_user($pdo, (string) $userId, $title, $body, $link);
    }
}

/** اعلان مربوط به گفتگوی دستیار است یا پیام سیستمی دیگر */
function notification_is_assistant(array $n): bool
{
    $link = (string) ($n['link'] ?? '');
    $blob = ((string) ($n['title'] ?? '')) . ' ' . ((string) ($n['body'] ?? '')) . ' ' . $link;
    return (mb_stripos($blob, 'دستیار') !== false)
        || (mb_stripos($blob, 'گفتگو') !== false)
        || (mb_stripos($link, '/doctor/intakes') !== false)
        || (mb_stripos($link, '/secretary/intakes') !== false)
        || (mb_stripos($link, '/assistant') !== false);
}

function fetch_notifications(PDO $pdo, string $userId, int $limit = 20, bool $unreadOnly = false): array
{
    ensure_notifications_table($pdo);
    $sql = 'SELECT * FROM notifications WHERE recipient_user_id = ?';
    if ($unreadOnly) {
        $sql .= ' AND is_read = 0';
    }
    $sql .= ' ORDER BY created_at DESC LIMIT ' . (int) $limit;
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$userId]);
    return $stmt->fetchAll();
}

function count_unread_notifications(PDO $pdo, string $userId): int
{
    ensure_notifications_table($pdo);
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM notifications WHERE recipient_user_id = ? AND is_read = 0');
    $stmt->execute([$userId]);
    return (int) $stmt->fetchColumn();
}

function mark_notifications_read(PDO $pdo, string $userId, ?string $notificationId = null): void
{
    ensure_notifications_table($pdo);
    if ($notificationId) {
        $pdo->prepare('UPDATE notifications SET is_read = 1 WHERE id = ? AND recipient_user_id = ?')
            ->execute([$notificationId, $userId]);
        return;
    }
    $pdo->prepare('UPDATE notifications SET is_read = 1 WHERE recipient_user_id = ? AND is_read = 0')
        ->execute([$userId]);
}

/** رندر بلوک پیام‌ها برای پنل */
function render_notifications_panel(array $items, string $markReadUrl): string
{
    if (!$items) {
        return '';
    }
    ob_start();
    ?>
    <div class="panel stack" style="margin-top:1rem;border-color:var(--primary)">
      <div class="row-between" style="align-items:center;gap:.75rem;flex-wrap:wrap">
        <h2 style="margin:0;font-size:1.1rem">پیام‌ها</h2>
        <form method="post" action="<?= e($markReadUrl) ?>" style="margin:0">
          <input type="hidden" name="mark_all" value="1">
          <button type="submit" class="btn btn-outline btn-sm">خواندن همه</button>
        </form>
      </div>
      <?php foreach ($items as $n): ?>
        <div class="row-between" style="border:1px solid var(--line);border-radius:.75rem;padding:.75rem;background:<?= !(int)$n['is_read'] ? 'var(--bg-soft)' : '#fff' ?>">
          <div style="flex:1;min-width:0">
            <strong><?= e($n['title']) ?></strong>
            <div style="font-size:.9rem;line-height:1.7;margin-top:.25rem"><?= e($n['body']) ?></div>
            <div class="muted" style="font-size:.75rem;margin-top:.35rem"><?= e(format_fa_datetime($n['created_at'])) ?></div>
          </div>
          <div style="display:flex;flex-direction:column;gap:.4rem;align-items:flex-end">
            <?php if (!(int)$n['is_read']): ?>
              <span class="badge">جدید</span>
            <?php endif; ?>
            <?php if (!empty($n['link'])): ?>
              <a class="btn btn-outline btn-sm" href="<?= e(url($n['link'])) ?>">مشاهده</a>
            <?php endif; ?>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
    <?php
    return (string) ob_get_clean();
}
