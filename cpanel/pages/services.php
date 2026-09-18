<?php
declare(strict_types=1);

$pageTitle = 'خدمات روانشناسی مانا کلینیک';
$pageDescription = 'خدمات مانا کلینیک سعادت‌آباد: مشاوره فردی، زوج‌درمانی، کودک و نوجوان، پیش از ازدواج، خانواده‌درمانی، کارگاه، آزمون و رزرو نوبت آنلاین.';
$pageCanonical = url('/services');
$pageKeywords = 'خدمات مانا کلینیک, مشاوره فردی سعادت آباد, زوج درمانی, مشاوره کودک, کارگاه روانشناسی, آزمون روانشناسی';

$services = [
    [
        'title' => 'مشاوره فردی',
        'desc' => 'گاهی برای روشن‌کردن مسیر و سبک‌کردن بار ذهنی، به همراهی حرفه‌ای نیاز دارید.',
        'href' => '/doctors',
        'icon' => 'person',
    ],
    [
        'title' => 'زوج‌درمانی',
        'desc' => 'وقتی رابطه برایتان مهم است، می‌توانید با گفتگو و مهارت تازه، به فهم و آرامش بیشتر برسید.',
        'href' => '/doctors',
        'icon' => 'couple',
    ],
    [
        'title' => 'کودک و نوجوان',
        'desc' => 'رفتار، اضطراب یا چالش‌های رشدی کودک و نوجوان را با نگاه تخصصی و فضای امن بررسی می‌کنیم.',
        'href' => '/doctors',
        'icon' => 'child',
    ],
    [
        'title' => 'پیش از ازدواج',
        'desc' => 'پیش از تصمیم بزرگ زندگی مشترک، شناخت عمیق‌تر از خود و طرف مقابل را جدی بگیرید.',
        'href' => '/doctors',
        'icon' => 'rings',
    ],
    [
        'title' => 'خانواده‌درمانی',
        'desc' => 'وقتی موضوع فقط به یک نفر محدود نیست، خانواده را در مسیر حل مسئله همراهی می‌کنیم.',
        'href' => '/doctors',
        'icon' => 'family',
    ],
    [
        'title' => 'کارگاه‌ها و دوره‌ها',
        'desc' => 'مهارت‌هایی برای زندگی، روابط و رشد فردی در قالب کارگاه و دوره‌های آموزشی مانا کلینیک.',
        'href' => '/#home-workshop-banners',
        'icon' => 'workshop',
    ],
    [
        'title' => 'آزمون‌های روانشناسی',
        'desc' => 'با آزمون‌های استاندارد، تصویری اولیه از وضعیت روان‌شناختی خود به‌دست آورید.',
        'href' => '/tests',
        'icon' => 'test',
    ],
    [
        'title' => 'دستیار هوشمند',
        'desc' => 'شروع مسیر با گفتگوی اولیه و راهنمایی هوشمند، پیش از رزرو جلسه حضوری یا آنلاین.',
        'href' => '/assistant',
        'icon' => 'assistant',
    ],
    [
        'title' => 'رزرو نوبت آنلاین',
        'desc' => 'متخصص مناسب را انتخاب کنید و نوبت حضوری یا آنلاین را به‌سادگی از سایت رزرو کنید.',
        'href' => '/doctors',
        'icon' => 'calendar',
    ],
];

$pageJsonLd = [
    '@context' => 'https://schema.org',
    '@graph' => [
        [
            '@type' => 'BreadcrumbList',
            'itemListElement' => [
                ['@type' => 'ListItem', 'position' => 1, 'name' => 'صفحه اصلی', 'item' => seo_absolute_url('/')],
                ['@type' => 'ListItem', 'position' => 2, 'name' => 'خدمات', 'item' => seo_absolute_url('/services')],
            ],
        ],
        [
            '@type' => 'CollectionPage',
            'name' => $pageTitle,
            'description' => $pageDescription,
            'url' => seo_absolute_url('/services'),
            'isPartOf' => ['@type' => 'WebSite', 'name' => 'مانا کلینیک', 'url' => seo_absolute_url('/')],
        ],
    ],
];

if (!function_exists('services_icon_svg')) {
    function services_icon_svg(string $name): string
    {
        $common = 'viewBox="0 0 24 24" width="28" height="28" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"';
        return match ($name) {
            'person' => '<svg ' . $common . '><circle cx="12" cy="8" r="3.2"/><path d="M5.5 19c1.6-3.2 4-4.8 6.5-4.8S16.9 15.8 18.5 19"/></svg>',
            'couple' => '<svg ' . $common . '><circle cx="8.5" cy="8" r="2.6"/><circle cx="15.5" cy="8" r="2.6"/><path d="M3.8 19c1.2-2.6 3-3.9 4.7-3.9s3.5 1.3 4.7 3.9M11 19c1.2-2.6 3-3.9 4.7-3.9s3.5 1.3 4.7 3.9"/></svg>',
            'child' => '<svg ' . $common . '><circle cx="12" cy="7.5" r="2.8"/><path d="M8 20v-5.2a4 4 0 0 1 8 0V20"/><path d="M9.2 12.5h5.6"/></svg>',
            'rings' => '<svg ' . $common . '><circle cx="9" cy="13" r="4.2"/><circle cx="15" cy="13" r="4.2"/></svg>',
            'family' => '<svg ' . $common . '><circle cx="8" cy="7.5" r="2.4"/><circle cx="16" cy="7.5" r="2.4"/><circle cx="12" cy="12.2" r="2"/><path d="M3.8 19c1-2.3 2.5-3.4 4.2-3.4s3.2 1.1 4.2 3.4M11.8 19c1-2.3 2.5-3.4 4.2-3.4s3.2 1.1 4.2 3.4"/></svg>',
            'workshop' => '<svg ' . $common . '><path d="M4 19V7h16v12H4z"/><path d="M8 7V5h8v2M9 11h6M9 15h4"/></svg>',
            'test' => '<svg ' . $common . '><path d="M8 4h8v4H8zM6 8h12v12H6z"/><path d="M9 13l2 2 4-4"/></svg>',
            'assistant' => '<svg ' . $common . '><rect x="5" y="4" width="14" height="12" rx="2"/><path d="M9 20h6M12 16v4M9 9h.01M12 9h.01M15 9h.01"/></svg>',
            'calendar' => '<svg ' . $common . '><rect x="4" y="5" width="16" height="15" rx="2"/><path d="M8 3v4M16 3v4M4 10h16M9 14h2v2H9z"/></svg>',
            default => '<svg ' . $common . '><circle cx="12" cy="12" r="8"/></svg>',
        };
    }
}

ob_start();
?>
<div class="container-page services-page">
  <nav class="services-breadcrumb" aria-label="مسیر صفحه">
    <a href="<?= e(url('/')) ?>">خانه</a>
    <span class="services-breadcrumb-sep" aria-hidden="true">/</span>
    <span>خدمات</span>
  </nav>

  <header class="services-hero">
    <p class="services-kicker"><span>خدمات ما</span></p>
    <h1>همراهی در مسیر آرامش و رشد</h1>
    <p class="services-lead">
      برای آشنایی با هر خدمت و رزرو نوبت با متخصصان مانا کلینیک در سعادت‌آباد، کارت مربوط را انتخاب کنید.
    </p>
  </header>

  <div class="services-grid">
    <?php foreach ($services as $item): ?>
      <a class="service-card" href="<?= e(url((string) $item['href'])) ?>">
        <span class="service-card-icon"><?= services_icon_svg((string) $item['icon']) ?></span>
        <h2 class="service-card-title"><?= e((string) $item['title']) ?></h2>
        <p class="service-card-desc"><?= e((string) $item['desc']) ?></p>
        <span class="service-card-more">بیشتر بدانید ←</span>
      </a>
    <?php endforeach; ?>
  </div>
</div>
<?php
$content = ob_get_clean();
require __DIR__ . '/../includes/layout.php';
