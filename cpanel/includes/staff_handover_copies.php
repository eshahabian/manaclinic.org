<?php
declare(strict_types=1);

function staff_handover_copies_render(array $rows, string $actionHref, bool $canDelete): string
{
    ob_start();
    ?>
<h1>پیام‌ها</h1>
<p class="muted" style="margin-top:.35rem;font-size:.9rem">
  کپی پیام‌هایی که منشی‌ها برای هم می‌فرستند.
</p>

<?php if ($canDelete && $rows): ?>
  <form method="post" action="<?= e(url($actionHref)) ?>" style="margin-top:1rem" onsubmit="return confirm('همه این کپی‌ها حذف شوند؟');">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="delete_all">
    <input type="hidden" name="next" value="<?= e($actionHref) ?>">
    <button type="submit" class="btn btn-danger">حذف همه پیام‌ها</button>
  </form>
<?php endif; ?>

<div class="stack" style="margin-top:1rem">
<?php foreach ($rows as $n): ?>
  <div class="panel row-between" style="align-items:flex-start;gap:1rem">
    <div style="flex:1;min-width:0">
      <?php if (!(int) ($n['is_read'] ?? 1)): ?>
        <span class="badge">جدید</span>
      <?php endif; ?>
      <strong><?= e((string) $n['title']) ?></strong>
      <div style="font-size:.9rem;line-height:1.7;margin-top:.35rem;white-space:pre-wrap"><?= e((string) $n['body']) ?></div>
      <div class="muted" style="font-size:.8rem;margin-top:.45rem">
        <?php if (!empty($n['recipient_name'])): ?>
          برای <?= e((string) $n['recipient_name']) ?>
          <?php if (!empty($n['recipient_role'])): ?>
            (<?= e(role_label((string) $n['recipient_role'])) ?>)
          <?php endif; ?>
          ·
        <?php endif; ?>
        <?= e(format_fa_datetime((string) $n['created_at'])) ?>
      </div>
    </div>
    <?php if ($canDelete): ?>
      <form method="post" action="<?= e(url($actionHref)) ?>" style="margin:0" onsubmit="return confirm('این پیام حذف شود؟');">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="delete">
        <input type="hidden" name="notification_id" value="<?= e((string) $n['id']) ?>">
        <input type="hidden" name="next" value="<?= e($actionHref) ?>">
        <button type="submit" class="btn btn-danger btn-sm">حذف</button>
      </form>
    <?php endif; ?>
  </div>
<?php endforeach; ?>
<?php if (!$rows): ?>
  <p class="muted">هنوز کپی پیامی از منشی‌ها نیست.</p>
<?php endif; ?>
</div>
    <?php
    return (string) ob_get_clean();
}
