<?php
declare(strict_types=1);

/** @var array $grouped */
/** @var array $enrollmentsByTab */
/** @var array $enrollByWorkshop */
/** @var array $wallet */
/** @var array $binderTabs */
/** @var bool $workshopBinderNested */
/** @var string $workshopBinderInitial */

$grouped = $grouped ?? ['in-person' => [], 'online' => [], 'offline' => [], 'archive' => []];
$enrollmentsByTab = $enrollmentsByTab ?? ['in-person' => [], 'online' => [], 'offline' => [], 'archive' => []];
$enrollByWorkshop = $enrollByWorkshop ?? [];
$wallet = $wallet ?? ['balance' => 0];
$binderTabs = $binderTabs ?? [
    'in-person' => ['label' => 'حضوری', 'class' => 'binder-tab-in-person', 'empty' => 'کارگاه حضوری فعالی برای ثبت‌نام نیست.'],
    'online' => ['label' => 'آنلاین', 'class' => 'binder-tab-online', 'empty' => 'کارگاه آنلاین فعالی برای ثبت‌نام نیست.'],
    'offline' => ['label' => 'آفلاین', 'class' => 'binder-tab-offline', 'empty' => 'دوره آفلاین فعالی برای ثبت‌نام نیست.'],
];
$workshopBinderNested = !empty($workshopBinderNested);
$workshopBinderInitial = (string) ($workshopBinderInitial ?? 'in-person');
if (!in_array($workshopBinderInitial, ['in-person', 'online', 'offline', 'archive'], true)) {
    $workshopBinderInitial = 'in-person';
}
$tabParam = $workshopBinderInitial;
?>
<div class="binder-tile<?= $workshopBinderNested ? ' binder-tile--nested' : '' ?>"
     data-binder-tabs
     <?= $workshopBinderNested ? 'data-binder-hash="0"' : '' ?>
     data-binder-initial="<?= e($tabParam) ?>"
     data-binder-tone="<?= e($tabParam) ?>">
  <div class="binder-tabs" role="tablist" aria-label="دسته‌بندی کارگاه‌ها">
    <?php foreach ($binderTabs as $id => $meta): ?>
      <button type="button" class="binder-tab <?= e($meta['class']) ?><?= $tabParam === $id ? ' is-active' : '' ?>" role="tab" data-binder-tab="<?= e($id) ?>" data-binder-tone="<?= e($id) ?>" aria-selected="<?= $tabParam === $id ? 'true' : 'false' ?>">
        <?= e($meta['label']) ?>
        <span class="binder-tab-count"><?= count($grouped[$id] ?? []) ?></span>
      </button>
    <?php endforeach; ?>
    <button type="button" class="binder-tab binder-tab-archive<?= $tabParam === 'archive' ? ' is-active' : '' ?>" role="tab" data-binder-tab="archive" data-binder-tone="archive" aria-selected="<?= $tabParam === 'archive' ? 'true' : 'false' ?>">
      آرشیو
      <span class="binder-tab-count"><?= count($grouped['archive'] ?? []) ?></span>
    </button>
  </div>
  <div class="binder-body">
    <?php foreach ($binderTabs as $id => $meta): ?>
      <section class="binder-panel<?= $tabParam === $id ? ' is-active' : '' ?>" data-binder-panel="<?= e($id) ?>" role="tabpanel"<?= $tabParam === $id ? '' : ' hidden' ?>>
        <h2 class="binder-sub" style="margin-top:0">کارگاه‌های قابل ثبت‌نام</h2>
        <?php
          $workshopList = $grouped[$id] ?? [];
          $archiveView = false;
          $emptyAvailable = $meta['empty'];
          require __DIR__ . '/patient_workshop_available.php';
        ?>
        <h2 class="binder-sub">ثبت‌نام‌های من</h2>
        <?php
          $enrollmentList = $enrollmentsByTab[$id] ?? [];
          $emptyEnrollments = 'هنوز در کارگاهی از این دسته ثبت‌نام نکرده‌اید.';
          require __DIR__ . '/patient_workshop_enrollments.php';
        ?>
      </section>
    <?php endforeach; ?>

    <section class="binder-panel<?= $tabParam === 'archive' ? ' is-active' : '' ?>" data-binder-panel="archive" role="tabpanel"<?= $tabParam === 'archive' ? '' : ' hidden' ?>>
      <p class="muted" style="margin:0 0 .85rem;font-size:.9rem">کارگاه‌هایی که زمانشان تمام شده اینجا هستند. ثبت‌نام جدید برای آن‌ها ممکن نیست؛ اگر قبلاً ثبت‌نام کرده باشید، محتوا و لینک جلسه را می‌بینید.</p>
      <h2 class="binder-sub" style="margin-top:0">کارگاه‌های آرشیو</h2>
      <?php
        $workshopList = $grouped['archive'] ?? [];
        $archiveView = true;
        $emptyAvailable = 'هنوز کارگاهی در آرشیو نیست.';
        require __DIR__ . '/patient_workshop_available.php';
      ?>
      <h2 class="binder-sub">ثبت‌نام‌های آرشیو من</h2>
      <?php
        $enrollmentList = $enrollmentsByTab['archive'] ?? [];
        $emptyEnrollments = 'ثبت‌نام آرشیوشده‌ای ندارید.';
        require __DIR__ . '/patient_workshop_enrollments.php';
      ?>
    </section>
  </div>
</div>
