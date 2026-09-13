<?php
declare(strict_types=1);

$user = require_login(['PATIENT']);
require_once __DIR__ . '/../../includes/patient_panel.php';

$stmt = $pdo->prepare('SELECT * FROM users WHERE id=?');
$stmt->execute([$user['id']]);
$profile = $stmt->fetch();

ob_start();
?>
<div style="max-width:28rem" class="stack">
  <h1>پروفایل</h1>
  <form class="panel form-stack" method="post" action="<?= e(url('/dashboard/profile')) ?>">
    <?= csrf_field() ?>
    <div>
      <label class="label">نام</label>
      <input class="input" name="name" value="<?= e($profile['name']) ?>" required>
    </div>
    <div>
      <label class="label">نام کاربری</label>
      <input class="input" value="<?= e((string)$profile['username']) ?>" disabled dir="ltr">
    </div>
    <div>
      <label class="label">موبایل</label>
      <input class="input" name="phone" value="<?= e((string)$profile['phone']) ?>" dir="ltr">
    </div>
    <button class="btn btn-primary" type="submit">ذخیره تغییرات</button>
  </form>

  <form id="change-password" class="panel form-stack" method="post" action="<?= e(url('/change-password')) ?>" autocomplete="off">
    <?= csrf_field() ?>
    <input type="hidden" name="return_to" value="/dashboard/profile">
    <div>
      <h2 style="font-size:1.1rem;margin:0">تغییر رمز عبور</h2>
      <p class="muted" style="margin:.35rem 0 0;font-size:.9rem">برای امنیت حساب، رمز جدید را اینجا تنظیم کنید.</p>
    </div>
    <?= password_field_html('current_password', 'current_password', [
        'label' => 'رمز فعلی',
        'autocomplete' => 'current-password',
        'rules' => false,
    ]) ?>
    <?= password_field_html('new_password', 'new_password', [
        'label' => 'رمز جدید',
        'autocomplete' => 'new-password',
        'rules' => false,
    ]) ?>
    <?= password_field_html('new_password_confirm', 'new_password_confirm', [
        'label' => 'تکرار رمز جدید',
        'autocomplete' => 'new-password',
        'confirm' => true,
        'pair' => 'new_password',
    ]) ?>
    <button class="btn btn-primary" type="submit">ذخیره رمز جدید</button>
  </form>
</div>
<?php
render_patient_page('پروفایل', ob_get_clean());
