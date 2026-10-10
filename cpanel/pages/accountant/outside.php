<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/accountant.php';
require_once __DIR__ . '/../../includes/petty_cash.php';

require_login(['ACCOUNTANT']);
ensure_outside_payments_schema($pdo);
$range = accountant_selected_range();
$rows = accountant_outside_rows($pdo, $range['start'], $range['end']);
$voided = accountant_outside_rows($pdo, $range['start'], $range['end'], true);
$totals = accountant_outside_totals($pdo, $range['start'], $range['end']);
[$todayY, $todayM, $todayD] = gregorian_to_jalali((int) date('Y'), (int) date('n'), (int) date('j'));

if (($_GET['export'] ?? '') === 'csv') {
    $csv = [['نوع', 'روش', 'بابت', 'طرف حساب', 'تاریخ', 'مبلغ', 'توضیح', 'ثبت‌کننده']];
    foreach ($rows as $row) {
        $csv[] = [
            outside_payment_direction_label((string) $row['direction']),
            outside_payment_method_label((string) $row['method']),
            (string) $row['title'],
            (string) ($row['party_name'] ?? ''),
            (string) $row['paid_on'],
            (string) (int) $row['amount'],
            (string) ($row['note'] ?? ''),
            (string) ($row['created_by_name'] ?? ''),
        ];
    }
    accountant_send_csv($csv, 'outside-' . $range['start'] . '.csv');
}

ob_start();
?>
<h1>خارج از روال</h1>
<p class="muted">پولی که با ثبت نوبت، کارگاه یا تنخواه وارد سیستم نمی‌شود: نقد، کارت‌خوان، انتقال، بیعانه، یا هزینه‌ای مثل اجاره و حقوق. حذف دائمی ندارد؛ اگر اشتباه بود باطل می‌شود و سابقه می‌ماند.</p>
<?= accountant_month_form('/accountant/outside', $range) ?>

<div class="grid-2" style="margin-top:1rem">
  <div class="panel" style="margin:0">
    <div class="muted">دریافت <?= e($range['label']) ?></div>
    <strong style="font-size:1.25rem;line-height:1.6"><?= e(format_price($totals['in'])) ?></strong>
  </div>
  <div class="panel" style="margin:0">
    <div class="muted">پرداخت <?= e($range['label']) ?></div>
    <strong style="font-size:1.25rem;line-height:1.6"><?= e(format_price($totals['out'])) ?></strong>
  </div>
</div>

<form class="panel form-stack" method="post" action="<?= e(url('/accountant/outside')) ?>" style="margin-top:1rem">
  <?= csrf_field() ?>
  <input type="hidden" name="action" value="add">
  <input type="hidden" name="jy" value="<?= (int) $range['year'] ?>">
  <input type="hidden" name="jm" value="<?= (int) $range['month'] ?>">
  <p style="margin:0;font-weight:600">ثبت مورد جدید</p>
  <div>
    <label class="label" for="out-direction">نوع</label>
    <select class="input" id="out-direction" name="direction" required>
      <option value="IN">دریافت</option>
      <option value="OUT">پرداخت</option>
    </select>
  </div>
  <div>
    <label class="label" for="out-method">روش</label>
    <select class="input" id="out-method" name="method" required>
      <?php foreach (['CASH', 'CARD', 'TRANSFER', 'DEPOSIT', 'OTHER'] as $method): ?>
        <option value="<?= e($method) ?>"><?= e(outside_payment_method_label($method)) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <div>
    <label class="label" for="out-title">بابت</label>
    <input class="input" id="out-title" name="title" required maxlength="190" placeholder="مثلاً اجاره، حقوق، بیعانه مراجع">
  </div>
  <div>
    <label class="label" for="out-party">طرف حساب</label>
    <input class="input" id="out-party" name="party_name" maxlength="190" placeholder="اختیاری">
  </div>
  <div>
    <label class="label" for="out-amount">مبلغ</label>
    <input class="input" id="out-amount" name="amount" inputmode="numeric" required placeholder="تومان">
  </div>
  <?= petty_cash_date_fields((int) $todayY, (int) $todayM, (int) $todayD) ?>
  <div>
    <label class="label" for="out-note">توضیح</label>
    <input class="input" id="out-note" name="note" maxlength="500" placeholder="اختیاری">
  </div>
  <button class="btn btn-primary" type="submit" style="justify-self:start">ثبت</button>
</form>

<div class="panel" style="margin-top:1rem;overflow:auto">
  <h2 style="margin:0 0 .75rem;font-size:1rem">موارد <?= e($range['label']) ?></h2>
  <?php if ($rows): ?>
    <p style="margin:0 0 .75rem"><a class="btn btn-outline btn-sm" href="<?= e(url('/accountant/outside?jy=' . (int) $range['year'] . '&jm=' . (int) $range['month'] . '&export=csv')) ?>">خروجی</a></p>
  <?php endif; ?>
  <?php if (!$rows): ?>
    <p class="muted" style="margin:0">در این ماه موردی خارج از روال ثبت نشده است.</p>
  <?php else: ?>
    <table class="table">
      <thead><tr><th>نوع</th><th>روش</th><th>بابت</th><th>تاریخ</th><th>مبلغ</th><th>ثبت</th><th></th></tr></thead>
      <tbody>
        <?php foreach ($rows as $row): ?>
          <tr>
            <td><?= e(outside_payment_direction_label((string) $row['direction'])) ?></td>
            <td><?= e(outside_payment_method_label((string) $row['method'])) ?></td>
            <td>
              <?= e((string) $row['title']) ?>
              <?php if (trim((string) ($row['party_name'] ?? '')) !== ''): ?>
                <div class="muted"><?= e((string) $row['party_name']) ?></div>
              <?php endif; ?>
              <?php if (trim((string) ($row['note'] ?? '')) !== ''): ?>
                <div class="muted"><?= e((string) $row['note']) ?></div>
              <?php endif; ?>
            </td>
            <td><?= e(to_jalali_label((string) $row['paid_on'])) ?></td>
            <td><?= e(format_price((int) $row['amount'])) ?></td>
            <td><?= e((string) ($row['created_by_name'] ?? '')) ?><div class="muted"><?= e(format_fa_datetime((string) $row['created_at'])) ?></div></td>
            <td>
              <form method="post" action="<?= e(url('/accountant/outside')) ?>" onsubmit="return confirm('این مورد باطل شود؟ از جمع مالی خارج می‌شود ولی سابقه می‌ماند.');">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="void">
                <input type="hidden" name="id" value="<?= e((string) $row['id']) ?>">
                <input type="hidden" name="jy" value="<?= (int) $range['year'] ?>">
                <input type="hidden" name="jm" value="<?= (int) $range['month'] ?>">
                <input class="input" name="void_reason" required maxlength="190" placeholder="دلیل ابطال" style="margin-bottom:.4rem">
                <button class="btn btn-outline btn-sm" type="submit">ابطال</button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
</div>

<?php if ($voided): ?>
  <div class="panel" style="margin-top:1rem;overflow:auto">
    <h2 style="margin:0 0 .75rem;font-size:1rem">باطل‌شده‌ها</h2>
    <table class="table">
      <thead><tr><th>بابت</th><th>مبلغ</th><th>دلیل</th><th>باطل‌کننده</th></tr></thead>
      <tbody>
        <?php foreach ($voided as $row): ?>
          <tr>
            <td><?= e((string) $row['title']) ?></td>
            <td><?= e(format_price((int) $row['amount'])) ?></td>
            <td><?= e((string) ($row['void_reason'] ?? '')) ?></td>
            <td><?= e((string) ($row['voided_by_name'] ?? '')) ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
<?php endif; ?>
<?php
render_accountant_page('خارج از روال', ob_get_clean());
