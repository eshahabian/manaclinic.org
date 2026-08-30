<?php
declare(strict_types=1);
if (current_user()) {
    redirect('/dashboard');
}
$pageTitle = 'ثبت‌نام';
$role = (($_GET['role'] ?? '') === 'DOCTOR') ? 'DOCTOR' : 'PATIENT';
ob_start();
?>
<div class="auth-wrap">
  <form class="panel auth-box form-stack" method="post" action="<?= e(url('/register')) ?>" id="register-form">
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

    <div>
      <label class="label">نام و نام خانوادگی</label>
      <input class="input" name="name" required>
    </div>
    <div>
      <label class="label">نام کاربری</label>
      <input class="input" name="username" required dir="ltr" autocomplete="username" pattern="[A-Za-z0-9._-]{3,32}" title="فقط حروف انگلیسی، عدد و ._- (۳ تا ۳۲ کاراکتر)">
    </div>
    <div>
      <label class="label">موبایل</label>
      <input class="input" name="phone" dir="ltr" placeholder="09...">
    </div>
    <?php if ($role === 'DOCTOR'): ?>
    <div>
      <label class="label">تخصص</label>
      <input class="input" name="specialty" required placeholder="مثلاً روان‌درمانی شناختی-رفتاری">
    </div>
    <?php endif; ?>
    <div>
      <label class="label">رمز عبور</label>
      <input class="input" name="password" type="password" required minlength="6" dir="ltr">
    </div>
    <button class="btn btn-primary" type="submit" name="submit_register" value="1">
      <?= $role === 'DOCTOR' ? 'ارسال درخواست' : 'ایجاد حساب' ?>
    </button>
  </form>
</div>
<?php
$content = ob_get_clean();
require __DIR__ . '/../includes/layout.php';
