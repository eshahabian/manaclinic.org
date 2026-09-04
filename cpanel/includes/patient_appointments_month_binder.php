<?php
declare(strict_types=1);

/** @var array $monthGroups */
/** @var string $defaultMonthId */
/** @var bool $monthBinderNested */
/** @var string $appointmentItemMode */
/** @var string $monthBinderAria */

$monthGroups = $monthGroups ?? [];
$defaultMonthId = (string) ($defaultMonthId ?? '');
$monthBinderNested = !empty($monthBinderNested);
$appointmentItemMode = $appointmentItemMode ?? 'simple';
$monthBinderAria = $monthBinderAria ?? 'ماه نوبت‌ها';

if ($defaultMonthId === '' && $monthGroups) {
    $defaultMonthId = (string) array_key_first($monthGroups);
}
$defaultTone = (string) (($monthGroups[$defaultMonthId]['tone'] ?? 'in-person'));
?>
<div class="binder-tile<?= $monthBinderNested ? ' binder-tile--nested' : '' ?>"
     data-binder-tabs
     <?= $monthBinderNested ? 'data-binder-hash="0"' : '' ?>
     data-binder-initial="<?= e($defaultMonthId) ?>"
     data-binder-tone="<?= e($defaultTone) ?>">
  <div class="binder-tabs" role="tablist" aria-label="<?= e($monthBinderAria) ?>">
    <?php foreach ($monthGroups as $id => $bucket): ?>
      <button type="button"
        class="binder-tab <?= e((string) ($bucket['class'] ?? 'binder-tab-in-person')) ?><?= $defaultMonthId === $id ? ' is-active' : '' ?>"
        role="tab"
        data-binder-tab="<?= e((string) $id) ?>"
        data-binder-tone="<?= e((string) ($bucket['tone'] ?? 'in-person')) ?>"
        aria-selected="<?= $defaultMonthId === $id ? 'true' : 'false' ?>">
        <?= e((string) ($bucket['tab_label'] ?? $bucket['short'] ?? $bucket['label'] ?? $id)) ?>
        <span class="binder-tab-count"><?= count($bucket['items'] ?? []) ?></span>
      </button>
    <?php endforeach; ?>
  </div>
  <div class="binder-body">
    <?php foreach ($monthGroups as $id => $bucket): ?>
      <section class="binder-panel<?= $defaultMonthId === $id ? ' is-active' : '' ?>" data-binder-panel="<?= e((string) $id) ?>" role="tabpanel"<?= $defaultMonthId === $id ? '' : ' hidden' ?>>
        <h2 class="binder-sub" style="margin-top:0"><?= e((string) ($bucket['label'] ?? '')) ?></h2>
        <?php
          $appointmentList = $bucket['items'] ?? [];
          require __DIR__ . '/patient_appointment_items.php';
        ?>
      </section>
    <?php endforeach; ?>
  </div>
</div>
