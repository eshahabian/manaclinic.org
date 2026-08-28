<?php
declare(strict_types=1);

$user = require_login();
$pageTitle = 'تغییر رمز عبور';
ob_start();
?>
<div class="auth-wrap">
  <form class="panel auth-box form-stack" method="post" action="<?= e(url('/change-password')) ?>">
    <div>
      <h1>تغییر رمز عبور</h1>
      <p class="muted">برای امنیت حساب، لطفاً رمز جدید خود را وارد کنید.</p>
    </div>
    <div>
      <label class="label">رمز فعلی</label>
      <input class="input" type="password" name="current_password" required dir="ltr">
    </div>
    <div>
      <label class="label">رمز جدید</label>
      <input class="input" type="password" name="new_password" required minlength="6" dir="ltr">
    </div>
    <div>
      <label class="label">تکرار رمز جدید</label>
      <input class="input" type="password" name="new_password_confirm" required minlength="6" dir="ltr">
    </div>
    <button class="btn btn-primary" type="submit">ذخیره رمز جدید</button>
  </form>
</div>
<?php
$content = ob_get_clean();
require __DIR__ . '/../includes/layout.php';
