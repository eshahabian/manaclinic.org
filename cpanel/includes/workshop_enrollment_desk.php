<?php
declare(strict_types=1);

/** @var array $enrollmentList */
$enrollmentList = $enrollmentList ?? [];
?>
<?php if (!$enrollmentList): ?>
  <p class="muted" style="margin:.75rem 0 0;font-size:.85rem">هنوز کسی در این کارگاه ثبت‌نام نکرده است.</p>
<?php else: ?>
  <div class="workshop-enroll-desk">
    <h3 class="workshop-enroll-desk-title">ثبت‌نام‌شده‌ها</h3>
    <?php foreach ($enrollmentList as $enr): ?>
      <?php
        $paid = (string) ($enr['pay_status'] ?? '') === 'PAID';
        $phone = trim((string) ($enr['patient_phone'] ?? ''));
        $paymentId = (string) ($enr['payment_id'] ?? '');
      ?>
      <div class="workshop-enroll-desk-row">
        <div>
          <strong><?= e((string) $enr['patient_name']) ?></strong>
          <?php if ($phone !== ''): ?>
            <div class="muted" style="font-size:.85rem;margin-top:.2rem"><a href="tel:<?= e($phone) ?>" dir="ltr" style="color:inherit"><?= e($phone) ?></a></div>
          <?php endif; ?>
          <div class="muted" style="font-size:.8rem;margin-top:.25rem"><?= e(format_fa_datetime((string) $enr['enrolled_at'])) ?></div>
          <?php if (!empty($enr['actor_name']) || !empty($enr['actor_username'])): ?>
            <?= staff_sign_html(['name' => $enr['actor_name'] ?? '', 'username' => $enr['actor_username'] ?? ''], 'ثبت توسط') ?>
          <?php else: ?>
            <span class="staff-sign">ثبت‌نام آنلاین</span>
          <?php endif; ?>
          <?php if ($paid && (!empty($enr['recorder_name']) || !empty($enr['recorder_username']))): ?>
            <?= staff_sign_html(['name' => $enr['recorder_name'] ?? '', 'username' => $enr['recorder_username'] ?? ''], 'پرداخت ثبت‌شده توسط') ?>
          <?php endif; ?>
          <?php if ($paid && (string) ($enr['ref_id'] ?? '') !== '' && (string) ($enr['ref_id'] ?? '') !== 'SECRETARY'): ?>
            <div class="muted" style="font-size:.75rem">پرداخت آنلاین · رسید زرین‌پال</div>
          <?php endif; ?>
        </div>
        <div class="workshop-enroll-desk-actions">
          <span class="badge"><?= e(enrollment_status_label((string) $enr['status'])) ?></span>
          <?php if (isset($enr['amount'])): ?>
            <span class="muted" style="font-size:.8rem"><?= e(format_price((int) $enr['amount'])) ?></span>
          <?php endif; ?>
          <?php if ($paid && $paymentId !== '' && !empty($enr['receipt_path'])): ?>
            <a class="btn btn-outline btn-sm" href="<?= e(url('/staff/receipt?id=' . $paymentId . '&kind=workshop')) ?>" target="_blank" rel="noopener">مشاهده فیش</a>
          <?php endif; ?>
          <?php if ($paid && $paymentId !== '' && empty($enr['receipt_path'])): ?>
            <form class="staff-receipt-form" method="post" action="<?= e(url('/secretary/workshops')) ?>" enctype="multipart/form-data">
              <input type="hidden" name="action" value="mark_paid">
              <input type="hidden" name="enrollment_id" value="<?= e((string) $enr['id']) ?>">
              <label class="btn btn-outline btn-sm staff-receipt-pick">
                بارگذاری فیش
                <input type="file" name="receipt" accept="image/jpeg,image/png,image/webp,application/pdf" required onchange="this.form.submit()">
              </label>
            </form>
          <?php endif; ?>
          <?php if (!$paid): ?>
            <form class="staff-receipt-form workshop-pay-form" method="post" action="<?= e(url('/secretary/workshops')) ?>" enctype="multipart/form-data">
              <input type="hidden" name="action" value="mark_paid">
              <input type="hidden" name="enrollment_id" value="<?= e((string) $enr['id']) ?>">
              <label class="btn btn-primary btn-sm staff-receipt-pick">
                پرداخت شده — بارگذاری فیش
                <input type="file" name="receipt" accept="image/jpeg,image/png,image/webp,application/pdf" required onchange="this.form.submit()">
              </label>
            </form>
          <?php endif; ?>
        </div>
      </div>
    <?php endforeach; ?>
  </div>
<?php endif; ?>
