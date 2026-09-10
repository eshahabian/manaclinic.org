<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/seo.php';
require_once __DIR__ . '/../includes/psych_tests.php';

header('Content-Type: application/xml; charset=utf-8');

$urls = [
    ['loc' => seo_absolute_url('/'), 'priority' => '1.0', 'changefreq' => 'daily'],
    ['loc' => seo_absolute_url('/doctors'), 'priority' => '0.9', 'changefreq' => 'weekly'],
    ['loc' => seo_absolute_url('/articles'), 'priority' => '0.9', 'changefreq' => 'daily'],
    ['loc' => seo_absolute_url('/assistant'), 'priority' => '0.8', 'changefreq' => 'weekly'],
    ['loc' => seo_absolute_url('/tests'), 'priority' => '0.7', 'changefreq' => 'monthly'],
    ['loc' => seo_absolute_url('/about'), 'priority' => '0.6', 'changefreq' => 'monthly'],
    ['loc' => seo_absolute_url('/contact'), 'priority' => '0.8', 'changefreq' => 'monthly'],
];

try {
    $articles = $pdo->query("
      SELECT slug, DATE_FORMAT(COALESCE(published_at, created_at), '%Y-%m-%d') AS lastmod
      FROM articles
      WHERE published = 1
      ORDER BY published_at DESC
    ")->fetchAll();
    foreach ($articles as $a) {
        $urls[] = [
            'loc' => seo_absolute_url('/articles/' . $a['slug']),
            'priority' => '0.8',
            'changefreq' => 'weekly',
            'lastmod' => $a['lastmod'],
        ];
    }
} catch (Throwable $e) {
    // فهرست مقالات را خالی می‌گذاریم تا نقشه سایت کل سایت را از کار نیندازد
}

try {
    $doctors = $pdo->query("
      SELECT id FROM doctor_profiles WHERE is_active=1 AND is_approved=1
    ")->fetchAll();
    foreach ($doctors as $d) {
        $urls[] = [
            'loc' => seo_absolute_url('/doctors/' . $d['id']),
            'priority' => '0.7',
            'changefreq' => 'weekly',
        ];
    }
} catch (Throwable $e) {
}

foreach (psych_tests_catalog() as $test) {
    $urls[] = [
        'loc' => seo_absolute_url('/tests/' . $test['slug']),
        'priority' => '0.5',
        'changefreq' => 'monthly',
    ];
}

echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
?>
<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">
<?php foreach ($urls as $u): ?>
  <url>
    <loc><?= htmlspecialchars((string) $u['loc'], ENT_XML1 | ENT_QUOTES, 'UTF-8') ?></loc>
    <?php if (!empty($u['lastmod'])): ?><lastmod><?= htmlspecialchars((string) $u['lastmod'], ENT_XML1 | ENT_QUOTES, 'UTF-8') ?></lastmod><?php endif; ?>
    <changefreq><?= htmlspecialchars((string) $u['changefreq'], ENT_XML1 | ENT_QUOTES, 'UTF-8') ?></changefreq>
    <priority><?= htmlspecialchars((string) $u['priority'], ENT_XML1 | ENT_QUOTES, 'UTF-8') ?></priority>
  </url>
<?php endforeach; ?>
</urlset>
