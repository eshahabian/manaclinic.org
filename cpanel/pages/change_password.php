<?php
declare(strict_types=1);

$user = require_login();
$forced = !empty($user['must_change_password']);
$pageTitle = 'تغییر رمز عبور';
ob_start();
?>
<div class="auth-wrap">
  <form class="panel auth-box form-stack" method="post" action="<?= e(url('/change-password')) ?>" autocomplete="off">
    <div>
      <h1>تغییر رمز عبور</h1>
      <?php if ($forced): ?>
        <p class="muted">اولین ورود است؛ لطفاً یک رمز جدید (حداقل ۶ کاراکتر) انتخاب کنید.</p>
      <?php else: ?>
        <p class="muted">برای امنیت حساب، لطفاً رمز جدید خود را وارد کنید.</p>
      <?php endif; ?>
    </div>
    <?php if (!$forced): ?>
    <div>
      <label class="label">رمز فعلی</label>
      <input class="input" type="password" name="current_password" required dir="ltr" autocomplete="current-password">
    </div>
    <?php endif; ?>
    <div>
      <label class="label">رمز جدید</label>
      <input class="input" type="password" name="new_password" required minlength="6" dir="ltr" autocomplete="new-password">
    </div>
    <div>
      <label class="label">تکرار رمز جدید</label>
      <input class="input" type="password" name="new_password_confirm" required minlength="6" dir="ltr" autocomplete="new-password">
    </div>
    <button class="btn btn-primary" type="submit">ذخیره رمز جدید</button>
  </form>
</div>
<?php
$content = ob_get_clean();
require __DIR__ . '/../includes/layout.php';
