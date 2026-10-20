<?php
declare(strict_types=1);

$pageTitle = 'همه دوره‌ها و کارگاه‌ها | مانا کلینیک';
$pageDescription = 'همه دوره‌ها و کارگاه‌های منتشرشده مانا کلینیک؛ مشاهده توضیحات و ثبت‌نام، از جدیدترین به قدیمی‌ترین.';
$pageCanonical = url('/workshops');
$workshopArchive = true;
$workshopBanners = workshop_home_banners($pdo, null);
ob_start();
?>
<div class="container-page section" dir="rtl">
  <nav class="services-breadcrumb" aria-label="مسیر صفحه">
    <a href="<?= e(url('/')) ?>">خانه</a>
    <span aria-hidden="true">/</span>
    <span>همه کارگاه‌ها</span>
  </nav>
  <h1>همه دوره‌ها و کارگاه‌ها</h1>
  <?php require __DIR__ . '/../includes/public_workshop_banners.php'; ?>
</div>
<?php
$content = ob_get_clean();
$pageScripts = '<script src="' . e(url('/assets/js/home-workshop-banners.js')) . '?v=20260908k"></script>';
require __DIR__ . '/../includes/layout.php';
