<?php
declare(strict_types=1);

$testsSidebarActive = $testsSidebarActive ?? '';
$tests = psych_tests_catalog();
?>
<aside class="tests-rail" aria-label="آزمون‌های روانشناسی">
  <p class="tests-rail-title">
    <a href="<?= e(url('/tests')) ?>">آزمون‌ها</a>
  </p>
  <nav class="tests-rail-nav">
    <?php foreach ($tests as $test): ?>
      <a
        href="<?= e(url('/tests/' . $test['slug'])) ?>"
        class="tests-chip<?= $testsSidebarActive === $test['slug'] ? ' is-active' : '' ?>"
        title="<?= e($test['abbr']) ?>"
      >
        <span class="tests-chip-title"><?= e($test['title']) ?></span>
        <span class="tests-chip-desc"><?= e($test['description']) ?></span>
      </a>
    <?php endforeach; ?>
  </nav>
</aside>
