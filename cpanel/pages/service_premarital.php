<?php
declare(strict_types=1);

$pageTitle = 'مشاوره پیش از ازدواج | مانا کلینیک سعادت‌آباد';
$pageDescription = 'مشاوره پیش از ازدواج در مانا کلینیک سعادت‌آباد: شناخت عمیق‌تر خود، طرف مقابل و رابطه برای تصمیم آگاهانه‌تر و آمادگی بیشتر برای زندگی مشترک.';
$pageCanonical = url('/services/premarital');
$pageKeywords = 'مشاوره پیش از ازدواج, آمادگی ازدواج, مانا کلینیک سعادت آباد';

$doctorsHref = service_doctors_search_href('پیش از ازدواج');
$domainDoctors = service_doctors_by_domain($pdo, 'premarital');
$pageJsonLd = service_detail_json_ld(
    'مشاوره پیش از ازدواج',
    '/services/premarital',
    $pageDescription,
    'مشاوره پیش از ازدواج'
);

ob_start();
?>
<div class="container-page services-page service-detail-page">
  <nav class="services-breadcrumb" aria-label="مسیر صفحه">
    <a href="<?= e(url('/')) ?>">خانه</a>
    <span class="services-breadcrumb-sep" aria-hidden="true">/</span>
    <a href="<?= e(url('/services')) ?>">خدمات</a>
    <span class="services-breadcrumb-sep" aria-hidden="true">/</span>
    <span>مشاوره پیش از ازدواج</span>
  </nav>

  <header class="services-hero">
    <p class="services-kicker"><span>خدمات ما</span></p>
    <h1>مشاوره پیش از ازدواج</h1>
  </header>

  <article class="service-detail-body">
    <section>
      <h2>پیش از ازدواج چه چیزهایی را بهتر است درباره رابطه‌مان بدانیم؟</h2>
      <p>تصمیم به ازدواج فقط درباره دوست داشتن یکدیگر نیست. زندگی مشترک، دنیایی از ارزش‌ها، انتظارات، تفاوت‌ها و تصمیم‌های مشترک را با خود به همراه دارد.</p>
      <p>مشاوره پیش از ازدواج فرصتی است برای اینکه پیش از ورود به این مرحله، خودتان، طرف مقابل و رابطه‌تان را با دقت بیشتری بشناسید و درباره موضوعاتی که ممکن است در آینده اهمیت پیدا کنند، گفت‌وگو کنید.</p>
    </section>

    <section>
      <h2>در مشاوره پیش از ازدواج چه موضوعاتی بررسی می‌شود؟</h2>
      <p>ارزش‌ها و باورها، انتظارات از ازدواج، شیوه ارتباط و حل اختلاف، مسائل مالی، رابطه با خانواده‌ها، فرزندآوری و فرزندپروری، سبک زندگی، صمیمیت و مسائل جنسی، اهداف آینده و تفاوت‌های فردی از جمله موضوعاتی هستند که می‌توانند مورد بررسی قرار بگیرند.</p>
    </section>

    <section>
      <h2>این جلسات قرار است به چه چیزی کمک کنند؟</h2>
      <p>هدف مشاوره پیش از ازدواج این نیست که درمانگر به جای شما تصمیم بگیرد که «ازدواج کنید یا نکنید». هدف این است که شناخت شما از خودتان، طرف مقابل و رابطه‌تان عمیق‌تر شود تا بتوانید تصمیمی آگاهانه‌تر بگیرید و با آمادگی بیشتری وارد زندگی مشترک شوید.</p>
    </section>

    <section class="service-detail-therapists" aria-labelledby="premarital-therapists-heading">
      <h2 id="premarital-therapists-heading">متخصصان مشاوره پیش از ازدواج</h2>
      <p><a href="<?= e($doctorsHref) ?>">معرفی متخصصان کلینیک در زمینه مشاوره پیش از ازدواج و روابط زوجین.</a></p>
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
      <a class="btn btn-primary" href="<?= e($doctorsHref) ?>">رزرو نوبت پیش از ازدواج</a>
      <a class="btn btn-outline" href="<?= e(url('/assistant')) ?>">شروع با دستیار هوشمند</a>
      <a class="btn btn-outline" href="<?= e(url('/contact')) ?>">تماس با کلینیک</a>
      <a class="btn btn-outline" href="<?= e(url('/services')) ?>">بازگشت به خدمات</a>
    </div>
  </article>
</div>
<?php
$content = ob_get_clean();
require __DIR__ . '/../includes/layout.php';
