<?php
declare(strict_types=1);

$pageTitle = 'خانواده‌درمانی | مانا کلینیک سعادت‌آباد';
$pageDescription = 'خانواده‌درمانی در مانا کلینیک سعادت‌آباد: بهبود الگوهای ارتباطی، کاهش تعارض و فضای امن‌تر میان اعضای خانواده با همراهی متخصص.';
$pageCanonical = url('/services/family');
$pageKeywords = 'خانواده درمانی, مشاوره خانواده, تعارض خانوادگی, مانا کلینیک سعادت آباد';

$doctorsHref = service_doctors_search_href('خانواده درمانی');
$domainDoctors = service_doctors_by_domain($pdo, 'family');
$pageJsonLd = service_detail_json_ld(
    'خانواده‌درمانی',
    '/services/family',
    $pageDescription,
    'خانواده‌درمانی'
);

ob_start();
?>
<div class="container-page services-page service-detail-page">
  <nav class="services-breadcrumb" aria-label="مسیر صفحه">
    <a href="<?= e(url('/')) ?>">خانه</a>
    <span class="services-breadcrumb-sep" aria-hidden="true">/</span>
    <a href="<?= e(url('/services')) ?>">خدمات</a>
    <span class="services-breadcrumb-sep" aria-hidden="true">/</span>
    <span>خانواده‌درمانی</span>
  </nav>

  <header class="services-hero">
    <p class="services-kicker"><span>خدمات ما</span></p>
    <h1>خانواده‌درمانی</h1>
  </header>

  <article class="service-detail-body">
    <section>
      <h2>خانواده‌درمانی چیست؟</h2>
      <p>گاهی مسئله‌ای که یک نفر از اعضای خانواده تجربه می‌کند، فقط به خودش مربوط نیست و در ارتباط او با سایر اعضای خانواده معنا پیدا می‌کند.</p>
      <p>خانواده‌درمانی به جای اینکه فقط روی یک فرد تمرکز کند، به روابط، الگوهای ارتباطی و نقش اعضای خانواده در شکل‌گیری و تداوم مشکلات نیز توجه می‌کند.</p>
    </section>

    <section>
      <h2>خانواده‌درمانی برای چه مسائلی می‌تواند کمک‌کننده باشد؟</h2>
      <p>تعارض میان والدین و فرزندان، مشکلات دوران نوجوانی، اختلاف‌های خانوادگی، تغییراتی مانند طلاق یا ازدواج مجدد، مشکلات ارتباطی، تعارض‌های طولانی‌مدت و موقعیت‌هایی که بر روابط میان اعضای خانواده تأثیر گذاشته‌اند.</p>
    </section>

    <section>
      <h2>در جلسات خانواده‌درمانی چه اتفاقی می‌افتد؟</h2>
      <p>اعضای خانواده فرصتی پیدا می‌کنند تا شیوه ارتباط خود با یکدیگر را از زاویه‌ای تازه ببینند؛ درباره نیازها و احساساتشان صحبت کنند و با کمک درمانگر، الگوهایی را که باعث تکرار تعارض‌ها می‌شوند بهتر بشناسند. هدف، پیدا کردن راه‌هایی برای ارتباط سالم‌تر و رابطه‌ای امن‌تر میان اعضای خانواده است.</p>
    </section>

    <section class="service-detail-therapists" aria-labelledby="family-therapists-heading">
      <h2 id="family-therapists-heading">متخصصان خانواده‌درمانی</h2>
      <p><a href="<?= e($doctorsHref) ?>">آشنایی با متخصصان کلینیک در حوزه خانواده‌درمانی.</a></p>
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
      <a class="btn btn-primary" href="<?= e($doctorsHref) ?>">رزرو نوبت خانواده‌درمانی</a>
      <a class="btn btn-outline" href="<?= e(url('/assistant')) ?>">شروع با دستیار هوشمند</a>
      <a class="btn btn-outline" href="<?= e(url('/contact')) ?>">تماس با کلینیک</a>
      <a class="btn btn-outline" href="<?= e(url('/services')) ?>">بازگشت به خدمات</a>
    </div>
  </article>
</div>
<?php
$content = ob_get_clean();
require __DIR__ . '/../includes/layout.php';
