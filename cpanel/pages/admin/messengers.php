<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/admin_panel.php';
require_once __DIR__ . '/../../includes/mail.php';
require_once __DIR__ . '/../../includes/clinic_manage.php';
require_site_admin();

ensure_mail_schema($pdo);
$items = clinic_messenger_catalog($pdo);

ob_start();
?>
<h1>دسترسی پیام رسان</h1>
<p class="muted" style="margin-top:.35rem;line-height:1.8">
  لینک‌هایی که اینجا روشن باشند در پایین سایت نمایش داده می‌شوند. اگر آدرسی خالی بماند، همان پیام‌رسان در فوتر دیده نمی‌شود.
</p>
<form method="post" action="<?= e(url('/admin/messengers')) ?>" class="panel form-stack" style="margin-top:1rem">
  <?= csrf_field() ?>
  <input type="hidden" name="action" value="save_messengers">
  <?php foreach ($items as $key => $item): ?>
    <div style="padding:.75rem 0;border-bottom:1px solid var(--line)">
      <label style="display:flex;gap:.5rem;align-items:center;font-weight:600">
        <input type="checkbox" name="on_<?= e($key) ?>" value="1" <?= !empty($item['on']) ? 'checked' : '' ?>>
        <?= e($item['label']) ?>
      </label>
      <label class="label" for="url_<?= e($key) ?>" style="margin-top:.55rem">آدرس</label>
      <input class="input" id="url_<?= e($key) ?>" name="url_<?= e($key) ?>" dir="ltr" value="<?= e($item['url']) ?>" placeholder="https://">
    </div>
  <?php endforeach; ?>
  <button type="submit" class="btn btn-primary">ذخیره</button>
</form>
<?php
render_admin_page('دسترسی پیام رسان', ob_get_clean());
