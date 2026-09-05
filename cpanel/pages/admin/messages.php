<?php
declare(strict_types=1);
require_once __DIR__ . '/../../includes/admin_panel.php';
require_login(['ADMIN']);

$rows = fetch_all_notifications($pdo, 150);

ob_start();
?>
<h1>پیام‌ها</h1>
<p class="muted" style="margin-top:.35rem;font-size:.9rem">
  همه اعلان‌های سایت. پیام‌های تحویل شیفت منشی‌ها هم اینجا کپی می‌شوند.
</p>

<?php if ($rows): ?>
  <form method="post" action="<?= e(url('/admin/messages')) ?>" style="margin-top:1rem" onsubmit="return confirm('همه پیام‌ها حذف شوند؟');">
    <input type="hidden" name="action" value="delete_all">
    <button type="submit" class="btn btn-danger">حذف همه پیام‌ها</button>
  </form>
<?php endif; ?>

<div class="stack" style="margin-top:1rem">
<?php foreach ($rows as $n): ?>
  <div class="panel row-between" style="align-items:flex-start;gap:1rem">
    <div style="flex:1;min-width:0">
      <strong><?= e((string) $n['title']) ?></strong>
      <div style="font-size:.9rem;line-height:1.7;margin-top:.35rem;white-space:pre-wrap"><?= e((string) $n['body']) ?></div>
      <div class="muted" style="font-size:.8rem;margin-top:.45rem">
        برای <?= e((string) ($n['recipient_name'] ?? '')) ?>
        (<?= e(role_label((string) ($n['recipient_role'] ?? ''))) ?>)
        · <?= e(format_fa_datetime((string) $n['created_at'])) ?>
        <?= !(int) ($n['is_read'] ?? 1) ? ' · خوانده‌نشده' : '' ?>
      </div>
    </div>
    <form method="post" action="<?= e(url('/admin/messages')) ?>" style="margin:0" onsubmit="return confirm('این پیام حذف شود؟');">
      <input type="hidden" name="action" value="delete">
      <input type="hidden" name="notification_id" value="<?= e((string) $n['id']) ?>">
      <button type="submit" class="btn btn-danger btn-sm">حذف</button>
    </form>
  </div>
<?php endforeach; ?>
<?php if (!$rows): ?>
  <p class="muted">پیامی نیست.</p>
<?php endif; ?>
</div>
<?php
render_admin_page('پیام‌ها', ob_get_clean());
