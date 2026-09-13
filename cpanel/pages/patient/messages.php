<?php
declare(strict_types=1);

$user = require_login(['PATIENT']);
require_once __DIR__ . '/../../includes/patient_panel.php';

$patientId = (string) $user['id'];
ensure_notifications_table($pdo);
$notifications = fetch_notifications($pdo, $patientId, 100);
$unreadCount = count_unread_notifications($pdo, $patientId);

ob_start();
?>
<div class="stack patient-inbox">
  <div class="row-between" style="align-items:flex-start;gap:1rem;flex-wrap:wrap">
    <div>
      <h1>پیام‌ها</h1>
      <p class="muted" style="margin:.35rem 0 0">پیام‌های کلینیک را اینجا می‌خوانید.<?= $unreadCount ? ' · ' . to_fa_digits((string) $unreadCount) . ' خوانده‌نشده' : '' ?></p>
    </div>
    <form method="post" action="<?= e(url('/dashboard/messages/read')) ?>" style="margin:0">
      <?= csrf_field() ?>
      <input type="hidden" name="mark_all" value="1">
      <button type="submit" class="btn btn-outline btn-sm"<?= $unreadCount > 0 ? '' : ' disabled' ?>>خواندن همه</button>
    </form>
  </div>

  <?php if (!$notifications): ?>
    <p class="muted">هنوز پیامی برایتان نرسیده است.</p>
  <?php else: ?>
    <div class="stack patient-inbox-list">
      <?php foreach ($notifications as $n): ?>
        <?php
          $isRead = (int) ($n['is_read'] ?? 0) === 1;
          $scopeLabel = notification_scope_label($n);
          $isBroadcast = $scopeLabel === 'همگانی';
          $link = trim((string) ($n['link'] ?? ''));
        ?>
        <article class="patient-inbox-item<?= $isRead ? '' : ' is-unread' ?>">
          <div class="patient-inbox-item-head">
            <div>
              <strong><?= e((string) $n['title']) ?></strong>
              <span class="badge<?= $isBroadcast ? ' patient-inbox-broadcast' : '' ?>"><?= e($scopeLabel) ?></span>
              <?php if (!$isRead): ?>
                <span class="badge">جدید</span>
              <?php endif; ?>
            </div>
            <span class="muted" style="font-size:.8rem"><?= e(format_fa_datetime((string) $n['created_at'])) ?></span>
          </div>
          <div class="patient-inbox-body"><?= nl2br(e((string) $n['body'])) ?></div>
          <div class="patient-inbox-actions">
            <?php if ($link !== '' && str_starts_with($link, '/')): ?>
              <a class="btn btn-outline btn-sm" href="<?= e(url($link)) ?>">مشاهده</a>
            <?php endif; ?>
            <?php if (!$isRead): ?>
              <form method="post" action="<?= e(url('/dashboard/messages/read')) ?>" style="margin:0">
                <?= csrf_field() ?>
                <input type="hidden" name="notification_id" value="<?= e((string) $n['id']) ?>">
                <button type="submit" class="btn btn-outline btn-sm">علامت خوانده‌شده</button>
              </form>
            <?php endif; ?>
          </div>
        </article>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</div>
<?php
render_patient_page('پیام‌ها', ob_get_clean());
