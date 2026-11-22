<?php
declare(strict_types=1);

$now = time();
$tabs = [
    'appts' => ['label' => 'نوبت‌های پیش‌رو', 'tone' => 'appts', 'empty' => 'نوبت قطعی برای آینده ندارید.', 'items' => []],
    'reserved' => ['label' => 'لیست رزرو', 'tone' => 'reservations', 'empty' => 'رزرو موقت برای آینده ندارید.', 'items' => []],
    'done' => ['label' => 'نوبت‌های گذشته', 'tone' => 'archive', 'empty' => 'نوبت گذشته‌ای ندارید.', 'items' => []],
    'cancelled' => ['label' => 'لغوشده‌ها', 'tone' => 'cancelled', 'empty' => 'نوبت لغوشده‌ای ندارید.', 'items' => []],
];

foreach (appointment_shared_notes_annotate($pdo, $appointments, (string) $user['id']) as $row) {
    $status = (string) $row['status'];
    $starts = strtotime((string) $row['starts_at']);
    if ($status === 'CANCELLED') {
        $tabs['cancelled']['items'][] = $row;
    } elseif ($status === 'COMPLETED' || $starts < $now) {
        $tabs['done']['items'][] = $row;
    } elseif ($status === 'CONFIRMED') {
        $tabs['appts']['items'][] = $row;
    } else {
        $tabs['reserved']['items'][] = $row;
    }
}
$tabs['appts']['items'] = array_reverse($tabs['appts']['items']);
$tabs['reserved']['items'] = array_reverse($tabs['reserved']['items']);
$initialTab = $booked ? 'reserved' : 'appts';

$appointmentItemMode = 'manage';
$appointmentOnlinePayEnabled = $onlinePayEnabled;
?>
<div class="binder-tile" data-binder-tabs data-binder-initial="<?= e($initialTab) ?>" data-binder-tone="<?= e($tabs[$initialTab]['tone']) ?>">
  <div class="binder-tabs" role="tablist" aria-label="دسته‌بندی نوبت‌ها">
    <?php foreach ($tabs as $key => $tab): ?>
      <button type="button" id="personal-tab-<?= e($key) ?>" class="binder-tab<?= $initialTab === $key ? ' is-active' : '' ?>" role="tab" data-binder-tab="<?= e($key) ?>" data-binder-tone="<?= e($tab['tone']) ?>" aria-controls="personal-panel-<?= e($key) ?>" aria-selected="<?= $initialTab === $key ? 'true' : 'false' ?>">
        <?= e($tab['label']) ?> <span class="binder-tab-count"><?= e(to_fa_digits((string) count($tab['items']))) ?></span>
        <?php if (in_array(true, array_column($tab['items'], '_shared_note_unread'), true)): ?>
          <?= appointment_shared_note_dot() ?>
        <?php endif; ?>
      </button>
    <?php endforeach; ?>
  </div>
  <div class="binder-body">
    <?php foreach ($tabs as $key => $tab): ?>
      <section id="personal-panel-<?= e($key) ?>" class="binder-panel<?= $initialTab === $key ? ' is-active' : '' ?>" data-binder-panel="<?= e($key) ?>" role="tabpanel" aria-labelledby="personal-tab-<?= e($key) ?>"<?= $initialTab === $key ? '' : ' hidden' ?>>
        <?php if ($tab['items'] === []): ?>
          <p class="muted binder-empty"><?= e($tab['empty']) ?></p>
        <?php elseif (in_array($key, ['appts', 'reserved'], true)): ?>
          <?php foreach (group_appointments_by_jalali_month($tab['items'], false)['months'] as $month): ?>
            <?php if ($month['items'] === []) { continue; } ?>
            <h2 class="binder-sub"><?= e($month['label']) ?></h2>
            <?php $appointmentList = $month['items']; require __DIR__ . '/patient_appointment_items.php'; ?>
          <?php endforeach; ?>
        <?php else: ?>
          <?php $appointmentList = $tab['items']; require __DIR__ . '/patient_appointment_items.php'; ?>
        <?php endif; ?>
      </section>
    <?php endforeach; ?>
  </div>
</div>
