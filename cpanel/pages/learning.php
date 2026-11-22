<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/learning.php';
require_once __DIR__ . '/../includes/learning_views.php';
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
$pageHead = '<link rel="stylesheet" href="' . e(url('/assets/css/learning.css')) . '?v=20261123views">';
$learningViewCounts = $series !== null ? mana_learning_views($pdo, current_user()) : null;
ob_start();
?>
<div class="container-page section mana-learning">
  <nav class="learning-breadcrumb" aria-label="مسیر یادگیری"><a href="<?= e(url('/')) ?>">خانه</a><span aria-hidden="true">/</span><?php if ($series): ?><a href="<?= e(url('/learn')) ?>">یادگیری با مانا</a><span aria-hidden="true">/</span><span aria-current="page"><?= e($series['title']) ?></span><?php else: ?><span aria-current="page">یادگیری با مانا</span><?php endif; ?></nav>
  <header class="learning-header">
    <p class="learning-eyebrow"><?= $series ? 'آرشیو مجموعه' : 'هر بار، فرصتی برای یادگرفتن' ?></p>
    <h1><?= e($pageTitle) ?></h1>
    <p><?= e($pageDescription) ?></p>
    <?php if (!$series): ?><p class="learning-note">از یک مجموعه شروع کنید و موضوع دلخواهتان را انتخاب کنید؛ درس‌های قبلی همیشه در آرشیو در دسترس‌اند.</p><?php endif; ?>
  </header>
  <?php if (!$series): ?>
    <section class="learning-grid" aria-label="مجموعه‌های آموزشی">
      <?php foreach ($catalog as $slug => $item): $lessons = mana_learning_lessons($slug, $item); $latestLesson = $lessons ? $lessons[array_key_last($lessons)] : null; ?>
      <a class="learning-series-card<?= $slug === 'schemas' ? ' learning-series-card-image-left' : '' ?>" href="<?= e(url('/learn/' . $slug)) ?>">
        <?php if ($item['image']): ?><img src="<?= e(url($latestLesson['image'] ?? $item['image'])) ?>" alt="<?= e($latestLesson['image_alt'] ?? $item['image_alt'] ?? $item['title']) ?>" width="1536" height="1024" loading="lazy" decoding="async"><?php else: ?><div class="learning-series-placeholder" aria-hidden="true">الگوها را بشناسیم.</div><?php endif; ?>
        <div class="learning-card-copy"><p class="learning-eyebrow"><?= e($item['cadence']) ?></p><h2><?= e($item['title']) ?></h2><p><?= e($latestLesson['summary'] ?? $item['description']) ?></p><span class="learning-status"><?= $latestLesson ? e('جدیدترین مطلب: ' . ($slug === 'schemas' ? $latestLesson['label'] : $latestLesson['season_title'] . ' · ' . $latestLesson['label']) . ' — ' . $latestLesson['title']) : 'در انتظار اولین درس' ?></span><span class="learning-link">مشاهدهٔ مجموعه ←</span></div>
      </a>
      <?php endforeach; ?>
    </section>
    <aside class="learning-empty"><h2>مسیرهای تازهٔ یادگیری</h2><p>مجموعه‌های آموزشی بعدی هم با دسته‌بندی متناسب با موضوع، به همین آرشیو اضافه می‌شوند.</p></aside>
  <?php elseif ($seriesSlug === 'schemas'): ?>
    <p>در مدل کلاسیک یانگ، ۱۸ طرح‌وارهٔ ناسازگار اولیه معرفی شده است. شماره‌ها برای نظم فهرست‌اند و ترتیب اهمیت یا درمان را تعیین نمی‌کنند. <a class="learning-link" href="https://schematherapysociety.org/Schema-Therapy">منبع: انجمن بین‌المللی طرح‌واره‌درمانی</a></p>
    <section aria-labelledby="schema-list-title"><h2 id="schema-list-title">فهرست ۱۸ طرح‌واره</h2><ol class="learning-episodes">
      <?php foreach (mana_schema_names() as $schemaNumber => $schemaName): $schemaLesson = $series['seasons'][1]['lessons'][$schemaNumber] ?? null; ?>
      <li><?php if ($schemaLesson): ?><a class="learning-episode" href="<?= e(url(mana_learning_lesson_path('schemas', 1, $schemaNumber))) ?>"><?php else: ?><div class="learning-episode"><?php endif; ?>
        <span class="learning-episode-number">طرح‌وارهٔ <?= e(mana_learning_fa_number($schemaNumber)) ?></span><div><h3><?= e($schemaName) ?></h3><?php if ($schemaLesson): ?><p><?= e($schemaLesson['summary']) ?></p><?= mana_learning_view_badge($learningViewCounts, mana_learning_lesson_path('schemas', 1, $schemaNumber)) ?><?php endif; ?></div><span class="<?= $schemaLesson ? 'learning-link' : 'learning-status' ?>"><?= $schemaLesson ? 'خواندن راهنما ←' : 'محتوا هنوز اضافه نشده' ?></span>
      <?php if ($schemaLesson): ?></a><?php else: ?></div><?php endif; ?></li>
      <?php endforeach; ?>
    </ol></section>
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
        <li><a class="learning-episode" href="<?= e(url($lesson['path'])) ?>"><span class="learning-episode-number"><?= e($lesson['label']) ?></span><div><h3><?= e($lesson['title']) ?></h3><p><?= e($lesson['summary']) ?></p><span class="learning-status"><?= e($lesson['format']) ?></span><?= mana_learning_view_badge($learningViewCounts, $lesson['path']) ?></div><span class="learning-link">خواندن درس ←</span></a></li>
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
