<?php
declare(strict_types=1);

/** @var array $openSlots */

$openSlots = $openSlots ?? [];
$groups = [];
foreach ($openSlots as $slot) {
    $dayKey = (string) ($slot['date'] ?? '') . '|' . (string) ($slot['doctor_id'] ?? '');
    if (!isset($groups[$dayKey])) {
        $groups[$dayKey] = [
            'doctor_id' => (string) ($slot['doctor_id'] ?? ''),
            'doctor_name' => (string) ($slot['doctor_name'] ?? ''),
            'specialty' => (string) ($slot['specialty'] ?? ''),
            'price' => (int) ($slot['price'] ?? 0),
            'date' => (string) ($slot['date'] ?? ''),
            'slots' => [],
        ];
    }
    $groups[$dayKey]['slots'][] = $slot;
}
?>
<h2 class="binder-sub">ساعت‌های خالی اعلام‌شده</h2>
<?php if (!$groups): ?>
  <p class="muted binder-empty">هنوز روز و ساعتی برای این ماه اعلام نشده است.</p>
<?php else: ?>
  <div class="stack">
    <?php foreach ($groups as $group): ?>
      <article class="patient-open-day">
        <div class="patient-open-day-head">
          <div>
            <strong><?= e($group['doctor_name']) ?></strong>
            <?php if ($group['specialty'] !== ''): ?>
              <div class="muted" style="font-size:.85rem"><?= e($group['specialty']) ?></div>
            <?php endif; ?>
            <div style="margin-top:.35rem;font-size:.9rem"><?= e(to_jalali_label($group['date'])) ?></div>
          </div>
          <?php if ($group['price'] > 0): ?>
            <div class="muted" style="font-size:.85rem"><?= e(format_price($group['price'])) ?></div>
          <?php endif; ?>
        </div>
        <div class="slots">
          <?php foreach ($group['slots'] as $slot): ?>
            <button
              type="button"
              class="slot-btn dash-book-btn"
              data-doctor="<?= e($slot['doctor_id']) ?>"
              data-date="<?= e($slot['date']) ?>"
              data-time="<?= e($slot['time']) ?>"
              disabled
            ><?= e(to_fa_digits((string) $slot['label'])) ?></button>
          <?php endforeach; ?>
        </div>
      </article>
    <?php endforeach; ?>
  </div>
<?php endif; ?>
