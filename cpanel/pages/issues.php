<?php
declare(strict_types=1);

$pageTitle = 'مسئله‌های درمان | مانا کلینیک سعادت‌آباد';
$pageDescription = 'از اضطراب و افسردگی تا رابطه و سوگ؛ لندینگ موضوعی مانا کلینیک سعادت‌آباد با توضیح مسئله، درمانگران همان حوزه و مسیر رزرو حضوری یا آنلاین.';
$pageCanonical = url('/issues');
$pageKeywords = 'روانشناس سعادت آباد, درمان اضطراب, افسردگی, زوج درمانی, مانا کلینیک';
$pageJsonLd = [
    '@context' => 'https://schema.org',
    '@graph' => [
        [
            '@type' => 'BreadcrumbList',
            'itemListElement' => [
                ['@type' => 'ListItem', 'position' => 1, 'name' => 'خانه', 'item' => seo_absolute_url('/')],
                ['@type' => 'ListItem', 'position' => 2, 'name' => 'مسئله‌ها', 'item' => seo_absolute_url('/issues')],
            ],
        ],
        [
            '@type' => 'CollectionPage',
            'name' => $pageTitle,
            'description' => $pageDescription,
            'url' => seo_absolute_url('/issues'),
        ],
    ],
];

ob_start();
?>
<div class="container-page services-page">
  <nav class="services-breadcrumb" aria-label="مسیر صفحه">
    <a href="<?= e(url('/')) ?>">خانه</a>
    <span class="services-breadcrumb-sep" aria-hidden="true">/</span>
    <span>مسئله‌ها</span>
  </nav>
  <header class="services-hero">
    <p class="services-kicker"><span>از مسئله شروع کن</span></p>
    <h1>چه چیزی تو را به کلینیک می‌رساند؟</h1>
    <p class="muted" style="max-width:40rem;line-height:1.9;margin-top:.85rem">
      هر موضوع یک صفحه جدا دارد: علائم رایج، زمان مراجعه، مسیر کار در سعادت‌آباد، درمانگران همان زمینه و مقالات مرتبط. جایگزین تشخیص نیست.
    </p>
  </header>
  <div class="grid-2" style="margin-top:1.5rem">
    <?php foreach (clinic_issue_topics() as $key => $row): ?>
      <a class="panel card-link" href="<?= e(url('/issues/' . $key)) ?>">
        <h2 style="margin:0;font-size:1.15rem;line-height:1.6"><?= e($row['h1']) ?></h2>
        <p class="muted" style="margin-top:.55rem;line-height:1.8;font-size:.9rem"><?= e($row['lead']) ?></p>
        <span class="doctor-card-cta">مشاهده موضوع و رزرو</span>
      </a>
    <?php endforeach; ?>
  </div>
</div>
<?php
$content = ob_get_clean();
require __DIR__ . '/../includes/layout.php';
