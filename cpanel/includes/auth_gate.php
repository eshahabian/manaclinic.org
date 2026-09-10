<?php
declare(strict_types=1);

/** کارت ورود / ثبت‌نام با تیغه مورب */
/** @var string $authMode login|register */

$authMode = (($authMode ?? 'login') === 'register') ? 'register' : 'login';
$role = (($_GET['role'] ?? '') === 'DOCTOR') ? 'DOCTOR' : 'PATIENT';
$authNext = (string) (safe_next_path((string) ($_GET['next'] ?? '')) ?? '');
$loginHref = url('/login') . ($authNext !== '' ? ('?next=' . rawurlencode($authNext)) : '');
$registerHref = url('/register') . ($authNext !== '' ? ('?next=' . rawurlencode($authNext)) : '');
$registerRoleHref = url('/register') . '?role=';
$nameDict = isset($pdo) ? build_name_transliterations_client_map($pdo) : [];
?>
<div class="auth-wrap">
  <div class="auth-card" data-auth-card data-mode="<?= e($authMode) ?>" data-login-url="<?= e($loginHref) ?>" data-register-url="<?= e($registerHref) ?>">
    <section class="auth-pane<?= $authMode === 'login' ? ' is-on' : '' ?>" data-auth-pane="login"<?= $authMode === 'login' ? '' : ' inert' ?>>
      <form class="auth-form" method="post" action="<?= e(url('/login')) ?>">
        <?= csrf_field() ?>
        <input type="hidden" name="next" value="<?= e($authNext) ?>">
        <h1 class="auth-form-title">ورود</h1>
        <label class="auth-line">
          <span>نام کاربری</span>
          <span class="auth-line-row">
            <input id="login-username" name="username" type="text" required dir="ltr" autocomplete="username">
            <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.7" aria-hidden="true"><path stroke-linecap="round" d="M12 12a4 4 0 1 0-4-4 4 4 0 0 0 4 4zm0 2c-3.3 0-6 1.7-6 3.5V19h12v-1.5c0-1.8-2.7-3.5-6-3.5z"/></svg>
          </span>
        </label>
        <div class="auth-line" data-password-field>
          <span>رمز عبور</span>
          <span class="auth-line-row">
            <input id="login-password" name="password" type="password" required dir="ltr" lang="en" autocomplete="current-password" data-password-input>
            <button type="button" class="password-toggle" data-password-toggle aria-label="نمایش رمز" title="نمایش رمز">
              <svg class="password-toggle-show" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M2.4 12S6 5.8 12 5.8 21.6 12 21.6 12 18 18.2 12 18.2 2.4 12 2.4 12Z"/><circle cx="12" cy="12" r="3.1"/></svg>
              <svg class="password-toggle-hide" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" aria-hidden="true"><path stroke-linecap="round" d="M3 3l18 18M10.6 10.6A3.1 3.1 0 0 0 12 15.1a3.1 3.1 0 0 0 3.1-3.1M6.5 6.7C4.4 8.2 2.8 10.4 2.4 12c0 0 3.6 6.2 9.6 6.2 1.7 0 3.2-.4 4.5-1M17.5 8.2C19.4 9.6 20.8 11.3 21.6 12c0 0-1.3 2.2-3.6 4"/></svg>
            </button>
          </span>
        </div>
        <button class="auth-submit" type="submit">ورود</button>
        <p class="auth-switch">حساب ندارید؟ <a href="<?= e($registerHref) ?>" data-auth-switch="register">ثبت‌نام</a></p>
      </form>
    </section>

    <section class="auth-pane<?= $authMode === 'register' ? ' is-on' : '' ?>" data-auth-pane="register"<?= $authMode === 'register' ? '' : ' inert' ?>>
      <form class="auth-form auth-form-register" method="post" action="<?= e(url('/register')) ?>" id="register-form">
        <?= csrf_field() ?>
        <input type="hidden" name="next" value="<?= e($authNext) ?>">
        <h1 class="auth-form-title"><?= $role === 'DOCTOR' ? 'درخواست درمانگر' : 'ثبت‌نام' ?></h1>
        <label class="auth-line">
          <span>نوع حساب</span>
          <select class="auth-select" id="role" name="role" required>
            <option value="PATIENT" <?= $role === 'PATIENT' ? 'selected' : '' ?>>مراجعه‌کننده</option>
            <option value="DOCTOR" <?= $role === 'DOCTOR' ? 'selected' : '' ?>>درمانگر</option>
          </select>
        </label>
        <?php if ($role === 'DOCTOR'): ?>
          <p class="auth-hint">فقط نام، نام خانوادگی و نام کاربری کافی است. بقیه اطلاعات بعد از تأیید مدیر تکمیل می‌شود.</p>
        <?php endif; ?>
        <div class="auth-grid">
          <label class="auth-line">
            <span>نام</span>
            <input class="input-rtl" name="first_name" id="first_name" required dir="rtl" autocomplete="given-name">
          </label>
          <?php if ($role !== 'DOCTOR'): ?>
          <label class="auth-line">
            <span>نام (انگلیسی)</span>
            <input name="name_en" id="name_en" required dir="ltr" lang="en" autocomplete="off">
          </label>
          <?php endif; ?>
          <label class="auth-line">
            <span>نام خانوادگی</span>
            <input class="input-rtl" name="last_name" id="last_name" required dir="rtl" autocomplete="family-name">
          </label>
          <?php if ($role !== 'DOCTOR'): ?>
          <label class="auth-line">
            <span>نام خانوادگی (انگلیسی)</span>
            <input name="surname" id="surname" required dir="ltr" lang="en" autocomplete="off">
          </label>
          <?php endif; ?>
        </div>
        <label class="auth-line">
          <span>نام کاربری</span>
          <?php if ($role === 'DOCTOR'): ?>
            <input name="username" id="username" required dir="ltr" autocomplete="username">
            <p class="auth-hint" id="username-hint">با حروف انگلیسی، عدد یا نقطه؛ حداقل ۳ کاراکتر.</p>
          <?php else: ?>
            <input name="username" id="username" required dir="ltr" readonly tabindex="-1">
            <p class="auth-hint" id="username-hint">با وارد کردن نام، به‌صورت خودکار ساخته می‌شود.</p>
          <?php endif; ?>
        </label>
        <?php if ($role !== 'DOCTOR'): ?>
        <label class="auth-line">
          <span>موبایل</span>
          <input name="phone" id="phone" required dir="ltr" inputmode="tel" autocomplete="tel">
        </label>
        <?php endif; ?>
        <div class="auth-grid">
          <?= password_field_html('password', 'password', [
              'label' => 'رمز عبور',
              'autocomplete' => 'new-password',
              'minlength' => password_min_length(),
              'rules' => false,
              'placeholder' => '',
          ]) ?>
          <?= password_field_html('password_confirm', 'password_confirm', [
              'label' => 'تکرار رمز عبور',
              'autocomplete' => 'new-password',
              'minlength' => password_min_length(),
              'confirm' => true,
              'pair' => 'password',
              'placeholder' => '',
          ]) ?>
        </div>
        <p class="auth-hint">حداقل <?= e(to_fa_digits((string) password_min_length())) ?> کاراکتر، با حروف و اعداد انگلیسی.</p>
        <button class="auth-submit" type="submit" name="submit_register" value="1">
          <?= $role === 'DOCTOR' ? 'ارسال درخواست' : 'ایجاد حساب' ?>
        </button>
        <p class="auth-switch">قبلاً ثبت‌نام کرده‌اید؟ <a href="<?= e($loginHref) ?>" data-auth-switch="login">ورود</a></p>
      </form>
    </section>

    <div class="auth-band" aria-hidden="true">
      <span class="auth-band-edge auth-band-lead"></span>
      <span class="auth-band-edge auth-band-trail"></span>
      <div class="auth-band-inner">
        <div class="auth-band-page<?= $authMode === 'login' ? ' is-on' : '' ?>" data-band="login">
          <p class="auth-kicker">مانا کلینیک</p>
          <h2 class="auth-welcome">خوش آمدید</h2>
        </div>
        <div class="auth-band-page<?= $authMode === 'register' ? ' is-on' : '' ?>" data-band="register">
          <p class="auth-kicker">مانا کلینیک</p>
          <h2 class="auth-welcome">ثبت‌نام</h2>
        </div>
      </div>
    </div>
  </div>
</div>
<script>
window.ManaAuthGate = {
  registerRoleUrl: <?= json_encode($registerRoleHref, JSON_UNESCAPED_UNICODE) ?>,
  authNext: <?= json_encode($authNext, JSON_UNESCAPED_UNICODE) ?>,
  isDoctor: <?= json_encode($role === 'DOCTOR') ?>,
  transliterateUrl: <?= json_encode(url('/api/transliterate-name')) ?>,
  nameDict: <?= json_encode($nameDict, JSON_UNESCAPED_UNICODE) ?>,
  minPass: <?= (int) password_min_length() ?>
};
</script>
<?php
$GLOBALS['pageHead'] = ($GLOBALS['pageHead'] ?? '') . '<link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,600;1,600&display=swap" rel="stylesheet">';
$GLOBALS['pageBodyClass'] = trim(($GLOBALS['pageBodyClass'] ?? '') . ' is-auth');
$GLOBALS['pageScripts'] = ($GLOBALS['pageScripts'] ?? '')
    . '<script src="' . e(url('/assets/js/name-transliterate.js')) . '?v=20260906p"></script>'
    . '<script src="' . e(url('/assets/js/auth-gate.js')) . '?v=20260910g"></script>';
