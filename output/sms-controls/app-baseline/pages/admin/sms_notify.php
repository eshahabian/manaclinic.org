<?php
declare(strict_types=1);
require_once __DIR__ . '/../../includes/admin_panel.php';
require_once __DIR__ . '/../../includes/mail.php';
require_once __DIR__ . '/../../includes/outreach.php';
require_sms_settings_operator();
ensure_mail_schema($pdo);
ensure_outreach_schema($pdo);
$catalog = sms_template_catalog();
$samples = sms_template_sample_variables();
$recent = $pdo->query("SELECT phone,body,status,last_error,created_at FROM sms_outbox WHERE kind='test' ORDER BY created_at DESC LIMIT 8")->fetchAll() ?: [];
ob_start();
?>
<h1>مدیریت پیامک‌ها</h1>
<p class="muted">برای هر پیامک، گیرنده، وضعیت ارسال و متن را ببینید. هر کارت جداگانه ذخیره می‌شود. این تنظیمات در سایت و اپلیکیشن مشترک است.</p>
<p><a href="<?= e(url('/admin/sms-settings')) ?>">تنظیمات اتصال پیامک</a> · <?= sms_connection_ready($pdo) ? 'اتصال ارسال فعال است' : 'اتصال ارسال غیرفعال است؛ تست هم ارسال نمی‌شود' ?></p>
<p class="muted">تغییر متن روی پیام‌های جدید اعمال می‌شود. غیرفعال‌کردن، پیام‌های در انتظار همان نوع را هم متوقف می‌کند؛ پیام تحویل‌شده به سرویس قابل پس‌گرفتن نیست. فعال‌کردن دوباره پیام‌های متوقف‌شده را ارسال نمی‌کند.</p>
<p class="muted">برای تست، شماره دلخواه را در همان کارت بنویسید. متن داخل کادر با اطلاعات نمونه ارسال می‌شود، حتی اگر ارسال خودکار آن نوع خاموش باشد. تست، تغییرات را ذخیره نمی‌کند و هیچ نوبتی را ثبت یا لغو نمی‌کند.</p>
<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(min(100%,340px),1fr));gap:1rem;align-items:start">
<?php foreach ($catalog as $kind => $item):
    $saved = mail_setting_get($pdo, 'sms_template_'.$kind, '');
    $text = trim($saved) !== '' ? $saved : $item[1];
    $enabled = sms_kind_enabled($pdo, $kind);
    $copy = $kind === 'therapist_copy';
?>
<section class="panel" id="sms-<?= e($kind) ?>" style="margin:0;min-width:0">
  <h2 style="font-size:1.1rem"><?= e($item[0]) ?></h2>
  <p><strong>گیرنده ارسال خودکار:</strong> <?= e(sms_kind_recipient($kind)) ?></p>
  <form method="post" action="<?= e(url('/admin/sms-notify')) ?>" class="form-stack" data-sms-template>
    <?= csrf_field() ?>
    <input type="hidden" name="kind" value="<?= e($kind) ?>">
    <label style="display:flex;gap:.5rem;align-items:center"><input type="checkbox" name="enabled" value="1" <?= $enabled ? 'checked' : '' ?> role="switch"> ارسال خودکار <?= $enabled ? 'فعال است' : 'غیرفعال است' ?></label>
    <label class="label" for="template-<?= e($kind) ?>">متن پیامک<?= $copy ? ' (عین پیام اصلی)' : '' ?></label>
    <textarea class="input" name="template" id="template-<?= e($kind) ?>" rows="4" maxlength="500" <?= $copy ? 'readonly' : '' ?>><?= e($text) ?></textarea>
    <?php if ($copy): ?><small>برای تغییر متن کپی، پیام اصلی درمانگر را ویرایش کنید.</small><?php endif; ?>
    <small>متغیرهای مجاز: <bdi dir="ltr"><?= e(implode(' / ', sms_template_tokens($kind))) ?></bdi></small>
    <?php if (in_array('{message}', sms_template_tokens($kind), true)): ?><small>متغیر {message} متن واقعی ساخته‌شده از اطلاعات رویداد است؛ نمونه زیر صرفاً برای تست است.</small><?php endif; ?>
    <details><summary>متن پیش‌فرض</summary><p style="overflow-wrap:anywhere"><?= e($item[1]) ?></p></details>
    <strong>پیش‌نمایش با اطلاعات نمونه</strong>
    <p data-sms-preview style="white-space:pre-wrap;overflow-wrap:anywhere;background:#f4f7f7;padding:.75rem;border-radius:.6rem"><?= e(sms_template_render($pdo, $kind, $samples, $text)) ?></p>
    <button type="submit" name="action" value="save_sms_template" class="btn btn-primary">ذخیره متن و وضعیت</button>
    <label class="label" for="phone-<?= e($kind) ?>">گیرنده تست (شماره دلخواه)</label>
    <input class="input" type="tel" inputmode="tel" name="phone" id="phone-<?= e($kind) ?>" dir="ltr" placeholder="09123456789" maxlength="20">
    <button type="submit" name="action" value="test_sms_template" class="btn btn-outline">ارسال یک پیامک تست</button>
  </form>
</section>
<?php endforeach; ?>
</div>
<section class="panel" style="margin-top:1rem">
  <h2>نتیجه آخرین تست‌ها</h2>
  <?php if (!$recent): ?><p class="muted">هنوز تستی ثبت نشده است.</p><?php endif; ?>
  <?php foreach ($recent as $job): ?>
  <article style="padding:.75rem 0;border-bottom:1px solid #ddd">
    <strong dir="ltr"><?= e($job['phone']) ?></strong> · <?= e(sms_status_label($job['status'])) ?> · <?= e(format_fa_datetime($job['created_at'])) ?>
    <p style="overflow-wrap:anywhere"><?= e($job['body']) ?></p>
    <?php if (!empty($job['last_error'])): ?><p class="muted"><?= e($job['last_error']) ?></p><?php endif; ?>
  </article>
  <?php endforeach; ?>
</section>
<script>
(function () {
  const samples = <?= json_encode($samples, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) ?>;
  const defaults = <?= json_encode(array_map(static fn($item) => $item[1], $catalog), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) ?>;
  document.querySelectorAll('[data-sms-template]').forEach(function (form) {
    const text = form.elements.template;
    const preview = form.querySelector('[data-sms-preview]');
    text.addEventListener('input', function () {
      let value = text.value.trim() || defaults[form.elements.kind.value];
      value = value.replace(/\{([a-z_]+)\}/g, function (token, name) { return samples[name] === undefined ? token : samples[name]; });
      value = value.replace(/\s+/g, ' ').trim();
      preview.textContent = Array.from(value).length > 500 ? Array.from(value).slice(0, 497).join('') + '…' : value;
    });
    form.addEventListener('submit', function (event) {
      if (event.submitter && event.submitter.value === 'test_sms_template' && !form.elements.phone.value.trim()) {
        event.preventDefault(); form.elements.phone.focus(); form.elements.phone.setCustomValidity('شماره گیرنده تست را وارد کنید.'); form.elements.phone.reportValidity();
      }
    });
    form.elements.phone.addEventListener('input', function () { this.setCustomValidity(''); });
  });
})();
</script>
<?php sms_render_page('مدیریت پیامک‌ها', ob_get_clean());
