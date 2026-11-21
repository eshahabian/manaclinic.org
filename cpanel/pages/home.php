<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/learning.php';
$homeLearningCatalog = mana_learning_catalog();
$homeCouplesLessons = mana_learning_lessons('couples-therapy', $homeLearningCatalog['couples-therapy']);
$homeLatestCouples = $homeCouplesLessons[array_key_last($homeCouplesLessons)];

$doctors = $pdo->query("
  SELECT dp.*, u.name
  FROM doctor_profiles dp
  JOIN users u ON u.id = dp.user_id
  WHERE dp.is_active = 1 AND dp.is_approved = 1 AND u.is_disabled = 0
    AND TRIM(COALESCE(dp.avatar_url, '')) <> ''
  ORDER BY RAND()
  LIMIT 4
")->fetchAll();

$articles = $pdo->query("
  SELECT a.*, u.name AS author_name
  FROM articles a
  JOIN users u ON u.id = a.author_id
  WHERE a.published = 1
  ORDER BY a.published_at DESC
  LIMIT 3
")->fetchAll();
$leadArticle = $articles[0] ?? null;
$moreArticles = array_slice($articles, 1);

$pageTitle = 'کلینیک روانشناسی سعادت‌آباد';
$pageDescription = 'مانا کلینیک سعادت‌آباد؛ روانشناسی، روان‌درمانی، زوج‌درمانی، کارگاه و دوره آموزشی، رزرو نوبت آنلاین و دستیار هوشمند سلامت روان.';
$pageCanonical = url('/');
$pageKeywords = 'مانا کلینیک, روانشناس سعادت آباد, رزرو نوبت روانشناسی, کارگاه روانشناسی, زوج درمانی, مشاوره اضطراب';
require_once dirname(__DIR__) . '/includes/daily_quotes.php';
$dailyQuote = daily_quote_for_date();
$workshopBanners = function_exists('workshop_home_banners') ? workshop_home_banners($pdo, 3) : [];
$pageHead = '<link rel="stylesheet" href="' . e(url('/assets/css/home-redesign-v2.css')) . '?v=20261118c">';
ob_start();
?>
<div class="mana-home-v2">
<?php if ($dailyQuote !== ''): ?>
<aside class="home-daily-quote" aria-labelledby="home-daily-quote-label">
  <div class="home-daily-quote-panel">
    <p class="home-daily-quote-label" id="home-daily-quote-label">جملهٔ امروز</p>
    <blockquote class="home-daily-quote-text"><p><?= e($dailyQuote) ?></p></blockquote>
  </div>
</aside>
<?php endif; ?>
<section class="container-page mana-welcome" aria-labelledby="mana-welcome-title">
  <div class="mana-welcome-copy">
    <p class="mana-kicker">مانا کلینیک · سعادت‌آباد</p>
    <h1 id="mana-welcome-title">آرامش ذهن،<br><span>مسیر روشن‌تر زندگی</span></h1>
    <p class="mana-welcome-description">گاهی یک گفت‌وگو، شروع یک مسیر تازه است. از دغدغه‌ات شروع کن؛ درمانگر مناسب را بشناس و برای خودت، رابطه‌ات و حال بهترت قدم بردار.</p>
    <div class="mana-welcome-actions">
      <a class="btn btn-accent" href="<?= e(url('/doctors')) ?>">انتخاب درمانگر و رزرو نوبت</a>
      <a class="mana-text-link" href="<?= e(url('/workshops')) ?>">کارگاه‌ها و دوره‌ها <span aria-hidden="true">←</span></a>
    </div>
  </div>
  <div class="mana-welcome-art">
    <img src="<?= e(url('/assets/img/mana-home-calm-v2.webp')) ?>" alt="تصویر مفهومی درخت زیتون و دری روشن در فضایی آرام" width="1536" height="1024" fetchpriority="high" loading="eager" decoding="async">
    <p class="mana-art-caption">برای خودت، فرصتی برای مکث بساز.</p>
  </div>
</section>

<section class="container-page section home-content">

      <a class="mana-couples-feature" href="<?= e(url($homeLatestCouples['path'])) ?>" aria-labelledby="mana-couples-title">
        <div class="mana-couples-copy">
          <p class="mana-couples-eyebrow">رابطه از زاویه‌ای دیگر <span lang="en" dir="ltr">Couples Therapy</span></p>
          <h2 id="mana-couples-title"><?= e($homeLatestCouples['title']) ?></h2>
          <p class="mana-couples-description"><?= e($homeLatestCouples['summary']) ?></p>
          <p class="mana-couples-context">درس‌هایی با نقطهٔ شروع مستند زوج‌درمانی با اورنا گورالنیک</p>
          <span class="mana-couples-cta">خواندن جدیدترین درس <span aria-hidden="true">←</span></span>
          <span class="mana-couples-episode"><?= e($homeLatestCouples['season_title'] . ' · ' . $homeLatestCouples['label']) ?></span>
        </div>
        <figure class="mana-couples-visual">
          <img src="<?= e(url($homeLatestCouples['image'] ?? $homeLearningCatalog['couples-therapy']['image'])) ?>" alt="<?= e($homeLatestCouples['image_alt'] ?? $homeLearningCatalog['couples-therapy']['image_alt']) ?>" width="1536" height="1024" loading="lazy" decoding="async">
          <figcaption>اعتماد، با رفتارهای قابل‌اتکا ساخته می‌شود.</figcaption>
        </figure>
      </a>

      <section class="mana-services" aria-labelledby="mana-services-title">
        <div class="mana-section-heading"><div><p class="mana-kicker">از کجا شروع کنیم؟</p><h2 id="mana-services-title">همراهی متناسب با دغدغهٔ شما</h2></div><a class="mana-text-link" href="<?= e(url('/services')) ?>">همه خدمات ←</a></div>
        <div class="mana-service-grid">
        <?php foreach ([
          ['فردی', 'مشاوره فردی', 'شناخت خود، احساسات و چالش‌های روزمره', '/services/individual', 'person'],
          ['رابطه', 'زوج‌درمانی', 'فرصتی برای شنیدن و بهتر فهمیدن یکدیگر', '/services/couples', 'couple'],
          ['رشد', 'کودک و نوجوان', 'همراهی در مسیر رشد و ارتباط با فرزند', '/services/child', 'child'],
          ['یادگیری', 'کارگاه‌ها و دوره‌ها', 'تمرین مهارت‌های تازه برای زندگی', '/workshops', 'workshop'],
        ] as $service): ?>
          <a class="mana-service-card" href="<?= e(url($service[3])) ?>"><img class="mana-service-image" src="<?= e(url('/assets/img/services/' . $service[4] . '-scene-v2.webp')) ?>" alt="" width="768" height="512" loading="lazy" decoding="async"><span class="mana-service-label"><?= e($service[0]) ?></span><h3><?= e($service[1]) ?></h3><p><?= e($service[2]) ?></p></a>
        <?php endforeach; ?>
        </div>
      </section>

      <?php if (function_exists('assistant_enabled') ? assistant_enabled() : true): ?>
      <a class="home-assistant-tile" href="<?= e(url('/assistant')) ?>">
        <span class="home-assistant-icon" aria-hidden="true">
          <svg viewBox="0 0 64 64" width="36" height="36" fill="none" focusable="false">

            <circle cx="32" cy="32" r="29" fill="#e0f2e9"/>
            <path d="M13 27c0-9 7-15 17-15h3c10 0 17 6 17 15v7c0 9-7 15-17 15h-8l-10 6 2-12c-3-3-4-6-4-9v-7Z" fill="#1a5c4a"/>
            <rect x="19" y="22" width="25" height="18" rx="8" fill="#f0fbf5"/>
            <path d="M24 29c1-2 3-2 4 0m7 0c1-2 3-2 4 0" stroke="#1a5c4a" stroke-width="2.4" stroke-linecap="round"/>
            <path d="M28 34c2 2 5 2 7 0" stroke="#2d8a6e" stroke-width="2" stroke-linecap="round"/>
            <path d="M49 7l1.8 5.2L56 14l-5.2 1.8L49 21l-1.8-5.2L42 14l5.2-1.8L49 7Z" fill="#c4a267"/>
            <circle cx="12" cy="17" r="2.5" fill="#7ac5a5"/>
            <path d="M48 45v8m-4-4h8" stroke="#7ac5a5" stroke-width="2.5" stroke-linecap="round"/>
          </svg>
        </span>
        <span class="home-assistant-copy">
          <span class="home-assistant-eyebrow">دستیار هوشمند مانا</span>
          <span class="home-assistant-title">فقط کافیه حالت رو بهم بگی</span>
          <span class="home-assistant-cta">شروع گفتگو</span>
        </span>
        <img class="home-assistant-art" src="<?= e(url('/assets/img/assistant-conversation-v1.png')) ?>" alt="" width="768" height="356" loading="lazy" decoding="async">
      </a>
      <?php endif; ?>
      <?php require __DIR__ . '/../includes/public_workshop_banners.php'; ?>
      <?php
      // TEMP hide whole therapist-guide block; set true to restore
      $showHomeTherapistGuide = false;
      if ($showHomeTherapistGuide) {
          require __DIR__ . '/../includes/home_therapist_guide.php';
      }
      ?>

      <div class="home-specialists-block" style="margin-top:3rem">
        <div class="section-head">
          <div>
            <p class="eyebrow">تیم درمانگران</p>
            <h2>متخصصانی که به آن‌ها اعتماد می‌کنید</h2>
          </div>
        </div>
        <?php if ($doctors): ?>
          <div class="therapists-all-link">
            <a class="articles-all-link" href="<?= e(url('/doctors')) ?>">مشاهده همه درمانگرها ←</a>
          </div>
        <?php endif; ?>
        <div class="doctors-showcase">
          <?php foreach ($doctors as $doc): ?>
            <?= doctor_card_html($doc) ?>
          <?php endforeach; ?>
          <?php if (!$doctors): ?><p class="muted">هنوز متخصصی ثبت نشده است.</p><?php endif; ?>
        </div>
      </div>
      <div class="home-articles-block" style="margin-top:3rem">
        <div class="section-head">
          <div>
            <h2>آخرین مقالات</h2>
            <p class="muted">دانش کاربردی برای سلامت روان</p>
          </div>
        </div>
        <?php if ($articles): ?>
          <div class="therapists-all-link">
            <a class="articles-all-link" href="<?= e(url('/articles')) ?>">مشاهده همه مقالات ←</a>
          </div>
        <?php endif; ?>
        <div class="articles-showcase">
          <?php foreach ($articles as $article): ?>
            <?php
            $homeArticleCovers = [
                'zog-darmani-zehn-agahi-duraes-2026' => 'couples-mindfulness',
                'tanzim-hayajan-va-ezterab' => 'emotion-regulation',
                'mehrbani-ba-khod-va-ezterab' => 'self-compassion',
            ];
            $homeArticleCover = $homeArticleCovers[(string) ($article['slug'] ?? '')] ?? null;
            if ($homeArticleCover !== null) {
                $article['cover_url'] = '/assets/img/articles/' . $homeArticleCover . '-v1.webp';
            }
            ?>
            <?= clinic_article_card_html($article) ?>
          <?php endforeach; ?>
        </div>
      </div>

</section>
</div>
<?php
$content = ob_get_clean();
$pageScripts = '<script src="' . e(url('/assets/js/home-workshop-banners.js')) . '?v=20260908k"></script>';
require __DIR__ . '/../includes/layout.php';
