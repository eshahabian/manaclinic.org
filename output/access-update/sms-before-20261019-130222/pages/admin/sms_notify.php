<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/admin_panel.php';
require_once __DIR__ . '/../../includes/mail.php';
require_once __DIR__ . '/../../includes/outreach.php';
require_sms_operator();

ensure_mail_schema($pdo);
$flags = [
    'quiet' => sms_notify_kind_enabled($pdo, 'quiet'),
    'workshop' => sms_notify_kind_enabled($pdo, 'workshop'),
    'approval' => sms_notify_kind_enabled($pdo, 'approval'),
];

ob_start();
?>
<h1>پیامک اطلاع رسانی</h1>
<p class="muted" style="margin-top:.35rem;line-height:1.8">
  این سوئیچ‌ها مشخص می‌کنند کدام پیامک‌ها وارد صف شوند. ارسال فقط پس از تکمیل تنظیمات اتصال انجام می‌شود. پیامک ثبت نوبت، لغو و یادآوری جلسه به‌صورت خودکار مدیریت می‌شود.
</p>
<form method="post" action="<?= e(url('/admin/sms-notify')) ?>" class="panel form-stack" style="margin-top:1rem">
  <?= csrf_field() ?>
  <input type="hidden" name="action" value="save_sms_notify">
  <label style="display:flex;gap:.55rem;align-items:flex-start;line-height:1.7">
    <input type="checkbox" name="notify_quiet" value="1" <?= $flags['quiet'] ? 'checked' : '' ?>>
    <span>اگر بیش از یک ماه از مراجعه‌کننده خبری نباشد، برای درمانگر، منشی‌ها و مدیر صف شود.</span>
  </label>
  <label style="display:flex;gap:.55rem;align-items:flex-start;line-height:1.7">
    <input type="checkbox" name="notify_workshop" value="1" <?= $flags['workshop'] ? 'checked' : '' ?>>
    <span>هنگام ساخت کارگاه، اگر گزینه اطلاع‌رسانی زده شود برای مراجعه‌کنندگان قدیمی صف شود.</span>
  </label>
  <label style="display:flex;gap:.55rem;align-items:flex-start;line-height:1.7">
    <input type="checkbox" checked disabled>
    <span>پیامک ثبت و تأیید نوبت به‌صورت خودکار و بدون ارسال تکراری صف می‌شود.</span>
  </label>
  <button type="submit" class="btn btn-primary">ذخیره</button>
</form>
<?php
sms_render_page('پیامک اطلاع رسانی', ob_get_clean());
