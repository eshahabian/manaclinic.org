<?php
declare(strict_types=1);
require_once __DIR__ . '/../../includes/admin_panel.php';
require_login(['ADMIN']);

$users = (int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
$doctors = (int)$pdo->query('SELECT COUNT(*) FROM doctor_profiles WHERE is_active=1')->fetchColumn();
$articles = (int)$pdo->query('SELECT COUNT(*) FROM articles WHERE published=1')->fetchColumn();
$appointments = (int)$pdo->query('SELECT COUNT(*) FROM appointments')->fetchColumn();
$paid = $pdo->query("SELECT COUNT(*) AS c, COALESCE(SUM(amount),0) AS s FROM payments WHERE status='PAID'")->fetch();

ob_start();
?>
<h1>داشبورد مدیریت</h1>
<p class="muted">نمای کلی وضعیت مانا کلینیک</p>
<div class="grid-3" style="margin-top:1.5rem">
  <div class="panel"><div class="muted" style="font-size:.85rem">کاربران</div><div style="font-size:1.6rem;font-weight:700;margin-top:.35rem"><?= $users ?></div></div>
  <div class="panel"><div class="muted" style="font-size:.85rem">دکترهای فعال</div><div style="font-size:1.6rem;font-weight:700;margin-top:.35rem"><?= $doctors ?></div></div>
  <div class="panel"><div class="muted" style="font-size:.85rem">مقالات منتشرشده</div><div style="font-size:1.6rem;font-weight:700;margin-top:.35rem"><?= $articles ?></div></div>
  <div class="panel"><div class="muted" style="font-size:.85rem">کل نوبت‌ها</div><div style="font-size:1.6rem;font-weight:700;margin-top:.35rem"><?= $appointments ?></div></div>
  <div class="panel"><div class="muted" style="font-size:.85rem">پرداخت‌های موفق</div><div style="font-size:1.2rem;font-weight:700;margin-top:.35rem"><?= (int)$paid['c'] ?> / <?= e(format_price((int)$paid['s'])) ?></div></div>
</div>
<?php
render_admin_page('ادمین', ob_get_clean());
