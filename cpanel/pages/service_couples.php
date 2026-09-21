<?php
declare(strict_types=1);

$pageTitle = 'زوج‌درمانی | مانا کلینیک سعادت‌آباد';
$pageDescription = 'زوج‌درمانی در مانا کلینیک سعادت‌آباد: شناخت الگوهای رابطه، بهبود ارتباط و حل تعارض با همراهی متخصص. رزرو نوبت حضوری و آنلاین.';
$pageCanonical = url('/services/couples');
$pageKeywords = 'زوج درمانی, مشاوره زوج, روابط عاطفی, مانا کلینیک سعادت آباد';

$doctorsHref = service_doctors_search_href('زوج درمانی');
$domainDoctors = service_doctors_by_domain($pdo, 'couples');
$pageJsonLd = service_detail_json_ld(
    'زوج‌درمانی',
    '/services/couples',
    $pageDescription,
    'زوج‌درمانی و روابط عاطفی'
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
require __DIR__ . '/../includes/layout.php';
