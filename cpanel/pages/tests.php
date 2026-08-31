<?php
declare(strict_types=1);

$tests = psych_tests_catalog();
$testsSidebarActive = 'index';
$pageTitle = 'آزمون‌های روانشناسی';

ob_start();
?>
<div class="container-page section">
  <h1>آزمون‌های روانشناسی</h1>
  <p class="muted" style="max-width:40rem;line-height:1.9">
    ابزارهای استاندارد غربالگری و خودارزیابی. محتوای هر آزمون به‌زودی فعال می‌شود.
  </p>
  <div class="grid-2" style="margin-top:2rem">
    <?php foreach ($tests as $test): ?>
      <a class="panel card-link" href="<?= e(url('/tests/' . $test['slug'])) ?>">
        <div style="display:flex;justify-content:space-between;gap:.75rem;align-items:start">
          <span class="badge"><?= e($test['category']) ?></span>
          <span class="muted" style="font-size:.8rem"><?= e($test['abbr']) ?></span>
        </div>
        <h2 style="margin:.75rem 0 0;font-size:1.15rem;line-height:1.7"><?= e($test['title']) ?></h2>
        <p class="muted" style="margin-top:.75rem;line-height:1.8;font-size:.9rem"><?= e($test['description']) ?></p>
        <p class="muted" style="margin-top:.75rem;font-size:.85rem">⏱ <?= e($test['duration']) ?></p>
        <?php if (!$test['ready']): ?>
          <p style="margin-top:.75rem;font-size:.85rem;color:var(--accent)">به‌زودی</p>
        <?php endif; ?>
      </a>
    <?php endforeach; ?>
  </div>
</div>
<?php
$content = ob_get_clean();
require __DIR__ . '/../includes/layout.php';
