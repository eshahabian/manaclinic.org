<?php
declare(strict_types=1);

require_once __DIR__ . '/seo.php';

$pageTitle = $GLOBALS['pageTitle'] ?? ($pageTitle ?? null);
$pageHead = $GLOBALS['pageHead'] ?? ($pageHead ?? '');
$pageScripts = $GLOBALS['pageScripts'] ?? ($pageScripts ?? '');
$content = $GLOBALS['content'] ?? ($content ?? '');

$user = current_user();
$panelHref = panel_href_for($user);
$flash = flash_get();
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <?= seo_render_head() ?>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link href="https://fonts.googleapis.com/css2?family=Vazirmatn:wght@400;600;700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="<?= e(url('/assets/css/style.css')) ?>?v=20260904n">
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
        <a href="<?= e(url('/tests')) ?>">آزمون‌ها</a>
        <?php if (function_exists('assistant_enabled') ? assistant_enabled() : false): ?>
          <a href="<?= e(url('/assistant')) ?>">دستیار هوشمند</a>
        <?php endif; ?>
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
        <p class="footer-address muted">
          سعادت‌آباد، خیابان ۳۱ شرقی (جندونی)، روبروی ساختمان پزشکان روزبه، پلاک ۴، واحد ۵، طبقه ۵
        </p>
        <p class="footer-contact muted">
          <a href="tel:09101387838" dir="ltr">۰۹۱۰ ۱۳۸ ۷۸۳۸</a>
          ·
          <a href="tel:02122065774" dir="ltr">۰۲۱ ۲۲۰۶ ۵۷۷۴</a>
        </p>
        <div class="footer-social" aria-label="پیام‌رسان‌ها">
          <a class="footer-social-link footer-social-whatsapp" href="https://wa.me/989101387838" target="_blank" rel="noopener noreferrer" title="واتساپ" aria-label="چت در واتساپ">
            <svg viewBox="0 0 24 24" width="22" height="22" aria-hidden="true"><path fill="currentColor" d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 01-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 012.893 6.994c-.003 5.45-4.435 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0012.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 005.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 00-3.48-8.413z"/></svg>
          </a>
          <a class="footer-social-link footer-social-telegram" href="https://t.me/+989101387838" target="_blank" rel="noopener noreferrer" title="تلگرام" aria-label="چت در تلگرام">
            <svg viewBox="0 0 24 24" width="22" height="22" aria-hidden="true"><path fill="currentColor" d="M11.944 0A12 12 0 000 12a12 12 0 0012 12 12 12 0 0012-12A12 12 0 0012 0a12 12 0 00-.056 0zm4.962 7.224c.1-.002.321.023.465.14a.506.506 0 01.171.325c.016.093.036.306.02.472-.18 1.898-.962 6.502-1.36 8.627-.168.9-.499 1.201-.82 1.23-.696.065-1.225-.46-1.9-.902-1.056-.693-1.653-1.124-2.678-1.8-1.185-.78-.417-1.21.258-1.91.177-.184 3.247-2.977 3.307-3.23.007-.032.014-.15-.056-.212s-.174-.041-.249-.024c-.106.024-1.793 1.14-5.061 3.345-.48.33-.913.49-1.302.48-.428-.008-1.252-.241-1.865-.44-.752-.245-1.349-.374-1.297-.789.027-.216.325-.437.893-.663 3.498-1.524 5.83-2.529 6.998-3.014 3.332-1.386 4.025-1.627 4.476-1.635z"/></svg>
          </a>
          <a class="footer-social-link footer-social-instagram" href="https://www.instagram.com/mana_clinic/" target="_blank" rel="noopener noreferrer" title="اینستاگرام" aria-label="صفحه اینستاگرام مانا کلینیک">
            <svg viewBox="0 0 24 24" width="22" height="22" aria-hidden="true"><path fill="currentColor" d="M12 2.163c3.204 0 3.584.012 4.85.07 3.252.148 4.771 1.691 4.919 4.919.058 1.265.069 1.645.069 4.849 0 3.205-.012 3.584-.069 4.849-.149 3.225-1.664 4.771-4.919 4.919-1.266.058-1.644.07-4.85.07-3.204 0-3.584-.012-4.849-.07-3.26-.149-4.771-1.699-4.919-4.92-.058-1.265-.07-1.644-.07-4.849 0-3.204.013-3.583.07-4.849.149-3.227 1.664-4.771 4.919-4.919 1.266-.057 1.645-.069 4.849-.069zM12 0C8.741 0 8.333.014 7.053.072 2.695.272.273 2.69.073 7.052.014 8.333 0 8.741 0 12c0 3.259.014 3.668.072 4.948.2 4.358 2.618 6.78 6.98 6.98C8.333 23.986 8.741 24 12 24c3.259 0 3.668-.014 4.948-.072 4.354-.2 6.782-2.618 6.979-6.98.059-1.28.073-1.689.073-4.948 0-3.259-.014-3.667-.072-4.947-.196-4.354-2.617-6.78-6.979-6.98C15.668.014 15.259 0 12 0zm0 5.838a6.162 6.162 0 100 12.324 6.162 6.162 0 000-12.324zM12 16a4 4 0 110-8 4 4 0 010 8zm6.406-11.845a1.44 1.44 0 100 2.881 1.44 1.44 0 000-2.881z"/></svg>
          </a>
        </div>
      </div>
      <div>
        <p class="footer-title">موقعیت مطب</p>
        <a class="footer-map" href="https://www.google.com/maps?q=35.772637,51.377568" target="_blank" rel="noopener noreferrer" aria-label="باز کردن موقعیت مانا کلینیک در گوگل‌مپ">
          <iframe
            title="نقشه مانا کلینیک"
            src="https://maps.google.com/maps?q=35.772637,51.377568&z=15&output=embed"
            loading="lazy"
            referrerpolicy="no-referrer-when-downgrade"
            tabindex="-1"
          ></iframe>
        </a>
      </div>
      <div>
        <p class="footer-title">دسترسی سریع</p>
        <a href="<?= e(url('/')) ?>">صفحه اصلی</a>
        <a href="<?= e(url('/doctors')) ?>">متخصصان</a>
        <a href="<?= e(url('/articles')) ?>">مقالات</a>
        <a href="<?= e(url('/tests')) ?>">آزمون‌ها</a>
        <a href="<?= e(url('/about')) ?>">درباره ما</a>
        <a href="<?= e(url('/contact')) ?>">تماس با ما</a>
        <a href="<?= e(url('/register')) ?>">ثبت‌نام</a>
      </div>
    </div>
    <div class="footer-copy">
      <div>© <?= date('Y') ?> مانا کلینیک — همه حقوق محفوظ است.</div>
      <p class="footer-tagline">فضای امن برای یادگیری، رشد و دریافت خدمات روانشناسی آنلاین.</p>
    </div>
  </footer>
</div>
<script src="<?= e(url('/assets/js/particles.js')) ?>"></script>
<?php if (!empty($pageScripts)): ?>
  <?= $pageScripts ?>
<?php endif; ?>
</body>
</html>
