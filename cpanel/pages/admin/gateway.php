<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/admin_panel.php';
require_once __DIR__ . '/../../includes/mail.php';
require_once __DIR__ . '/../../includes/clinic_manage.php';
require_site_admin();

ensure_mail_schema($pdo);
$savedMerchant = (string) mail_setting_get($pdo, 'gateway_merchant_id', '');
$sandboxRaw = mail_setting_get($pdo, 'gateway_sandbox', '');
$onlineRaw = mail_setting_get($pdo, 'gateway_online', '');
$sandbox = $sandboxRaw === '1' || $sandboxRaw === '0' ? $sandboxRaw === '1' : !empty($config['zarinpal_sandbox']);
$online = $onlineRaw === '1' || $onlineRaw === '0' ? $onlineRaw === '1' : !empty($config['online_payment_enabled']);
$merchant = $savedMerchant !== '' ? $savedMerchant : (string) ($config['zarinpal_merchant_id'] ?? '');

ob_start();
?>
<h1>درگاه پرداخت</h1>
<p class="muted" style="margin-top:.35rem;line-height:1.8">
  زرین‌پال از همین‌جا خوانده می‌شود. اگر شناسه پذیرنده را خالی ذخیره کنید، مقدار فایل تنظیمات سرور استفاده می‌شود.
</p>
<form method="post" action="<?= e(url('/admin/gateway')) ?>" class="panel form-stack" style="margin-top:1rem" autocomplete="off">
  <?= csrf_field() ?>
  <input type="hidden" name="action" value="save_gateway">
  <div>
    <label class="label" for="merchant">شناسه پذیرنده زرین‌پال</label>
    <input class="input" id="merchant" name="merchant_id" dir="ltr" value="<?= e($merchant) ?>" autocomplete="off">
  </div>
  <label style="display:flex;gap:.5rem;align-items:center">
    <input type="checkbox" name="sandbox" value="1" <?= $sandbox ? 'checked' : '' ?>>
    حالت آزمایشی (sandbox)
  </label>
  <label style="display:flex;gap:.5rem;align-items:center">
    <input type="checkbox" name="online" value="1" <?= $online ? 'checked' : '' ?>>
    پرداخت آنلاین فعال باشد
  </label>
  <button type="submit" class="btn btn-primary">ذخیره</button>
</form>
<?php
render_admin_page('درگاه پرداخت', ob_get_clean());
