<?php
declare(strict_types=1);

$pageTitle = 'آزمون‌ها و ارزیابی‌های روان‌شناختی | مانا کلینیک';
$pageDescription = 'آزمون‌ها و ارزیابی‌های روان‌شناختی در مانا کلینیک: شخصیت، توانایی‌های شناختی، توجه، استعداد و وضعیت هیجانی در کنار تفسیر تخصصی. مشاهده آزمون‌های قابل ارائه.';
$pageCanonical = url('/services/assessments');
$pageKeywords = 'آزمون روانشناسی, ارزیابی روانشناختی, پرسشنامه شخصیت, مانا کلینیک';

$testsHref = url('/tests');
$doctorsHref = url('/doctors');
$pageJsonLd = service_detail_json_ld(
    'آزمون‌ها و ارزیابی‌های روان‌شناختی',
    '/services/assessments',
    $pageDescription,
    'آزمون‌ها و ارزیابی‌های روان‌شناختی'
);

ob_start();
?>
<div class="container-page services-page service-detail-page">
  <nav class="services-breadcrumb" aria-label="مسیر صفحه">
    <a href="<?= e(url('/')) ?>">خانه</a>
    <span class="services-breadcrumb-sep" aria-hidden="true">/</span>
    <a href="<?= e(url('/services')) ?>">خدمات</a>
    <span class="services-breadcrumb-sep" aria-hidden="true">/</span>
    <span>آزمون‌ها و ارزیابی‌ها</span>
  </nav>

  <header class="services-hero">
    <p class="services-kicker"><span>خدمات ما</span></p>
    <h1>آزمون‌ها و ارزیابی‌های روان‌شناختی</h1>
  </header>

  <article class="service-detail-body">
    <section>
      <h2>آزمون روان‌شناختی چه کمکی می‌کند؟</h2>
      <p>گاهی برای شناخت دقیق‌تر یک مسئله، تنها صحبت کردن کافی نیست. آزمون‌های روان‌شناختی استاندارد می‌توانند در کنار مصاحبه تخصصی، اطلاعات بیشتری درباره ویژگی‌ها، توانایی‌ها یا برخی جنبه‌های روان‌شناختی فرد در اختیار متخصص قرار دهند.</p>
    </section>

    <section>
      <h2>چه چیزهایی می‌توان ارزیابی کرد؟</h2>
      <p>بسته به نوع ارزیابی، موضوعاتی مانند ویژگی‌های شخصیتی، توانایی‌های شناختی، توجه، استعدادها، وضعیت هیجانی و برخی مشکلات روان‌شناختی می‌توانند مورد بررسی قرار بگیرند.</p>
    </section>

    <section>
      <h2>نتیجه آزمون چگونه تفسیر می‌شود؟</h2>
      <p>آزمون روان‌شناختی به‌تنهایی قرار نیست درباره یک فرد حکم قطعی صادر کند. نتایج باید در کنار مصاحبه، شرایط زندگی و سایر اطلاعات مرتبط بررسی و توسط متخصص تفسیر شوند تا تصویر دقیق‌تر و قابل‌اعتمادتری به دست آید.</p>
    </section>

    <section>
      <h2>آزمون‌های قابل ارائه</h2>
      <p><a href="<?= e($testsHref) ?>">معرفی آزمون‌های موجود، کاربرد هر آزمون و متخصصان ارائه‌دهنده خدمات ارزیابی.</a></p>
    </section>

    <div class="service-detail-cta">
      <a class="btn btn-primary" href="<?= e($testsHref) ?>">مشاهده آزمون‌ها</a>
      <a class="btn btn-outline" href="<?= e($doctorsHref) ?>">مشاوره با متخصص</a>
      <a class="btn btn-outline" href="<?= e(url('/contact')) ?>">تماس با کلینیک</a>
      <a class="btn btn-outline" href="<?= e(url('/services')) ?>">بازگشت به خدمات</a>
    </div>
  </article>
</div>
<?php
$content = ob_get_clean();
require __DIR__ . '/../includes/layout.php';
