<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/doctor_panel.php';
require_once __DIR__ . '/../../includes/assistant.php';

$ctx = require_doctor_profile($pdo);
$userId = doctor_ctx_user_id($ctx);
ensure_assistant_schema($pdo);

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
    } elseif (!notification_is_staff_copy($n) && notification_kind($n) !== 'handover') {
        $otherNotifs[] = $n;
    }
}

$intakeRows = $pdo->query("
  SELECT s.id, s.sent_at, s.ai_summary, s.intake_text, s.patient_id,
         u.name AS patient_name, u.phone AS patient_phone
  FROM assistant_sessions s
  LEFT JOIN users u ON u.id = s.patient_id
  WHERE s.status = 'SENT'
    AND s.sent_at IS NOT NULL
    AND (s.patient_id IS NULL OR s.patient_id = '')
  ORDER BY s.sent_at DESC
  LIMIT 100
")->fetchAll();

$intakeUnread = [];
$intakeById = [];
foreach ($intakeRows as $row) {
    $intakeById[(string) $row['id']] = $row;
}
foreach ($aiNotifs as $n) {
    $link = (string) ($n['link'] ?? '');
    if (preg_match('#/doctor/intakes/([a-zA-Z0-9_-]+)#', $link, $m)) {
        $sid = $m[1];
        if (!(int) ($n['is_read'] ?? 0)) {
            $intakeUnread[$sid] = true;
        }
    }
}

$assistantFeed = [];
foreach ($aiNotifs as $n) {
    $link = (string) ($n['link'] ?? '');
    $sid = (preg_match('#/doctor/intakes/([a-zA-Z0-9_-]+)#', $link, $m)) ? $m[1] : '';
    if ($sid !== '' && isset($intakeById[$sid])) {
        continue;
    }
    $assistantFeed[] = [
        'sort' => (string) ($n['created_at'] ?? ''),
        'unread' => !(int) ($n['is_read'] ?? 0),
        'title' => (string) ($n['title'] ?? ''),
        'body' => (string) ($n['body'] ?? ''),
        'link' => $link,
        'created_at' => (string) ($n['created_at'] ?? ''),
        'source' => 'notification',
    ];
}
foreach ($intakeRows as $row) {
    $sid = (string) $row['id'];
    $summary = trim((string) ($row['ai_summary'] ?? ''));
    if ($summary === '') {
        $summary = mb_substr(trim((string) ($row['intake_text'] ?? '')), 0, 220);
    }
    $assistantFeed[] = [
        'sort' => (string) ($row['sent_at'] ?? ''),
        'unread' => !empty($intakeUnread[$sid]),
        'title' => 'گفتگوی دستیار — مراجعه‌کننده مهمان',
        'body' => $summary,
        'link' => '/doctor/intakes/' . $sid,
        'created_at' => (string) ($row['sent_at'] ?? ''),
        'source' => 'intake',
    ];
}
usort($assistantFeed, static fn(array $a, array $b): int => strcmp((string) $b['sort'], (string) $a['sort']));

$rows = $kind === 'other' ? $otherNotifs : $assistantFeed;
$unreadCount = count_unread_notifications($pdo, $userId);

ob_start();
?>
<div class="panel">
  <h1>اعلان‌ها</h1>
  <p class="muted">گفتگوهای دستیار و اعلان دستیار در یک فهرست هستند. پیام‌های دیگر سیستم را از تب کناری ببینید.</p>

  <div class="panel-subtabs-row">
    <nav class="panel-subtabs" aria-label="نوع اعلان">
      <a class="panel-subtab<?= $kind === 'assistant' ? ' is-active' : '' ?>" href="<?= e(url('/doctor/notifications?kind=assistant')) ?>">
        اعلان دستیار
        <span class="panel-subtab-count"><?= count($assistantFeed) ?></span>
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
      <?= $kind === 'assistant' ? 'گفتگو یا اعلان دستیاری نیست.' : 'پیام سیستمی دیگری نیست.' ?>
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
          $unreadItem = !empty($n['unread']) || (isset($n['is_read']) && !(int) $n['is_read']);
        ?>
        <article class="intake-item<?= $unreadItem ? ' is-unread' : '' ?>">
          <div class="intake-item-body">
            <?php if ($unreadItem): ?>
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
