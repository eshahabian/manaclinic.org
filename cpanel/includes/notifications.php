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
        kind VARCHAR(32) NOT NULL DEFAULT 'other',
        is_read TINYINT(1) NOT NULL DEFAULT 0,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_notif_user_read (recipient_user_id, is_read, created_at)
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    try {
        $hasKind = $pdo->query("SHOW COLUMNS FROM notifications LIKE 'kind'")->fetch();
        if (!$hasKind) {
            $pdo->exec("ALTER TABLE notifications ADD COLUMN kind VARCHAR(32) NOT NULL DEFAULT 'other' AFTER link");
        }
    } catch (Throwable $ignored) {
    }
    $ready = true;
}

function notify_user(PDO $pdo, string $userId, string $title, string $body, ?string $link = null, string $kind = 'other'): void
{
    ensure_notifications_table($pdo);
    $kind = notification_normalize_kind($kind);
    $pdo->prepare('INSERT INTO notifications (id, recipient_user_id, title, body, link, kind, is_read) VALUES (?,?,?,?,?,?,0)')
        ->execute([cuid(), $userId, $title, $body, $link, $kind]);
}

/** اطلاع به همه کاربران با نقش مشخص */
function notify_role(PDO $pdo, string $role, string $title, string $body, ?string $link = null, string $kind = 'other'): void
{
    ensure_notifications_table($pdo);
    $stmt = $pdo->prepare('SELECT id FROM users WHERE role = ?');
    $stmt->execute([$role]);
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $userId) {
        notify_user($pdo, (string) $userId, $title, $body, $link, $kind);
    }
}

/** اطلاع به کاربر درمانگر از روی doctor_profiles.id */
function notify_doctor_profile(PDO $pdo, string $doctorProfileId, string $title, string $body, ?string $link = null, string $kind = 'other'): void
{
    $stmt = $pdo->prepare('SELECT user_id FROM doctor_profiles WHERE id = ? LIMIT 1');
    $stmt->execute([$doctorProfileId]);
    $userId = $stmt->fetchColumn();
    if ($userId) {
        notify_user($pdo, (string) $userId, $title, $body, $link, $kind);
    }
}

function notification_normalize_kind(string $kind): string
{
    $kind = strtolower(trim($kind));
    return in_array($kind, ['appointment', 'workshop', 'assistant', 'article', 'other'], true)
        ? $kind
        : 'other';
}

/** اعلان مربوط به گفتگوی دستیار است یا پیام سیستمی دیگر */
function notification_is_assistant(array $n): bool
{
    return notification_kind($n) === 'assistant';
}

function notification_kind(array $n): string
{
    $stored = notification_normalize_kind((string) ($n['kind'] ?? ''));
    if ($stored !== 'other') {
        return $stored;
    }

    $link = (string) ($n['link'] ?? '');
    $blob = ((string) ($n['title'] ?? '')) . ' ' . ((string) ($n['body'] ?? '')) . ' ' . $link;

    if ((mb_stripos($blob, 'دستیار') !== false)
        || (mb_stripos($link, '/doctor/intakes') !== false)
        || (mb_stripos($link, '/secretary/intakes') !== false)
        || (mb_stripos($link, '/assistant') !== false)
    ) {
        return 'assistant';
    }
    if ((mb_stripos($blob, 'کارگاه') !== false)
        || (mb_stripos($link, '/workshops') !== false)
        || (mb_stripos($link, 'workshop') !== false)
    ) {
        return 'workshop';
    }
    if ((mb_stripos($blob, 'مقاله') !== false)
        || (mb_stripos($link, '/articles') !== false)
    ) {
        return 'article';
    }
    if ((mb_stripos($blob, 'نوبت') !== false)
        || (mb_stripos($blob, 'مراجعه‌کننده') !== false)
        || (mb_stripos($link, '/appointments') !== false)
        || (mb_stripos($link, '/patients') !== false)
    ) {
        return 'appointment';
    }

    return 'other';
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

/** پیام‌های منشی: بدون گفتگوی دستیار، فقط نوبت و کارگاه */
function secretary_split_notifications(array $items): array
{
    $appointment = [];
    $workshop = [];
    foreach ($items as $n) {
        $kind = notification_kind($n);
        if ($kind === 'assistant') {
            continue;
        }
        if ($kind === 'workshop') {
            $workshop[] = $n;
        } else {
            $appointment[] = $n;
        }
    }
    return ['appointment' => $appointment, 'workshop' => $workshop];
}

function secretary_unread_desk_count(array $items): int
{
    $count = 0;
    foreach ($items as $n) {
        if (notification_kind($n) === 'assistant') {
            continue;
        }
        if (!(int) ($n['is_read'] ?? 1)) {
            $count++;
        }
    }
    return $count;
}

function secretary_recent_shared_appointments(PDO $pdo, int $limit = 30): array
{
    $limit = max(1, min(80, $limit));
    $stmt = $pdo->query("
      SELECT a.id, a.starts_at, a.status,
             pu.name AS patient_name,
             du.name AS doctor_name,
             cu.name AS actor_name, cu.username AS actor_username
      FROM appointments a
      JOIN users pu ON pu.id = a.patient_id
      JOIN doctor_profiles dp ON dp.id = a.doctor_id
      JOIN users du ON du.id = dp.user_id
      LEFT JOIN users cu ON cu.id = a.created_by_user_id
      WHERE a.status IN ('PENDING_PAYMENT','CONFIRMED','COMPLETED')
      ORDER BY a.starts_at DESC, a.created_at DESC
      LIMIT {$limit}
    ");
    return $stmt->fetchAll();
}

function secretary_recent_shared_enrollments(PDO $pdo, int $limit = 30): array
{
    ensure_workshop_schema($pdo);
    $limit = max(1, min(80, $limit));
    $stmt = $pdo->query("
      SELECT e.id, e.enrolled_at, e.status,
             w.title AS workshop_title, w.starts_at,
             pu.name AS patient_name,
             du.name AS doctor_name,
             cu.name AS actor_name, cu.username AS actor_username
      FROM workshop_enrollments e
      JOIN workshops w ON w.id = e.workshop_id
      JOIN users pu ON pu.id = e.patient_id
      JOIN doctor_profiles dp ON dp.id = w.doctor_id
      JOIN users du ON du.id = dp.user_id
      LEFT JOIN users cu ON cu.id = e.created_by_user_id
      WHERE e.status IN ('PENDING_PAYMENT','CONFIRMED','COMPLETED')
      ORDER BY e.enrolled_at DESC
      LIMIT {$limit}
    ");
    return $stmt->fetchAll();
}

function render_notification_rows(array $items): string
{
    if (!$items) {
        return '';
    }
    ob_start();
    foreach ($items as $n):
        ?>
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
        <?php
    endforeach;
    return (string) ob_get_clean();
}

/** رندر بلوک پیام‌ها برای پنل */
function render_notifications_panel(array $items, string $markReadUrl): string
{
    return render_secretary_messages_panel($items, $markReadUrl, [], [], 'appointment', '/secretary');
}

function render_secretary_messages_panel(
    array $items,
    string $markReadUrl,
    array $recentAppointments = [],
    array $recentEnrollments = [],
    string $activeTab = 'appointment',
    string $pagePath = '/secretary'
): string {
    $split = secretary_split_notifications($items);
    $appointmentNotifs = $split['appointment'];
    $workshopNotifs = $split['workshop'];
    $activeTab = $activeTab === 'workshop' ? 'workshop' : 'appointment';
    $base = $pagePath === '/secretary/messages' ? '/secretary/messages' : '/secretary';
    $unread = secretary_unread_desk_count($items);

    ob_start();
    ?>
    <div class="panel stack" id="secretary-messages" style="margin-top:1rem;border-color:var(--primary)">
      <div class="panel-subtabs-row">
        <h2 style="margin:0;font-size:1.1rem">پیام‌ها</h2>
        <nav class="panel-subtabs" aria-label="نوع پیام">
          <a class="panel-subtab<?= $activeTab === 'appointment' ? ' is-active' : '' ?>" href="<?= e(url($base . '?msg=appointment')) ?>#secretary-messages">
            نوبت‌ها
            <span class="panel-subtab-count"><?= count($appointmentNotifs) + count($recentAppointments) ?></span>
          </a>
          <a class="panel-subtab<?= $activeTab === 'workshop' ? ' is-active' : '' ?>" href="<?= e(url($base . '?msg=workshop')) ?>#secretary-messages">
            کارگاه‌ها
            <span class="panel-subtab-count"><?= count($workshopNotifs) + count($recentEnrollments) ?></span>
          </a>
        </nav>
        <form method="post" action="<?= e($markReadUrl) ?>" class="panel-subtabs-action" style="margin:0">
          <input type="hidden" name="mark_all" value="1">
          <input type="hidden" name="next" value="<?= e($base . '?msg=' . $activeTab) ?>">
          <button type="submit" class="btn btn-outline btn-sm"<?= $unread > 0 ? '' : ' disabled' ?>>خواندن همه</button>
        </form>
      </div>
      <p class="muted" style="margin:0;font-size:.85rem;line-height:1.7">
        هر نوبت یا ثبت‌نام کارگاه را همه منشی‌ها می‌بینند تا وقت تکراری ثبت نشود.
      </p>

      <?php if ($activeTab === 'appointment'): ?>
        <?php if ($appointmentNotifs): ?>
          <?= render_notification_rows($appointmentNotifs) ?>
        <?php endif; ?>
        <h3 style="margin:.5rem 0 0;font-size:1rem">نوبت‌های ثبت‌شده همکاران</h3>
        <?php if (!$recentAppointments): ?>
          <p class="muted" style="margin:0">نوبت ثبت‌شده‌ای نیست.</p>
        <?php else: ?>
          <?php foreach ($recentAppointments as $row): ?>
            <div class="row-between" style="border:1px solid var(--line);border-radius:.75rem;padding:.75rem">
              <div>
                <strong><?= e((string) $row['patient_name']) ?></strong>
                <div class="muted" style="font-size:.85rem">دکتر: <?= e((string) $row['doctor_name']) ?></div>
                <div style="font-size:.85rem;margin-top:.25rem"><?= e(format_fa_datetime((string) $row['starts_at'])) ?></div>
                <?php if (!empty($row['actor_name']) || !empty($row['actor_username'])): ?>
                  <?= staff_sign_html(['name' => $row['actor_name'] ?? '', 'username' => $row['actor_username'] ?? ''], 'ثبت توسط') ?>
                <?php else: ?>
                  <span class="staff-sign">ثبت آنلاین توسط مراجعه‌کننده</span>
                <?php endif; ?>
              </div>
              <span class="badge"><?= e(appointment_status_label((string) $row['status'])) ?></span>
            </div>
          <?php endforeach; ?>
        <?php endif; ?>
      <?php else: ?>
        <?php if ($workshopNotifs): ?>
          <?= render_notification_rows($workshopNotifs) ?>
        <?php endif; ?>
        <h3 style="margin:.5rem 0 0;font-size:1rem">ثبت‌نام‌های کارگاه</h3>
        <?php if (!$recentEnrollments): ?>
          <p class="muted" style="margin:0">ثبت‌نام کارگاهی نیست.</p>
        <?php else: ?>
          <?php foreach ($recentEnrollments as $row): ?>
            <div class="row-between" style="border:1px solid var(--line);border-radius:.75rem;padding:.75rem">
              <div>
                <strong><?= e((string) $row['patient_name']) ?></strong>
                <div class="muted" style="font-size:.85rem">کارگاه: <?= e((string) $row['workshop_title']) ?></div>
                <div class="muted" style="font-size:.85rem">دکتر: <?= e((string) $row['doctor_name']) ?></div>
                <div style="font-size:.85rem;margin-top:.25rem"><?= e(format_fa_datetime((string) ($row['enrolled_at'] ?: $row['starts_at']))) ?></div>
                <?php if (!empty($row['actor_name']) || !empty($row['actor_username'])): ?>
                  <?= staff_sign_html(['name' => $row['actor_name'] ?? '', 'username' => $row['actor_username'] ?? ''], 'ثبت توسط') ?>
                <?php else: ?>
                  <span class="staff-sign">ثبت‌نام آنلاین توسط مراجعه‌کننده</span>
                <?php endif; ?>
              </div>
              <span class="badge"><?= e(function_exists('enrollment_status_label') ? enrollment_status_label((string) $row['status']) : $row['status']) ?></span>
            </div>
          <?php endforeach; ?>
        <?php endif; ?>
      <?php endif; ?>
    </div>
    <?php
    return (string) ob_get_clean();
}
