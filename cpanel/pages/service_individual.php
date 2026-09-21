<?php
declare(strict_types=1);

$pageTitle = 'مشاوره فردی | مانا کلینیک سعادت‌آباد';
$pageDescription = 'مشاوره فردی در مانا کلینیک سعادت‌آباد: فضای امن و محرمانه برای شناخت افکار و احساسات، کاهش اضطراب و نگرانی، و همراهی با روان‌شناس. رزرو نوبت حضوری و آنلاین.';
$pageCanonical = url('/services/individual');
$pageKeywords = 'مشاوره فردی, روان‌درمانی فردی, روانشناس سعادت آباد, مشاوره اضطراب, مانا کلینیک';

$doctorsHref = service_doctors_search_href('مشاوره فردی');
$heroImage = url('/assets/img/services/individual-counseling.png');
$individualDoctors = service_doctors_by_domain($pdo, 'individual');
$pageJsonLd = service_detail_json_ld(
    'مشاوره فردی',
    '/services/individual',
    $pageDescription,
    'مشاوره و روان‌درمانی فردی',
    '/assets/img/services/individual-counseling.png'
);

ob_start();
?>
<div class="container-page services-page service-detail-page">
  <nav class="services-breadcrumb" aria-label="مسیر صفحه">
    <a href="<?= e(url('/')) ?>">خانه</a>
    <span class="services-breadcrumb-sep" aria-hidden="true">/</span>
    <a href="<?= e(url('/services')) ?>">خدمات</a>
    <span class="services-breadcrumb-sep" aria-hidden="true">/</span>
    <span>مشاوره فردی</span>
  </nav>

  <header class="services-hero">
    <figure class="service-detail-hero-media">
      <img
        src="<?= e($heroImage) ?>"
        alt="فضای آرام اتاق مشاوره فردی در کلینیک روانشناسی"
        width="1600"
        height="900"
        loading="eager"
        decoding="async"
      >
    </figure>
    <p class="services-kicker"><span>خدمات ما</span></p>
    <h1>مشاوره فردی</h1>
  </header>

  <article class="service-detail-body">
    <section>
      <h2>مشاوره فردی چیست؟</h2>
      <p>گاهی در زندگی با مسئله‌ای روبه‌رو می‌شویم که به‌تنهایی پیدا کردن راه‌حل برایش ساده نیست؛ گاهی احساس می‌کنیم در یک چرخه تکراری گیر افتاده‌ایم، در روابطمان به مشکل خورده‌ایم یا حتی نمی‌دانیم دقیقاً چه چیزی حالمان را خوب نمی‌کند.</p>
      <p>مشاوره فردی فرصتی است برای اینکه در فضایی امن، محرمانه و بدون قضاوت، درباره آنچه تجربه می‌کنید صحبت کنید و با کمک یک متخصص، افکار، احساسات و الگوهای رفتاری خود را بهتر بشناسید و برای تغییر قدم بردارید.</p>
    </section>

    <section>
      <h2>چه زمانی می‌توان از مشاوره فردی کمک گرفت؟</h2>
      <p>اضطراب و نگرانی، احساس غم یا بی‌انگیزگی، مشکلات اعتمادبه‌نفس، دشواری در برقراری یا حفظ روابط، تعارض‌های بین‌فردی، تصمیم‌گیری‌های مهم، مسائل شغلی و تحصیلی، تجربه سوگ یا جدایی، تغییرات مهم زندگی و بسیاری از چالش‌های فردی دیگر می‌توانند موضوع جلسات مشاوره باشند.</p>
    </section>

    <section>
      <h2>مسیر مشاوره از کجا شروع می‌شود؟</h2>
      <p>قرار نیست از همان جلسه اول همه چیز را بدانید یا پاسخ مشخصی برای مشکلتان داشته باشید. در جلسات ابتدایی، فرصت دارید درباره آنچه شما را به مشاوره رسانده صحبت کنید و درمانگر با شناخت دقیق‌تر شرایط، سابقه و نیازهای شما، به درک روشن‌تری از مسئله کمک می‌کند. سپس با همکاری یکدیگر، مسیر و هدف‌های مناسب برای ادامه جلسات مشخص می‌شود.</p>
    </section>

    <section class="service-detail-therapists" aria-labelledby="individual-therapists-heading">
      <h2 id="individual-therapists-heading">متخصصان مشاوره فردی</h2>
      <p><a href="<?= e($doctorsHref) ?>">آشنایی با روان‌شناسان و درمانگران کلینیک که در زمینه مشاوره و روان‌درمانی فردی فعالیت می‌کنند.</a></p>
      <?php if ($individualDoctors): ?>
        <div class="doctors-directory service-detail-doctors">
          <?php foreach ($individualDoctors as $doc): ?>
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
      <a class="btn btn-primary" href="<?= e($doctorsHref) ?>">رزرو نوبت مشاوره فردی</a>
      <a class="btn btn-outline" href="<?= e(url('/assistant')) ?>">شروع با دستیار هوشمند</a>
      <a class="btn btn-outline" href="<?= e(url('/contact')) ?>">تماس با کلینیک</a>
      <a class="btn btn-outline" href="<?= e(url('/services')) ?>">بازگشت به خدمات</a>
    </div>
  </article>
</div>
<?php
$content = ob_get_clean();
require __DIR__ . '/../includes/layout.php';
