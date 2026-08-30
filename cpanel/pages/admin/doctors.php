<?php
declare(strict_types=1);
require_once __DIR__ . '/../../includes/admin_panel.php';
require_login(['ADMIN']);
$doctors = $pdo->query("
  SELECT dp.*, u.name, u.username, u.phone FROM doctor_profiles dp
  JOIN users u ON u.id=dp.user_id ORDER BY dp.created_at DESC
")->fetchAll();
$pending = array_values(array_filter($doctors, fn($d) => !(int) $d['is_approved']));
$approved = array_values(array_filter($doctors, fn($d) => (int) $d['is_approved']));
ob_start();
?>
<h1>مدیریت درمانگرها</h1>

<?php if ($pending): ?>
<section class="stack" style="margin-top:1rem">
  <h2 style="margin:0;font-size:1.05rem;color:var(--primary)">درخواست‌های در انتظار تأیید (<?= count($pending) ?>)</h2>
  <?php foreach ($pending as $d): ?>
    <div class="panel row-between" style="border:2px solid color-mix(in srgb, var(--primary) 35%, transparent)">
      <div>
        <strong><?= e($d['name']) ?></strong>
        <div style="color:var(--primary);font-size:.9rem"><?= e($d['specialty']) ?></div>
        <div class="muted" style="font-size:.85rem" dir="ltr"><?= e($d['username']) ?></div>
        <?php if (!empty($d['phone'])): ?>
          <div class="muted" style="font-size:.85rem" dir="ltr"><?= e($d['phone']) ?></div>
        <?php endif; ?>
      </div>
      <div style="display:flex;gap:.5rem;flex-wrap:wrap">
        <form method="post" action="<?= e(url('/admin/doctors')) ?>">
          <input type="hidden" name="action" value="approve">
          <input type="hidden" name="id" value="<?= e($d['id']) ?>">
          <button class="btn btn-sm btn-primary" type="submit">تأیید درمانگر</button>
        </form>
        <form method="post" action="<?= e(url('/admin/doctors')) ?>">
          <input type="hidden" name="action" value="reject">
          <input type="hidden" name="id" value="<?= e($d['id']) ?>">
          <button class="btn btn-sm btn-danger" type="submit">رد درخواست</button>
        </form>
      </div>
    </div>
  <?php endforeach; ?>
</section>
<?php endif; ?>

<form class="panel form-stack" method="post" action="<?= e(url('/admin/doctors')) ?>" style="margin-top:1rem">
  <input type="hidden" name="action" value="create">
  <h2 style="margin:0;font-size:1.05rem">افزودن درمانگر جدید (مستقیم)</h2>
  <div class="grid-2">
    <div><label class="label">نام</label><input class="input" name="name" required></div>
    <div><label class="label">نام کاربری</label><input class="input" name="username" required dir="ltr" pattern="[A-Za-z0-9._-]{3,32}"></div>
    <div><label class="label">رمز موقت</label><input class="input" name="password" value="123" required dir="ltr"></div>
    <div><label class="label">موبایل</label><input class="input" name="phone" dir="ltr"></div>
    <div><label class="label">تخصص</label><input class="input" name="specialty" required></div>
    <div><label class="label">هزینه جلسه</label><input class="input" type="number" name="session_price" value="3000000" required dir="ltr"></div>
  </div>
  <div><label class="label">بیوگرافی</label><textarea class="input" name="bio" rows="4"></textarea></div>
  <button class="btn btn-primary" type="submit">ایجاد حساب درمانگر</button>
</form>

<div class="stack" style="margin-top:1.5rem">
  <h2 style="margin:0;font-size:1.05rem">درمانگرهای تأییدشده</h2>
  <?php if (!$approved): ?>
    <p class="muted">هنوز درمانگر تأییدشده‌ای وجود ندارد.</p>
  <?php endif; ?>
<?php foreach ($approved as $d): ?>
  <div class="panel row-between">
    <div>
      <strong><?= e($d['name']) ?></strong>
      <div style="color:var(--primary);font-size:.9rem"><?= e($d['specialty']) ?></div>
      <div class="muted" style="font-size:.85rem" dir="ltr"><?= e($d['username']) ?></div>
      <div style="font-size:.9rem;margin-top:.25rem"><?= e(format_price((int)$d['session_price'])) ?></div>
      <?php if (!(int)$d['is_active']): ?>
        <div style="color:var(--danger);font-size:.8rem;margin-top:.25rem">غیرفعال</div>
      <?php endif; ?>
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
render_admin_page('درمانگرها', ob_get_clean());
