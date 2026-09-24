<?php
declare(strict_types=1);

$key = strtolower(trim((string) ($_GET['topic'] ?? '')));
$topic = clinic_issue_topic($key);
if (!$topic) {
    http_response_code(404);
    $pageTitle = 'یافت نشد';
    $pageRobots = 'noindex,follow';
    require __DIR__ . '/404.php';
    exit;
}

$doctors = clinic_doctors_by_focus($pdo, $key);
$articles = clinic_articles_for_topic($pdo, $key, 6);
$doctorsHref = clinic_doctors_href(['focus' => $key]);
$serviceHref = url((string) $topic['service']);

$pageTitle = $topic['title'];
$pageDescription = $topic['description'];
$pageCanonical = url('/issues/' . $key);
$pageKeywords = $topic['keywords'];
$pageJsonLd = [
    '@context' => 'https://schema.org',
    '@graph' => [
        [
            '@type' => 'BreadcrumbList',
            'itemListElement' => [
                ['@type' => 'ListItem', 'position' => 1, 'name' => 'خانه', 'item' => seo_absolute_url('/')],
                ['@type' => 'ListItem', 'position' => 2, 'name' => 'مسئله‌ها', 'item' => seo_absolute_url('/doctors')],
                ['@type' => 'ListItem', 'position' => 3, 'name' => $topic['label'], 'item' => seo_absolute_url($pageCanonical)],
            ],
        ],
        [
            '@type' => 'MedicalWebPage',
            'name' => $topic['h1'],
            'description' => $topic['description'],
            'url' => seo_absolute_url($pageCanonical),
            'about' => ['@type' => 'MedicalCondition', 'name' => $topic['label']],
        ],
    ],
];

ob_start();
?>
<div class="container-page services-page service-detail-page">
  <nav class="services-breadcrumb" aria-label="مسیر صفحه">
    <a href="<?= e(url('/')) ?>">خانه</a>
    <span class="services-breadcrumb-sep" aria-hidden="true">/</span>
    <a href="<?= e(url('/doctors')) ?>">متخصصان</a>
    <span class="services-breadcrumb-sep" aria-hidden="true">/</span>
    <span><?= e($topic['label']) ?></span>
  </nav>

  <header class="services-hero">
    <p class="services-kicker"><span>مسئله و درمان</span></p>
    <h1><?= e($topic['h1']) ?></h1>
    <p class="muted" style="max-width:40rem;line-height:1.9;margin-top:.85rem"><?= e($topic['lead']) ?></p>
  </header>

  <article class="service-detail-body">
    <p>مانا کلینیک در سعادت‌آباد جلسه حضوری و آنلاین دارد. این صفحه درمانگرانی را نشان می‌دهد که «<?= e($topic['label']) ?>» را در زمینه تخصصی خود ثبت کرده‌اند. جایگزین تشخیص نیست؛ اگر حالت اورژانسی است با اورژانس تماس بگیر.</p>

    <?= clinic_related_block_html($doctors, 'متخصصان این موضوع', $doctorsHref, 'فیلتر فهرست متخصصان') ?>

    <?php if ($articles): ?>
      <section class="clinic-match" aria-labelledby="issue-arts-h">
        <h2 id="issue-arts-h">مقالات مرتبط</h2>
        <div class="grid-2">
          <?php foreach ($articles as $article): ?>
            <a class="panel card-link" href="<?= e(url('/articles/' . $article['slug'])) ?>">
              <span class="badge"><?= e((string) $article['author_name']) ?></span>
              <h3 style="margin:.75rem 0 0;font-size:1.1rem;line-height:1.7"><?= e((string) $article['title']) ?></h3>
              <?php if (!empty($article['excerpt'])): ?>
                <p class="muted line-clamp-3" style="margin-top:.65rem;font-size:.9rem"><?= e((string) $article['excerpt']) ?></p>
              <?php endif; ?>
            </a>
          <?php endforeach; ?>
        </div>
        <p class="service-detail-related"><a href="<?= e(url('/articles?topic=' . rawurlencode($key))) ?>">همه مقالات این موضوع</a></p>
      </section>
    <?php endif; ?>

    <div class="service-detail-cta">
      <a class="btn btn-primary" href="<?= e($doctorsHref) ?>">رزرو نوبت این موضوع</a>
      <a class="btn btn-outline" href="<?= e($serviceHref) ?>">خدمات مرتبط</a>
      <a class="btn btn-outline" href="<?= e(url('/assistant')) ?>">شروع با دستیار</a>
      <a class="btn btn-outline" href="<?= e(url('/contact')) ?>">تماس با کلینیک</a>
    </div>
  </article>
</div>
<?php
$content = ob_get_clean();
require __DIR__ . '/../includes/layout.php';
