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
    require __DIR__ . '/404.php';
    exit;
}

$pageTitle = $article['title'];
ob_start();
?>
<article class="container-page section">
  <a href="<?= e(url('/articles')) ?>" style="color:var(--primary);font-size:.9rem">← بازگشت به مقالات</a>
  <h1 style="margin-top:1rem;max-width:48rem;line-height:1.4"><?= e($article['title']) ?></h1>
  <div style="margin-top:1rem;display:flex;gap:.75rem;align-items:center">
    <span class="badge"><?= e($article['author_name']) ?></span>
  </div>
  <div class="panel whitespace-pre" style="margin-top:2rem;max-width:48rem;line-height:1.9">
    <?= e($article['content']) ?>
  </div>
</article>
<?php
$content = ob_get_clean();
require __DIR__ . '/../includes/layout.php';
