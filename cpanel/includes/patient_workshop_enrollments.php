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
      <?php
        $archivedEnroll = workshop_is_archived([
            'status' => (string) ($e['workshop_status'] ?? ''),
            'type' => (string) ($e['type'] ?? ''),
            'ends_at' => (string) ($e['ends_at'] ?? ''),
        ]);
        $canManage = in_array($e['status'], ['PENDING_PAYMENT', 'CONFIRMED'], true) && !$archivedEnroll;
        $confirmed = $e['status'] === 'CONFIRMED' || $e['status'] === 'COMPLETED';
        $enrollmentMedia = workshop_media_counts_from_row($e);
        $hasMedia = $enrollmentMedia['total'] > 0;
      ?>
      <div class="enrollment-card">
        <div class="enrollment-card-main">
          <strong><?= e($e['title']) ?></strong>
          <?php $enrollmentStats = workshop_media_counts_html($enrollmentMedia); if ($enrollmentStats): ?>
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
          <?php if ($e['type'] === 'IN_PERSON' && !empty($e['location'])): ?>
            <div class="enrollment-address" style="margin-top:.5rem"><span class="enrollment-label">محل:</span> <?= e((string) $e['location']) ?></div>
          <?php endif; ?>
          <?php if ($e['status'] === 'PENDING_PAYMENT' && $e['type'] === 'OFFLINE'): ?>
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
          <?php if ($canManage): ?>
            <button type="button" class="btn btn-outline btn-sm cancel-btn" data-id="<?= e((string) $e['id']) ?>">لغو ثبت‌نام</button>
          <?php endif; ?>
          <?php if ($confirmed && $e['type'] === 'ONLINE' && !empty($e['meeting_url'])): ?>
            <a class="btn btn-outline btn-sm" href="<?= e((string) $e['meeting_url']) ?>" target="_blank" rel="noopener">ورود به جلسه</a>
          <?php endif; ?>
          <?php if ($confirmed && !empty($e['group_url'])): ?>
            <a class="btn btn-outline btn-sm" href="<?= e((string) $e['group_url']) ?>" target="_blank" rel="noopener"><?= e(workshop_group_link_label((string) $e['group_url'])) ?></a>
          <?php endif; ?>
          <?php if ($confirmed && $e['type'] === 'IN_PERSON'): ?>
            <?php $navUri = workshop_navigation_uri_from_row($e); ?>
            <?php if ($navUri): ?>
              <a href="<?= e($navUri) ?>" class="btn btn-outline btn-sm enrollment-nav-btn">مسیر‌یابی</a>
            <?php endif; ?>
          <?php endif; ?>
          <?php if ($confirmed && ($hasMedia || $e['type'] === 'OFFLINE')): ?>
            <a class="btn btn-outline btn-sm" href="<?= e(workshop_media_course_url((string) $e['id'])) ?>">
              <?= $e['type'] === 'OFFLINE' ? 'مشاهده محتوای آفلاین' : 'مشاهده ضبط جلسات' ?>
            </a>
          <?php endif; ?>
          <?php if ($canManage && $e['status'] === 'CONFIRMED' && $e['type'] !== 'OFFLINE' && !workshop_refund_allowed($e['starts_at'])): ?>
            <span class="muted enrollment-refund-note">کمتر از ۲۴ ساعت مانده — بازگشت وجه نیست.</span>
          <?php endif; ?>
        </div>
      </div>
    <?php endforeach; ?>
  </div>
<?php endif; ?>
