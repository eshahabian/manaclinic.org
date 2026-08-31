<?php
declare(strict_types=1);

$testsSidebarActive = $testsSidebarActive ?? '';
$tests = psych_tests_catalog();
?>
<aside class="tests-sidebar" aria-label="آزمون‌های روانشناسی">
  <div class="tests-sidebar-inner">
    <p class="tests-sidebar-title">
      <a href="<?= e(url('/tests')) ?>">آزمون‌ها</a>
    </p>
    <nav class="tests-sidebar-nav">
      <?php foreach ($tests as $test): ?>
        <?php
        $isActive = $testsSidebarActive === $test['slug'] || ($testsSidebarActive === 'index' && false);
        $href = url('/tests/' . $test['slug']);
        ?>
        <a
          href="<?= e($href) ?>"
          class="tests-sidebar-link<?= $testsSidebarActive === $test['slug'] ? ' is-active' : '' ?>"
          title="<?= e($test['abbr']) ?>"
        >
          <span class="tests-sidebar-link-title"><?= e($test['title']) ?></span>
          <span class="tests-sidebar-link-abbr"><?= e($test['abbr']) ?></span>
        </a>
      <?php endforeach; ?>
    </nav>
  </div>
</aside>
