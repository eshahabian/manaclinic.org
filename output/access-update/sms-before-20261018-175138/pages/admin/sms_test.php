<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/admin_panel.php';
require_once __DIR__ . '/../../includes/outreach.php';
require_sms_operator();

ensure_outreach_schema($pdo);
$recent = sms_recent($pdo, 'test');

ob_start();
?>
<h1>تست پیامک</h1>
<p class="muted" style="margin-top:.35rem;line-height:1.8">
  <?= sms_panel_enabled()?'پیامک آزمایشی برای شماره موبایل ایران در صف ارسال قرار می‌گیرد.':'تا تکمیل شماره خط و تنظیمات اتصال توسط مدیر، پیامک ارسال نمی‌شود.' ?> <?php if(sms_settings_allowed(current_user())): ?><a href="<?= e(url('/admin/sms-settings')) ?>">تنظیمات پیامک</a><?php endif; ?>
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
  <button type="submit" class="btn btn-primary">ثبت پیامک آزمایشی</button>
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
          <td><?= e(sms_status_label((string) $row['status'])) ?></td>
          <td><?= e(format_fa_datetime((string) $row['created_at'])) ?></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php endif; ?>
<?php
sms_render_page('تست پیامک', ob_get_clean());
