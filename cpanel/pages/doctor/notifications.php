<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/doctor_panel.php';

$ctx = require_doctor_profile($pdo);
$userId = (string) $ctx['user']['id'];

$kind = trim((string) ($_GET['kind'] ?? 'assistant'));
if (!in_array($kind, ['assistant', 'other'], true)) {
    $kind = 'assistant';
}

$allNotifs = fetch_notifications($pdo, $userId, 80);
$aiNotifs = [];
$otherNotifs = [];
foreach ($allNotifs as $n) {
    if (notification_is_assistant($n)) {
        $aiNotifs[] = $n;
    } else {
        $otherNotifs[] = $n;
    }
}
$rows = $kind === 'other' ? $otherNotifs : $aiNotifs;
$unreadCount = count_unread_notifications($pdo, $userId);

ob_start();
?>
<div class="panel">
  <p class="panel-back">
    <a class="btn btn-outline btn-sm" href="<?= e(url('/doctor')) ?>">بازگشت به پنل</a>
  </p>
  <h1>اعلان‌ها</h1>
  <p class="muted">اعلان دستیار از گفتگوهای ارسال‌شده جداست؛ از تب بالا نوع پیام را عوض کنید.</p>

  <div class="panel-subtabs-row">
    <nav class="panel-subtabs" aria-label="نوع اعلان">
      <a class="panel-subtab<?= $kind === 'assistant' ? ' is-active' : '' ?>" href="<?= e(url('/doctor/notifications?kind=assistant')) ?>">
        اعلان دستیار
        <span class="panel-subtab-count"><?= count($aiNotifs) ?></span>
      </a>
      <a class="panel-subtab<?= $kind === 'other' ? ' is-active' : '' ?>" href="<?= e(url('/doctor/notifications?kind=other')) ?>">
        سایر پیام‌ها
        <span class="panel-subtab-count"><?= count($otherNotifs) ?></span>
      </a>
    </nav>
    <form method="post" action="<?= e(url('/doctor/notifications/read')) ?>" class="panel-subtabs-action">
      <input type="hidden" name="next" value="/doctor/notifications?kind=<?= e($kind) ?>">
      <button type="submit" class="btn btn-outline btn-sm"<?= $unreadCount > 0 ? '' : ' disabled' ?>>خواندن همه پیام‌ها</button>
    </form>
  </div>

  <?php if (!$rows): ?>
    <p class="muted" style="margin-top:1rem">
      <?= $kind === 'assistant' ? 'اعلان دستیار تازه‌ای نیست.' : 'پیام سیستمی دیگری نیست.' ?>
    </p>
  <?php else: ?>
    <div class="intake-list">
      <?php foreach ($rows as $n): ?>
        <?php
          $body = str_replace(
              ['پرونده بیماران', 'لیست بیماران', 'مراجع مهمان', 'بیماران', 'بیمار'],
              ['پرونده مراجعه‌کنندگان', 'لیست مراجعه‌کنندگان', 'مراجعه‌کننده مهمان', 'مراجعه‌کنندگان', 'مراجعه‌کننده'],
              trim((string) ($n['body'] ?? ''))
          );
          $title = str_replace(
              ['پرونده بیماران', 'بیماران', 'بیمار'],
              ['پرونده مراجعه‌کنندگان', 'مراجعه‌کنندگان', 'مراجعه‌کننده'],
              (string) ($n['title'] ?? '')
          );
          $preview = $body !== '' ? mb_substr($body, 0, 220) : '';
        ?>
        <article class="intake-item<?= !(int) $n['is_read'] ? ' is-unread' : '' ?>">
          <div class="intake-item-body">
            <?php if (!(int) $n['is_read']): ?>
              <span class="badge">جدید</span>
            <?php endif; ?>
            <strong><?= e($title) ?></strong>
            <p class="muted intake-item-meta"><?= e(format_fa_datetime((string) ($n['created_at'] ?? ''))) ?></p>
            <?php if ($preview !== ''): ?>
              <p class="intake-item-summary"><?= e($preview) ?><?= mb_strlen($body) > 220 ? '…' : '' ?></p>
            <?php endif; ?>
          </div>
          <?php if (!empty($n['link'])): ?>
            <a class="btn btn-outline btn-sm intake-item-btn" href="<?= e(url((string) $n['link'])) ?>">مشاهده کامل</a>
          <?php endif; ?>
        </article>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</div>
<?php
render_doctor_page($kind === 'assistant' ? 'اعلان دستیار' : 'سایر پیام‌ها', ob_get_clean());
