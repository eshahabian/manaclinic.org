<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/secretary_panel.php';
require_once __DIR__ . '/../../includes/secretary_patient.php';
require_once __DIR__ . '/../../includes/user_referral.php';

require_login(['SECRETARY']);

$users = secretary_bookable_patients($pdo);
$doctors = secretary_active_doctors($pdo);

ob_start();
?>
<h1>کاربران</h1>
<p class="muted">از اینجا فقط درمانگر، معرف و موبایل هر کاربر عوض می‌شود.</p>
<div class="stack" style="margin-top:1rem">
  <?php if (!$users): ?>
    <p class="muted">کاربری برای ویرایش نیست.</p>
  <?php endif; ?>
  <?php foreach ($users as $row): ?>
    <form class="panel form-stack" method="post" action="<?= e(url('/secretary/users')) ?>" style="margin:0">
      <?= csrf_field() ?>
      <input type="hidden" name="user_id" value="<?= e((string) $row['id']) ?>">
      <strong><?= e((string) $row['name']) ?></strong>
      <?php if (trim((string) ($row['username'] ?? '')) !== ''): ?>
        <span class="muted" dir="ltr" style="font-size:.85rem"><?= e((string) $row['username']) ?></span>
      <?php endif; ?>
      <div>
        <label class="label">درمانگر</label>
        <select class="input" name="preferred_doctor_id" required>
          <option value="">انتخاب درمانگر</option>
          <?php foreach ($doctors as $doctor): ?>
            <option value="<?= e((string) $doctor['id']) ?>"<?= ((string) ($row['preferred_doctor_id'] ?? '') === (string) $doctor['id']) ? ' selected' : '' ?>><?= e((string) $doctor['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div>
        <label class="label">معرف</label>
        <?= user_referral_options_html('referral_source', 'referral-' . (string) $row['id'], (string) ($row['referral_source'] ?? '')) ?>
      </div>
      <div>
        <label class="label">موبایل</label>
        <input class="input" name="phone" dir="ltr" inputmode="tel" value="<?= e((string) ($row['phone'] ?? '')) ?>" required>
      </div>
      <button class="btn btn-primary btn-sm" type="submit" style="justify-self:start">ذخیره</button>
    </form>
  <?php endforeach; ?>
</div>
<?php
render_secretary_page('کاربران', ob_get_clean());
