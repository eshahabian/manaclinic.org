<?php
declare(strict_types=1);

$slug = (string) ($_GET['slug'] ?? '');
$test = psych_test_by_slug($slug);

if (!$test) {
    http_response_code(404);
    $pageTitle = 'یافت نشد';
    $pageRobots = 'noindex,follow';
    require __DIR__ . '/404.php';
    exit;
}

$testsSidebarActive = $test['slug'];
if (empty($test['ready'])) {
    $pageRobots = 'noindex,follow';
}
$pageTitle = $test['title'];
$pageDescription = trim((string) ($test['description'] ?? '')) !== ''
    ? (string) $test['description'] . ' آزمون روانشناسی در مانا کلینیک سعادت‌آباد؛ جایگزین تشخیص بالینی نیست.'
    : ($test['title'] . ' — آزمون روانشناسی مانا کلینیک سعادت‌آباد.');
$pageCanonical = url('/tests/' . $test['slug']);
$pageKeywords = $test['title'] . ', ' . ($test['abbr'] ?? '') . ', آزمون روانشناسی, مانا کلینیک';

require_once __DIR__ . '/../includes/psych_assessments.php';
if (!psych_assessment_enabled($slug)) {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
        http_response_code(404);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        echo json_encode(['error' => 'این آزمون هنوز فعال نشده است.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $pageRobots = 'noindex,follow';
    $pageHead = ($pageHead ?? '') . '<link rel="stylesheet" href="' . e(url('/assets/css/bdi.css?v=4')) . '">';
    ob_start();
    ?>
    <section class="container-page section assessment-coming-soon">
      <a href="<?= e(url('/tests')) ?>">← آزمون‌های روان‌شناسی</a>
      <article class="panel" style="margin-top:1.5rem;max-width:44rem;margin-inline:auto;text-align:center;padding:2rem">
        <img src="<?= e(url('/assets/img/tests/' . $slug . '.webp')) ?>" width="180" height="180" alt="" style="border-radius:24px;max-width:100%;height:auto">
        <h1><?= e($test['title']) ?></h1>
        <p class="muted"><?= e($test['description']) ?></p>
        <span class="badge">به‌زودی</span>
        <p class="muted">این آزمون هنوز برای اجرا فعال نشده است.</p>
      </article>
    </section>
    <?php
    $content = ob_get_clean();
    require __DIR__ . '/../includes/layout.php';
    exit;
}

if ($slug === 'bdi') {
    require __DIR__ . '/test_bdi.php';
    exit;
}

if (isset(psych_assessment_specs()[$slug])) {
    require __DIR__ . '/test_assessment.php';
    exit;
}

ob_start();
?>
<div class="container-page section">
  <div style="display:flex;flex-wrap:wrap;gap:.5rem;align-items:center;margin-bottom:1rem">
    <span class="badge"><?= e($test['category']) ?></span>
    <span class="muted" style="font-size:.9rem"><?= e($test['abbr']) ?></span>
    <span class="muted" style="font-size:.85rem">⏱ <?= e($test['duration']) ?></span>
  </div>
  <h1 style="margin:0;line-height:1.5"><?= e($test['title']) ?></h1>
  <p class="muted" style="margin-top:1rem;max-width:42rem;line-height:1.9"><?= e($test['description']) ?></p>

  <div class="panel" style="margin-top:2rem;padding:1.5rem">
  <?php if ($test['ready']): ?>
    <p>فرم آزمون اینجا قرار می‌گیرد.</p>
  <?php else: ?>
    <p style="margin:0;line-height:1.9;color:var(--muted)">
      محتوای این آزمون هنوز بارگذاری نشده است. به‌زودی سوالات و تفسیر نتایج اضافه می‌شود.
    </p>
  <?php endif; ?>
  </div>

  <p style="margin-top:1.5rem;font-size:.85rem;line-height:1.8;color:var(--muted)">
    این آزمون‌ها جایگزین تشخیص بالینی نیستند. در صورت نگرانی، با متخصص مشورت کنید.
    <a href="<?= e(url('/doctors')) ?>" style="color:var(--primary);font-weight:600">رزرو نوبت</a>
  </p>
</div>
<?php
$content = ob_get_clean();
require __DIR__ . '/../includes/layout.php';
