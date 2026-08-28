<?php
declare(strict_types=1);
if (current_user()) {
    redirect('/dashboard');
}
$pageTitle = 'ثبت‌نام';
ob_start();
?>
<div class="auth-wrap">
  <form class="panel auth-box form-stack" method="post" action="<?= e(url('/register')) ?>">
    <div>
      <h1>ثبت‌نام بیمار</h1>
      <p class="muted">قبلاً ثبت‌نام کرده‌اید؟ <a href="<?= e(url('/login')) ?>" style="color:var(--primary);font-weight:600">ورود</a></p>
    </div>
    <div>
      <label class="label">نام و نام خانوادگی</label>
      <input class="input" name="name" required>
    </div>
    <div>
      <label class="label">نام کاربری</label>
      <input class="input" name="username" required dir="ltr" autocomplete="username" pattern="[A-Za-z0-9._-]{3,32}" title="فقط حروف انگلیسی، عدد و ._- (۳ تا ۳۲ کاراکتر)">
    </div>
    <div>
      <label class="label">موبایل</label>
      <input class="input" name="phone" dir="ltr" placeholder="0912...">
    </div>
    <div>
      <label class="label">رمز عبور</label>
      <input class="input" name="password" type="password" required minlength="6" dir="ltr">
    </div>
    <button class="btn btn-primary" type="submit">ایجاد حساب</button>
  </form>
</div>
<?php
$content = ob_get_clean();
require __DIR__ . '/../includes/layout.php';
