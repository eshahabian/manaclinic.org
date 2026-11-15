<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/learning.php';
$catalog = mana_learning_catalog();
$seriesSlug = $learningSeries ?? null;
$series = $seriesSlug !== null ? ($catalog[$seriesSlug] ?? null) : null;
if ($seriesSlug !== null && $series === null) {
    http_response_code(404);
    require __DIR__ . '/404.php';
    return;
}
$pageTitle = $series['title'] ?? 'یادگیری با مانا';
$pageDescription = $series['description'] ?? 'آرشیو مجموعه‌های آموزشی مانا؛ درس‌های هفتگی Couples Therapy، شناخت طرح‌واره‌ها و آموزش‌های دیگر، به تفکیک مجموعه، فصل و قسمت.';
$pageCanonical = url('/learn' . ($seriesSlug !== null ? '/' . $seriesSlug : ''));
$pageHead = '<link rel="stylesheet" href="' . e(url('/assets/css/learning.css')) . '?v=20261115a">';
ob_start();
?>
<div class="container-page section mana-learning">
  <nav class="learning-breadcrumb" aria-label="مسیر یادگیری"><a href="<?= e(url('/')) ?>">خانه</a><span aria-hidden="true">/</span><?php if ($series): ?><a href="<?= e(url('/learn')) ?>">یادگیری با مانا</a><span aria-hidden="true">/</span><span aria-current="page"><?= e($series['title']) ?></span><?php else: ?><span aria-current="page">یادگیری با مانا</span><?php endif; ?></nav>
  <header class="learning-header">
    <p class="learning-eyebrow"><?= $series ? 'آرشیو مجموعه' : 'هر بار، فرصتی برای یادگرفتن' ?></p>
    <h1><?= e($pageTitle) ?></h1>
    <p><?= e($pageDescription) ?></p>
    <?php if (!$series): ?><p class="learning-note">از یک مجموعه شروع کنید؛ فصل و قسمت دلخواهتان را انتخاب کنید و هر زمان خواستید به درس‌های قبلی برگردید.</p><?php endif; ?>
  </header>
  <?php if (!$series): ?>
    <section class="learning-grid" aria-label="مجموعه‌های آموزشی">
      <?php foreach ($catalog as $slug => $item): $lessons = mana_learning_lessons($slug, $item); ?>
      <a class="learning-series-card" href="<?= e(url('/learn/' . $slug)) ?>">
        <?php if ($item['image']): ?><img src="<?= e(url($item['image'])) ?>" alt="تصویرسازی مفهومی دو برداشت متفاوت در رابطه" width="1536" height="1024" loading="lazy" decoding="async"><?php else: ?><div class="learning-series-placeholder" aria-hidden="true">الگوها را بشناسیم.</div><?php endif; ?>
        <div class="learning-card-copy"><p class="learning-eyebrow"><?= e($item['cadence']) ?></p><h2><?= e($item['title']) ?></h2><p><?= e($item['description']) ?></p><span class="learning-status"><?= $lessons ? 'شروع از فصل اول، قسمت اول' : 'در انتظار اولین درس' ?></span><span class="learning-link">مشاهدهٔ مجموعه ←</span></div>
      </a>
      <?php endforeach; ?>
    </section>
    <aside class="learning-empty"><h2>مسیرهای تازهٔ یادگیری</h2><p>مجموعه‌های آموزشی بعدی هم به همین آرشیو اضافه می‌شوند؛ هر موضوع با فصل‌ها و درس‌های خودش.</p></aside>
  <?php elseif (!$series['seasons']): ?>
    <section class="learning-empty"><h2>این مجموعه هنوز درسی ندارد</h2><p>پس از آماده‌شدن اولین درس، فهرست فصل‌ها و قسمت‌ها در همین صفحه در دسترس خواهد بود.</p><a class="learning-link" href="<?= e(url('/learn')) ?>">بازگشت به مجموعه‌ها ←</a></section>
  <?php else: $allLessons = mana_learning_lessons($seriesSlug, $series); ?>
    <div class="learning-series-intro">
      <p>درس‌های این مجموعه به‌صورت هفتگی به آرشیو اضافه می‌شوند. اینجا می‌توانید قسمت‌های موجود را به ترتیب بخوانید یا مستقیم سراغ قسمت دلخواهتان بروید.</p>
      <a class="btn btn-primary" href="<?= e(url($allLessons[0]['path'])) ?>">شروع از اولین درس ←</a>
    </div>
    <nav class="learning-seasons" aria-label="انتخاب فصل"><?php foreach ($series['seasons'] as $number => $season): ?><a href="#season-<?= (int) $number ?>"><?= e($season['title']) ?></a><?php endforeach; ?></nav>
    <?php foreach ($series['seasons'] as $number => $season): ?>
    <section class="learning-season" id="season-<?= (int) $number ?>" aria-labelledby="season-title-<?= (int) $number ?>">
      <h2 id="season-title-<?= (int) $number ?>"><?= e($season['title']) ?></h2>
      <ol class="learning-episodes">
      <?php foreach ($allLessons as $lesson): if ($lesson['season'] !== $number) continue; ?>
        <li><a class="learning-episode" href="<?= e(url($lesson['path'])) ?>"><span class="learning-episode-number"><?= e($lesson['label']) ?></span><div><h3><?= e($lesson['title']) ?></h3><p><?= e($lesson['summary']) ?></p><span class="learning-status"><?= e($lesson['format']) ?></span></div><span class="learning-link">خواندن درس ←</span></a></li>
      <?php endforeach; ?>
      </ol>
    </section>
    <?php endforeach; ?>
    <p class="learning-note">قسمت‌های بعدی پس از آماده‌شدن در همین فصل قرار می‌گیرند.</p>
  <?php endif; ?>
</div>
<?php
$content = ob_get_clean();
require __DIR__ . '/../includes/layout.php';
