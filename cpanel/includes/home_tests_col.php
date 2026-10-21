<?php
declare(strict_types=1);

$tests = psych_tests_catalog();
?>
<div class="home-tests-col">
  <div class="section-head home-tests-head">
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
        <img src="<?= e(url('/assets/img/tests/' . $test['slug'] . '.webp')) ?>" width="48" height="48" loading="lazy" alt="" style="width:48px;height:48px;object-fit:cover;border-radius:12px;flex-shrink:0">
        <span class="home-test-chip-title"><?= e($test['title']) ?></span>
        <span class="home-test-chip-tip" role="tooltip"><?= e($test['description']) ?></span>
      </a>
    <?php endforeach; ?>
  </div>
</div>
