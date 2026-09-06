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
$sessionsByWorkshop = $sessionsByWorkshop ?? [];
$wallet = $wallet ?? ['balance' => 0];
$binderTabs = $binderTabs ?? [
    'in-person' => ['label' => 'حضوری', 'class' => 'binder-tab-in-person', 'empty' => 'کارگاه حضوری فعالی برای ثبت‌نام نیست.'],
    'online' => ['label' => 'آنلاین', 'class' => 'binder-tab-online', 'empty' => 'کارگاه آنلاین فعالی برای ثبت‌نام نیست.'],
    'offline' => ['label' => 'آفلاین', 'class' => 'binder-tab-offline', 'empty' => 'دوره آفلاین فعالی برای ثبت‌نام نیست.'],
];
$workshopBinderNested = !empty($workshopBinderNested);
$workshopBinderMode = (string) ($workshopBinderMode ?? 'all');
if (!in_array($workshopBinderMode, ['all', 'catalog', 'requested', 'mine'], true)) {
    $workshopBinderMode = 'all';
}
$showCatalog = in_array($workshopBinderMode, ['all', 'catalog'], true);
$showEnrollments = in_array($workshopBinderMode, ['all', 'requested', 'mine'], true);
if ($workshopBinderMode === 'requested') {
    $enrollmentsByTab = patient_enrollments_filter_status($enrollmentsByTab, ['PENDING_PAYMENT']);
} elseif ($workshopBinderMode === 'mine') {
    $enrollmentsByTab = patient_enrollments_filter_status($enrollmentsByTab, ['CONFIRMED', 'COMPLETED']);
}
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
        <span class="binder-tab-count"><?= $showCatalog ? count($grouped[$id] ?? []) : count($enrollmentsByTab[$id] ?? []) ?></span>
      </button>
    <?php endforeach; ?>
    <button type="button" class="binder-tab binder-tab-archive<?= $tabParam === 'archive' ? ' is-active' : '' ?>" role="tab" data-binder-tab="archive" data-binder-tone="archive" aria-selected="<?= $tabParam === 'archive' ? 'true' : 'false' ?>">
      آرشیو
      <span class="binder-tab-count"><?= $showCatalog ? count($grouped['archive'] ?? []) : count($enrollmentsByTab['archive'] ?? []) ?></span>
    </button>
  </div>
  <div class="binder-body">
    <?php foreach ($binderTabs as $id => $meta): ?>
      <section class="binder-panel<?= $tabParam === $id ? ' is-active' : '' ?>" data-binder-panel="<?= e($id) ?>" role="tabpanel"<?= $tabParam === $id ? '' : ' hidden' ?>>
        <?php if ($showCatalog): ?>
        <h2 class="binder-sub" style="margin-top:0">کارگاه‌های قابل ثبت‌نام</h2>
        <?php
          $workshopList = $grouped[$id] ?? [];
          $archiveView = false;
          $emptyAvailable = $meta['empty'];
          require __DIR__ . '/patient_workshop_available.php';
        ?>
        <?php endif; ?>
        <?php if ($showEnrollments): ?>
        <h2 class="binder-sub"<?= $showCatalog ? '' : ' style="margin-top:0"' ?>><?= $workshopBinderMode === 'requested' ? 'درخواست‌های این دسته' : ($workshopBinderMode === 'mine' ? 'دوره‌های تأییدشده' : 'ثبت‌نام‌های من') ?></h2>
        <?php
          $enrollmentList = $enrollmentsByTab[$id] ?? [];
          $emptyEnrollments = $workshopBinderMode === 'requested'
            ? 'در این دسته درخواست در انتظاری ندارید.'
            : ($workshopBinderMode === 'mine' ? 'هنوز کارگاه تأییدشده‌ای در این دسته ندارید.' : 'هنوز در کارگاهی از این دسته ثبت‌نام نکرده‌اید.');
          require __DIR__ . '/patient_workshop_enrollments.php';
        ?>
        <?php endif; ?>
      </section>
    <?php endforeach; ?>

    <section class="binder-panel<?= $tabParam === 'archive' ? ' is-active' : '' ?>" data-binder-panel="archive" role="tabpanel"<?= $tabParam === 'archive' ? '' : ' hidden' ?>>
      <p class="muted" style="margin:0 0 .85rem;font-size:.9rem">کارگاه‌هایی که زمانشان تمام شده اینجا هستند. ثبت‌نام جدید برای آن‌ها ممکن نیست؛ اگر قبلاً ثبت‌نام کرده باشید، محتوا و لینک جلسه را می‌بینید.</p>
      <?php if ($showCatalog): ?>
      <h2 class="binder-sub" style="margin-top:0">کارگاه‌های آرشیو</h2>
      <?php
        $workshopList = $grouped['archive'] ?? [];
        $archiveView = true;
        $emptyAvailable = 'هنوز کارگاهی در آرشیو نیست.';
        require __DIR__ . '/patient_workshop_available.php';
      ?>
      <?php endif; ?>
      <?php if ($showEnrollments): ?>
      <h2 class="binder-sub"<?= $showCatalog ? '' : ' style="margin-top:0"' ?>><?= $workshopBinderMode === 'requested' ? 'درخواست‌های آرشیو' : 'ثبت‌نام‌های آرشیو من' ?></h2>
      <?php
        $enrollmentList = $enrollmentsByTab['archive'] ?? [];
        $emptyEnrollments = $workshopBinderMode === 'requested' ? 'درخواست آرشیوشده‌ای ندارید.' : 'ثبت‌نام آرشیوشده‌ای ندارید.';
        require __DIR__ . '/patient_workshop_enrollments.php';
      ?>
      <?php endif; ?>
    </section>
  </div>
</div>
