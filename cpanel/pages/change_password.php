<?php
declare(strict_types=1);

$user = require_login();
$forced = !empty($user['must_change_password']);
$pageTitle = 'تغییر رمز عبور';
ob_start();
?>
<div class="auth-wrap">
  <form class="panel auth-box form-stack" method="post" action="<?= e(url('/change-password')) ?>" autocomplete="off">
    <?= csrf_field() ?>
    <div>
      <h1>تغییر رمز عبور</h1>
      <?php if ($forced): ?>
        <p class="muted">اولین ورود است؛ لطفاً یک رمز جدید انتخاب کنید.</p>
      <?php else: ?>
        <p class="muted">برای امنیت حساب، لطفاً رمز جدید خود را وارد کنید.</p>
      <?php endif; ?>
    </div>
    <?php if (!$forced): ?>
      <?= password_field_html('current_password', 'current_password', [
          'label' => 'رمز فعلی',
          'autocomplete' => 'current-password',
          'rules' => false,
      ]) ?>
    <?php endif; ?>
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
$content = ob_get_clean();
require __DIR__ . '/../includes/layout.php';
