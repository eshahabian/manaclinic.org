<?php
declare(strict_types=1);

$user = require_login(['PATIENT']);
$nav = [
    ['href' => '/dashboard', 'label' => 'خلاصه'],
    ['href' => '/dashboard/appointments', 'label' => 'نوبت‌های من'],
    ['href' => '/dashboard/profile', 'label' => 'پروفایل'],
    ['href' => '/doctors', 'label' => 'رزرو نوبت جدید'],
];
$stmt = $pdo->prepare('SELECT * FROM users WHERE id=?');
$stmt->execute([$user['id']]);
$profile = $stmt->fetch();

$pageTitle = 'پروفایل';
ob_start();
?>
<div class="container-page panel-layout">
  <aside class="panel side-nav">
    <p class="side-nav-title">پنل بیمار</p>
    <nav><?php foreach ($nav as $item): ?><a href="<?= e(url($item['href'])) ?>"><?= e($item['label']) ?></a><?php endforeach; ?></nav>
  </aside>
  <div style="max-width:28rem">
    <h1>پروفایل</h1>
    <form class="panel form-stack" method="post" action="<?= e(url('/dashboard/profile')) ?>">
      <div>
        <label class="label">نام</label>
        <input class="input" name="name" value="<?= e($profile['name']) ?>" required>
      </div>
      <div>
        <label class="label">ایمیل</label>
        <input class="input" value="<?= e($profile['email']) ?>" disabled dir="ltr">
      </div>
      <div>
        <label class="label">موبایل</label>
        <input class="input" name="phone" value="<?= e((string)$profile['phone']) ?>" dir="ltr">
      </div>
      <button class="btn btn-primary" type="submit">ذخیره تغییرات</button>
    </form>
  </div>
</div>
<?php
$content = ob_get_clean();
require __DIR__ . '/../../includes/layout.php';
