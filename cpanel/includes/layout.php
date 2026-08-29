<?php
declare(strict_types=1);

$user = current_user();
$panelHref = panel_href_for($user);
$flash = flash_get();
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= e(($pageTitle ?? 'مانا کلینیک') . ' | مانا کلینیک') ?></title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link href="https://fonts.googleapis.com/css2?family=Vazirmatn:wght@400;600;700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="<?= e(url('/assets/css/style.css')) ?>">
  <?php if (!empty($pageHead)): ?>
    <?= $pageHead ?>
  <?php endif; ?>
</head>
<body>
<canvas id="particle-canvas" aria-hidden="true"></canvas>
<div class="site-layer">
  <header class="site-header">
    <div class="container-page header-inner">
      <a class="brand" href="<?= e(url('/')) ?>">مانا کلینیک</a>
      <nav class="nav-links">
        <a href="<?= e(url('/')) ?>">صفحه اصلی</a>
        <a href="<?= e(url('/doctors')) ?>">متخصصان</a>
        <a href="<?= e(url('/articles')) ?>">مقالات</a>
        <a href="<?= e(url('/about')) ?>">درباره ما</a>
        <a href="<?= e(url('/contact')) ?>">تماس با ما</a>
        <?php if ($panelHref): ?>
          <a href="<?= e(url($panelHref)) ?>">پنل من</a>
        <?php endif; ?>
      </nav>
      <div class="header-actions">
        <?php if ($user): ?>
          <span class="user-name"><?= e($user['name']) ?></span>
          <a class="btn btn-outline" href="<?= e(url('/logout')) ?>">خروج</a>
        <?php else: ?>
          <a class="btn btn-outline" href="<?= e(url('/login')) ?>">ورود</a>
          <a class="btn btn-primary" href="<?= e(url('/register')) ?>">ثبت‌نام</a>
        <?php endif; ?>
      </div>
    </div>
  </header>

  <main>
    <?php if ($flash): ?>
      <div class="container-page" style="padding-top:1rem">
        <div class="flash flash-<?= e($flash['type']) ?>"><?= e($flash['message']) ?></div>
      </div>
    <?php endif; ?>
    <?= $content ?? '' ?>
  </main>

  <footer class="site-footer">
    <div class="container-page footer-grid">
      <div>
        <p class="brand">مانا کلینیک</p>
        <p class="muted">فضای امن برای یادگیری، رشد و دریافت خدمات روانشناسی آنلاین.</p>
      </div>
      <div>
        <p class="footer-title">دسترسی سریع</p>
        <a href="<?= e(url('/')) ?>">صفحه اصلی</a>
        <a href="<?= e(url('/doctors')) ?>">متخصصان</a>
        <a href="<?= e(url('/articles')) ?>">مقالات</a>
        <a href="<?= e(url('/about')) ?>">درباره ما</a>
        <a href="<?= e(url('/contact')) ?>">تماس با ما</a>
        <a href="<?= e(url('/register')) ?>">ثبت‌نام بیمار</a>
      </div>
      <div>
        <p class="footer-title">تماس</p>
        <p class="muted">ایمیل: info@manaclinic.org<br>پشتیبانی همه روزه ۹ تا ۱۸</p>
      </div>
    </div>
    <div class="footer-copy">© <?= date('Y') ?> مانا کلینیک — همه حقوق محفوظ است.</div>
  </footer>
</div>
<script src="<?= e(url('/assets/js/particles.js')) ?>"></script>
<?php if (!empty($pageScripts)): ?>
  <?= $pageScripts ?>
<?php endif; ?>
</body>
</html>
