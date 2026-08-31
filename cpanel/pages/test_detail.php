<?php
declare(strict_types=1);

$slug = (string) ($_GET['slug'] ?? '');
$test = psych_test_by_slug($slug);

if (!$test) {
    http_response_code(404);
    $pageTitle = 'یافت نشد';
    require __DIR__ . '/404.php';
    exit;
}

$testsSidebarActive = $test['slug'];
$pageTitle = $test['title'];

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
