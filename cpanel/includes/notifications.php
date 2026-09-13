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
        sender_user_id VARCHAR(32) NULL,
        title VARCHAR(255) NOT NULL,
        body TEXT NOT NULL,
        link VARCHAR(255) NULL,
        kind VARCHAR(32) NOT NULL DEFAULT 'other',
        scope VARCHAR(16) NOT NULL DEFAULT 'personal',
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
    $addColumn = static function (PDO $pdo, string $column, string $ddl): void {
        try {
            $has = $pdo->query('SHOW COLUMNS FROM notifications LIKE ' . $pdo->quote($column))->fetch();
            if (!$has) {
                $pdo->exec("ALTER TABLE notifications ADD COLUMN {$ddl}");
            }
        } catch (Throwable $ignored) {
        }
    };
    $addColumn($pdo, 'sender_user_id', 'sender_user_id VARCHAR(32) NULL AFTER recipient_user_id');
    $addColumn($pdo, 'scope', "scope VARCHAR(16) NOT NULL DEFAULT 'personal' AFTER kind");
    $ready = true;
}

function notify_user(
    PDO $pdo,
    string $userId,
    string $title,
    string $body,
    ?string $link = null,
    string $kind = 'other',
    ?string $senderUserId = null,
    string $scope = 'personal'
): void {
    ensure_notifications_table($pdo);
    $kind = notification_normalize_kind($kind);
    $scope = notification_normalize_scope($scope);
    $pdo->prepare('INSERT INTO notifications (id, recipient_user_id, sender_user_id, title, body, link, kind, scope, is_read) VALUES (?,?,?,?,?,?,?,?,0)')
        ->execute([cuid(), $userId, $senderUserId, $title, $body, $link, $kind, $scope]);
}

/** اطلاع به همه کاربران با نقش مشخص */
function notify_role(
    PDO $pdo,
    string $role,
    string $title,
    string $body,
    ?string $link = null,
    string $kind = 'other',
    ?string $senderUserId = null,
    string $scope = 'personal'
): void {
    ensure_notifications_table($pdo);
    $stmt = $pdo->prepare('SELECT id FROM users WHERE role = ?');
    $stmt->execute([$role]);
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $userId) {
        notify_user($pdo, (string) $userId, $title, $body, $link, $kind, $senderUserId, $scope);
    }
}

/** پیام همگانی یا نقش‌محور به همه مراجعه‌کنندگان */
function notify_patients_all(
    PDO $pdo,
    string $title,
    string $body,
    ?string $link = null,
    string $kind = 'broadcast',
    ?string $senderUserId = null
): int {
    ensure_notifications_table($pdo);
    $kind = notification_normalize_kind($kind === 'clinic' ? 'clinic' : 'broadcast');
    $stmt = $pdo->prepare("SELECT id FROM users WHERE role = 'PATIENT'");
    $stmt->execute();
    $ids = $stmt->fetchAll(PDO::FETCH_COLUMN);
    foreach ($ids as $userId) {
        notify_user($pdo, (string) $userId, $title, $body, $link, $kind, $senderUserId, 'broadcast');
    }
    return count($ids);
}

function notification_normalize_scope(string $scope): string
{
    $scope = strtolower(trim($scope));
    return $scope === 'broadcast' ? 'broadcast' : 'personal';
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

function notify_patient_personal(
    PDO $pdo,
    string $patientId,
    string $title,
    string $body,
    ?string $senderUserId = null,
    ?string $link = '/dashboard/messages'
): void {
    notify_user($pdo, $patientId, $title, $body, $link, 'clinic', $senderUserId, 'personal');
}

function notification_normalize_kind(string $kind): string
{
    $kind = strtolower(trim($kind));
    return in_array($kind, ['appointment', 'workshop', 'assistant', 'article', 'handover', 'handover_copy', 'clinic', 'broadcast', 'other'], true)
        ? $kind
        : 'other';
}

function notification_is_patient_inbox(array $n): bool
{
    $kind = notification_kind($n);
    if (in_array($kind, ['clinic', 'broadcast'], true)) {
        return true;
    }
    $scope = notification_normalize_scope((string) ($n['scope'] ?? 'personal'));
    return $scope === 'broadcast' || $kind === 'other';
}

function notification_scope_label(array $n): string
{
    $kind = notification_normalize_kind((string) ($n['kind'] ?? ''));
    if ($kind === 'broadcast'
        || notification_normalize_scope((string) ($n['scope'] ?? '')) === 'broadcast') {
        return 'همگانی';
    }
    if ($kind === 'workshop') {
        return 'کارگاه';
    }
    if ($kind === 'appointment') {
        return 'نوبت';
    }
    return 'شخصی';
}

/** کپی پیام منشی‌ها برای درمانگرها / ادمین */
function notification_is_staff_copy(array $n): bool
{
    $kind = notification_kind($n);
    if ($kind === 'handover_copy') {
        return true;
    }
    $link = (string) ($n['link'] ?? '');
    return $kind === 'handover' && (
        str_contains($link, '/staff-messages')
        || str_contains($link, '/admin/messages')
        || str_contains($link, '/doctor/notifications')
    );
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
    if ((mb_stripos($blob, 'کپی پیام منشی') !== false)
        || (mb_stripos($link, '/staff-messages') !== false)
    ) {
        return 'handover_copy';
    }
    if ((mb_stripos($blob, 'همکار') !== false)
        || (mb_stripos($blob, 'تحویل شیفت') !== false)
        || (mb_stripos($link, 'colleague') !== false)
        || (mb_stripos($link, 'handover') !== false)
    ) {
        return 'handover';
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

function delete_notification(PDO $pdo, string $notificationId, ?string $userId = null): void
{
    ensure_notifications_table($pdo);
    if ($userId) {
        $pdo->prepare('DELETE FROM notifications WHERE id=? AND recipient_user_id=?')
            ->execute([$notificationId, $userId]);
        return;
    }
    $pdo->prepare('DELETE FROM notifications WHERE id=?')->execute([$notificationId]);
}

function delete_all_notifications(PDO $pdo, ?string $userId = null): int
{
    ensure_notifications_table($pdo);
    if ($userId) {
        $stmt = $pdo->prepare('DELETE FROM notifications WHERE recipient_user_id=?');
        $stmt->execute([$userId]);
        return $stmt->rowCount();
    }
    return (int) $pdo->exec('DELETE FROM notifications');
}

function fetch_all_notifications(PDO $pdo, int $limit = 80): array
{
    ensure_notifications_table($pdo);
    $limit = max(1, min(200, $limit));
    return $pdo->query("
      SELECT n.*, u.name AS recipient_name, u.role AS recipient_role, u.username AS recipient_username
      FROM notifications n
      JOIN users u ON u.id = n.recipient_user_id
      ORDER BY n.created_at DESC
      LIMIT {$limit}
    ")->fetchAll();
}

/** کپی پیام منشی‌ها برای درمانگرهای تأییدشده و ادمین */
function fetch_staff_message_copies(PDO $pdo, ?string $userId = null, int $limit = 80): array
{
    ensure_notifications_table($pdo);
    $limit = max(1, min(200, $limit));
    $sql = "
      SELECT n.*, u.name AS recipient_name, u.role AS recipient_role, u.username AS recipient_username
      FROM notifications n
      JOIN users u ON u.id = n.recipient_user_id
      WHERE u.role IN ('DOCTOR', 'ADMIN')
        AND (
          n.kind IN ('handover_copy', 'handover')
          OR n.link LIKE '%/staff-messages%'
          OR n.title LIKE '%کپی پیام منشی%'
        )
    ";
    $params = [];
    if ($userId) {
        $sql .= ' AND n.recipient_user_id = ?';
        $params[] = $userId;
    }
    $sql .= " ORDER BY n.created_at DESC LIMIT {$limit}";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = [];
    foreach ($stmt->fetchAll() as $row) {
        if (notification_is_staff_copy($row) || notification_kind($row) === 'handover') {
            $rows[] = $row;
        }
    }
    return $rows;
}

function mark_notifications_read_kinds(PDO $pdo, string $userId, array $kinds): void
{
    ensure_notifications_table($pdo);
    $kinds = array_values(array_filter($kinds, static fn($k) => is_string($k) && $k !== ''));
    if (!$kinds) {
        return;
    }
    $place = implode(',', array_fill(0, count($kinds), '?'));
    $stmt = $pdo->prepare("
      UPDATE notifications
      SET is_read = 1
      WHERE recipient_user_id = ? AND is_read = 0 AND kind IN ({$place})
    ");
    $stmt->execute(array_merge([$userId], $kinds));
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
        if ($kind === 'assistant' || $kind === 'handover' || $kind === 'handover_copy') {
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
             pu.name AS patient_name, pu.phone AS patient_phone,
             du.name AS doctor_name,
             cu.name AS actor_name, cu.username AS actor_username,
             wp.status AS pay_status, wp.receipt_path
      FROM workshop_enrollments e
      JOIN workshops w ON w.id = e.workshop_id
      JOIN users pu ON pu.id = e.patient_id
      JOIN doctor_profiles dp ON dp.id = w.doctor_id
      JOIN users du ON du.id = dp.user_id
      LEFT JOIN users cu ON cu.id = e.created_by_user_id
      LEFT JOIN workshop_payments wp ON wp.enrollment_id = e.id
      WHERE e.status IN ('PENDING_PAYMENT','CONFIRMED','COMPLETED')
      ORDER BY e.enrolled_at DESC
      LIMIT {$limit}
    ");
    return $stmt->fetchAll();
}

function render_delivery_ticks(bool $delivered, bool $read, ?string $deliveredLabel = null, ?string $readLabel = null): string
{
    if (!$delivered) {
        return '';
    }
    $title = $read
        ? ($readLabel ?: 'خوانده شد')
        : ($deliveredLabel ?: 'رسید');
    $cls = $read ? 'is-read' : 'is-delivered';
    $marks = $read ? '✓✓' : '✓';
    return '<span class="msg-ticks ' . $cls . '" title="' . e($title) . '" aria-label="' . e($title) . '">'
        . $marks
        . '</span>';
}

function render_notification_rows(array $items): string
{
    if (!$items) {
        return '';
    }
    ob_start();
    foreach ($items as $n):
        $isRead = (int) ($n['is_read'] ?? 0) === 1;
        ?>
        <div class="row-between" style="border:1px solid var(--line);border-radius:.75rem;padding:.75rem;background:<?= $isRead ? '#fff' : 'var(--bg-soft)' ?>">
          <div style="flex:1;min-width:0">
            <strong><?= e($n['title']) ?></strong>
            <div style="font-size:.9rem;line-height:1.7;margin-top:.25rem;white-space:pre-wrap"><?= e($n['body']) ?></div>
            <div class="muted" style="font-size:.75rem;margin-top:.35rem;display:flex;flex-wrap:wrap;gap:.45rem;align-items:center">
              <span><?= e(format_fa_datetime($n['created_at'])) ?></span>
              <?= render_delivery_ticks(true, $isRead) ?>
              <span><?= $isRead ? 'خوانده شد' : 'رسید' ?></span>
            </div>
          </div>
          <div style="display:flex;flex-direction:column;gap:.4rem;align-items:flex-end">
            <?php if (!$isRead): ?>
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
    return render_secretary_messages_panel($items, $markReadUrl, [], [], 'appointment', '/secretary/messages');
}

function render_secretary_messages_panel(
    array $items,
    string $markReadUrl,
    array $recentAppointments = [],
    array $recentEnrollments = [],
    string $activeTab = 'appointment',
    string $pagePath = '/secretary/messages',
    array $colleague = [],
    array $patients = []
): string {
    $split = secretary_split_notifications($items);
    $appointmentNotifs = $split['appointment'];
    $workshopNotifs = $split['workshop'];
    $activeTab = in_array($activeTab, ['workshop', 'colleague', 'patients'], true) ? $activeTab : 'appointment';
    $base = str_starts_with($pagePath, '/secretary') ? $pagePath : '/secretary/messages';
    $unread = secretary_unread_desk_count($items);
    $peers = $colleague['peers'] ?? [];
    $inbox = $colleague['inbox'] ?? [];
    $sent = $colleague['sent'] ?? [];
    $colleagueUnread = 0;
    foreach ($inbox as $note) {
        if (empty($note['read_at'])) {
            $colleagueUnread++;
        }
    }

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
          <a class="panel-subtab<?= $activeTab === 'colleague' ? ' is-active' : '' ?>" href="<?= e(url($base . '?msg=colleague')) ?>#secretary-messages">
            پیام همکار
            <span class="panel-subtab-count"><?= count($inbox) + count($sent) ?></span>
          </a>
          <a class="panel-subtab<?= $activeTab === 'patients' ? ' is-active' : '' ?>" href="<?= e(url($base . '?msg=patients')) ?>#secretary-messages">
            ارسال به مراجع
            <span class="panel-subtab-count"><?= count($patients) ?></span>
          </a>
        </nav>
        <?php if ($activeTab !== 'colleague' && $activeTab !== 'patients'): ?>
        <form method="post" action="<?= e($markReadUrl) ?>" class="panel-subtabs-action" style="margin:0">
          <?= csrf_field() ?>
          <input type="hidden" name="mark_all" value="1">
          <input type="hidden" name="next" value="<?= e($base . '?msg=' . $activeTab) ?>">
          <button type="submit" class="btn btn-outline btn-sm"<?= $unread > 0 ? '' : ' disabled' ?>>خواندن همه</button>
        </form>
        <?php endif; ?>
      </div>
      <?php if ($activeTab !== 'colleague' && $activeTab !== 'patients'): ?>
      <p class="muted" style="margin:0;font-size:.85rem;line-height:1.7">
        هر نوبت یا ثبت‌نام کارگاه را همه منشی‌ها می‌بینند تا وقت تکراری ثبت نشود.
        ✓ رسید · ✓✓ خوانده شد
      </p>
      <?php endif; ?>

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
      <?php elseif ($activeTab === 'workshop'): ?>
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
      <?php elseif ($activeTab === 'patients'): ?>
        <p class="muted" style="margin:0;font-size:.85rem;line-height:1.8">
          پیام شخصی برای یک مراجع، یا همگانی برای همه. مثلاً تبریک، کنسلی جلسه، یا تخفیف.
          مراجع فقط می‌خواند و پاسخ نمی‌دهد.
        </p>
        <form method="post" action="<?= e(url('/secretary/patient-message')) ?>" class="form-stack" id="secretary-patient-message-form">
          <?= csrf_field() ?>
          <input type="hidden" name="next" value="<?= e($base . '?msg=patients') ?>">
          <div>
            <label class="label">نوع ارسال</label>
            <div style="display:flex;gap:.75rem;flex-wrap:wrap;margin-top:.35rem">
              <label style="display:inline-flex;align-items:center;gap:.35rem;font-size:.9rem">
                <input type="radio" name="mode" value="personal" checked data-patient-mode>
                شخصی
              </label>
              <label style="display:inline-flex;align-items:center;gap:.35rem;font-size:.9rem">
                <input type="radio" name="mode" value="broadcast" data-patient-mode>
                همگانی (همه مراجعه‌کنندگان)
              </label>
            </div>
          </div>
          <div id="secretary-patient-pick">
            <label class="label" for="patient_id">مراجعه‌کننده</label>
            <select class="input" name="patient_id" id="patient_id">
              <option value="">انتخاب کنید…</option>
              <?php foreach ($patients as $p): ?>
                <option value="<?= e((string) $p['id']) ?>">
                  <?= e((string) $p['name']) ?>
                  <?php if (!empty($p['phone'])): ?> · <?= e((string) $p['phone']) ?><?php endif; ?>
                </option>
              <?php endforeach; ?>
            </select>
            <?php if (!$patients): ?>
              <p class="muted" style="margin:.35rem 0 0;font-size:.85rem">هنوز مراجعه‌کننده‌ای ثبت نشده.</p>
            <?php endif; ?>
          </div>
          <div>
            <label class="label" for="patient_msg_title">عنوان</label>
            <input class="input" type="text" name="title" id="patient_msg_title" required maxlength="255" placeholder="مثلاً کنسلی جلسه فردا">
          </div>
          <div>
            <label class="label" for="patient_msg_body">متن پیام</label>
            <textarea class="input" name="body" id="patient_msg_body" rows="5" required placeholder="متن پیام برای مراجع…"></textarea>
          </div>
          <button type="submit" class="btn btn-primary">ارسال پیام</button>
        </form>
        <script>
        (function(){
          var form = document.getElementById('secretary-patient-message-form');
          if (!form) return;
          var pick = document.getElementById('secretary-patient-pick');
          var select = document.getElementById('patient_id');
          function sync(){
            var mode = (form.querySelector('input[name="mode"]:checked') || {}).value || 'personal';
            var personal = mode === 'personal';
            if (pick) pick.style.display = personal ? '' : 'none';
            if (select) select.required = personal;
          }
          form.querySelectorAll('[data-patient-mode]').forEach(function(el){
            el.addEventListener('change', sync);
          });
          sync();
        })();
        </script>
      <?php else: ?>
        <p class="muted" style="margin:0;font-size:.85rem;line-height:1.8">
          متن برای همه منشی‌های دیگر می‌رود. با ورود بعدی، کل صفحه را می‌بینند و تا «خواندم» نزنند وارد پورتال نمی‌شوند.
          <?= $colleagueUnread ? ' · ' . $colleagueUnread . ' پیام خوانده‌نشده' : '' ?>
        </p>
        <?php if (!$peers): ?>
          <p class="muted" style="margin:0">منشی دیگری برای ارسال نیست.</p>
        <?php else: ?>
          <form method="post" action="<?= e(url('/secretary/handover')) ?>" class="form-stack">
            <?= csrf_field() ?>
            <div>
              <label class="label">متن پیام</label>
              <textarea class="input" name="body" rows="5" required placeholder="مثلاً وضعیت نوبت‌ها، کار باقی‌مانده، یا نکته شیفت…"></textarea>
            </div>
            <button type="submit" class="btn btn-primary">ارسال پیام به همکاران</button>
          </form>
        <?php endif; ?>

        <h3 style="margin:.5rem 0 0;font-size:1rem">تاریخچه دریافت‌شده</h3>
        <?php if (!$inbox): ?>
          <p class="muted" style="margin:0">پیام همکاری دریافت نشده است.</p>
        <?php else: ?>
          <?php foreach ($inbox as $note): ?>
            <?php $noteRead = !empty($note['read_at']); ?>
            <div class="row-between" style="border:1px solid var(--line);border-radius:.75rem;padding:.75rem;background:<?= $noteRead ? '#fff' : 'var(--bg-soft)' ?>">
              <div style="flex:1;min-width:0">
                <strong>از <?= e((string) ($note['from_name'] ?? 'منشی')) ?></strong>
                <div style="font-size:.95rem;line-height:1.8;margin-top:.4rem;white-space:pre-wrap"><?= e((string) $note['body']) ?></div>
                <div class="muted" style="font-size:.75rem;margin-top:.4rem;display:flex;flex-wrap:wrap;gap:.45rem;align-items:center">
                  <span><?= e(format_fa_datetime((string) $note['created_at'])) ?></span>
                  <?= render_delivery_ticks(true, $noteRead) ?>
                  <span><?= $noteRead ? 'خوانده شد' : 'رسید' ?></span>
                </div>
              </div>
              <?php if (!$noteRead): ?>
                <form method="post" action="<?= e(url('/secretary/handover/ack')) ?>" style="margin:0">
                  <?= csrf_field() ?>
                  <input type="hidden" name="note_id" value="<?= e((string) $note['id']) ?>">
                  <button type="submit" class="btn btn-primary btn-sm">خواندم</button>
                </form>
              <?php endif; ?>
            </div>
          <?php endforeach; ?>
        <?php endif; ?>

        <h3 style="margin:.5rem 0 0;font-size:1rem">تاریخچه ارسال‌های شما</h3>
        <?php if (!$sent): ?>
          <p class="muted" style="margin:0">هنوز پیامی نفرستاده‌اید.</p>
        <?php else: ?>
          <?php foreach ($sent as $note): ?>
            <?php
              $recips = $note['recipients'] ?? [];
              $allRead = $recips && (int) ($note['unread_count'] ?? 0) === 0;
            ?>
            <div style="border:1px solid var(--line);border-radius:.75rem;padding:.75rem .85rem">
              <div style="font-size:.95rem;line-height:1.8;white-space:pre-wrap"><?= e((string) $note['body']) ?></div>
              <div class="muted" style="font-size:.75rem;margin-top:.45rem;display:flex;flex-wrap:wrap;gap:.45rem;align-items:center">
                <span><?= e(format_fa_datetime((string) $note['created_at'])) ?></span>
                <?= render_delivery_ticks(true, $allRead) ?>
                <span><?= $allRead ? 'همه خواندند' : ((int) $note['recipient_count'] . ' رسید') ?></span>
              </div>
              <?php if ($recips): ?>
                <ul class="msg-tick-list">
                  <?php foreach ($recips as $r): ?>
                    <?php $rRead = !empty($r['read_at']); ?>
                    <li>
                      <?= e((string) ($r['to_name'] ?? 'منشی')) ?>
                      <?= render_delivery_ticks(true, $rRead) ?>
                      <span class="muted"><?= $rRead ? 'خوانده شد' : 'رسید' ?></span>
                    </li>
                  <?php endforeach; ?>
                </ul>
              <?php endif; ?>
            </div>
          <?php endforeach; ?>
        <?php endif; ?>
      <?php endif; ?>
    </div>
    <?php
    return (string) ob_get_clean();
}
