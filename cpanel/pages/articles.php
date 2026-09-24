<?php
declare(strict_types=1);

$articles = $pdo->query("
  SELECT a.*, u.name AS author_name
  FROM articles a
  JOIN users u ON u.id = a.author_id
  WHERE a.published = 1
  ORDER BY a.published_at DESC
")->fetchAll();

$topicKey = clinic_normalize_filter((string) ($_GET['topic'] ?? ''), doctor_focus_options());
$topic = $topicKey !== '' ? clinic_issue_topic($topicKey) : null;
if ($topicKey !== '') {
    $articles = array_values(array_filter($articles, static function ($row) use ($topicKey) {
        return in_array($topicKey, clinic_article_topics($row), true);
    }));
}

$pageTitle = $topic ? ('مقالات ' . $topic['label'] . ' | مانا کلینیک') : 'مقالات';
$pageDescription = $topic
    ? ('مقالات «' . $topic['label'] . '» از تیم مانا کلینیک سعادت‌آباد؛ همراه با معرفی درمانگر مرتبط و امکان رزرو نوبت.')
    : 'مقالات تخصصی روانشناسی و روان‌درمانی مانا کلینیک؛ اضطراب، خواب، مهربانی با خود، تنظیم هیجان و سلامت روان.';
$pageCanonical = $topic ? url('/issues/' . $topicKey) : url('/articles');
$pageKeywords = $topic ? $topic['keywords'] : 'مقالات روانشناسی, سلامت روان, اضطراب, تنظیم هیجان, مانا کلینیک';
ob_start();
?>
<div class="container-page section">
  <h1><?= $topic ? e('مقالات ' . $topic['label']) : 'مقالات روانشناسی' ?></h1>
  <p class="muted">هر مقاله به موضوع و درمانگر همان حوزه وصل است. از چیپ‌ها موضوع را ببین یا مستقیم نوبت بگیر.</p>
  <?php if ($topic): ?>
    <p class="service-detail-cta" style="margin-top:1rem">
      <a class="btn btn-primary" href="<?= e(url('/issues/' . $topicKey)) ?>">لندینگ «<?= e($topic['label']) ?>»</a>
      <a class="btn btn-outline" href="<?= e(clinic_doctors_href(['focus' => $topicKey])) ?>">رزرو درمانگر این موضوع</a>
    </p>
  <?php endif; ?>
  <div class="dir-filter-chips issue-home-chips" style="margin-top:1rem">
    <a class="dir-chip<?= $topicKey === '' ? ' is-on' : '' ?>" href="<?= e(url('/articles')) ?>">همه</a>
    <?php foreach (clinic_issue_topics() as $key => $row): ?>
      <a class="dir-chip<?= $topicKey === $key ? ' is-on' : '' ?>" href="<?= e(url('/articles?topic=' . rawurlencode($key))) ?>"><?= e($row['label']) ?></a>
    <?php endforeach; ?>
  </div>
  <div class="grid-2" style="margin-top:2rem">
    <?php foreach ($articles as $article): ?>
      <?= clinic_article_card_html($article) ?>
    <?php endforeach; ?>
    <?php if (!$articles): ?><p class="muted">هنوز مقاله‌ای نیست.</p><?php endif; ?>
  </div>
</div>
<?php
$content = ob_get_clean();
require __DIR__ . '/../includes/layout.php';
