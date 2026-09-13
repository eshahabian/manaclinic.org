<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/admin_panel.php';
require_once __DIR__ . '/../../includes/mail.php';
require_login(['ADMIN']);

ensure_mail_schema($pdo);
$mc = mail_config($pdo);
$hostOptions = mail_host_options();
$currentHost = $mc['host'];
if (!isset($hostOptions[$currentHost])) {
    $hostOptions[$currentHost] = $currentHost;
}

$probeLog = (string) ($_SESSION['mail_probe_log'] ?? '');
unset($_SESSION['mail_probe_log']);

$testTo = (string) ($_SESSION['mail_test_to'] ?? (current_user()['email'] ?? ''));
if (!mail_is_real_email($testTo)) {
    $testTo = '';
}

$passHint = match ($mc['pass_source']) {
    'database' => 'رمز در دیتابیس ذخیره شده — برای تغییر، رمز جدید بنویسید.',
    'config' => 'رمز از config.php خوانده می‌شود — برای ذخیره در دیتابیس، رمز را اینجا بنویسید.',
    default => 'هنوز رمزی تنظیم نشده.',
};

ob_start();
?>
<h1>ایمیل و SMTP</h1>
<p class="muted" style="margin-top:.35rem;line-height:1.8">
  ایمیل‌های فراموشی رمز و خوش‌آمد ثبت‌نام از حساب
  <strong dir="ltr"><?= e(mail_default_from_email()) ?></strong>
  ارسال می‌شوند. روی سی‌پنل معمولاً میزبان <span dir="ltr">localhost</span> کافی است.
</p>

<div class="panel form-stack" style="margin-top:1rem;max-width:36rem">
  <form method="post" action="<?= e(url('/admin/mail')) ?>" class="form-stack">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="save_host">
    <label class="label" for="smtp_host">میزبان SMTP</label>
    <div style="display:flex;gap:.6rem;flex-wrap:wrap;align-items:center">
      <select class="input" id="smtp_host" name="smtp_host" style="flex:1;min-width:12rem">
        <?php foreach ($hostOptions as $value => $label): ?>
          <option value="<?= e((string) $value) ?>" <?= $currentHost === $value ? 'selected' : '' ?>><?= e($label) ?></option>
        <?php endforeach; ?>
      </select>
      <button type="submit" class="btn btn-accent">ذخیره میزبان</button>
    </div>
    <p class="muted" style="font-size:.85rem;margin:0">پورت فعلی: <span dir="ltr"><?= e(to_fa_digits((string) $mc['port'])) ?></span>
      · رمزنگاری: <span dir="ltr"><?= e($mc['encryption']) ?></span>
      · کاربر: <span dir="ltr"><?= e($mc['user']) ?></span>
    </p>
  </form>
</div>

<div class="panel form-stack" style="margin-top:1rem;max-width:36rem">
  <h2 style="margin:0;font-size:1.05rem">رمز SMTP</h2>
  <p class="muted" style="margin:0;font-size:.9rem;line-height:1.8">
    رمز حساب <span dir="ltr"><?= e($mc['user']) ?></span> را وارد کنید؛ بعد از ذخیره، «تشخیص مستقیم SMTP» را بزنید.
  </p>
  <form method="post" action="<?= e(url('/admin/mail')) ?>" class="form-stack" autocomplete="off">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="save_pass">
    <label class="label" for="smtp_pass">رمز <?= e($mc['user']) ?></label>
    <input class="input" id="smtp_pass" name="smtp_pass" type="password" dir="ltr" autocomplete="new-password" placeholder="<?= e($passHint) ?>">
    <div style="display:flex;gap:.6rem;flex-wrap:wrap">
      <button type="submit" class="btn btn-accent">ذخیره رمز SMTP</button>
    </div>
  </form>
  <form method="post" action="<?= e(url('/admin/mail')) ?>" style="margin:0">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="clear_pass">
    <button type="submit" class="btn btn-outline">پاک کردن رمز دیتابیس (استفاده از config.php)</button>
  </form>
</div>

<div class="panel form-stack" style="margin-top:1rem;max-width:36rem">
  <h2 style="margin:0;font-size:1.05rem">تشخیص مستقیم SMTP</h2>
  <p class="muted" style="margin:0;font-size:.9rem;line-height:1.8">
    بدون کتابخانهٔ بیرونی به سرور وصل می‌شود و کدهای واقعی پاسخ (مثل خطای ۵۳۵) را نشان می‌دهد.
  </p>
  <form method="post" action="<?= e(url('/admin/mail')) ?>">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="probe">
    <button type="submit" class="btn btn-outline">تشخیص مستقیم SMTP</button>
  </form>
  <?php if ($probeLog !== ''): ?>
    <pre class="panel" style="margin:0;padding:.75rem;font-size:.75rem;line-height:1.55;direction:ltr;text-align:left;overflow:auto;max-height:18rem;white-space:pre-wrap;background:var(--bg-soft)"><?= e($probeLog) ?></pre>
  <?php endif; ?>
</div>

<div class="panel form-stack" style="margin-top:1rem;max-width:36rem">
  <h2 style="margin:0;font-size:1.05rem">ارسال تست</h2>
  <form method="post" action="<?= e(url('/admin/mail')) ?>" class="form-stack">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="send_test">
    <label class="label" for="test_to">ارسال ایمیل تست به</label>
    <input class="input" id="test_to" name="test_to" type="email" required dir="ltr" value="<?= e($testTo) ?>" placeholder="you@example.com">
    <button type="submit" class="btn btn-accent">ارسال تست</button>
  </form>
</div>

<div class="panel form-stack" style="margin-top:1rem;max-width:36rem">
  <h2 style="margin:0;font-size:1.05rem">محدودیت درخواست (rate limit)</h2>
  <p class="muted" style="margin:0;font-size:.9rem;line-height:1.8">
    اگر خطای «درخواست زیاد» برای فراموشی رمز دیدید، محدودیت IP فعلی را ریست کنید.
  </p>
  <form method="post" action="<?= e(url('/admin/mail')) ?>">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="reset_throttle">
    <button type="submit" class="btn btn-outline">ریست محدودیت IP من</button>
  </form>
</div>
<?php
render_admin_page('ایمیل و SMTP', ob_get_clean());
