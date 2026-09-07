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
    <?= csrf_field() ?>
    <div>
      <h1>ورود به مانا کلینیک</h1>
      <p class="muted">حساب ندارید؟ <a href="<?= e(url('/register')) ?>" style="color:var(--primary);font-weight:600">ثبت‌نام</a></p>
    </div>
    <input type="hidden" name="next" value="<?= e((string) (safe_next_path((string) ($_GET['next'] ?? '')) ?? '')) ?>">
    <div>
      <label class="label" for="username">نام کاربری</label>
      <input class="input" id="username" name="username" type="text" required dir="ltr" autocomplete="username">
    </div>
    <?= password_field_html('password', 'password', [
        'label' => 'رمز عبور',
        'autocomplete' => 'current-password',
        'rules' => false,
    ]) ?>
    <button class="btn btn-primary" type="submit">ورود</button>
  </form>
</div>
<?php
$content = ob_get_clean();
require __DIR__ . '/../includes/layout.php';
