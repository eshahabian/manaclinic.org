<?php
declare(strict_types=1);

$slug = (string) ($_GET['slug'] ?? '');
$stmt = $pdo->prepare("
  SELECT a.*, u.name AS author_name
  FROM articles a
  JOIN users u ON u.id = a.author_id
  WHERE a.slug = ? AND a.published = 1
");
$stmt->execute([$slug]);
$article = $stmt->fetch();
if (!$article) {
    http_response_code(404);
    $pageTitle = 'یافت نشد';
    $pageRobots = 'noindex,follow';
    require __DIR__ . '/404.php';
    exit;
}

$pageTitle = $article['title'];
$pageDescription = trim((string) ($article['excerpt'] ?: mb_substr(strip_tags((string) $article['content']), 0, 160)));
$pageKeywords = 'مانا کلینیک, ' . $article['title'] . ', روانشناسی, ' . $article['author_name'];
$pageCanonical = url('/articles/' . $article['slug']);
$pageOgType = 'article';
$pageJsonLd = [
    '@type' => 'Article',
    'headline' => $article['title'],
    'description' => $pageDescription,
    'inLanguage' => 'fa-IR',
    'datePublished' => $article['published_at'] ?? null,
    'author' => [
        '@type' => 'Person',
        'name' => $article['author_name'],
    ],
    'publisher' => [
        '@type' => 'Organization',
        'name' => 'مانا کلینیک',
        'url' => 'https://manaclinic.org',
    ],
    'mainEntityOfPage' => seo_absolute_url($pageCanonical),
];

ob_start();
?>
<article class="container-page section" itemscope itemtype="https://schema.org/Article">
  <a href="<?= e(url('/articles')) ?>" style="color:var(--primary);font-size:.9rem">← بازگشت به مقالات</a>
  <h1 style="margin-top:1rem;max-width:48rem;line-height:1.4" itemprop="headline"><?= e($article['title']) ?></h1>
  <div style="margin-top:1rem;display:flex;gap:.75rem;align-items:center">
    <span class="badge" itemprop="author"><?= e($article['author_name']) ?></span>
    <?php if (!empty($article['published_at'])): ?>
      <time class="muted" style="font-size:.85rem" datetime="<?= e(date('c', strtotime((string) $article['published_at']))) ?>" itemprop="datePublished">
        <?= e(format_fa_datetime((string) $article['published_at'])) ?>
      </time>
    <?php endif; ?>
  </div>
  <div class="panel article-body" style="margin-top:2rem;max-width:48rem;line-height:1.9" itemprop="articleBody">
    <?= rich_html_for_display($article['content']) ?>
  </div>
</article>
<?php
$content = ob_get_clean();
require __DIR__ . '/../includes/layout.php';
