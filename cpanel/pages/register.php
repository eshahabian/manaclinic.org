<?php
declare(strict_types=1);
if (current_user()) {
    redirect('/dashboard');
}
$pageTitle = 'ثبت‌نام';
$role = (($_GET['role'] ?? '') === 'DOCTOR') ? 'DOCTOR' : 'PATIENT';
$registerNext = (string) (safe_next_path((string) ($_GET['next'] ?? '')) ?? '');
$nameDict = build_name_transliterations_client_map($pdo);
ob_start();
?>
<div class="auth-wrap">
  <form class="panel auth-box form-stack" method="post" action="<?= e(url('/register')) ?>" id="register-form" style="width:min(520px,100%)">
    <?= csrf_field() ?>
    <div>
      <h1>ثبت‌نام</h1>
      <p class="muted">قبلاً ثبت‌نام کرده‌اید؟ <a href="<?= e(url('/login') . ($registerNext !== '' ? ('?next=' . rawurlencode($registerNext)) : '')) ?>" style="color:var(--primary);font-weight:600">ورود</a></p>
    </div>
    <input type="hidden" name="next" value="<?= e($registerNext) ?>">

    <div>
      <label class="label" for="role">نوع حساب</label>
      <select class="input" id="role" name="role" required onchange="window.location.href='<?= e(url('/register')) ?>?role=' + encodeURIComponent(this.value)<?= $registerNext !== '' ? " + '&next=' + encodeURIComponent(" . json_encode($registerNext, JSON_UNESCAPED_UNICODE) . ")" : '' ?>">
        <option value="PATIENT" <?= $role === 'PATIENT' ? 'selected' : '' ?>>مراجعه‌کننده</option>
        <option value="DOCTOR" <?= $role === 'DOCTOR' ? 'selected' : '' ?>>درمانگر</option>
      </select>
      <?php if ($role === 'DOCTOR'): ?>
        <p class="muted" style="font-size:.75rem;line-height:1.7;margin:.5rem 0 0">حساب درمانگر پس از بررسی و تأیید مدیر سایت فعال می‌شود.</p>
      <?php endif; ?>
    </div>

    <div class="grid-2">
      <div>
        <label class="label" for="first_name">نام</label>
        <input class="input input-rtl" name="first_name" id="first_name" required dir="rtl" autocomplete="given-name" placeholder="نام">
      </div>
      <div>
        <label class="label label-ltr" for="name_en">نام (انگلیسی)</label>
        <input class="input" name="name_en" id="name_en" required dir="ltr" lang="en" autocomplete="off" placeholder="name">
        <p class="muted" style="font-size:.75rem;margin:.35rem 0 0">از دیتابیس نام‌ها و جستجوی آنلاین پیشنهاد می‌شود؛ در صورت نیاز ویرایش کنید.</p>
      </div>
      <div>
        <label class="label" for="last_name">نام خانوادگی</label>
        <input class="input input-rtl" name="last_name" id="last_name" required dir="rtl" autocomplete="family-name" placeholder="نام خانوادگی">
      </div>
      <div>
        <label class="label label-ltr" for="surname">نام خانوادگی (انگلیسی)</label>
        <input class="input" name="surname" id="surname" required dir="ltr" lang="en" autocomplete="off" placeholder="surname">
        <p class="muted" style="font-size:.75rem;margin:.35rem 0 0">از دیتابیس نام‌ها و جستجوی آنلاین پیشنهاد می‌شود؛ در صورت نیاز ویرایش کنید.</p>
      </div>
    </div>

    <div>
      <label class="label" for="username">نام کاربری</label>
      <input class="input" name="username" id="username" required dir="ltr" readonly tabindex="-1" style="background:var(--bg-soft);cursor:default">
      <p class="muted" id="username-hint" style="margin:.4rem 0 0;font-size:.8rem;line-height:1.6">با وارد کردن نام، به‌صورت خودکار ساخته می‌شود.</p>
    </div>

    <div>
      <label class="label" for="phone">موبایل</label>
      <input class="input" name="phone" id="phone" required dir="ltr" inputmode="tel" autocomplete="tel" placeholder="مثلاً 0912... یا +1..." title="شماره ایران یا بین‌المللی">
    </div>

    <?php if ($role === 'DOCTOR'): ?>
    <div>
      <label class="label" for="specialty">تخصص</label>
      <input class="input" name="specialty" id="specialty" required placeholder="مثلاً روان‌درمانی شناختی-رفتاری">
    </div>
    <?php endif; ?>

    <div class="grid-2">
      <div>
        <label class="label" for="password">رمز عبور</label>
        <input class="input" name="password" id="password" type="password" required minlength="6" dir="ltr" autocomplete="new-password" placeholder="حداقل ۶ کاراکتر">
      </div>
      <div>
        <label class="label" for="password_confirm">تکرار رمز عبور</label>
        <input class="input" name="password_confirm" id="password_confirm" type="password" required minlength="6" dir="ltr" autocomplete="new-password" placeholder="تکرار رمز">
      </div>
    </div>

    <button class="btn btn-primary" type="submit" name="submit_register" value="1">
      <?= $role === 'DOCTOR' ? 'ارسال درخواست' : 'ایجاد حساب' ?>
    </button>
  </form>
</div>
<?php
$content = ob_get_clean();

$pageScripts = '
<script src="' . e(url('/assets/js/name-transliterate.js')) . '?v=20260906p"></script>
<script>
(function(){
  var firstNameEl = document.getElementById("first_name");
  var lastNameEl = document.getElementById("last_name");
  var nameEnEl = document.getElementById("name_en");
  var surnameEl = document.getElementById("surname");
  var userEl = document.getElementById("username");
  var passEl = document.getElementById("password");
  var passConfirmEl = document.getElementById("password_confirm");

  bindNameTransliteration({
    firstName: firstNameEl,
    lastName: lastNameEl,
    nameEn: nameEnEl,
    surname: surnameEl,
    username: userEl,
    usernameHint: document.getElementById("username-hint"),
    transliterateUrl: ' . json_encode(url('/api/transliterate-name')) . ',
    nameDict: ' . json_encode($nameDict, JSON_UNESCAPED_UNICODE) . ',
    usernameReadonly: true,
    emptyHint: "با وارد کردن نام، به‌صورت خودکار ساخته می‌شود."
  });

  document.getElementById("register-form").addEventListener("submit", function(e){
    var phoneEl = document.getElementById("phone");
    if (!nameEnEl.value.trim() || !surnameEl.value.trim()) {
      e.preventDefault();
      alert("فیلدهای انگلیسی نام و نام خانوادگی الزامی هستند.");
      (!nameEnEl.value.trim() ? nameEnEl : surnameEl).focus();
      return;
    }
    if (phoneEl && !window.manaIsValidPhone(phoneEl.value.trim())) {
      e.preventDefault();
      alert("موبایل الزامی است. شماره ایران یا بین‌المللی معتبر وارد کنید.");
      phoneEl.focus();
      return;
    }
    var user = userEl.value.trim().toLowerCase();
    if (!/^[a-z0-9._-]{3,32}$/.test(user)) {
      e.preventDefault();
      alert("نام کاربری معتبر ساخته نشد. فیلدهای انگلیسی را بررسی کنید.");
      nameEnEl.focus();
      return;
    }
    if (passEl.value.length < 6) {
      e.preventDefault();
      alert("رمز عبور حداقل ۶ کاراکتر باشد.");
      passEl.focus();
      return;
    }
    if (passEl.value !== passConfirmEl.value) {
      e.preventDefault();
      alert("رمز عبور و تکرار آن یکسان نیست.");
      passConfirmEl.focus();
    }
  });
})();
</script>
';

require __DIR__ . '/../includes/layout.php';
