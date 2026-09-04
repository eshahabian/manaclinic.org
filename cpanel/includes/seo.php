<?php
declare(strict_types=1);

function seo_site_name(): string
{
    global $config;
    return (string) ($config['app_name'] ?? 'مانا کلینیک');
}

function seo_default_description(): string
{
    return 'مانا کلینیک؛ مرکز روانشناسی و روان‌درمانی در سعادت‌آباد تهران. رزرو نوبت آنلاین، مشاوره فردی و زوج‌درمانی، مقالات تخصصی و دستیار هوشمند سلامت روان.';
}

function seo_default_keywords(): string
{
    return 'مانا کلینیک, روانشناسی, روان‌درمانی, مشاوره روانشناسی, زوج‌درمانی, اضطراب, افسردگی, سعادت آباد, رزرو نوبت روانشناس, دکتر عطیه گارسچی';
}

/** آدرس مطلق برای SEO (canonical / Open Graph) */
function seo_absolute_url(string $path = '/'): string
{
    global $config;
    $base = rtrim((string) ($config['app_url'] ?? ''), '/');
    if ($base === '') {
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host = (string) ($_SERVER['HTTP_HOST'] ?? 'manaclinic.org');
        $base = $scheme . '://' . $host;
    }
    if ($path === '/' || $path === '') {
        return $base . '/';
    }
    if (str_starts_with($path, 'http://') || str_starts_with($path, 'https://')) {
        return $path;
    }
    return $base . '/' . ltrim($path, '/');
}

function seo_render_head(?array $meta = null): string
{
    $site = seo_site_name();
    $title = trim((string) ($meta['title'] ?? ($GLOBALS['pageTitle'] ?? $site)));
    $fullTitle = str_contains($title, $site) ? $title : ($title . ' | ' . $site);
    $description = trim((string) ($meta['description'] ?? ($GLOBALS['pageDescription'] ?? seo_default_description())));
    $keywords = trim((string) ($meta['keywords'] ?? ($GLOBALS['pageKeywords'] ?? seo_default_keywords())));
    $canonical = (string) ($meta['canonical'] ?? ($GLOBALS['pageCanonical'] ?? seo_absolute_url(url('/'))));
    if (!str_starts_with($canonical, 'http')) {
        $canonical = seo_absolute_url($canonical);
    }
    $image = (string) ($meta['image'] ?? ($GLOBALS['pageImage'] ?? seo_absolute_url(url('/assets/img/hero.png'))));
    if (!str_starts_with($image, 'http')) {
        $image = seo_absolute_url($image);
    }
    $type = (string) ($meta['type'] ?? ($GLOBALS['pageOgType'] ?? 'website'));
    $robots = (string) ($meta['robots'] ?? ($GLOBALS['pageRobots'] ?? 'index,follow,max-image-preview:large'));
    $jsonLd = $meta['json_ld'] ?? ($GLOBALS['pageJsonLd'] ?? null);

    $out = [];
    $out[] = '<title>' . e($fullTitle) . '</title>';
    $out[] = '<meta name="description" content="' . e($description) . '">';
    $out[] = '<meta name="keywords" content="' . e($keywords) . '">';
    $out[] = '<meta name="robots" content="' . e($robots) . '">';
    $out[] = '<meta name="author" content="' . e($site) . '">';
    $out[] = '<meta name="theme-color" content="#1a5c4a">';
    $out[] = '<link rel="canonical" href="' . e($canonical) . '">';

    $out[] = '<meta property="og:locale" content="fa_IR">';
    $out[] = '<meta property="og:type" content="' . e($type) . '">';
    $out[] = '<meta property="og:site_name" content="' . e($site) . '">';
    $out[] = '<meta property="og:title" content="' . e($fullTitle) . '">';
    $out[] = '<meta property="og:description" content="' . e($description) . '">';
    $out[] = '<meta property="og:url" content="' . e($canonical) . '">';
    $out[] = '<meta property="og:image" content="' . e($image) . '">';

    $out[] = '<meta name="twitter:card" content="summary_large_image">';
    $out[] = '<meta name="twitter:title" content="' . e($fullTitle) . '">';
    $out[] = '<meta name="twitter:description" content="' . e($description) . '">';
    $out[] = '<meta name="twitter:image" content="' . e($image) . '">';

    // LocalBusiness + WebSite JSON-LD پیش‌فرض
    $defaultLd = [
        '@context' => 'https://schema.org',
        '@graph' => [
            [
                '@type' => 'MedicalBusiness',
                '@id' => seo_absolute_url('/') . '#clinic',
                'name' => $site,
                'url' => seo_absolute_url('/'),
                'telephone' => ['+989101387838', '+982122065774'],
                'email' => 'info@manaclinic.org',
                'image' => $image,
                'address' => [
                    '@type' => 'PostalAddress',
                    'addressCountry' => 'IR',
                    'addressLocality' => 'تهران',
                    'addressRegion' => 'تهران',
                    'streetAddress' => 'سعادت‌آباد، خیابان ۳۱ شرقی (جندونی)، روبروی ساختمان پزشکان روزبه، پلاک ۴، واحد ۵، طبقه ۵',
                ],
                'geo' => [
                    '@type' => 'GeoCoordinates',
                    'latitude' => 35.772637,
                    'longitude' => 51.377568,
                ],
                'sameAs' => [
                    'https://www.instagram.com/mana_clinic/',
                    'https://wa.me/989101387838',
                ],
            ],
            [
                '@type' => 'WebSite',
                '@id' => seo_absolute_url('/') . '#website',
                'url' => seo_absolute_url('/'),
                'name' => $site,
                'inLanguage' => 'fa-IR',
                'publisher' => ['@id' => seo_absolute_url('/') . '#clinic'],
            ],
        ],
    ];

    if (is_array($jsonLd) && $jsonLd) {
        if (isset($jsonLd['@graph']) && is_array($jsonLd['@graph'])) {
            $defaultLd['@graph'] = array_merge($defaultLd['@graph'], $jsonLd['@graph']);
        } else {
            $defaultLd['@graph'][] = $jsonLd;
        }
    }

    $out[] = '<script type="application/ld+json">' . json_encode($defaultLd, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . '</script>';

    return implode("\n  ", $out);
}
