<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/admin_panel.php';
require_once __DIR__ . '/../../includes/outreach.php';
require_site_admin();

ensure_outreach_schema($pdo);
$recent = sms_recent($pdo, 'test');

ob_start();
?>
<h1>تست پیامک</h1>
<p class="muted" style="margin-top:.35rem;line-height:1.8">
  پنل پیامک هنوز وصل نیست. متن اینجا فقط در صف می‌ماند و برای هیچ شماره‌ای فرستاده نمی‌شود.
</p>
<form method="post" action="<?= e(url('/admin/sms-test')) ?>" class="panel form-stack" style="margin-top:1rem">
  <?= csrf_field() ?>
  <input type="hidden" name="action" value="sms_test">
  <div>
    <label class="label" for="sms-phone">شماره موبایل</label>
    <input class="input" id="sms-phone" name="phone" dir="ltr" required placeholder="0912…">
  </div>
  <div>
    <label class="label" for="sms-body">متن</label>
    <textarea class="input" id="sms-body" name="body" rows="4" required maxlength="500"></textarea>
  </div>
  <button type="submit" class="btn btn-primary">گذاشتن در صف</button>
</form>
<?php if ($recent): ?>
<div class="panel" style="margin-top:1rem;padding:0">
  <table class="table">
    <thead>
      <tr>
        <th>شماره</th>
        <th>متن</th>
        <th>وضعیت</th>
        <th>زمان</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($recent as $row): ?>
        <tr>
          <td dir="ltr"><?= e((string) $row['phone']) ?></td>
          <td><?= e((string) $row['body']) ?></td>
          <td><?= e((string) $row['status']) ?></td>
          <td><?= e(format_fa_datetime((string) $row['created_at'])) ?></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php endif; ?>
<?php
render_admin_page('تست پیامک', ob_get_clean());
