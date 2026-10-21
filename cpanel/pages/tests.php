<?php
declare(strict_types=1);

$tests = psych_tests_catalog();
$testsSidebarActive = 'index';
$pageTitle = 'آزمون‌های روانشناسی';
$pageDescription = 'آزمون‌های روانشناسی مانا کلینیک: غربالگری اضطراب، افسردگی، استرس و شخصیت. جایگزین تشخیص بالینی نیست؛ در سعادت‌آباد می‌توانید نوبت بگیرید.';
$pageCanonical = url('/tests');
$pageKeywords = 'آزمون روانشناسی, پرسشنامه افسردگی بک, آزمون اضطراب, مانا کلینیک';
$pageHead = ($pageHead ?? '') . '<link rel="stylesheet" href="' . e(url('/assets/css/psych-tests.css?v=2')) . '">';

ob_start();
?>
<div class="container-page section">
  <h1>آزمون‌های روانشناسی</h1>
  <p class="muted" style="max-width:40rem;line-height:1.9">
    ابزارهای غربالگری و خودارزیابی؛ وضعیت دسترسی هر آزمون در کارت آن مشخص است. نتیجه جایگزین ارزیابی بالینی نیست.
  </p>
  <div class="psych-tests-grid" style="margin-top:1.25rem">
    <?php foreach ($tests as $test): ?>
      <a class="panel card-link" href="<?= e(url('/tests/' . $test['slug'])) ?>">
        <div class="psych-test-tile-top">
          <img class="psych-test-tile-image" src="<?= e(url('/assets/img/tests/' . $test['slug'] . '.webp')) ?>" width="72" height="72" loading="lazy" alt="">
          <div>
        <div style="display:flex;justify-content:space-between;gap:.75rem;align-items:start">
          <span class="badge"><?= e($test['category']) ?></span>
          <span class="muted" style="font-size:.8rem"><?= e($test['abbr']) ?></span>
        </div>
        <h2 style="margin:.75rem 0 0;font-size:1rem;line-height:1.7"><?= e($test['title']) ?></h2>
          </div>
        </div>
        <p class="muted" style="margin-top:.75rem;line-height:1.8;font-size:.9rem"><?= e($test['description']) ?></p>
        <p class="muted" style="margin-top:.75rem;font-size:.85rem">⏱ <?= e($test['duration']) ?></p>
        <?php if (!psych_assessment_enabled($test['slug'])): ?>
          <p style="margin-top:.75rem;font-size:.85rem;color:var(--accent)">به‌زودی</p>
        <?php elseif ($test['slug'] === 'bdi'): ?>
          <p style="margin-top:.75rem;font-size:.85rem;color:var(--accent)"><?= $test['ready'] ? 'شروع پرسشنامه · نمودار و تحلیل هوش مصنوعی' : 'امتیازدهی، نمودار و تحلیل پاسخنامه موجود' ?></p>
        <?php else: ?>
          <p style="margin-top:.75rem;font-size:.85rem;color:var(--accent)"><?= $test['ready'] ? 'شروع پرسشنامه' : ($test['slug'] === 'mmpi' ? 'نمایش و توضیح گزارش رسمی · ویژه متخصص' : 'امتیازدهی و نمودار آماده · سؤال‌ها پس از مجوز') ?></p>
        <?php endif; ?>
      </a>
    <?php endforeach; ?>
  </div>
</div>
<?php
$content = ob_get_clean();
require __DIR__ . '/../includes/layout.php';
