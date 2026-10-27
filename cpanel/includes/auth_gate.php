<?php
declare(strict_types=1);

/** کارت ورود / ثبت‌نام با تیغه مورب */
/** @var string $authMode login|register */

$authMode = (($authMode ?? 'login') === 'register') ? 'register' : 'login';
$askedDoctor = (($_GET['role'] ?? '') === 'DOCTOR');
$role = 'PATIENT';
$authNext = (string) (safe_next_path((string) ($_GET['next'] ?? '')) ?? '');
$loginHref = url('/login') . ($authNext !== '' ? ('?next=' . rawurlencode($authNext)) : '');
$registerHref = url('/register') . ($authNext !== '' ? ('?next=' . rawurlencode($authNext)) : '');
$registerRoleHref = url('/register') . '?role=';
// ورود با پیامک پیش‌فرض است؛ رمز عبور با method=password
$smsLogin = (($_GET['method'] ?? 'sms') !== 'password');
$passwordLoginHref = $loginHref . (str_contains($loginHref, '?') ? '&' : '?') . 'method=password';
$smsLoginHref = $loginHref . (str_contains($loginHref, '?') ? '&' : '?') . 'method=sms';
$otpReady = !empty($_SESSION['login_otp_id']);
$otpPhone = (string) ($_SESSION['login_otp_phone'] ?? '');
$nameDict = isset($pdo) ? build_name_transliterations_client_map($pdo) : [];
?>
<div class="auth-wrap">
  <div class="auth-card" data-auth-card data-mode="<?= e($authMode) ?>" data-login-url="<?= e($smsLoginHref) ?>" data-register-url="<?= e($registerHref) ?>">
    <section class="auth-pane<?= $authMode === 'login' ? ' is-on' : '' ?>" data-auth-pane="login"<?= $authMode === 'login' ? '' : ' inert' ?>>
      <nav aria-label="روش ورود" style="display:flex;gap:12px;justify-content:center;padding:20px 12px 0;position:relative;z-index:2">
        <a class="btn<?= !$smsLogin ? ' btn-primary' : '' ?>" href="<?= e($passwordLoginHref) ?>"<?= !$smsLogin ? ' aria-current="page"' : '' ?>>ورود با رمز عبور</a>
        <a class="btn<?= $smsLogin ? ' btn-primary' : '' ?>" href="<?= e($smsLoginHref) ?>"<?= $smsLogin ? ' aria-current="page"' : '' ?>>ورود با پیامک</a>
      </nav>
      <?php if (!$smsLogin): ?>
      <form class="auth-form" method="post" action="<?= e($passwordLoginHref) ?>">
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
        <p class="auth-forgot">
          <a href="<?= e(url('/forgot-password')) ?>">رمز را فراموش کرده‌اید؟</a>
        </p>
        <button class="auth-submit" type="submit">ورود</button>
        <p class="auth-switch">حساب ندارید؟ <a href="<?= e($registerHref) ?>" data-auth-switch="register">ثبت‌نام</a></p>
      </form>
      <?php else: ?>
        <h1 class="auth-form-title" style="text-align:center;margin-top:20px">ورود با پیامک</h1>
        <p class="auth-hint"><?= $otpReady
          ? 'کد ۶ رقمی ارسال‌شده را وارد کنید. در صورت نیاز می‌توانید دوباره کد بگیرید.'
          : 'شماره موبایل ثبت‌شده در حساب را وارد کنید. ارسال کد برای شماره‌های ایران است.' ?></p>
        <form class="auth-form" method="post" action="<?= e(url('/login/otp')) ?>" data-otp-phone-form data-otp-busy-label="در حال ارسال…" onsubmit="return typeof window.manaAuthOtpBusy!=='function'||window.manaAuthOtpBusy(this)">
          <?= csrf_field() ?>
          <input type="hidden" name="next" value="<?= e($authNext) ?>">
          <label class="auth-line">موبایل<input name="phone" required dir="ltr" inputmode="tel" autocomplete="tel" value="<?= e($otpPhone) ?>"<?= $otpReady ? ' aria-describedby="otp-code-step"' : '' ?>></label>
          <button class="auth-submit" type="submit"><?= $otpReady ? 'ارسال مجدد کد' : 'دریافت کد ورود' ?></button>
        </form>
        <form class="auth-form<?= $otpReady ? '' : ' is-otp-pending' ?>" method="post" action="<?= e($smsLoginHref) ?>" data-otp-code-form data-otp-busy-label="در حال تأیید…" onsubmit="return typeof window.manaAuthOtpBusy!=='function'||window.manaAuthOtpBusy(this)"<?= $otpReady ? '' : ' inert aria-disabled="true"' ?> id="otp-code-step">
          <?= csrf_field() ?>
          <input type="hidden" name="next" value="<?= e($authNext) ?>">
          <input type="hidden" name="login_method" value="otp">
          <label class="auth-line">کد ۶ رقمی<input name="otp_code" dir="ltr" inputmode="numeric" autocomplete="one-time-code" maxlength="6"<?= $otpReady ? ' required autofocus' : ' disabled tabindex="-1"' ?>></label>
          <button class="auth-submit" type="submit"<?= $otpReady ? '' : ' disabled' ?>>تأیید کد و ورود</button>
        </form>
        <p class="auth-switch">حساب ندارید؟ <a href="<?= e($registerHref) ?>" data-auth-switch="register">ثبت‌نام</a></p>
      <?php endif; ?>
    </section>

    <section class="auth-pane<?= $authMode === 'register' ? ' is-on' : '' ?>" data-auth-pane="register"<?= $authMode === 'register' ? '' : ' inert' ?>>
      <form class="auth-form auth-form-register" method="post" action="<?= e(url('/register')) ?>" id="register-form">
        <?= csrf_field() ?>
        <input type="hidden" name="next" value="<?= e($authNext) ?>">
        <input type="hidden" name="role" value="PATIENT">
        <input class="footer-consult-hp" type="text" name="mana_hp" tabindex="-1" autocomplete="off" aria-hidden="true">
        <h1 class="auth-form-title">ثبت‌نام</h1>
        <?php if ($askedDoctor): ?>
          <p class="auth-hint">ثبت‌نام درمانگر برداشته شده است. برای همکاری <a href="<?= e(url('/collaborate')) ?>">فرم درخواست همکاری</a> را بفرستید. این فرم فقط برای مراجعه‌کننده است.</p>
        <?php endif; ?>
        <div class="auth-grid">
          <label class="auth-line">
            <span>موبایل</span>
            <input name="phone" id="phone" required dir="ltr" inputmode="tel" autocomplete="tel">
          </label>
        </div>
        <div class="auth-grid">
          <label class="auth-line">
            <span>نام</span>
            <input class="input-rtl" name="first_name" id="first_name" required dir="rtl" autocomplete="given-name">
          </label>
          <label class="auth-line">
            <span>نام (انگلیسی)</span>
            <input name="name_en" id="name_en" required dir="ltr" lang="en" autocomplete="off">
          </label>
          <label class="auth-line">
            <span>نام خانوادگی</span>
            <input class="input-rtl" name="last_name" id="last_name" required dir="rtl" autocomplete="family-name">
          </label>
          <label class="auth-line">
            <span>نام خانوادگی (انگلیسی)</span>
            <input name="surname" id="surname" required dir="ltr" lang="en" autocomplete="off">
          </label>
        </div>
        <label class="auth-line">
          <span>جنسیت</span>
          <select name="gender" required>
            <option value="" selected disabled>انتخاب کنید</option>
            <option value="male">مرد</option>
            <option value="female">زن</option>
          </select>
        </label>
        <div class="auth-grid">
          <label class="auth-line">
            <span>نام کاربری</span>
            <input name="username" id="username" required dir="ltr" readonly tabindex="-1" placeholder="با وارد کردن نام، به‌صورت خودکار ساخته می‌شود.">
          </label>
          <label class="auth-line">
            <span>ایمیل</span>
            <input name="email" id="email" type="email" required dir="ltr" autocomplete="email" inputmode="email" placeholder="ایمیل واقعی برای خوش‌آمد و بازیابی رمز لازم است.">
          </label>
        </div>
        <div class="auth-grid">
          <?= password_field_html('password', 'password', [
              'label' => 'رمز عبور',
              'autocomplete' => 'new-password',
              'minlength' => password_min_length(),
              'rules' => false,
              'placeholder' => '',
              'suggest' => true,
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
        <div class="auth-actions">
          <button class="auth-submit" type="submit" name="submit_register" value="1">ایجاد حساب</button>
          <a class="auth-submit auth-submit-secondary" href="<?= e($loginHref) ?>" data-auth-switch="login">قبلاً ثبت‌نام کرده‌اید؟</a>
        </div>
      </form>
    </section>

    <div class="auth-band" aria-hidden="true">
      <span class="auth-band-edge auth-band-lead"></span>
      <span class="auth-band-edge auth-band-trail"></span>
      <div class="auth-band-inner">
        <div class="auth-band-page<?= $authMode === 'login' ? ' is-on' : '' ?>" data-band="login">
          <div class="auth-band-copy">
            <p class="auth-kicker">مانا کلینیک</p>
            <h2 class="auth-welcome">خوش آمدید</h2>
          </div>
        </div>
        <div class="auth-band-page<?= $authMode === 'register' ? ' is-on' : '' ?>" data-band="register">
          <div class="auth-band-copy">
            <p class="auth-kicker">مانا کلینیک</p>
            <h2 class="auth-welcome">بیا یه حال خوب را با هم تجربه کنیم</h2>
          </div>
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
window.manaAuthOtpBusy = function (form) {
  if (!form || form.getAttribute('data-otp-busy') === '1') return false;
  form.setAttribute('data-otp-busy', '1');
  var btn = form.querySelector('button[type="submit"]');
  if (btn) {
    var label = form.getAttribute('data-otp-busy-label') || 'لطفاً صبر کنید…';
    btn.textContent = label;
    btn.setAttribute('aria-busy', 'true');
    btn.classList.add('is-busy');
    btn.style.pointerEvents = 'none';
    // disabled را بعد از شروع ارسال می‌گذاریم تا بعضی مرورگرها فرم را قطع نکنند
    window.setTimeout(function () { btn.disabled = true; }, 0);
  }
  return true;
};
</script>
<?php
$GLOBALS['pageBodyClass'] = trim(($GLOBALS['pageBodyClass'] ?? '') . ' is-auth');
$GLOBALS['pageScripts'] = ($GLOBALS['pageScripts'] ?? '')
    . '<script src="' . e(url('/assets/js/name-transliterate.js')) . '?v=20260914a"></script>'
    . '<script src="' . e(url('/assets/js/auth-gate.js')) . '?v=20261028b"></script>';
