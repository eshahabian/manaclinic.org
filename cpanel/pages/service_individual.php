<?php
declare(strict_types=1);

$pageTitle = 'مشاوره فردی | مانا کلینیک سعادت‌آباد';
$pageDescription = 'مشاوره فردی در مانا کلینیک سعادت‌آباد: فضای امن و محرمانه برای شناخت افکار و احساسات، کاهش اضطراب و نگرانی، و همراهی با روان‌شناس. رزرو نوبت حضوری و آنلاین.';
$pageCanonical = url('/services/individual');
$pageKeywords = 'مشاوره فردی, روان‌درمانی فردی, روانشناس سعادت آباد, مشاوره اضطراب, مانا کلینیک';

$doctorsHref = url('/doctors?q=' . rawurlencode('مشاوره فردی'));

$pageJsonLd = [
    '@context' => 'https://schema.org',
    '@graph' => [
        [
            '@type' => 'BreadcrumbList',
            'itemListElement' => [
                ['@type' => 'ListItem', 'position' => 1, 'name' => 'صفحه اصلی', 'item' => seo_absolute_url('/')],
                ['@type' => 'ListItem', 'position' => 2, 'name' => 'خدمات', 'item' => seo_absolute_url('/services')],
                ['@type' => 'ListItem', 'position' => 3, 'name' => 'مشاوره فردی', 'item' => seo_absolute_url('/services/individual')],
            ],
        ],
        [
            '@type' => 'MedicalWebPage',
            'name' => 'مشاوره فردی',
            'description' => $pageDescription,
            'url' => seo_absolute_url('/services/individual'),
            'isPartOf' => ['@type' => 'WebSite', 'name' => 'مانا کلینیک', 'url' => seo_absolute_url('/')],
            'about' => [
                '@type' => 'MedicalTherapy',
                'name' => 'مشاوره و روان‌درمانی فردی',
            ],
        ],
    ],
];

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
    <p class="services-kicker"><span>خدمات ما</span></p>
    <h1>مشاوره فردی</h1>
    <p class="services-lead">فضایی امن و محرمانه برای شناخت خود، سبک کردن بار ذهنی، و برداشتن قدم‌های روشن‌تر با همراهی متخصص.</p>
  </header>

  <article class="service-detail-body">
    <section>
      <h2>مشاوره فردی چیست؟</h2>
      <p>
        گاهی در زندگی با مسئله‌ای روبه‌رو می‌شویم که به‌تنهایی پیدا کردن راه‌حل برایش ساده نیست؛ گاهی احساس می‌کنیم در یک چرخه تکراری گیر افتاده‌ایم، در روابطمان به مشکل خورده‌ایم یا حتی نمی‌دانیم دقیقاً چه چیزی حالمان را خوب نمی‌کند.
      </p>
      <p>
        مشاوره فردی فرصتی است برای اینکه در فضایی امن، محرمانه و بدون قضاوت، درباره آنچه تجربه می‌کنید صحبت کنید و با کمک یک متخصص، افکار، احساسات و الگوهای رفتاری خود را بهتر بشناسید و برای تغییر قدم بردارید.
      </p>
    </section>

    <section>
      <h2>چه زمانی می‌توان از مشاوره فردی کمک گرفت؟</h2>
      <p>
        اضطراب و نگرانی، احساس غم یا بی‌انگیزگی، مشکلات اعتمادبه‌نفس، دشواری در برقراری یا حفظ روابط، تعارض‌های بین‌فردی، تصمیم‌گیری‌های مهم، مسائل شغلی و تحصیلی، تجربه سوگ یا جدایی، تغییرات مهم زندگی و بسیاری از چالش‌های فردی دیگر می‌توانند موضوع جلسات مشاوره باشند.
      </p>
    </section>

    <section>
      <h2>مسیر مشاوره از کجا شروع می‌شود؟</h2>
      <p>
        قرار نیست از همان جلسه اول همه چیز را بدانید یا پاسخ مشخصی برای مشکلتان داشته باشید. در جلسات ابتدایی، فرصت دارید درباره آنچه شما را به مشاوره رسانده صحبت کنید و درمانگر با شناخت دقیق‌تر شرایط، سابقه و نیازهای شما، به درک روشن‌تری از مسئله کمک می‌کند. سپس با همکاری یکدیگر، مسیر و هدف‌های مناسب برای ادامه جلسات مشخص می‌شود.
      </p>
    </section>

    <section>
      <h2>متخصصان مشاوره فردی</h2>
      <p>
        با
        <a href="<?= e($doctorsHref) ?>">روان‌شناسان و درمانگران مانا کلینیک</a>
        که در زمینه مشاوره و روان‌درمانی فردی فعالیت می‌کنند آشنا شوید و نوبت حضوری یا آنلاین رزرو کنید.
      </p>
    </section>

    <div class="service-detail-cta">
      <a class="btn btn-primary" href="<?= e($doctorsHref) ?>">رزرو نوبت مشاوره فردی</a>
      <a class="btn btn-outline" href="<?= e(url('/assistant')) ?>">شروع با دستیار هوشمند</a>
      <a class="btn btn-outline" href="<?= e(url('/contact')) ?>">تماس با کلینیک</a>
    </div>

    <p class="muted service-detail-related">
      همچنین می‌توانید
      <a href="<?= e(url('/faq')) ?>">سؤالات متداول</a>
      یا
      <a href="<?= e(url('/articles')) ?>">مقالات</a>
      را ببینید، یا به
      <a href="<?= e(url('/services')) ?>">فهرست خدمات</a>
      برگردید.
    </p>
  </article>
</div>
<?php
$content = ob_get_clean();
require __DIR__ . '/../includes/layout.php';
