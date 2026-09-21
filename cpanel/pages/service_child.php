<?php
declare(strict_types=1);

$pageTitle = 'مشاوره کودک و نوجوان | مانا کلینیک سعادت‌آباد';
$pageDescription = 'مشاوره و روان‌درمانی کودک و نوجوان در مانا کلینیک سعادت‌آباد: اضطراب، رفتار، تحصیل و رشد هیجانی با همراهی متخصص. رزرو نوبت.';
$pageCanonical = url('/services/child');
$pageKeywords = 'مشاوره کودک, روانشناس نوجوان, درمان کودک سعادت آباد, مانا کلینیک';

$doctorsHref = service_doctors_search_href('کودک و نوجوان');
$domainDoctors = service_doctors_by_domain($pdo, 'child');
$pageJsonLd = service_detail_json_ld(
    'کودک و نوجوان',
    '/services/child',
    $pageDescription,
    'مشاوره و روان‌درمانی کودک و نوجوان'
);

ob_start();
?>
<div class="container-page services-page service-detail-page">
  <nav class="services-breadcrumb" aria-label="مسیر صفحه">
    <a href="<?= e(url('/')) ?>">خانه</a>
    <span class="services-breadcrumb-sep" aria-hidden="true">/</span>
    <a href="<?= e(url('/services')) ?>">خدمات</a>
    <span class="services-breadcrumb-sep" aria-hidden="true">/</span>
    <span>کودک و نوجوان</span>
  </nav>

  <header class="services-hero">
    <p class="services-kicker"><span>خدمات ما</span></p>
    <h1>کودک و نوجوان</h1>
  </header>

  <article class="service-detail-body">
    <section>
      <h2>مشاوره کودک و نوجوان چیست؟</h2>
      <p>کودکان و نوجوانان همیشه نمی‌توانند آنچه در درونشان می‌گذرد را با کلمات بیان کنند. گاهی یک تغییر در رفتار، افت تحصیلی، پرخاشگری، گوشه‌گیری یا حتی تغییر در خواب و روابطشان می‌تواند نشانه‌ای از مسئله‌ای باشد که نیاز به توجه دارد.</p>
      <p>مشاوره و روان‌درمانی کودک و نوجوان به آنها کمک می‌کند متناسب با سن و شرایطشان، احساسات و تجربه‌های خود را بهتر بشناسند و مهارت‌های لازم برای مواجهه با چالش‌هایشان را یاد بگیرند.</p>
    </section>

    <section>
      <h2>چه موضوعاتی ممکن است نیاز به کمک تخصصی داشته باشد؟</h2>
      <p>اضطراب و ترس، پرخاشگری، مشکلات رفتاری، افت تحصیلی، مشکلات ارتباط با همسالان، کاهش اعتمادبه‌نفس، مشکلات خواب، جدایی والدین، تغییرات خانوادگی، مشکلات دوران نوجوانی و سایر مسائل هیجانی و رفتاری.</p>
    </section>

    <section>
      <h2>جلسات کودک و نوجوان چگونه پیش می‌رود؟</h2>
      <p>شیوه کار به سن، شرایط و مسئله کودک یا نوجوان بستگی دارد. ممکن است بخشی از جلسات با والدین و بخشی با خود کودک یا نوجوان برگزار شود. متخصص ابتدا تلاش می‌کند تصویری کامل‌تر از شرایط فردی، خانوادگی، تحصیلی و ارتباطی کودک به دست آورد و سپس مناسب‌ترین مسیر را پیشنهاد می‌دهد.</p>
    </section>

    <section class="service-detail-therapists" aria-labelledby="child-therapists-heading">
      <h2 id="child-therapists-heading">متخصصان کودک و نوجوان</h2>
      <p><a href="<?= e($doctorsHref) ?>">آشنایی با متخصصان حوزه کودک و نوجوان کلینیک.</a></p>
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
      <a class="btn btn-primary" href="<?= e($doctorsHref) ?>">رزرو نوبت کودک و نوجوان</a>
      <a class="btn btn-outline" href="<?= e(url('/assistant')) ?>">شروع با دستیار هوشمند</a>
      <a class="btn btn-outline" href="<?= e(url('/contact')) ?>">تماس با کلینیک</a>
      <a class="btn btn-outline" href="<?= e(url('/services')) ?>">بازگشت به خدمات</a>
    </div>
  </article>
</div>
<?php
$content = ob_get_clean();
require __DIR__ . '/../includes/layout.php';
