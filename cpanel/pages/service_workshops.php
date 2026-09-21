<?php
declare(strict_types=1);

$pageTitle = 'کارگاه‌ها و دوره‌های آموزشی | مانا کلینیک';
$pageDescription = 'کارگاه‌ها و دوره‌های آموزشی مانا کلینیک سعادت‌آباد: یادگیری مهارت‌های روان‌شناختی، ارتباطی و رشد فردی. برنامه‌های پیش رو و ثبت‌نام آنلاین.';
$pageCanonical = url('/services/workshops');
$pageKeywords = 'کارگاه روانشناسی, دوره آموزشی, مهارت ارتباطی, مانا کلینیک سعادت آباد';

$upcomingWorkshops = service_upcoming_workshops($pdo);
$pageJsonLd = service_detail_json_ld(
    'کارگاه‌ها و دوره‌های آموزشی',
    '/services/workshops',
    $pageDescription,
    'کارگاه‌ها و دوره‌های آموزشی روان‌شناختی'
);

ob_start();
?>
<div class="container-page services-page service-detail-page">
  <nav class="services-breadcrumb" aria-label="مسیر صفحه">
    <a href="<?= e(url('/')) ?>">خانه</a>
    <span class="services-breadcrumb-sep" aria-hidden="true">/</span>
    <a href="<?= e(url('/services')) ?>">خدمات</a>
    <span class="services-breadcrumb-sep" aria-hidden="true">/</span>
    <span>کارگاه‌ها و دوره‌ها</span>
  </nav>

  <header class="services-hero">
    <p class="services-kicker"><span>خدمات ما</span></p>
    <h1>کارگاه‌ها و دوره‌های آموزشی</h1>
    <p class="services-lead">فرصتی برای یادگیری، تجربه و رشد در کنار دیگران</p>
  </header>

  <article class="service-detail-body">
    <section>
      <p>روان‌شناسی فقط به اتاق درمان محدود نمی‌شود. گاهی یک دوره آموزشی، یک گفت‌وگوی گروهی یا تجربه‌ای مشترک با افرادی که دغدغه‌ای مشابه دارند، می‌تواند دریچه تازه‌ای برای شناخت خود و روابطمان باز کند.</p>
      <p>کارگاه‌ها و دوره‌های کلینیک مانا با موضوعات متنوع روان‌شناختی و مهارت‌های فردی و بین‌فردی برگزار می‌شوند؛ برنامه‌هایی که می‌توانند فرصتی برای یادگیری، تمرین و تجربه در فضایی تخصصی و تعاملی باشند.</p>
    </section>

    <section>
      <h2>چه موضوعاتی در این برنامه‌ها مطرح می‌شود؟</h2>
      <p>موضوع هر برنامه متناسب با هدف و مخاطبان آن متفاوت است؛ از خودشناسی و رشد فردی و مهارت‌های ارتباطی گرفته تا روابط عاطفی، فرزندپروری، تنظیم هیجان، مهارت‌های زندگی و موضوعات تخصصی‌تر روان‌شناسی.</p>
      <p>برخی برنامه‌ها با رویکرد آموزشی طراحی می‌شوند و برخی دیگر امکان گفت‌وگو، تعامل و تمرین گروهی بیشتری دارند.</p>
    </section>

    <section>
      <h2>در این دوره‌ها چه چیزی تجربه می‌کنیم؟</h2>
      <p>قرار نیست فقط شنونده باشیم. بسته به نوع برنامه، ممکن است با مفاهیم جدید آشنا شویم، درباره تجربه‌های خود گفت‌وگو کنیم، تمرین‌های فردی یا گروهی انجام دهیم و از دیدگاه‌ها و تجربه‌های دیگران نیز استفاده کنیم.</p>
      <p>هدف این است که آنچه در این برنامه‌ها یاد می‌گیریم، تا حد امکان از فضای کلاس فراتر برود و در زندگی روزمره، روابط و تصمیم‌های ما قابل استفاده باشد.</p>
    </section>

    <section class="service-detail-upcoming-workshops" aria-labelledby="upcoming-workshops-heading">
      <h2 id="upcoming-workshops-heading">برنامه‌های پیش رو</h2>
      <?php if ($upcomingWorkshops): ?>
        <ul class="service-workshop-list">
          <?php foreach ($upcomingWorkshops as $workshop): ?>
            <?php
              $phase = workshop_promo_phase($workshop);
              $phaseLabel = workshop_promo_phase_label($phase);
              $when = service_workshop_when_label($workshop);
              $applyHref = workshop_apply_url((string) ($workshop['id'] ?? ''));
            ?>
            <li class="service-workshop-item">
              <div class="service-workshop-item-main">
                <a class="service-workshop-title" href="<?= e($applyHref) ?>"><?= e((string) ($workshop['title'] ?? '')) ?></a>
                <div class="service-workshop-meta">
                  <span class="badge"><?= e(workshop_type_label((string) ($workshop['type'] ?? ''))) ?></span>
                  <span class="badge"><?= e($phaseLabel) ?></span>
                  <?php if (!empty($workshop['doctor_name'])): ?>
                    <span class="muted"><?= e((string) $workshop['doctor_name']) ?></span>
                  <?php endif; ?>
                </div>
                <p class="service-workshop-when muted"><?= e($when) ?></p>
              </div>
              <a class="btn btn-outline btn-sm" href="<?= e($applyHref) ?>">جزئیات و ثبت‌نام</a>
            </li>
          <?php endforeach; ?>
        </ul>
      <?php else: ?>
        <p class="muted">در حال حاضر کارگاه یا دوره فعالی برای نمایش نیست؛ به‌زودی برنامه‌های جدید اینجا اعلام می‌شود.</p>
      <?php endif; ?>
      <p class="service-detail-related">
        <a href="<?= e(url('/#home-workshop-banners')) ?>">مشاهده کارگاه‌ها در صفحه اصلی</a>
      </p>
    </section>

    <div class="service-detail-cta">
      <a class="btn btn-primary" href="<?= e(url('/#home-workshop-banners')) ?>">کارگاه‌های صفحه اصلی</a>
      <a class="btn btn-outline" href="<?= e(url('/contact')) ?>">تماس با کلینیک</a>
      <a class="btn btn-outline" href="<?= e(url('/services')) ?>">بازگشت به خدمات</a>
    </div>
  </article>
</div>
<?php
$content = ob_get_clean();
require __DIR__ . '/../includes/layout.php';
