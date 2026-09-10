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
$colorfulParticles = $user && strcasecmp((string) ($user['username'] ?? ''), 'eshahabian') === 0;
$pageBodyClass = trim((string) ($GLOBALS['pageBodyClass'] ?? ($pageBodyClass ?? '')));
$bodyAttrs = '';
if ($pageBodyClass !== '') {
    $bodyAttrs .= ' class="' . e($pageBodyClass) . '"';
}
if ($colorfulParticles) {
    $bodyAttrs .= ' data-particles="colorful"';
}
if ($user && ($user['role'] ?? '') === 'SECRETARY') {
    $bodyAttrs .= ' data-secretary-desk="1" data-heartbeat="' . e(url('/secretary/heartbeat')) . '" data-logout="' . e(url('/logout')) . '"';
}
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl" translate="no">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="google" content="notranslate">
  <script>
  (function(){
    try {
      var t = localStorage.getItem("mana-theme");
      if (t === "dark" || t === "light") {
        document.documentElement.setAttribute("data-theme", t);
      }
    } catch (e) {}
  })();
  </script>
  <?= seo_render_head() ?>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link href="https://fonts.googleapis.com/css2?family=Vazirmatn:wght@400;600;700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="<?= e(url('/assets/css/style.css')) ?>?v=20260910h">
  <meta name="csrf-token" content="<?= e(csrf_token()) ?>">
  <script>
  (function(){
    var meta = document.querySelector('meta[name="csrf-token"]');
    var token = meta ? meta.getAttribute("content") : "";
    if (!token) return;
    var origFetch = window.fetch;
    window.fetch = function(input, init){
      init = init || {};
      var method = String(init.method || "GET").toUpperCase();
      if (method !== "GET" && method !== "HEAD" && method !== "OPTIONS") {
        var headers = new Headers(init.headers || {});
        if (!headers.has("X-CSRF-Token")) headers.set("X-CSRF-Token", token);
        init.headers = headers;
      }
      return origFetch.call(this, input, init);
    };
    function injectForms(){
      document.querySelectorAll("form").forEach(function(form){
        var method = (form.getAttribute("method") || "get").toLowerCase();
        if (method !== "post" || form.querySelector('input[name="_csrf"]')) return;
        var input = document.createElement("input");
        input.type = "hidden";
        input.name = "_csrf";
        input.value = token;
        form.appendChild(input);
      });
    }
    if (document.readyState === "loading") document.addEventListener("DOMContentLoaded", injectForms);
    else injectForms();
  })();
  </script>
  <?php if (!empty($pageHead)): ?>
    <?= $pageHead ?>
  <?php endif; ?>
</head>
<body<?= $bodyAttrs ?>>
<canvas id="particle-canvas" aria-hidden="true"></canvas>
<div class="site-layer">
  <header class="site-header">
    <div class="container-page header-inner">
      <div class="header-brand">
        <button type="button" class="nav-toggle" aria-label="منو" aria-expanded="false" aria-controls="mobile-nav">
          <span class="nav-toggle-bars" aria-hidden="true">
            <svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round">
              <path d="M4 7h16M4 12h16M4 17h16"/>
            </svg>
          </span>
          <span class="nav-toggle-close" aria-hidden="true">
            <svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round">
              <path d="M6 6l12 12M18 6L6 18"/>
            </svg>
          </span>
        </button>
        <a class="brand" href="<?= e(url('/')) ?>">
          <img class="brand-logo" src="<?= e(url('/assets/img/logo.png')) ?>" width="36" height="36" alt="">
          <span class="brand-text">مانا کلینیک</span>
        </a>
      </div>
      <nav class="nav-links" id="site-nav">
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
        <button type="button" class="theme-toggle" id="theme-toggle" title="حالت شب" aria-label="حالت شب">
          <svg class="icon-sun" viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true">
            <circle cx="12" cy="12" r="4"/>
            <path d="M12 3v2M12 19v2M5 12H3M21 12h-2M6.2 6.2l1.4 1.4M16.4 16.4l1.4 1.4M6.2 17.8l1.4-1.4M16.4 7.6l1.4-1.4"/>
          </svg>
          <svg class="icon-moon" viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true">
            <path d="M20 14.5A8.5 8.5 0 1 1 9.5 4 7 7 0 0 0 20 14.5z"/>
          </svg>
        </button>
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
  <div class="mobile-nav-overlay" data-nav-overlay aria-hidden="true"></div>
  <div id="mobile-nav" class="mobile-nav" role="dialog" aria-modal="true" aria-label="منو" aria-hidden="true" inert>
    <div class="mobile-nav-inner">
      <?php
        $bookNext = '/doctors';
        $bookHref = $user ? url($bookNext) : url('/register?next=' . rawurlencode($bookNext));
        $workshopsHref = ($user && ($user['role'] ?? '') === 'PATIENT')
          ? url('/dashboard/workshops')
          : url('/#home-workshop-banners');
        $assistantOn = function_exists('assistant_enabled') ? assistant_enabled() : false;
      ?>
      <nav class="mobile-nav-grid" aria-label="منوی سایت">
        <a class="mobile-nav-tile" href="<?= e($workshopsHref) ?>">
          <span class="mobile-nav-tile-icon" aria-hidden="true">
            <svg viewBox="0 0 24 24" width="26" height="26" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"><path d="M4 19V7h16v12H4zM8 7V5h8v2M9 11h6M9 15h4"/></svg>
          </span>
          <span>کارگاه‌ها</span>
        </a>
        <a class="mobile-nav-tile mobile-nav-tile-accent" href="<?= e($bookHref) ?>">
          <span class="mobile-nav-tile-icon" aria-hidden="true">
            <svg viewBox="0 0 24 24" width="26" height="26" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"><rect x="4" y="5" width="16" height="16" rx="2"/><path d="M8 3v4M16 3v4M4 10h16M9 14h2v2H9z"/></svg>
          </span>
          <span>گرفتن نوبت</span>
        </a>
        <a class="mobile-nav-tile" href="<?= e(url('/')) ?>">
          <span class="mobile-nav-tile-icon" aria-hidden="true">
            <svg viewBox="0 0 24 24" width="26" height="26" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"><path d="M4 11.5 12 4l8 7.5V20H4v-8.5z"/><path d="M10 20v-6h4v6"/></svg>
          </span>
          <span>صفحه اصلی سایت</span>
        </a>
        <a class="mobile-nav-tile" href="<?= e(url('/articles')) ?>">
          <span class="mobile-nav-tile-icon" aria-hidden="true">
            <svg viewBox="0 0 24 24" width="26" height="26" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"><path d="M6 4h9l5 5v11H6V4z"/><path d="M15 4v5h5M9 13h6M9 17h4"/></svg>
          </span>
          <span>مقالات</span>
        </a>
        <a class="mobile-nav-tile" href="<?= e(url('/tests')) ?>">
          <span class="mobile-nav-tile-icon" aria-hidden="true">
            <svg viewBox="0 0 24 24" width="26" height="26" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"><path d="M8 4h8v4H8zM6 8h12v12H6z"/><path d="M9 13l2 2 4-4"/></svg>
          </span>
          <span>آزمون‌ها</span>
        </a>
        <?php if ($assistantOn): ?>
          <a class="mobile-nav-tile" href="<?= e(url('/assistant')) ?>">
            <span class="mobile-nav-tile-icon" aria-hidden="true">
              <svg viewBox="0 0 24 24" width="26" height="26" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"><circle cx="12" cy="10" r="4"/><path d="M5 20c1.5-3 4-5 7-5s5.5 2 7 5"/></svg>
            </span>
            <span>دستیار هوشمند</span>
          </a>
        <?php endif; ?>
        <a class="mobile-nav-tile" href="<?= e(url('/about')) ?>">
          <span class="mobile-nav-tile-icon" aria-hidden="true">
            <svg viewBox="0 0 24 24" width="26" height="26" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"><circle cx="12" cy="12" r="8"/><path d="M12 11v5M12 8h.01"/></svg>
          </span>
          <span>درباره ما</span>
        </a>
        <a class="mobile-nav-tile" href="<?= e(url('/contact')) ?>">
          <span class="mobile-nav-tile-icon" aria-hidden="true">
            <svg viewBox="0 0 24 24" width="26" height="26" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"><path d="M5 6h14v12H5z"/><path d="M5 8l7 5 7-5"/></svg>
          </span>
          <span>تماس با ما</span>
        </a>
        <?php if ($panelHref): ?>
          <a class="mobile-nav-tile" href="<?= e(url($panelHref)) ?>">
            <span class="mobile-nav-tile-icon" aria-hidden="true">
              <svg viewBox="0 0 24 24" width="26" height="26" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"><path d="M4 7h16v12H4z"/><path d="M8 7V5h8v2"/></svg>
            </span>
            <span>پنل من</span>
          </a>
        <?php endif; ?>
      </nav>
      <div class="mobile-nav-panel" data-mobile-panel hidden></div>
    </div>
  </div>

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
        <p class="brand">
          <img class="brand-logo" src="<?= e(url('/assets/img/logo.png')) ?>" width="36" height="36" alt="">
          مانا کلینیک
        </p>
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
<div class="site-chrome" aria-label="بازگشت به بالا">
  <button type="button" class="site-chrome-btn site-chrome-top is-hidden" id="back-to-top" title="بازگشت به بالا" aria-label="بازگشت به بالا">
    <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true">
      <path d="M6 14l6-6 6 6"/>
    </svg>
  </button>
</div>
<?php
if (!function_exists('workshop_overview_modal_html')) {
    require_once __DIR__ . '/workshop_overview.php';
}
echo workshop_overview_modal_html();
$overviewJs = __DIR__ . '/../assets/js/workshop-overview.js';
?>
<script><?php if (is_file($overviewJs)) { echo file_get_contents($overviewJs); } ?></script>
<script src="<?= e(url('/assets/js/particles.js')) ?>?v=20260904t"></script>
<script src="<?= e(url('/assets/js/password-field.js')) ?>?v=20260908a"></script>
<script src="<?= e(url('/assets/js/mobile-nav.js')) ?>?v=20260909b"></script>
<script src="<?= e(url('/assets/js/site-chrome.js')) ?>?v=20260909b"></script>
<?php
$videoWatch = function_exists('video_call_watch_config') ? video_call_watch_config($user) : null;
if ($videoWatch):
?>
<script>window.__VIDEO_CALL_WATCH__ = <?= json_encode($videoWatch, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;</script>
<script src="<?= e(url('/assets/js/video-call-watch.js')) ?>?v=20260909f"></script>
<?php endif; ?>
<?php if ($user && ($user['role'] ?? '') === 'SECRETARY'): ?>
<script src="<?= e(url('/assets/js/secretary-idle.js')) ?>?v=20260906y"></script>
<?php endif; ?>
<?php
$handoverBlock = $GLOBALS['handoverBlock'] ?? null;
if ($handoverBlock && $user && ($user['role'] ?? '') === 'SECRETARY'):
?>
<div class="handover-overlay" role="dialog" aria-modal="true" aria-labelledby="handover-title">
  <div class="handover-card">
    <p class="handover-kicker">پیام همکار</p>
    <h1 id="handover-title">پیامی از <?= e((string) ($handoverBlock['from_name'] ?? 'منشی')) ?></h1>
    <p class="muted" style="margin:.35rem 0 0;font-size:.85rem">
      <?= e(format_fa_datetime((string) ($handoverBlock['created_at'] ?? ''))) ?>
      <?php if (!empty($handoverBlock['from_username'])): ?>
        · <span dir="ltr"><?= e((string) $handoverBlock['from_username']) ?></span>
      <?php endif; ?>
    </p>
    <div class="handover-body"><?= nl2br(e((string) ($handoverBlock['body'] ?? ''))) ?></div>
    <form method="post" action="<?= e(url('/secretary/handover/ack')) ?>" class="handover-actions">
      <input type="hidden" name="note_id" value="<?= e((string) ($handoverBlock['id'] ?? '')) ?>">
      <?= csrf_field() ?>
      <button type="submit" class="btn btn-primary">خواندم</button>
      <a class="btn btn-outline" href="<?= e(url('/logout')) ?>">خروج</a>
    </form>
  </div>
</div>
<?php endif; ?>
<?php if (!empty($pageScripts)): ?>
  <?= $pageScripts ?>
<?php endif; ?>
</body>
</html>
