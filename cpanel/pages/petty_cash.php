<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/petty_cash.php';
require_once __DIR__ . '/../includes/doctor_profile_fields.php';

$user = require_login();
if (!petty_cash_user_allowed($user)) {
    flash_set('error', 'تنخواه فقط برای منشی، دکتر شیوا گرانمایه‌پور و دکتر عطیه گارسچی است.');
    redirect(((string) ($user['role'] ?? '')) === 'DOCTOR' ? '/doctor/appointments' : '/');
}

$role = (string) ($user['role'] ?? '');
$base = $role === 'SECRETARY' ? '/secretary/petty-cash' : '/doctor/petty-cash';
$path = (string) ($GLOBALS['path'] ?? '');
if ($role === 'SECRETARY' && !str_contains($path, '/secretary/')) {
    redirect('/secretary/petty-cash');
}
if ($role === 'DOCTOR' && !str_contains($path, '/doctor/')) {
    redirect('/doctor/petty-cash');
}
if ($role === 'DOCTOR') {
    require_once __DIR__ . '/../includes/doctor_panel.php';
    require_doctor_profile($pdo);
} else {
    require_once __DIR__ . '/../includes/secretary_panel.php';
    require_login(['SECRETARY']);
}

ensure_petty_cash_schema($pdo);
$meta = jalali_current_month_meta();
$jy = (int) ($_GET['jy'] ?? ($meta['year'] ?? 0));
$jm = (int) ($_GET['jm'] ?? ($meta['month'] ?? 1));
$range = petty_cash_month_range($jy, $jm);
$report = petty_cash_month_report($pdo, $range['start'], $range['end']);
$monthQuery = 'jy=' . $range['year'] . '&jm=' . $range['month'];

ob_start();
?>
<h1>تنخواه</h1>
<p class="muted">مبلغ ورودی و هزینه‌ها اینجا می‌ماند. آخر ماه جمع هر قلم، مثل دستمال کاغذی، همین‌جا دیده می‌شود.</p>

<div class="grid-2" style="margin-top:1rem">
  <div class="panel" style="margin:0">
    <div class="muted">ورودی <?= e($range['label']) ?></div>
    <strong style="font-size:1.3rem"><?= e(format_price($report['in'])) ?></strong>
  </div>
  <div class="panel" style="margin:0">
    <div class="muted">هزینه <?= e($range['label']) ?></div>
    <strong style="font-size:1.3rem"><?= e(format_price($report['out'])) ?></strong>
  </div>
</div>
<p class="muted" style="margin:.75rem 0 0">مانده این ماه: <?= e(format_price($report['in'] - $report['out'])) ?></p>

<form class="panel form-stack" method="get" action="<?= e(url($base)) ?>" style="margin-top:1rem">
  <div style="display:grid;grid-template-columns:1fr 1fr auto;gap:.5rem;align-items:end">
    <label>
      <span class="label">سال</span>
      <input class="input" name="jy" inputmode="numeric" value="<?= e((string) $range['year']) ?>" required>
    </label>
    <label>
      <span class="label">ماه</span>
      <input class="input" name="jm" inputmode="numeric" value="<?= e((string) $range['month']) ?>" required>
    </label>
    <button class="btn btn-outline" type="submit">این ماه</button>
  </div>
</form>

<div class="panel" style="margin-top:1rem">
  <h2 style="margin:0 0 .75rem;font-size:1rem">مبلغ ورودی</h2>
  <form class="form-stack" method="post" action="<?= e(url($base)) ?>">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="in">
    <input type="hidden" name="jy" value="<?= (int) $range['year'] ?>">
    <input type="hidden" name="jm" value="<?= (int) $range['month'] ?>">
    <div>
      <label class="label" for="petty-in-amount">مبلغ</label>
      <input class="input" id="petty-in-amount" name="amount" inputmode="numeric" required placeholder="تومان">
    </div>
    <div>
      <label class="label" for="petty-in-date">تاریخ</label>
      <input class="input" id="petty-in-date" name="entry_date" type="text" data-jdp data-jdp-only-date autocomplete="off" readonly required placeholder="تاریخ شمسی" style="cursor:pointer">
    </div>
    <div>
      <label class="label" for="petty-in-note">توضیح</label>
      <input class="input" id="petty-in-note" name="note" placeholder="اختیاری">
    </div>
    <button class="btn btn-primary" type="submit">ثبت ورودی</button>
  </form>
</div>

<div class="panel" style="margin-top:1rem">
  <h2 style="margin:0 0 .75rem;font-size:1rem">هزینه</h2>
  <form class="form-stack" method="post" action="<?= e(url($base)) ?>" enctype="multipart/form-data">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="out">
    <input type="hidden" name="jy" value="<?= (int) $range['year'] ?>">
    <input type="hidden" name="jm" value="<?= (int) $range['month'] ?>">
    <div>
      <label class="label" for="petty-item">قلم هزینه</label>
      <input class="input" id="petty-item" name="item_name" required placeholder="مثلاً دستمال کاغذی">
    </div>
    <div>
      <label class="label" for="petty-out-amount">مبلغ</label>
      <input class="input" id="petty-out-amount" name="amount" inputmode="numeric" required placeholder="تومان">
    </div>
    <div>
      <label class="label" for="petty-out-date">تاریخ</label>
      <input class="input" id="petty-out-date" name="entry_date" type="text" data-jdp data-jdp-only-date autocomplete="off" readonly required placeholder="تاریخ شمسی" style="cursor:pointer">
    </div>
    <div>
      <label class="label" for="petty-receipt">رسید</label>
      <input class="input" id="petty-receipt" type="file" name="receipt" accept="image/jpeg,image/png,image/webp,application/pdf">
    </div>
    <div>
      <label class="label" for="petty-out-note">توضیح</label>
      <input class="input" id="petty-out-note" name="note" placeholder="اختیاری">
    </div>
    <button class="btn btn-primary" type="submit">ثبت هزینه</button>
  </form>
</div>

<div class="panel" style="margin-top:1rem;overflow:auto">
  <h2 style="margin:0 0 .75rem;font-size:1rem">جمع قلم‌ها در <?= e($range['label']) ?></h2>
  <?php if (!$report['items']): ?>
    <p class="muted" style="margin:0">هزینه‌ای در این ماه نیست.</p>
  <?php else: ?>
    <table class="table">
      <thead><tr><th>قلم</th><th>تعداد</th><th>جمع</th></tr></thead>
      <tbody>
        <?php foreach ($report['items'] as $item): ?>
          <tr>
            <td><?= e((string) $item['item_name']) ?></td>
            <td><?= e(to_fa_digits((string) (int) $item['n'])) ?></td>
            <td><?= e(format_price((int) $item['total'])) ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
</div>

<div class="panel" style="margin-top:1rem;overflow:auto">
  <h2 style="margin:0 0 .75rem;font-size:1rem">ریز <?= e($range['label']) ?></h2>
  <?php if (!$report['entries']): ?>
    <p class="muted" style="margin:0">موردی ثبت نشده است.</p>
  <?php else: ?>
    <table class="table">
      <thead><tr><th>تاریخ</th><th>نوع</th><th>قلم</th><th>مبلغ</th><th>ثبت</th><th>رسید</th></tr></thead>
      <tbody>
        <?php foreach ($report['entries'] as $entry): ?>
          <tr>
            <td><?= e(to_jalali_label(substr((string) $entry['entry_date'], 0, 10))) ?></td>
            <td><?= ($entry['kind'] ?? '') === 'IN' ? 'ورودی' : 'هزینه' ?></td>
            <td><?= e((string) ($entry['item_name'] !== '' ? $entry['item_name'] : '—')) ?></td>
            <td><?= e(format_price((int) $entry['amount'])) ?></td>
            <td><?= e((string) $entry['created_by_name']) ?></td>
            <td>
              <?php if (!empty($entry['receipt_path'])): ?>
                <a href="<?= e(url('/petty-cash/receipt?id=' . rawurlencode((string) $entry['id']))) ?>" target="_blank" rel="noopener">رسید</a>
              <?php else: ?>
                —
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
</div>
<?php
$html = ob_get_clean();
$GLOBALS['pageHead'] = '<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@majidh1/jalalidatepicker/dist/jalalidatepicker.min.css">';
$GLOBALS['pageScripts'] = '<script src="https://cdn.jsdelivr.net/npm/@majidh1/jalalidatepicker/dist/jalalidatepicker.min.js"></script><script>
if (window.jalaliDatepicker) {
  jalaliDatepicker.startWatch({ selector: "[data-jdp]", time: false, hideAfterChange: true, showTodayBtn: true, autoReadOnlyInput: true, persianDigits: true, zIndex: 100000, container: "body" });
}
</script>';
if ($role === 'DOCTOR') {
    render_doctor_page('تنخواه', $html);
} else {
    render_secretary_page('تنخواه', $html);
}
