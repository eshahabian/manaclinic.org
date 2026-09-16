<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/mentions.php';

$user = require_login(['ADMIN', 'DOCTOR', 'SECRETARY', 'PATIENT']);
$userId = (string) ($user['id'] ?? '');
$role = strtoupper((string) ($user['role'] ?? ''));
$path = mentions_panel_path($role);

if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) === 'POST') {
    csrf_verify();
    $action = post('action');
    if ($action === 'ack') {
        $mid = trim((string) ($_POST['mention_id'] ?? ''));
        mentions_mark_read($pdo, $userId, $mid !== '' ? $mid : null);
        flash_set('success', 'منشن خوانده شد.');
    } elseif ($action === 'ack_all') {
        $n = mentions_mark_read($pdo, $userId, null);
        flash_set('success', $n > 0 ? (to_fa_digits((string) $n) . ' منشن خوانده شد.') : 'منشن خوانده‌نشده‌ای نبود.');
    }
    redirect($path);
}

$inbox = mentions_inbox_for($pdo, $userId, 80);
$unread = mentions_unread_count($pdo, $userId);

ob_start();
?>
<div class="stack">
  <h1>منشن‌ها</h1>
  <p class="muted" style="margin-top:.35rem;line-height:1.8">
    وقتی کسی در پیام یا یادداشت با <strong style="color:<?= e(MENTION_TEXT_COLOR) ?>">@نام شما</strong> اشاره‌تان کند، اینجا می‌بینید.
  </p>

  <?php if ($unread > 0): ?>
    <form method="post" action="<?= e(url($path)) ?>" style="margin:0">
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
              <form method="post" action="<?= e(url($path)) ?>" style="margin:0">
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
$html = ob_get_clean();

if ($role === 'ADMIN') {
    require_once __DIR__ . '/../includes/admin_panel.php';
    render_admin_page('منشن‌ها', $html);
} elseif ($role === 'DOCTOR') {
    require_once __DIR__ . '/../includes/doctor_panel.php';
    render_doctor_page('منشن‌ها', $html);
} elseif ($role === 'SECRETARY') {
    require_once __DIR__ . '/../includes/secretary_panel.php';
    render_secretary_page('منشن‌ها', $html);
} else {
    require_once __DIR__ . '/../includes/patient_panel.php';
    render_patient_page('منشن‌ها', $html);
}
