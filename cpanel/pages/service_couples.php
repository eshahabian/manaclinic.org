<?php
declare(strict_types=1);

$pageTitle = 'زوج‌درمانی | مانا کلینیک سعادت‌آباد';
$pageDescription = 'زوج‌درمانی در مانا کلینیک سعادت‌آباد: شناخت الگوهای رابطه، بهبود ارتباط و حل تعارض با همراهی متخصص. رزرو نوبت حضوری و آنلاین.';
$pageCanonical = url('/services/couples');
$pageKeywords = 'زوج درمانی, مشاوره زوج, روابط عاطفی, مانا کلینیک سعادت آباد';

$doctorsHref = service_doctors_search_href('زوج درمانی');
$heroImage = url('/assets/img/services/couples-therapy.png');
$domainDoctors = service_doctors_by_domain($pdo, 'couples');

$workshopBanners = [];
if (function_exists('workshop_home_banners')) {
    $rawBanners = workshop_home_banners($pdo, 12);
    foreach ($rawBanners as $promo) {
        if (workshop_promo_phase($promo) === 'done') {
            continue;
        }
        $workshopBanners[] = $promo;
    }
    usort($workshopBanners, static function (array $a, array $b): int {
        return strcmp((string) ($b['created_at'] ?? ''), (string) ($a['created_at'] ?? ''));
    });
}

$pageJsonLd = service_detail_json_ld(
    'زوج‌درمانی',
    '/services/couples',
    $pageDescription,
    'زوج‌درمانی و روابط عاطفی',
    '/assets/img/services/couples-therapy.png'
);

ob_start();
?>
<div class="container-page services-page service-detail-page">
  <nav class="services-breadcrumb" aria-label="مسیر صفحه">
    <a href="<?= e(url('/')) ?>">خانه</a>
    <span class="services-breadcrumb-sep" aria-hidden="true">/</span>
    <a href="<?= e(url('/services')) ?>">خدمات</a>
    <span class="services-breadcrumb-sep" aria-hidden="true">/</span>
    <span>زوج‌درمانی</span>
  </nav>

  <header class="services-hero">
    <figure class="service-detail-hero-media">
      <img
        src="<?= e($heroImage) ?>"
        alt="فضای آرام اتاق زوج‌درمانی در کلینیک روانشناسی"
        width="1600"
        height="900"
        loading="eager"
        decoding="async"
      >
    </figure>
    <p class="services-kicker"><span>خدمات ما</span></p>
    <h1>زوج‌درمانی</h1>
  </header>

  <article class="service-detail-body">
    <section>
      <h2>زوج‌درمانی چیست؟</h2>
      <p>هر رابطه‌ای ممکن است دوره‌هایی از نزدیکی، فاصله، اختلاف و سوءتفاهم را تجربه کند. گاهی تلاش برای حل مشکلات به گفت‌وگوهای تکراری، دلخوری‌های بیشتر یا دور شدن دو نفر از یکدیگر منجر می‌شود.</p>
      <p>زوج‌درمانی فضایی تخصصی برای کمک به زوج‌هاست تا آنچه در رابطه میانشان می‌گذرد را بهتر ببینند، الگوهای تکرارشونده رابطه را بشناسند و راه‌های سالم‌تر و مؤثرتری برای ارتباط و حل تعارض پیدا کنند.</p>
    </section>

    <section>
      <h2>چه مسائلی می‌تواند موضوع زوج‌درمانی باشد؟</h2>
      <p>تعارض‌های مکرر، مشکلات ارتباطی، فاصله عاطفی، بی‌اعتمادی، خیانت، اختلاف درباره مسائل مالی یا خانواده‌ها، تفاوت در سبک زندگی، مسائل جنسی، فرزندپروری و تصمیم‌های مهم زندگی مشترک.</p>
    </section>

    <section>
      <h2>در جلسات زوج‌درمانی چه اتفاقی می‌افتد؟</h2>
      <p>درمانگر تلاش می‌کند تنها به این سؤال که «چه کسی مقصر است؟» نپردازد؛ بلکه به زوج کمک می‌کند چرخه‌ای را که میان آنها شکل گرفته بشناسند و بفهمند پشت بسیاری از اختلاف‌ها چه نیازها، احساسات و الگوهایی قرار دارد. هدف، ایجاد فضایی برای گفت‌وگوی مؤثرتر و ساختن رابطه‌ای آگاهانه‌تر است.</p>
    </section>

    <section class="home-workshop-banners service-detail-workshop-banners" id="home-workshop-banners" aria-labelledby="upcoming-workshops-heading">
      <div class="section-head">
        <div>
          <h2 id="upcoming-workshops-heading">برنامه‌های پیش رو</h2>
          <p class="muted">روی کارت بزنید تا توضیح و ثبت‌نام را ببینید</p>
        </div>
      </div>
      <?php if ($workshopBanners): ?>
        <div class="home-workshop-track">
          <?php foreach ($workshopBanners as $promo): ?>
            <?php
              $phase = workshop_promo_phase($promo);
              $when = workshop_is_offline((string) ($promo['type'] ?? ''))
                  ? 'دوره آفلاین'
                  : (format_workshop_datetime_fa((string) ($promo['starts_at'] ?? '')) . ' تا ' . format_workshop_datetime_fa((string) ($promo['ends_at'] ?? '')));
              $meta = trim(workshop_type_label((string) ($promo['type'] ?? '')) . ' · ' . (string) ($promo['doctor_name'] ?? '') . ' · ' . $when);
              $canApply = workshop_can_enroll($promo) && $phase !== 'done' && (string) ($promo['status'] ?? '') === 'PUBLISHED';
              $bannerSrc = workshop_promo_image_src($promo);
              $blurb = workshop_promo_blurb($promo);
              $notes = trim((string) ($promo['description'] ?? ''));
              $typeKey = workshop_tab_from_type((string) ($promo['type'] ?? ''));
            ?>
            <button
              type="button"
              class="home-workshop-banner"
              data-workshop-banner
              data-title="<?= e((string) ($promo['title'] ?? '')) ?>"
              data-description="<?= e($blurb) ?>"
              data-notes="<?= e($notes) ?>"
              data-meta="<?= e($meta) ?>"
              data-image="<?= e($bannerSrc) ?>"
              data-apply="<?= e(workshop_apply_url((string) ($promo['id'] ?? ''))) ?>"
              data-can-apply="<?= $canApply ? '1' : '0' ?>"
            >
              <img src="<?= e($bannerSrc) ?>" alt="<?= e((string) ($promo['title'] ?? 'بنر دوره')) ?>">
              <span class="home-workshop-caption">
                <strong><?= e((string) ($promo['title'] ?? 'دوره')) ?></strong>
              </span>
              <span class="home-workshop-badges">
                <span class="home-workshop-badge home-workshop-badge--type-<?= e($typeKey) ?>"><?= e(workshop_type_label((string) ($promo['type'] ?? ''))) ?></span>
                <span class="home-workshop-badge home-workshop-badge--<?= e($phase) ?>"><?= e(workshop_promo_phase_label($phase)) ?></span>
              </span>
            </button>
          <?php endforeach; ?>
        </div>
      <?php else: ?>
        <p class="muted home-workshop-empty">به‌زودی دوره‌های جدید اینجا اعلام می‌شود.</p>
      <?php endif; ?>
    </section>

    <div id="home-workshop-modal" class="home-workshop-modal" aria-hidden="true" role="dialog" aria-modal="true" aria-labelledby="home-workshop-modal-title">
      <div class="home-workshop-modal-backdrop" data-workshop-close tabindex="-1"></div>
      <div class="home-workshop-modal-panel">
        <div class="home-workshop-modal-header">
          <h2 id="home-workshop-modal-title"></h2>
          <button type="button" class="home-workshop-modal-close" data-workshop-close aria-label="بستن">×</button>
        </div>
        <div class="home-workshop-modal-body" id="home-workshop-modal-body"></div>
        <div class="home-workshop-modal-actions">
          <a class="btn btn-primary" id="home-workshop-modal-cta" href="#">ثبت‌نام در دوره</a>
          <button type="button" class="btn btn-outline" data-workshop-close>بستن</button>
        </div>
      </div>
    </div>

    <section class="service-detail-therapists" aria-labelledby="couples-therapists-heading">
      <h2 id="couples-therapists-heading">متخصصان زوج‌درمانی</h2>
      <p><a href="<?= e($doctorsHref) ?>">معرفی متخصصان کلینیک در حوزه زوج‌درمانی و روابط عاطفی.</a></p>
      <?php if ($domainDoctors): ?>
        <div class="doctors-directory service-detail-doctors">
          <?php foreach ($domainDoctors as $doc): ?>
            <?= doctor_card_html($doc) ?>
          <?php endforeach; ?>
        </div>
        <p class="service-detail-related">
          <a href="<?= e($doctorsHref) ?>">مشاهده همه متخصصان مرتبط</a>
        </p>
      <?php else: ?>
        <p class="muted">
          در حال حاضر درمانگر فعالی با این حوزه ثبت نشده است.
          <a href="<?= e(url('/doctors')) ?>">فهرست همه متخصصان</a>
        </p>
      <?php endif; ?>
    </section>

    <div class="service-detail-cta">
      <a class="btn btn-primary" href="<?= e($doctorsHref) ?>">رزرو نوبت زوج‌درمانی</a>
      <a class="btn btn-outline" href="<?= e(url('/assistant')) ?>">شروع با دستیار هوشمند</a>
      <a class="btn btn-outline" href="<?= e(url('/contact')) ?>">تماس با کلینیک</a>
      <a class="btn btn-outline" href="<?= e(url('/services')) ?>">بازگشت به خدمات</a>
    </div>
  </article>
</div>
<?php
$content = ob_get_clean();
$pageScripts = '<script src="' . e(url('/assets/js/home-workshop-banners.js')) . '?v=20260908k"></script>';
$GLOBALS['pageScripts'] = $pageScripts;
require __DIR__ . '/../includes/layout.php';
