<?php
declare(strict_types=1);

/** @var array $workshopList */
/** @var array $enrollByWorkshop */
/** @var bool $archiveView */

$workshopList = $workshopList ?? [];
$enrollByWorkshop = $enrollByWorkshop ?? [];
$archiveView = !empty($archiveView);
$emptyAvailable = $emptyAvailable ?? 'کارگاه فعالی برای ثبت‌نام در این دسته نیست.';
?>
<?php if (!$workshopList): ?>
  <p class="muted binder-empty"><?= e($emptyAvailable) ?></p>
<?php else: ?>
  <div class="stack">
    <?php foreach ($workshopList as $w): ?>
      <?php
        $enr = $enrollByWorkshop[(string) $w['id']] ?? null;
        $enrolled = $enr && in_array((string) ($enr['status'] ?? ''), ['PENDING_PAYMENT', 'CONFIRMED', 'COMPLETED'], true);
      ?>
      <article class="workshop-binder-card<?= $archiveView ? ' is-archived' : '' ?>">
        <div class="workshop-card-row">
          <div class="workshop-card-main">
            <strong><?= e($w['title']) ?></strong>
            <span class="badge" style="margin-right:.5rem"><?= e(workshop_type_label((string) $w['type'])) ?></span>
            <?php $mediaStats = workshop_media_counts_html(workshop_media_counts_from_row($w)); if ($mediaStats): ?>
              <div style="margin-top:.4rem"><?= $mediaStats ?></div>
            <?php endif; ?>
            <div class="muted" style="font-size:.85rem;margin-top:.25rem"><?= e((string) ($w['doctor_name'] ?? '')) ?></div>
            <div style="font-size:.85rem;margin-top:.35rem">
              <?php if ($w['type'] === 'OFFLINE'): ?>
                <span class="muted">دوره آفلاین — دسترسی به ویدیوها پس از ثبت‌نام</span>
              <?php else: ?>
                <?= e(format_workshop_datetime_fa((string) $w['starts_at'])) ?> — <?= e(format_workshop_datetime_fa((string) $w['ends_at'])) ?>
              <?php endif; ?>
            </div>
            <div class="muted" style="font-size:.85rem;margin-top:.25rem"><?= e(format_price((int) $w['price'])) ?></div>
            <?php if (!empty($w['items_to_bring'])): ?>
              <div style="font-size:.8rem;margin-top:.35rem"><strong>همراه داشته باشید:</strong> <?= e((string) $w['items_to_bring']) ?></div>
            <?php endif; ?>
            <?php if (!empty($w['description'])): ?>
              <div class="muted" style="font-size:.8rem;margin-top:.25rem"><?= e((string) $w['description']) ?></div>
            <?php endif; ?>
            <?php if ($archiveView): ?>
              <p class="muted" style="font-size:.8rem;margin-top:.5rem">زمان این کارگاه تمام شده و در آرشیو است.</p>
            <?php endif; ?>
          </div>
          <div class="workshop-card-actions">
            <?php if ($archiveView): ?>
              <span class="badge">آرشیو</span>
            <?php elseif (!$enrolled && workshop_can_enroll($w)): ?>
              <button type="button" class="btn btn-primary btn-sm enroll-btn" data-id="<?= e((string) $w['id']) ?>">ثبت‌نام</button>
            <?php elseif (!$enrolled): ?>
              <span class="badge">ثبت‌نام بسته</span>
            <?php else: ?>
              <span class="badge">ثبت‌نام شده</span>
            <?php endif; ?>
          </div>
        </div>
      </article>
    <?php endforeach; ?>
  </div>
<?php endif; ?>
