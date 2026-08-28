<?php
declare(strict_types=1);
require_once __DIR__ . '/../../includes/admin_panel.php';
require_login(['ADMIN']);
$doctors = $pdo->query("
  SELECT dp.*, u.name, u.email FROM doctor_profiles dp
  JOIN users u ON u.id=dp.user_id ORDER BY dp.created_at DESC
")->fetchAll();
ob_start();
?>
<h1>مدیریت دکترها</h1>
<form class="panel form-stack" method="post" action="<?= e(url('/admin/doctors')) ?>" style="margin-top:1rem">
  <input type="hidden" name="action" value="create">
  <h2 style="margin:0;font-size:1.05rem">افزودن دکتر جدید</h2>
  <div class="grid-2">
    <div><label class="label">نام</label><input class="input" name="name" required></div>
    <div><label class="label">ایمیل</label><input class="input" name="email" type="email" required dir="ltr"></div>
    <div><label class="label">رمز موقت</label><input class="input" name="password" required minlength="6" dir="ltr"></div>
    <div><label class="label">موبایل</label><input class="input" name="phone" dir="ltr"></div>
    <div><label class="label">تخصص</label><input class="input" name="specialty" required></div>
    <div><label class="label">هزینه جلسه</label><input class="input" type="number" name="session_price" value="3000000" required dir="ltr"></div>
  </div>
  <div><label class="label">بیوگرافی</label><textarea class="input" name="bio" rows="4"></textarea></div>
  <button class="btn btn-primary" type="submit">ایجاد حساب دکتر</button>
</form>
<div class="stack" style="margin-top:1.5rem">
<?php foreach ($doctors as $d): ?>
  <div class="panel row-between">
    <div>
      <strong><?= e($d['name']) ?></strong>
      <div style="color:var(--primary);font-size:.9rem"><?= e($d['specialty']) ?></div>
      <div class="muted" style="font-size:.85rem" dir="ltr"><?= e($d['email']) ?></div>
      <div style="font-size:.9rem;margin-top:.25rem"><?= e(format_price((int)$d['session_price'])) ?></div>
    </div>
    <form method="post" action="<?= e(url('/admin/doctors')) ?>">
      <input type="hidden" name="action" value="toggle">
      <input type="hidden" name="id" value="<?= e($d['id']) ?>">
      <button class="btn btn-sm <?= $d['is_active'] ? 'btn-danger' : 'btn-primary' ?>" type="submit">
        <?= $d['is_active'] ? 'غیرفعال کردن' : 'فعال کردن' ?>
      </button>
    </form>
  </div>
<?php endforeach; ?>
</div>
<?php
render_admin_page('دکترها', ob_get_clean());
