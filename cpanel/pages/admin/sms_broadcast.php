<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/admin_panel.php';
require_once __DIR__ . '/../../includes/outreach.php';
require_site_admin();

ensure_outreach_schema($pdo);
$recent = sms_recent($pdo, 'broadcast');

ob_start();
?>
<h1>پیامک همگانی</h1>
<p class="muted" style="margin-top:.35rem;line-height:1.8">
  شماره‌های تکراری یک بار صف می‌شوند. ارسال واقعی خاموش است و وضعیت همه «در انتظار» می‌ماند.
</p>
<form method="post" action="<?= e(url('/admin/sms-broadcast')) ?>" class="panel form-stack" style="margin-top:1rem" onsubmit="return confirm('این متن فقط در صف ذخیره شود؟');">
  <?= csrf_field() ?>
  <input type="hidden" name="action" value="sms_broadcast">
  <div>
    <label class="label" for="sms-audience">گیرندگان</label>
    <select class="input" id="sms-audience" name="audience" required>
      <option value="patients">مراجعه‌کنندگان فعال</option>
      <option value="doctors">درمانگرها</option>
      <option value="secretaries">منشی‌ها</option>
      <option value="outreach">مراجعه‌کنندگان قدیمی</option>
      <option value="everyone">همه موارد بالا</option>
    </select>
  </div>
  <div>
    <label class="label" for="sms-body">متن</label>
    <textarea class="input" id="sms-body" name="body" rows="5" required maxlength="500"></textarea>
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
render_admin_page('پیامک همگانی', ob_get_clean());
