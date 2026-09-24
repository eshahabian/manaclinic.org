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
$signs = $topic['signs'] ?? [];
$faqs = $topic['faqs'] ?? [];

$pageTitle = $topic['title'];
$pageDescription = $topic['description'];
$pageCanonical = url('/issues/' . $key);
$pageKeywords = $topic['keywords'];
$faqLd = [];
foreach ($faqs as $i => $faq) {
    $faqLd[] = [
        '@type' => 'Question',
        'name' => $faq['q'],
        'acceptedAnswer' => ['@type' => 'Answer', 'text' => $faq['a']],
    ];
}
$pageJsonLd = [
    '@context' => 'https://schema.org',
    '@graph' => [
        [
            '@type' => 'BreadcrumbList',
            'itemListElement' => [
                ['@type' => 'ListItem', 'position' => 1, 'name' => 'خانه', 'item' => seo_absolute_url('/')],
                ['@type' => 'ListItem', 'position' => 2, 'name' => 'مسئله‌ها', 'item' => seo_absolute_url('/issues')],
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
if ($faqLd !== []) {
    $pageJsonLd['@graph'][] = ['@type' => 'FAQPage', 'mainEntity' => $faqLd];
}

ob_start();
?>
<div class="container-page services-page service-detail-page">
  <nav class="services-breadcrumb" aria-label="مسیر صفحه">
    <a href="<?= e(url('/')) ?>">خانه</a>
    <span class="services-breadcrumb-sep" aria-hidden="true">/</span>
    <a href="<?= e(url('/issues')) ?>">مسئله‌ها</a>
    <span class="services-breadcrumb-sep" aria-hidden="true">/</span>
    <span><?= e($topic['label']) ?></span>
  </nav>

  <header class="services-hero">
    <p class="services-kicker"><span>مسئله و درمان در سعادت‌آباد</span></p>
    <h1><?= e($topic['h1']) ?></h1>
    <p class="muted" style="max-width:40rem;line-height:1.9;margin-top:.85rem"><?= e($topic['lead']) ?></p>
    <p class="service-detail-cta" style="margin-top:1rem">
      <a class="btn btn-primary" href="<?= e($doctorsHref) ?>">رزرو درمانگر این موضوع</a>
      <a class="btn btn-outline" href="<?= e($serviceHref) ?>">خدمات مرتبط</a>
    </p>
  </header>

  <article class="service-detail-body">
    <?php if ($signs !== []): ?>
      <section>
        <h2>نشانه‌هایی که افراد معمولاً می‌آورند</h2>
        <ul>
          <?php foreach ($signs as $sign): ?>
            <li><?= e((string) $sign) ?></li>
          <?php endforeach; ?>
        </ul>
      </section>
    <?php endif; ?>

    <?php if (!empty($topic['when'])): ?>
      <section>
        <h2>چه زمانی مراجعه مفید است؟</h2>
        <p><?= e((string) $topic['when']) ?></p>
      </section>
    <?php endif; ?>

    <?php if (!empty($topic['how'])): ?>
      <section>
        <h2>مسیر کار در مانا کلینیک</h2>
        <p><?= e((string) $topic['how']) ?></p>
      </section>
    <?php endif; ?>

    <p class="muted">این صفحه غربالگری عمومی است، نه تشخیص. اگر خطر فوری برای جانت هست با اورژانس تماس بگیر. تلفن کلینیک: <a href="tel:02122065774" dir="ltr">۰۲۱ ۲۲۰۶ ۵۷۷۴</a> — اینستاگرام <a href="https://www.instagram.com/mana_clinic/" rel="noopener noreferrer" target="_blank">mana_clinic</a>.</p>

    <?= clinic_related_block_html($doctors, 'متخصصان «' . $topic['label'] . '»', $doctorsHref, 'فیلتر فهرست متخصصان') ?>

    <?php if ($articles): ?>
      <section class="clinic-match" aria-labelledby="issue-arts-h">
        <h2 id="issue-arts-h">مقالات همین موضوع</h2>
        <div class="grid-2">
          <?php foreach ($articles as $article): ?>
            <?= clinic_article_card_html($article) ?>
          <?php endforeach; ?>
        </div>
      </section>
    <?php endif; ?>

    <?php if ($faqs !== []): ?>
      <section class="clinic-match" aria-labelledby="issue-faq-h">
        <h2 id="issue-faq-h">سؤال‌های رایج</h2>
        <?php foreach ($faqs as $faq): ?>
          <h3 style="margin:1rem 0 .35rem;font-size:1rem"><?= e((string) $faq['q']) ?></h3>
          <p><?= e((string) $faq['a']) ?></p>
        <?php endforeach; ?>
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
