<?php
declare(strict_types=1);
/** @var array $peerList */
$peerList = $peerList ?? [];
if (!$peerList) {
    return;
}
?>
<h3 class="binder-sub">کارگاه‌های دیگر</h3>
<p class="muted" style="font-size:.85rem;margin:.2rem 0 .65rem">چون عضو این کارگاه‌ها نیستید، فقط تعداد اعضا دیده می‌شود.</p>
<div class="stack">
  <?php foreach ($peerList as $peer): ?>
    <?php if (!is_array($peer)) { continue; } ?>
    <article class="workshop-binder-card workshop-binder-card--peer">
      <strong><?= e((string) ($peer['title'] ?? 'کارگاه')) ?></strong>
      <div class="muted" style="font-size:.9rem;margin-top:.35rem">
        <?= e(to_fa_digits((string) (int) ($peer['enrolled_count'] ?? 0))) ?> نفر عضو
      </div>
    </article>
  <?php endforeach; ?>
</div>
