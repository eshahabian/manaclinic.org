<?php
declare(strict_types=1);

$pageTitle = 'مشاوره فردی و خدمات روانشناسی | مانا کلینیک سعادت‌آباد';
$pageDescription = 'مشاوره فردی، زوج‌درمانی، کودک و نوجوان، پیش از ازدواج، خانواده‌درمانی، آزمون و کارگاه‌های مانا کلینیک سعادت‌آباد. رزرو نوبت حضوری و آنلاین.';
$pageCanonical = url('/services/individual');
$pageKeywords = 'مشاوره فردی, زوج درمانی, کودک و نوجوان, پیش از ازدواج, خانواده درمانی, کارگاه روانشناسی, مانا کلینیک';

$doctorsHref = service_doctors_search_href('مشاوره فردی');
$heroImage = url('/assets/img/services/individual-counseling.png');
$individualDoctors = service_doctors_by_domain($pdo, 'individual');

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
    'مشاوره فردی و خدمات روانشناسی',
    '/services/individual',
    $pageDescription,
    'مشاوره و روان‌درمانی',
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
      <?php endif; ?>
    </section>

    <section>
      <h2>زوج‌درمانی</h2>
      <h3>زوج‌درمانی چیست؟</h3>
      <p>هر رابطه‌ای ممکن است دوره‌هایی از نزدیکی، فاصله، اختلاف و سوءتفاهم را تجربه کند. گاهی تلاش برای حل مشکلات به گفت‌وگوهای تکراری، دلخوری‌های بیشتر یا دور شدن دو نفر از یکدیگر منجر می‌شود.</p>
      <p>زوج‌درمانی فضایی تخصصی برای کمک به زوج‌هاست تا آنچه در رابطه میانشان می‌گذرد را بهتر ببینند، الگوهای تکرارشونده رابطه را بشناسند و راه‌های سالم‌تر و مؤثرتری برای ارتباط و حل تعارض پیدا کنند.</p>
      <h3>چه مسائلی می‌تواند موضوع زوج‌درمانی باشد؟</h3>
      <p>تعارض‌های مکرر، مشکلات ارتباطی، فاصله عاطفی، بی‌اعتمادی، خیانت، اختلاف درباره مسائل مالی یا خانواده‌ها، تفاوت در سبک زندگی، مسائل جنسی، فرزندپروری و تصمیم‌های مهم زندگی مشترک.</p>
      <h3>در جلسات زوج‌درمانی چه اتفاقی می‌افتد؟</h3>
      <p>درمانگر تلاش می‌کند تنها به این سؤال که «چه کسی مقصر است؟» نپردازد؛ بلکه به زوج کمک می‌کند چرخه‌ای را که میان آنها شکل گرفته بشناسند و بفهمند پشت بسیاری از اختلاف‌ها چه نیازها، احساسات و الگوهایی قرار دارد. هدف، ایجاد فضایی برای گفت‌وگوی مؤثرتر و ساختن رابطه‌ای آگاهانه‌تر است.</p>
      <p><a href="<?= e(url('/services/couples')) ?>">متخصصان زوج‌درمانی — معرفی متخصصان کلینیک در حوزه زوج‌درمانی و روابط عاطفی.</a></p>
    </section>

    <section>
      <h2>کودک و نوجوان</h2>
      <h3>مشاوره کودک و نوجوان چیست؟</h3>
      <p>کودکان و نوجوانان همیشه نمی‌توانند آنچه در درونشان می‌گذرد را با کلمات بیان کنند. گاهی یک تغییر در رفتار، افت تحصیلی، پرخاشگری، گوشه‌گیری یا حتی تغییر در خواب و روابطشان می‌تواند نشانه‌ای از مسئله‌ای باشد که نیاز به توجه دارد.</p>
      <p>مشاوره و روان‌درمانی کودک و نوجوان به آنها کمک می‌کند متناسب با سن و شرایطشان، احساسات و تجربه‌های خود را بهتر بشناسند و مهارت‌های لازم برای مواجهه با چالش‌هایشان را یاد بگیرند.</p>
      <h3>چه موضوعاتی ممکن است نیاز به کمک تخصصی داشته باشد؟</h3>
      <p>اضطراب و ترس، پرخاشگری، مشکلات رفتاری، افت تحصیلی، مشکلات ارتباط با همسالان، کاهش اعتمادبه‌نفس، مشکلات خواب، جدایی والدین، تغییرات خانوادگی، مشکلات دوران نوجوانی و سایر مسائل هیجانی و رفتاری.</p>
      <h3>جلسات کودک و نوجوان چگونه پیش می‌رود؟</h3>
      <p>شیوه کار به سن، شرایط و مسئله کودک یا نوجوان بستگی دارد. ممکن است بخشی از جلسات با والدین و بخشی با خود کودک یا نوجوان برگزار شود. متخصص ابتدا تلاش می‌کند تصویری کامل‌تر از شرایط فردی، خانوادگی، تحصیلی و ارتباطی کودک به دست آورد و سپس مناسب‌ترین مسیر را پیشنهاد می‌دهد.</p>
      <p><a href="<?= e(url('/services/child')) ?>">متخصصان کودک و نوجوان — آشنایی با متخصصان حوزه کودک و نوجوان کلینیک.</a></p>
    </section>

    <section>
      <h2>مشاوره پیش از ازدواج</h2>
      <h3>پیش از ازدواج چه چیزهایی را بهتر است درباره رابطه‌مان بدانیم؟</h3>
      <p>تصمیم به ازدواج فقط درباره دوست داشتن یکدیگر نیست. زندگی مشترک، دنیایی از ارزش‌ها، انتظارات، تفاوت‌ها و تصمیم‌های مشترک را با خود به همراه دارد.</p>
      <p>مشاوره پیش از ازدواج فرصتی است برای اینکه پیش از ورود به این مرحله، خودتان، طرف مقابل و رابطه‌تان را با دقت بیشتری بشناسید و درباره موضوعاتی که ممکن است در آینده اهمیت پیدا کنند، گفت‌وگو کنید.</p>
      <h3>در مشاوره پیش از ازدواج چه موضوعاتی بررسی می‌شود؟</h3>
      <p>ارزش‌ها و باورها، انتظارات از ازدواج، شیوه ارتباط و حل اختلاف، مسائل مالی، رابطه با خانواده‌ها، فرزندآوری و فرزندپروری، سبک زندگی، صمیمیت و مسائل جنسی، اهداف آینده و تفاوت‌های فردی از جمله موضوعاتی هستند که می‌توانند مورد بررسی قرار بگیرند.</p>
      <h3>این جلسات قرار است به چه چیزی کمک کنند؟</h3>
      <p>هدف مشاوره پیش از ازدواج این نیست که درمانگر به جای شما تصمیم بگیرد که «ازدواج کنید یا نکنید». هدف این است که شناخت شما از خودتان، طرف مقابل و رابطه‌تان عمیق‌تر شود تا بتوانید تصمیمی آگاهانه‌تر بگیرید و با آمادگی بیشتری وارد زندگی مشترک شوید.</p>
      <p><a href="<?= e(url('/services/premarital')) ?>">متخصصان مشاوره پیش از ازدواج — معرفی متخصصان کلینیک در زمینه مشاوره پیش از ازدواج و روابط زوجین.</a></p>
    </section>

    <section>
      <h2>خانواده‌درمانی</h2>
      <h3>خانواده‌درمانی چیست؟</h3>
      <p>گاهی مسئله‌ای که یک نفر از اعضای خانواده تجربه می‌کند، فقط به خودش مربوط نیست و در ارتباط او با سایر اعضای خانواده معنا پیدا می‌کند.</p>
      <p>خانواده‌درمانی به جای اینکه فقط روی یک فرد تمرکز کند، به روابط، الگوهای ارتباطی و نقش اعضای خانواده در شکل‌گیری و تداوم مشکلات نیز توجه می‌کند.</p>
      <h3>خانواده‌درمانی برای چه مسائلی می‌تواند کمک‌کننده باشد؟</h3>
      <p>تعارض میان والدین و فرزندان، مشکلات دوران نوجوانی، اختلاف‌های خانوادگی، تغییراتی مانند طلاق یا ازدواج مجدد، مشکلات ارتباطی، تعارض‌های طولانی‌مدت و موقعیت‌هایی که بر روابط میان اعضای خانواده تأثیر گذاشته‌اند.</p>
      <h3>در جلسات خانواده‌درمانی چه اتفاقی می‌افتد؟</h3>
      <p>اعضای خانواده فرصتی پیدا می‌کنند تا شیوه ارتباط خود با یکدیگر را از زاویه‌ای تازه ببینند؛ درباره نیازها و احساساتشان صحبت کنند و با کمک درمانگر، الگوهایی را که باعث تکرار تعارض‌ها می‌شوند بهتر بشناسند. هدف، پیدا کردن راه‌هایی برای ارتباط سالم‌تر و رابطه‌ای امن‌تر میان اعضای خانواده است.</p>
      <p><a href="<?= e(url('/services/family')) ?>">متخصصان خانواده‌درمانی — آشنایی با متخصصان کلینیک در حوزه خانواده‌درمانی.</a></p>
    </section>

    <section>
      <h2>آزمون‌ها و ارزیابی‌های روان‌شناختی</h2>
      <h3>آزمون روان‌شناختی چه کمکی می‌کند؟</h3>
      <p>گاهی برای شناخت دقیق‌تر یک مسئله، تنها صحبت کردن کافی نیست. آزمون‌های روان‌شناختی استاندارد می‌توانند در کنار مصاحبه تخصصی، اطلاعات بیشتری درباره ویژگی‌ها، توانایی‌ها یا برخی جنبه‌های روان‌شناختی فرد در اختیار متخصص قرار دهند.</p>
      <h3>چه چیزهایی می‌توان ارزیابی کرد؟</h3>
      <p>بسته به نوع ارزیابی، موضوعاتی مانند ویژگی‌های شخصیتی، توانایی‌های شناختی، توجه، استعدادها، وضعیت هیجانی و برخی مشکلات روان‌شناختی می‌توانند مورد بررسی قرار بگیرند.</p>
      <h3>نتیجه آزمون چگونه تفسیر می‌شود؟</h3>
      <p>آزمون روان‌شناختی به‌تنهایی قرار نیست درباره یک فرد حکم قطعی صادر کند. نتایج باید در کنار مصاحبه، شرایط زندگی و سایر اطلاعات مرتبط بررسی و توسط متخصص تفسیر شوند تا تصویر دقیق‌تر و قابل‌اعتمادتری به دست آید.</p>
      <h3>آزمون‌های قابل ارائه</h3>
      <p><a href="<?= e(url('/tests')) ?>">معرفی آزمون‌های موجود، کاربرد هر آزمون و متخصصان ارائه‌دهنده خدمات ارزیابی.</a></p>
    </section>

    <section>
      <h2>کارگاه‌ها و دوره‌های آموزشی</h2>
      <p class="services-lead" style="margin:0 0 1rem;text-align:right">فرصتی برای یادگیری، تجربه و رشد در کنار دیگران</p>
      <p>روان‌شناسی فقط به اتاق درمان محدود نمی‌شود. گاهی یک دوره آموزشی، یک گفت‌وگوی گروهی یا تجربه‌ای مشترک با افرادی که دغدغه‌ای مشابه دارند، می‌تواند دریچه تازه‌ای برای شناخت خود و روابطمان باز کند.</p>
      <p>کارگاه‌ها و دوره‌های کلینیک مانا با موضوعات متنوع روان‌شناختی و مهارت‌های فردی و بین‌فردی برگزار می‌شوند؛ برنامه‌هایی که می‌توانند فرصتی برای یادگیری، تمرین و تجربه در فضایی تخصصی و تعاملی باشند.</p>
      <h3>چه موضوعاتی در این برنامه‌ها مطرح می‌شود؟</h3>
      <p>موضوع هر برنامه متناسب با هدف و مخاطبان آن متفاوت است؛ از خودشناسی و رشد فردی و مهارت‌های ارتباطی گرفته تا روابط عاطفی، فرزندپروری، تنظیم هیجان، مهارت‌های زندگی و موضوعات تخصصی‌تر روان‌شناسی.</p>
      <p>برخی برنامه‌ها با رویکرد آموزشی طراحی می‌شوند و برخی دیگر امکان گفت‌وگو، تعامل و تمرین گروهی بیشتری دارند.</p>
      <h3>در این دوره‌ها چه چیزی تجربه می‌کنیم؟</h3>
      <p>قرار نیست فقط شنونده باشیم. بسته به نوع برنامه، ممکن است با مفاهیم جدید آشنا شویم، درباره تجربه‌های خود گفت‌وگو کنیم، تمرین‌های فردی یا گروهی انجام دهیم و از دیدگاه‌ها و تجربه‌های دیگران نیز استفاده کنیم.</p>
      <p>هدف این است که آنچه در این برنامه‌ها یاد می‌گیریم، تا حد امکان از فضای کلاس فراتر برود و در زندگی روزمره، روابط و تصمیم‌های ما قابل استفاده باشد.</p>
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
$pageScripts = '<script src="' . e(url('/assets/js/home-workshop-banners.js')) . '?v=20260908k"></script>';
$GLOBALS['pageScripts'] = $pageScripts;
require __DIR__ . '/../includes/layout.php';
