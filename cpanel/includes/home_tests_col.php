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
      <a
        class="home-test-chip panel card-link"
        href="<?= e(url('/tests/' . $test['slug'])) ?>"
        title="<?= e($test['title']) ?>"
      >
        <span class="home-test-chip-title"><?= e($test['title']) ?></span>
        <span class="home-test-chip-tip" role="tooltip"><?= e($test['description']) ?></span>
      </a>
    <?php endforeach; ?>
  </div>
  <p style="margin-top:1.25rem">
    <a class="articles-all-link" href="<?= e(url('/tests')) ?>">همه آزمون‌ها</a>
  </p>
</div>
