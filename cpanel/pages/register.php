<?php
declare(strict_types=1);
if (current_user()) {
    redirect('/dashboard');
}
$pageTitle = 'ثبت‌نام';
$role = (($_GET['role'] ?? '') === 'DOCTOR') ? 'DOCTOR' : 'PATIENT';
$takenUsernames = $pdo->query("SELECT username FROM users WHERE username IS NOT NULL AND username <> ''")->fetchAll(PDO::FETCH_COLUMN);
$doctors = $pdo->query("
  SELECT dp.id, u.name, dp.specialty
  FROM doctor_profiles dp
  JOIN users u ON u.id = dp.user_id
  WHERE dp.is_active = 1 AND dp.is_approved = 1
  ORDER BY u.name ASC
")->fetchAll();
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

    <?php if ($role === 'PATIENT'): ?>
    <div>
      <label class="label" for="preferred_doctor_id">درمانگر من</label>
      <select class="input" name="preferred_doctor_id" id="preferred_doctor_id" required>
        <option value="">انتخاب درمانگر</option>
        <?php foreach ($doctors as $d): ?>
          <option value="<?= e($d['id']) ?>"><?= e($d['name']) ?> — <?= e($d['specialty']) ?></option>
        <?php endforeach; ?>
      </select>
      <?php if (!$doctors): ?>
        <p class="muted" style="font-size:.75rem;margin:.4rem 0 0">در حال حاضر درمانگر فعالی برای انتخاب وجود ندارد.</p>
      <?php endif; ?>
    </div>
    <?php endif; ?>

    <div class="grid-2">
      <div>
        <label class="label" for="first_name">نام</label>
        <input class="input input-rtl" name="first_name" id="first_name" required dir="rtl" autocomplete="given-name" placeholder="نام">
      </div>
      <div>
        <label class="label label-ltr" for="name_en">نام (انگلیسی)</label>
        <input class="input" name="name_en" id="name_en" required dir="ltr" lang="en" autocomplete="off" placeholder="name">
        <p class="muted" style="font-size:.75rem;margin:.35rem 0 0">از روی نام فارسی پر می‌شود؛ در صورت نیاز ویرایش کنید.</p>
      </div>
      <div>
        <label class="label" for="last_name">نام خانوادگی</label>
        <input class="input input-rtl" name="last_name" id="last_name" required dir="rtl" autocomplete="family-name" placeholder="نام خانوادگی">
      </div>
      <div>
        <label class="label label-ltr" for="surname">نام خانوادگی (انگلیسی)</label>
        <input class="input" name="surname" id="surname" required dir="ltr" lang="en" autocomplete="off" placeholder="surname">
        <p class="muted" style="font-size:.75rem;margin:.35rem 0 0">از روی نام خانوادگی فارسی پر می‌شود؛ در صورت نیاز ویرایش کنید.</p>
      </div>
    </div>

    <div>
      <label class="label" for="username">نام کاربری</label>
      <input class="input" name="username" id="username" required dir="ltr" readonly tabindex="-1" style="background:var(--bg-soft);cursor:default">
      <p class="muted" id="username-hint" style="margin:.4rem 0 0;font-size:.8rem;line-height:1.6">با وارد کردن نام، به‌صورت خودکار ساخته می‌شود.</p>
    </div>

    <div>
      <label class="label" for="phone">موبایل</label>
      <input class="input" name="phone" id="phone" required dir="ltr" placeholder="09..." pattern="09[0-9]{9}" title="شماره موبایل ۱۱ رقمی با ۰۹">
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

  var faMap = {
    "آ":"a","ا":"a","أ":"a","إ":"a","ب":"b","پ":"p","ت":"t","ث":"s","ج":"j","چ":"ch",
    "ح":"h","خ":"kh","د":"d","ذ":"z","ر":"r","ز":"z","ژ":"zh","س":"s","ش":"sh","ص":"s",
    "ض":"z","ط":"t","ظ":"z","ع":"a","غ":"gh","ف":"f","ق":"gh","ک":"k","ك":"k","گ":"g",
    "ل":"l","م":"m","ن":"n","و":"o","ؤ":"o","ه":"h","ۀ":"e","ة":"e","ی":"i","ي":"i","ئ":"i","ء":""
  };

  function persianToLatin(text) {
    var out = "";
    String(text || "").split("").forEach(function(ch) {
      if (faMap[ch] !== undefined) out += faMap[ch];
      else if (/[a-zA-Z]/.test(ch)) out += ch.toLowerCase();
    });
    return out;
  }

  function latinWord(value) {
    return persianToLatin(value).toLowerCase().replace(/[^a-z]/g, "");
  }

  var firstNameEl = document.getElementById("first_name");
  var lastNameEl = document.getElementById("last_name");
  var nameEnEl = document.getElementById("name_en");
  var surnameEl = document.getElementById("surname");
  var userEl = document.getElementById("username");
  var passEl = document.getElementById("password");
  var passConfirmEl = document.getElementById("password_confirm");
  var usernameHint = document.getElementById("username-hint");
  var nameEnTouched = false;
  var surnameTouched = false;

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

  function fillEnglishFromPersian() {
    if (!nameEnTouched && firstNameEl.value.trim()) {
      nameEnEl.value = persianToLatin(firstNameEl.value.trim());
    }
    if (!surnameTouched && lastNameEl.value.trim()) {
      surnameEl.value = persianToLatin(lastNameEl.value.trim());
    }
  }

  function applyUsername() {
    var base = baseUsernameFromParts();
    if (!base) {
      userEl.value = "";
      usernameHint.textContent = "با وارد کردن نام، به‌صورت خودکار ساخته می‌شود.";
      return;
    }
    var suggested = uniqueUsername(base);
    if (!suggested) {
      usernameHint.textContent = "پیشنهاد معتبری پیدا نشد؛ نام انگلیسی را اصلاح کنید.";
      return;
    }
    userEl.value = suggested;
    usernameHint.textContent = suggested === base
      ? "نام کاربری: " + suggested
      : "نام کاربری (بدون تکرار): " + suggested;
  }

  function onNameInput() {
    fillEnglishFromPersian();
    applyUsername();
  }

  nameEnEl.addEventListener("input", function(){ nameEnTouched = true; applyUsername(); });
  surnameEl.addEventListener("input", function(){ surnameTouched = true; applyUsername(); });
  [firstNameEl, lastNameEl].forEach(function(el){
    el.addEventListener("input", onNameInput);
    el.addEventListener("blur", onNameInput);
  });

  document.getElementById("register-form").addEventListener("submit", function(e){
    fillEnglishFromPersian();
    applyUsername();
    var phoneEl = document.getElementById("phone");
    if (!nameEnEl.value.trim() || !surnameEl.value.trim()) {
      e.preventDefault();
      alert("فیلدهای انگلیسی نام و نام خانوادگی الزامی هستند.");
      (!nameEnEl.value.trim() ? nameEnEl : surnameEl).focus();
      return;
    }
    if (phoneEl && !/^09[0-9]{9}$/.test(phoneEl.value.trim())) {
      e.preventDefault();
      alert("موبایل الزامی است و باید ۱۱ رقم با ۰۹ باشد.");
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
    if (takenSet[user]) {
      e.preventDefault();
      alert("این نام کاربری قبلاً ثبت شده است. نام انگلیسی را کمی تغییر دهید.");
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
