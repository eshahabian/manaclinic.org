<?php
declare(strict_types=1);

$testsSidebarActive = $testsSidebarActive ?? '';
$tests = psych_tests_catalog();
?>
<aside class="tests-rail" aria-label="آزمون‌های روانشناسی">
  <div class="section-head tests-rail-head">
    <div>
      <h2><a href="<?= e(url('/tests')) ?>">آزمون‌ها</a></h2>
      <p class="muted">ابزارهای خودارزیابی روانشناسی</p>
    </div>
  </div>
  <nav class="tests-rail-nav">
    <?php foreach ($tests as $test): ?>
      <a
        href="<?= e(url('/tests/' . $test['slug'])) ?>"
        class="tests-chip panel card-link<?= $testsSidebarActive === $test['slug'] ? ' is-active' : '' ?>"
        title="<?= e($test['abbr']) ?>"
      >
        <span class="tests-chip-title"><?= e($test['title']) ?></span>
        <span class="tests-chip-desc muted"><?= e($test['description']) ?></span>
      </a>
    <?php endforeach; ?>
  </nav>
</aside>
