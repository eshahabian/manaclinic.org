<?php
declare(strict_types=1);
/** @var array $peerList */
$peerList = $peerList ?? [];
if (!$peerList) {
    return;
}
?>
<h3 class="binder-sub">کارگاه‌های سایر درمانگران</h3>
<p class="muted" style="font-size:.85rem;margin:.2rem 0 .65rem">کارگاه‌های منتشرشده که مراجعان هم در «دوره‌های من» می‌بینند.</p>
<div class="stack">
  <?php foreach ($peerList as $peer): ?>
    <article class="workshop-binder-card workshop-binder-card--peer">
      <strong><?= e($peer['title']) ?></strong>
      <span class="badge" style="margin-right:.5rem"><?= e(workshop_type_label($peer['type'])) ?></span>
      <div class="muted" style="font-size:.85rem;margin-top:.35rem">درمانگر: <?= e($peer['doctor_name']) ?></div>
      <div class="muted" style="font-size:.85rem;margin-top:.25rem">
        <?php if ($peer['type'] === 'OFFLINE'): ?>
          دوره آفلاین
        <?php else: ?>
          <?= e(format_workshop_datetime_fa($peer['starts_at'])) ?> — <?= e(format_workshop_datetime_fa($peer['ends_at'])) ?>
        <?php endif; ?>
        · <?= e(format_price((int)$peer['price'])) ?>
        · <?= !empty($peer['enrollment_open']) ? 'ثبت‌نام باز' : 'ثبت‌نام بسته' ?>
      </div>
    </article>
  <?php endforeach; ?>
</div>
