<?php
declare(strict_types=1);
require_once __DIR__ . '/../../includes/doctor_panel.php';
$ctx = require_doctor_profile($pdo);
$p = $ctx['profile'];
ob_start();
?>
<h1>پروفایل حرفه‌ای</h1>
<form class="panel form-stack" method="post" action="<?= e(url('/doctor/profile')) ?>" style="max-width:40rem;margin-top:1rem">
  <div><label class="label">نام نمایشی</label><input class="input" name="name" value="<?= e($p['name']) ?>" required></div>
  <div><label class="label">تخصص</label><input class="input" name="specialty" value="<?= e($p['specialty']) ?>" required></div>
  <div><label class="label">بیوگرافی</label><textarea class="input" name="bio" rows="6"><?= e($p['bio']) ?></textarea></div>
  <div>
    <label class="label">هزینه جلسه (تومان)</label>
    <input class="input" type="number" name="session_price" value="<?= e((string)$p['session_price']) ?>" required dir="ltr">
  </div>
  <button class="btn btn-primary" type="submit">ذخیره</button>
</form>
<?php
render_doctor_page('پروفایل', ob_get_clean());
