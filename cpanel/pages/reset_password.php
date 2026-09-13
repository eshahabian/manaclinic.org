<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/mail.php';

if (current_user()) {
    redirect(panel_href_for(current_user()) ?: '/');
}

$token = trim((string) ($_GET['token'] ?? ''));
ensure_mail_schema($pdo);
$row = password_reset_find_valid($pdo, $token);

$pageTitle = 'تعیین رمز جدید';
$pageRobots = 'noindex,nofollow';
$GLOBALS['pageTitle'] = $pageTitle;
$GLOBALS['pageRobots'] = $pageRobots;
$GLOBALS['pageBodyClass'] = trim(($GLOBALS['pageBodyClass'] ?? '') . ' is-auth');

ob_start();
?>
<div class="auth-wrap">
  <?php if (!$row): ?>
    <div class="panel auth-box form-stack" style="max-width:24rem;width:100%">
      <h1>لینک نامعتبر</h1>
      <p class="muted" style="line-height:1.8">این لینک منقضی شده یا قبلاً استفاده شده است.</p>
      <p class="auth-switch" style="margin:0">
        <a href="<?= e(url('/forgot-password')) ?>">درخواست لینک جدید</a>
        ·
        <a href="<?= e(url('/login')) ?>">ورود</a>
      </p>
    </div>
  <?php else: ?>
    <form class="panel auth-box form-stack" method="post" action="<?= e(url('/reset-password')) ?>" autocomplete="off" style="max-width:24rem;width:100%">
      <?= csrf_field() ?>
      <input type="hidden" name="token" value="<?= e($token) ?>">
      <div>
        <h1>تعیین رمز جدید</h1>
        <p class="muted" style="margin:.4rem 0 0;line-height:1.8">
          حساب: <strong><?= e((string) $row['name']) ?></strong>
          <?php if (!empty($row['username'])): ?>
            <span class="muted" dir="ltr">(<?= e((string) $row['username']) ?>)</span>
          <?php endif; ?>
        </p>
      </div>
      <?= password_field_html('new_password', 'new_password', [
          'label' => 'رمز جدید',
          'autocomplete' => 'new-password',
          'minlength' => password_min_length(),
          'rules' => false,
      ]) ?>
      <?= password_field_html('new_password_confirm', 'new_password_confirm', [
          'label' => 'تکرار رمز جدید',
          'autocomplete' => 'new-password',
          'minlength' => password_min_length(),
          'confirm' => true,
          'pair' => 'new_password',
      ]) ?>
      <p class="muted" style="font-size:.85rem;margin:0">حداقل <?= e(to_fa_digits((string) password_min_length())) ?> کاراکتر، با حروف و اعداد انگلیسی.</p>
      <button class="btn btn-primary" type="submit">ذخیره رمز جدید</button>
    </form>
  <?php endif; ?>
</div>
<?php
$content = ob_get_clean();
require __DIR__ . '/../includes/layout.php';
