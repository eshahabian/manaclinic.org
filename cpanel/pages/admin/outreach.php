<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/admin_panel.php';
require_once __DIR__ . '/../../includes/outreach.php';
require_login(['ADMIN']);

if (!sms_operator_allowed(current_user())) {
    flash_set('error', 'این بخش فقط برای مدیر سایت است.');
    redirect('/admin');
}

ensure_outreach_schema($pdo);
$contacts = outreach_contacts($pdo);
$pending = 0;
try {
    $pending = (int) $pdo->query("SELECT COUNT(*) FROM sms_outbox WHERE status='PENDING'")->fetchColumn();
} catch (Throwable $ignored) {
}

ob_start();
?>
<h1>مراجعه‌کنندگان قدیمی</h1>
<p class="muted" style="margin-top:.35rem;line-height:1.9">
  اسم و شماره را اینجا نگه دارید، بدون ساختن حساب کاربری.
  اگر بعداً با همین شماره ثبت‌نام کنند یا در کارگاه اسم بنویسند، مانعی پیش نمی‌آید و همین ردیف به حسابشان وصل می‌شود.
  <?php if (!sms_panel_enabled()): ?>
    پنل پیامک هنوز خاموش است؛ متن‌ها فقط در صف می‌مانند و ارسال نمی‌شوند.
  <?php else: ?>
    پنل پیامک روشن است.
  <?php endif; ?>
  پیامک در صف: <?= e(to_fa_digits((string) $pending)) ?>
</p>

<div class="grid-2" style="margin-top:1rem">
  <form class="panel" method="post" action="<?= e(url('/admin/outreach')) ?>" style="margin:0">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="add">
    <h2 style="margin:0 0 .75rem;font-size:1rem">یک نفر</h2>
    <label>
      <span class="label">نام</span>
      <input class="input" name="name" required>
    </label>
    <label style="display:block;margin-top:.6rem">
      <span class="label">شماره تماس</span>
      <input class="input" name="phone" required dir="ltr" inputmode="tel">
    </label>
    <label style="display:block;margin-top:.6rem">
      <span class="label">یادداشت</span>
      <input class="input" name="note">
    </label>
    <button class="btn btn-primary" type="submit" style="margin-top:.8rem">ذخیره</button>
  </form>

  <form class="panel" method="post" action="<?= e(url('/admin/outreach')) ?>" style="margin:0">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="import">
    <h2 style="margin:0 0 .75rem;font-size:1rem">چند نفر با هم</h2>
    <p class="muted" style="margin:0 0 .5rem;font-size:.85rem">هر خط: نام و بعد شماره. مثلاً «مهراد بابایی ۰۹۱۲۱۲۳۴۵۶۷»</p>
    <textarea class="input" name="lines" rows="6" required></textarea>
    <button class="btn btn-primary" type="submit" style="margin-top:.8rem">افزودن فهرست</button>
  </form>
</div>

<form class="panel" method="post" action="<?= e(url('/admin/outreach')) ?>" style="margin-top:1rem">
  <?= csrf_field() ?>
  <input type="hidden" name="action" value="queue">
  <h2 style="margin:0 0 .5rem;font-size:1rem">پیامک دستی به همین فهرست</h2>
  <textarea class="input" name="body" rows="3" required placeholder="متن پیامک، مثلاً اعلام یک کارگاه"></textarea>
  <button class="btn btn-primary" type="submit" style="margin-top:.75rem">گذاشتن در صف پیامک</button>
</form>

<div class="panel" style="padding:0;overflow:auto;margin-top:1rem">
  <table class="table">
    <thead>
      <tr>
        <th>نام</th>
        <th>شماره</th>
        <th>حساب سایت</th>
        <th>یادداشت</th>
        <th></th>
      </tr>
    </thead>
    <tbody>
      <?php if (!$contacts): ?>
        <tr><td colspan="5" class="muted">هنوز شماره‌ای ثبت نشده است.</td></tr>
      <?php endif; ?>
      <?php foreach ($contacts as $row): ?>
        <tr>
          <td><?= e((string) $row['name']) ?></td>
          <td dir="ltr"><?= e((string) $row['phone']) ?></td>
          <td><?= !empty($row['linked_user_id']) ? 'وصل به ' . e((string) ($row['linked_name'] ?: 'حساب')) : 'هنوز حساب ندارد' ?></td>
          <td><?= e((string) ($row['note'] ?? '')) ?></td>
          <td>
            <form method="post" action="<?= e(url('/admin/outreach')) ?>" onsubmit="return confirm('این شماره از فهرست حذف شود؟');">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="delete">
              <input type="hidden" name="id" value="<?= e((string) $row['id']) ?>">
              <button class="btn btn-outline btn-sm" type="submit">حذف</button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php
render_admin_page('مراجعه‌کنندگان قدیمی', ob_get_clean());
