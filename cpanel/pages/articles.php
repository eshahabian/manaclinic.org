<?php
declare(strict_types=1);

$articles = $pdo->query("
  SELECT a.*, u.name AS author_name
  FROM articles a
  JOIN users u ON u.id = a.author_id
  WHERE a.published = 1
  ORDER BY a.published_at DESC
")->fetchAll();

$pageTitle = 'مقالات';
$pageDescription = 'مقالات تخصصی روانشناسی و روان‌درمانی مانا کلینیک؛ اضطراب، خواب، مهربانی با خود، تنظیم هیجان و سلامت روان.';
$pageCanonical = url('/articles');
$pageKeywords = 'مقالات روانشناسی, سلامت روان, اضطراب, تنظیم هیجان, مانا کلینیک';
ob_start();
?>
<div class="container-page section">
  <h1>مقالات روانشناسی</h1>
  <p class="muted">محتوای تخصصی از تیم مانا کلینیک</p>
  <div class="grid-2" style="margin-top:2rem">
    <?php foreach ($articles as $article): ?>
      <a class="panel card-link" href="<?= e(url('/articles/' . $article['slug'])) ?>">
        <span class="badge"><?= e($article['author_name']) ?></span>
        <h2 style="margin:.75rem 0 0;font-size:1.25rem;line-height:1.7"><?= e($article['title']) ?></h2>
        <p class="muted line-clamp-3" style="margin-top:.75rem;line-height:1.8;font-size:.9rem"><?= e($article['excerpt']) ?></p>
      </a>
    <?php endforeach; ?>
    <?php if (!$articles): ?><p class="muted">هنوز مقاله‌ای نیست.</p><?php endif; ?>
  </div>
</div>
<?php
$content = ob_get_clean();
require __DIR__ . '/../includes/layout.php';
