<?php
declare(strict_types=1);
require_once __DIR__ . '/../../includes/outreach.php';
require_sms_settings_operator();
ensure_mail_schema($pdo);
ensure_outreach_schema($pdo);
$settings=sms_connection_config($pdo);
$workerSeen=mail_setting_get($pdo,'sms_worker_last_run','');
$recent=$pdo->query('SELECT phone,body,status,due_at,last_error FROM sms_outbox ORDER BY created_at DESC LIMIT 25')->fetchAll()?:[];
ob_start();
?>
<h1>تنظیمات پیامک</h1>
<p class="muted">سرویس: وب‌وان (همان پنل هفت‌رخ). با کلید API و شماره خط ارسال می‌شود. تا تکمیل تنظیمات، پیامکی ارسال نمی‌شود.</p>
<p><?= sms_connection_ready($pdo)?'ارسال پیامک فعال است.':'ارسال پیامک فعلاً خاموش است.' ?></p>
<p class="muted">آخرین اجرای صف: <?= $workerSeen!==''?e(format_fa_datetime($workerSeen)):'هنوز اجرا نشده' ?></p>
<?php if (mail_setting_get($pdo,'sms_cron_installed','0')!=='1'): ?>
<div class="panel">
  <strong>یادآوری خودکار به زمان‌بندی هاست نیاز دارد.</strong>
  <p>در بخش Cron Jobs سی‌پنل، زمان‌بندی را روی هر دقیقه (* * * * *) بگذارید و فرمان زیر را وارد کنید. نسخه PHP خط فرمان باید ۸ یا بالاتر باشد. سپس زمان آخرین اجرای صف را در همین صفحه بررسی کنید.</p>
  <code dir="ltr" style="display:block;overflow-wrap:anywhere">/usr/local/bin/php <?= e(dirname(__DIR__,2).'/sms-worker.php') ?> &gt; /dev/null 2&gt;&amp;1</code>
</div>
<?php endif; ?>
<form class="panel form-stack" method="post" action="<?= e(url('/admin/sms-settings')) ?>">
  <?= csrf_field() ?><input type="hidden" name="action" value="save_sms_settings">
  <label class="label" for="sms-sender">شماره خط فرستنده</label><input class="input" id="sms-sender" name="sender" dir="ltr" value="<?= e($settings['sender']) ?>" placeholder="مثلاً 9998621381" required>
  <label class="label" for="sms-api-key">کلید API وب‌وان</label><input class="input" id="sms-api-key" name="api_key" type="password" dir="ltr" autocomplete="new-password" placeholder="<?= $settings['api_key']!==''?'ذخیره شده؛ برای حفظ خالی بگذارید':'کلید API' ?>">
  <details>
    <summary>مسیر جایگزین با نام کاربری و رمز وب‌سرویس</summary>
    <label class="label" for="sms-username">نام کاربری وب‌سرویس</label><input class="input" id="sms-username" name="username" dir="ltr" value="<?= e($settings['username']) ?>" autocomplete="off">
    <label class="label" for="sms-password">رمز وب‌سرویس</label><input class="input" id="sms-password" name="password" type="password" dir="ltr" autocomplete="new-password" placeholder="<?= $settings['password']!==''?'ذخیره شده؛ برای حفظ خالی بگذارید':'رمز وب‌سرویس' ?>">
  </details>
  <p class="muted">کلید ارسالی شما محفوظ است. مسیر رسمی مثل هفت‌رخ: <a href="https://webone-sms.com/fa/صفحه/وب-سرویس-ارسال-پیامک" target="_blank" rel="noopener noreferrer">مستند REST وب‌وان</a> با هدر <code dir="ltr">X-API-KEY</code>.</p>
  <div class="panel" style="background:#fff8e8">
    <strong>اگر پیامک ارسال نمی‌شود:</strong>
    <p>در پنل وب‌وان → تنظیمات → آی‌پی‌های مجاز REST این آی‌پی سرور را اضافه کنید:</p>
    <code dir="ltr" style="display:block">89.42.208.211</code>
    <p class="muted" style="margin-top:.5rem">تا وقتی این آی‌پی ثبت نشود، وب‌وان پاسخ <code dir="ltr">IP Is Invalid</code> می‌دهد و هیچ پیامکی نمی‌رود.</p>
  </div>
  <label><input type="checkbox" name="enabled" value="1" <?= $settings['enabled']==='1'?'checked':'' ?>> فعال‌کردن ارسال پس از تکمیل اطلاعات بالا</label>
  <button type="submit" class="btn btn-primary">ذخیره تنظیمات</button>
</form>
<section class="panel" style="margin-top:1rem">
  <h2>آخرین پیامک‌ها</h2>
  <?php foreach($recent as $job): ?>
    <article style="padding:.75rem 0;border-bottom:1px solid #ddd">
      <strong dir="ltr"><?= e($job['phone']) ?></strong> · <?= e(sms_status_label($job['status'])) ?>
      <p><?= e($job['body']) ?></p>
      <?php if(!empty($job['due_at'])): ?><small>زمان ارسال: <?= e(format_fa_datetime($job['due_at'])) ?></small><?php else: ?><small>صف قدیمی؛ ارسال خودکار ندارد.</small><?php endif; ?>
      <?php if(!empty($job['last_error'])): ?><p class="muted"><?= e($job['last_error']) ?></p><?php endif; ?>
    </article>
  <?php endforeach; ?>
</section>
<?php sms_render_page('تنظیمات پیامک',ob_get_clean());
