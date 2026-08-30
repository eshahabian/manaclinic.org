<?php
declare(strict_types=1);
if (current_user()) {
    redirect('/dashboard');
}
$pageTitle = 'ثبت‌نام';
$role = (($_GET['role'] ?? '') === 'DOCTOR') ? 'DOCTOR' : 'PATIENT';
$takenUsernames = $pdo->query("SELECT username FROM users WHERE username IS NOT NULL AND username <> ''")->fetchAll(PDO::FETCH_COLUMN);
ob_start();
?>
<div class="auth-wrap">
  <form class="panel auth-box form-stack" method="post" action="<?= e(url('/register')) ?>" id="register-form" style="width:min(520px,100%)">
    <div>
      <h1>ثبت‌نام</h1>
      <p class="muted">قبلاً ثبت‌نام کرده‌اید؟ <a href="<?= e(url('/login')) ?>" style="color:var(--primary);font-weight:600">ورود</a></p>
    </div>

    <div>
      <label class="label" for="role">نوع حساب</label>
      <select class="input" id="role" name="role" required onchange="window.location.href='<?= e(url('/register')) ?>?role=' + this.value">
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
        <input class="input" name="first_name" id="first_name" required dir="auto" autocomplete="given-name" placeholder="نام">
      </div>
      <div>
        <label class="label" for="name_en">name</label>
        <input class="input" name="name_en" id="name_en" dir="ltr" lang="en" autocomplete="off" placeholder="name">
      </div>
      <div>
        <label class="label" for="last_name">نام خانوادگی</label>
        <input class="input" name="last_name" id="last_name" required dir="auto" autocomplete="family-name" placeholder="نام خانوادگی">
      </div>
      <div>
        <label class="label" for="surname">surname</label>
        <input class="input" name="surname" id="surname" dir="ltr" lang="en" autocomplete="off" placeholder="surname">
      </div>
    </div>

    <div>
      <label class="label" for="username">نام کاربری</label>
      <div style="display:flex;gap:.5rem;align-items:stretch">
        <input class="input" name="username" id="username" required dir="ltr" autocomplete="username" pattern="[A-Za-z0-9._-]{3,32}" title="فقط حروف انگلیسی، عدد و ._- (۳ تا ۳۲ کاراکتر)" placeholder="حروف انگلیسی، عدد و ._- " style="flex:1">
        <button type="button" class="btn btn-outline" id="suggest-username" title="پیشنهاد از روی name و surname">پیشنهاد</button>
      </div>
      <p class="muted" id="username-hint" style="margin:.4rem 0 0;font-size:.8rem;line-height:1.6"></p>
    </div>

    <div>
      <label class="label" for="phone">موبایل</label>
      <input class="input" name="phone" id="phone" dir="ltr" placeholder="09...">
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
<script>
(function(){
  var takenUsernames = ' . json_encode(array_values(array_map(static fn($u) => mb_strtolower((string) $u), $takenUsernames)), JSON_UNESCAPED_UNICODE) . ';
  var takenSet = {};
  takenUsernames.forEach(function(u){ if (u) takenSet[u] = true; });

  var firstNameEl = document.getElementById("first_name");
  var lastNameEl = document.getElementById("last_name");
  var nameEnEl = document.getElementById("name_en");
  var surnameEl = document.getElementById("surname");
  var userEl = document.getElementById("username");
  var passEl = document.getElementById("password");
  var passConfirmEl = document.getElementById("password_confirm");
  var suggestBtn = document.getElementById("suggest-username");
  var usernameHint = document.getElementById("username-hint");
  var usernameTouched = false;

  function latinWord(value) {
    return String(value || "").toLowerCase().replace(/[^a-z]/g, "");
  }

  function baseUsernameFromParts() {
    var first = latinWord(nameEnEl.value) || latinWord(firstNameEl.value);
    var last = latinWord(surnameEl.value) || latinWord(lastNameEl.value);
    if (!first && !last) return "";
    if (!last) return first.slice(0, 32);
    if (!first) return last.slice(0, 32);
    return (first.charAt(0) + last).slice(0, 32);
  }

  function uniqueUsername(base) {
    if (!base || base.length < 3) return "";
    var candidate = base;
    var n = 1;
    while (takenSet[candidate]) {
      var suffix = String(n++);
      candidate = (base.slice(0, Math.max(3, 32 - suffix.length)) + suffix);
      if (n > 999) return "";
    }
    return candidate;
  }

  function applySuggestion(force) {
    if (!force && usernameTouched) return;
    var base = baseUsernameFromParts();
    if (!base) {
      if (force || !userEl.value.trim()) {
        if (!usernameTouched) userEl.value = "";
        usernameHint.textContent = "با تایپ name و surname پیشنهاد نام کاربری همین‌جا می‌آید.";
      }
      return;
    }
    var suggested = uniqueUsername(base);
    if (!suggested) {
      usernameHint.textContent = "پیشنهاد معتبری پیدا نشد؛ نام کاربری را دستی وارد کنید.";
      return;
    }
    if (force || !usernameTouched) {
      userEl.value = suggested;
    }
    usernameHint.textContent = suggested === base
      ? "پیشنهاد: " + suggested
      : "پیشنهاد (بدون تکرار): " + suggested;
  }

  userEl.addEventListener("input", function(){ usernameTouched = true; });
  [firstNameEl, lastNameEl, nameEnEl, surnameEl].forEach(function(el){
    el.addEventListener("input", function(){ applySuggestion(false); });
    el.addEventListener("blur", function(){ applySuggestion(false); });
  });
  suggestBtn.addEventListener("click", function(){
    usernameTouched = false;
    applySuggestion(true);
  });

  document.getElementById("register-form").addEventListener("submit", function(e){
    var user = userEl.value.trim().toLowerCase();
    if (!/^[a-z0-9._-]{3,32}$/.test(user)) {
      e.preventDefault();
      alert("نام کاربری باید ۳ تا ۳۲ کاراکتر انگلیسی باشد.");
      userEl.focus();
      return;
    }
    if (takenSet[user]) {
      e.preventDefault();
      alert("این نام کاربری قبلاً ثبت شده است.");
      userEl.focus();
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
