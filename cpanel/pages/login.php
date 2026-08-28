<?php
declare(strict_types=1);
if (current_user()) {
    $href = panel_href_for(current_user()) ?: '/';
    redirect($href);
}
$pageTitle = 'ورود';
ob_start();
?>
<div class="auth-wrap">
  <form class="panel auth-box form-stack" method="post" action="<?= e(url('/login')) ?>">
    <div>
      <h1>ورود به مانا کلینیک</h1>
      <p class="muted">حساب ندارید؟ <a href="<?= e(url('/register')) ?>" style="color:var(--primary);font-weight:600">ثبت‌نام بیمار</a></p>
    </div>
    <input type="hidden" name="next" value="<?= e((string)($_GET['next'] ?? '')) ?>">
    <div>
      <label class="label" for="email">ایمیل</label>
      <input class="input" id="email" name="email" type="email" required dir="ltr">
    </div>
    <div>
      <label class="label" for="password">رمز عبور</label>
      <input class="input" id="password" name="password" type="password" required dir="ltr">
    </div>
    <button class="btn btn-primary" type="submit">ورود</button>
    <p class="muted" style="font-size:.75rem;background:var(--bg-soft);padding:.75rem;border-radius:.65rem;line-height:1.7">
      نمونه: admin@ravansara.ir / admin123 — doctor@ravansara.ir / doctor123 — patient@ravansara.ir / patient123 — secretary@manaclinic.org
    </p>
  </form>
</div>
<?php
$content = ob_get_clean();
require __DIR__ . '/../includes/layout.php';
