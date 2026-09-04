<?php
declare(strict_types=1);

/** @var array $enrollmentList */
/** @var array $wallet */

$enrollmentList = $enrollmentList ?? [];
$wallet = $wallet ?? ['balance' => 0];
$emptyEnrollments = $emptyEnrollments ?? 'هنوز در کارگاهی از این دسته ثبت‌نام نکرده‌اید.';
?>
<?php if (!$enrollmentList): ?>
  <p class="muted binder-empty"><?= e($emptyEnrollments) ?></p>
<?php else: ?>
  <div class="stack">
    <?php foreach ($enrollmentList as $e): ?>
      <div class="enrollment-card">
        <div class="enrollment-card-main">
          <strong><?= e($e['title']) ?></strong>
          <?php $enrollmentStats = workshop_media_counts_html(workshop_media_counts_from_row($e)); if ($enrollmentStats): ?>
            <div style="margin-top:.35rem"><?= $enrollmentStats ?></div>
          <?php endif; ?>
          <div class="muted" style="font-size:.85rem;margin-top:.25rem"><?= e((string) ($e['doctor_name'] ?? '')) ?></div>
          <?php if ($e['type'] !== 'OFFLINE'): ?>
            <div style="font-size:.85rem;margin-top:.35rem"><?= e(format_workshop_datetime_fa((string) $e['starts_at'])) ?></div>
          <?php endif; ?>
          <span class="badge" style="margin-top:.5rem;display:inline-block"><?= e(enrollment_status_label((string) $e['status'])) ?></span>
          <?php if ($e['amount']): ?>
            <div class="muted" style="font-size:.85rem;margin-top:.35rem"><?= e(format_price((int) $e['amount'])) ?></div>
          <?php endif; ?>
          <?php if ($e['status'] === 'CONFIRMED' || $e['status'] === 'COMPLETED'): ?>
            <?php if ($e['type'] === 'ONLINE' && !empty($e['meeting_url'])): ?>
              <div style="font-size:.85rem;margin-top:.35rem"><a href="<?= e((string) $e['meeting_url']) ?>" target="_blank" rel="noopener">ورود به جلسه آنلاین</a></div>
            <?php endif; ?>
            <?php if (!empty($e['group_url'])): ?>
              <div style="font-size:.85rem;margin-top:.35rem">
                <a class="btn btn-outline btn-sm" href="<?= e((string) $e['group_url']) ?>" target="_blank" rel="noopener"><?= e(workshop_group_link_label((string) $e['group_url'])) ?></a>
              </div>
            <?php endif; ?>
            <?php if ($e['type'] === 'IN_PERSON' && ($e['location'] || workshop_navigation_uri_from_row($e))): ?>
              <div class="enrollment-location">
                <?php if (!empty($e['location'])): ?>
                  <div class="enrollment-address"><span class="enrollment-label">محل:</span> <?= e((string) $e['location']) ?></div>
                <?php endif; ?>
                <?php $navUri = workshop_navigation_uri_from_row($e); ?>
                <?php if ($navUri): ?>
                  <a href="<?= e($navUri) ?>" class="btn btn-outline btn-sm enrollment-nav-btn">مسیر‌یابی</a>
                <?php endif; ?>
              </div>
            <?php endif; ?>
            <?php
              $enrollmentMedia = workshop_media_counts_from_row($e);
              $hasMedia = $enrollmentMedia['total'] > 0;
            ?>
            <?php if ($hasMedia || $e['type'] === 'OFFLINE'): ?>
              <div style="font-size:.85rem;margin-top:.35rem">
                <a class="btn btn-outline btn-sm" href="<?= e(workshop_media_course_url((string) $e['id'])) ?>">
                  <?= $e['type'] === 'OFFLINE' ? 'مشاهده محتوای آفلاین' : 'مشاهده ضبط جلسات' ?>
                </a>
              </div>
            <?php endif; ?>
          <?php elseif ($e['status'] === 'PENDING_PAYMENT' && $e['type'] === 'OFFLINE'): ?>
            <p class="muted" style="font-size:.8rem;margin-top:.35rem">پس از پرداخت، محتوای آفلاین فعال می‌شود.</p>
          <?php endif; ?>
        </div>
        <div class="enrollment-card-actions">
          <?php if ($e['status'] === 'PENDING_PAYMENT'): ?>
            <label class="enrollment-wallet-label">
              <input type="checkbox" class="use-wallet" data-id="<?= e((string) $e['id']) ?>" <?= (int) $wallet['balance'] > 0 ? '' : 'disabled' ?>>
              استفاده از کیف پول (<?= e(format_price((int) $wallet['balance'])) ?>)
            </label>
            <button type="button" class="btn btn-primary btn-sm pay-btn" data-id="<?= e((string) $e['id']) ?>">پرداخت آنلاین</button>
          <?php endif; ?>
          <?php if (in_array($e['status'], ['PENDING_PAYMENT', 'CONFIRMED'], true) && !workshop_is_archived([
              'status' => (string) ($e['workshop_status'] ?? ''),
              'type' => (string) ($e['type'] ?? ''),
              'ends_at' => (string) ($e['ends_at'] ?? ''),
          ])): ?>
            <button type="button" class="btn btn-outline btn-sm cancel-btn" data-id="<?= e((string) $e['id']) ?>">لغو ثبت‌نام</button>
            <?php if ($e['status'] === 'CONFIRMED' && $e['type'] !== 'OFFLINE' && !workshop_refund_allowed($e['starts_at'])): ?>
              <p class="muted enrollment-refund-note">کمتر از ۲۴ ساعت مانده — بازگشت وجه نیست.</p>
            <?php endif; ?>
          <?php endif; ?>
        </div>
      </div>
    <?php endforeach; ?>
  </div>
<?php endif; ?>
