<?php
declare(strict_types=1);

$tests = psych_tests_catalog();
?>
<div class="home-tests-col">
  <div class="section-head">
    <div>
      <h2>آزمون‌ها</h2>
      <p class="muted">ابزارهای خودارزیابی روانشناسی</p>
    </div>
  </div>
  <div class="home-tests-grid">
    <?php foreach ($tests as $test): ?>
      <a class="panel card-link" href="<?= e(url('/tests/' . $test['slug'])) ?>">
        <span class="badge"><?= e($test['category']) ?></span>
        <h3 style="margin:.75rem 0 0;line-height:1.7"><?= e($test['title']) ?></h3>
        <p class="muted line-clamp-3" style="font-size:.9rem;line-height:1.8;margin-top:.75rem"><?= e($test['description']) ?></p>
      </a>
    <?php endforeach; ?>
  </div>
  <p style="margin-top:1.25rem">
    <a class="articles-all-link" href="<?= e(url('/tests')) ?>">همه آزمون‌ها</a>
  </p>
</div>
