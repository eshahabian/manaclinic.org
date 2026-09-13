<?php
declare(strict_types=1);

if (current_user()) {
    redirect(panel_href_for(current_user()) ?: '/');
}

$pageTitle = 'فراموشی رمز عبور';
$pageDescription = 'بازیابی رمز عبور حساب مانا کلینیک از طریق ایمیل.';
$pageCanonical = url('/forgot-password');
$pageRobots = 'noindex,nofollow';
$GLOBALS['pageTitle'] = $pageTitle;
$GLOBALS['pageDescription'] = $pageDescription;
$GLOBALS['pageCanonical'] = $pageCanonical;
$GLOBALS['pageRobots'] = $pageRobots;
$GLOBALS['pageBodyClass'] = trim(($GLOBALS['pageBodyClass'] ?? '') . ' is-auth');

ob_start();
?>
<div class="auth-wrap">
  <form class="panel auth-box form-stack" method="post" action="<?= e(url('/forgot-password')) ?>" autocomplete="off" style="max-width:24rem;width:100%">
    <?= csrf_field() ?>
    <div>
      <h1>فراموشی رمز عبور</h1>
      <p class="muted" style="margin:.4rem 0 0;line-height:1.8">
        نام کاربری یا ایمیل حساب را وارد کنید. اگر حساب با ایمیل واقعی ثبت شده باشد، لینک بازیابی برایتان ارسال می‌شود.
      </p>
    </div>
    <div>
      <label class="label" for="account">نام کاربری یا ایمیل</label>
      <input class="input" id="account" name="account" required dir="ltr" autocomplete="username">
    </div>
    <button class="btn btn-primary" type="submit">ارسال لینک بازیابی</button>
    <p class="auth-switch" style="margin:0">
      <a href="<?= e(url('/login')) ?>">بازگشت به ورود</a>
    </p>
  </form>
</div>
<?php
$content = ob_get_clean();
require __DIR__ . '/../includes/layout.php';
