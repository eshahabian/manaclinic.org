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
$cashPath = $role === 'SECRETARY' ? '/secretary/petty-cash' : '/doctor/petty-cash';
$requestPath = (string) ($GLOBALS['path'] ?? '');
if ($role === 'SECRETARY' && !str_contains($requestPath, '/secretary/')) {
    redirect('/secretary/petty-cash');
}
if ($role === 'DOCTOR' && !str_contains($requestPath, '/doctor/')) {
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
[$todayY, $todayM, $todayD] = gregorian_to_jalali((int) date('Y'), (int) date('n'), (int) date('j'));
$jy = (int) ($_GET['jy'] ?? ($meta['year'] ?? $todayY));
$jm = (int) ($_GET['jm'] ?? ($meta['month'] ?? $todayM));
$range = petty_cash_month_range($jy, $jm);
$report = petty_cash_month_report($pdo, $range['start'], $range['end']);
$viewYears = [];
for ($y = (int) $todayY - 1; $y <= (int) $todayY + 1; $y++) {
    $viewYears[] = $y;
}

ob_start();
?>
<h1>تنخواه</h1>
<p class="muted">مبلغ ورودی و هزینه‌ها اینجا می‌ماند. آخر ماه جمع هر قلم، مثل دستمال کاغذی، در همین صفحه است.</p>

<div class="grid-2" style="margin-top:1rem">
  <div class="panel" style="margin:0">
    <div class="muted">ورودی <?= e($range['label']) ?></div>
    <strong style="font-size:1.3rem;line-height:1.6"><?= e(format_price($report['in'])) ?></strong>
  </div>
  <div class="panel" style="margin:0">
    <div class="muted">هزینه <?= e($range['label']) ?></div>
    <strong style="font-size:1.3rem;line-height:1.6"><?= e(format_price($report['out'])) ?></strong>
  </div>
</div>
<p class="muted" style="margin:.75rem 0 0">مانده این ماه: <?= e(format_price($report['in'] - $report['out'])) ?></p>

<form class="panel form-stack" method="get" action="<?= e(url($cashPath)) ?>" style="margin-top:1rem">
  <p style="margin:0;font-weight:600">ماه گزارش</p>
  <div>
    <label class="label" for="petty-view-year">سال</label>
    <select class="input" id="petty-view-year" name="jy">
      <?php foreach ($viewYears as $y): ?>
        <option value="<?= (int) $y ?>"<?= (int) $y === (int) $range['year'] ? ' selected' : '' ?>><?= e(to_fa_digits((string) $y)) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <div>
    <label class="label" for="petty-view-month">ماه</label>
    <select class="input" id="petty-view-month" name="jm">
      <?php for ($m = 1; $m <= 12; $m++): ?>
        <option value="<?= $m ?>"<?= $m === (int) $range['month'] ? ' selected' : '' ?>><?= e(petty_cash_month_name($m)) ?></option>
      <?php endfor; ?>
    </select>
  </div>
  <button class="btn btn-outline" type="submit" style="justify-self:start">نمایش این ماه</button>
</form>

<form class="panel form-stack" method="post" action="<?= e(url($cashPath)) ?>" style="margin-top:1rem">
  <?= csrf_field() ?>
  <input type="hidden" name="action" value="in">
  <input type="hidden" name="jy" value="<?= (int) $range['year'] ?>">
  <input type="hidden" name="jm" value="<?= (int) $range['month'] ?>">
  <p style="margin:0;font-weight:600">مبلغ ورودی</p>
  <div>
    <label class="label" for="petty-in-amount">مبلغ</label>
    <input class="input" id="petty-in-amount" name="amount" inputmode="numeric" required placeholder="تومان">
  </div>
  <?= petty_cash_date_fields((int) $todayY, (int) $todayM, (int) $todayD) ?>
  <div>
    <label class="label" for="petty-in-note">توضیح</label>
    <input class="input" id="petty-in-note" name="note" placeholder="اختیاری">
  </div>
  <button class="btn btn-primary" type="submit" style="justify-self:start">ثبت ورودی</button>
</form>

<form class="panel form-stack" method="post" action="<?= e(url($cashPath)) ?>" enctype="multipart/form-data" style="margin-top:1rem">
  <?= csrf_field() ?>
  <input type="hidden" name="action" value="out">
  <input type="hidden" name="jy" value="<?= (int) $range['year'] ?>">
  <input type="hidden" name="jm" value="<?= (int) $range['month'] ?>">
  <p style="margin:0;font-weight:600">هزینه</p>
  <div id="petty-lines" style="display:grid;gap:.65rem">
    <div class="petty-line" style="display:grid;grid-template-columns:minmax(0,1.6fr) minmax(7.5rem,.9fr);gap:.5rem;align-items:end">
      <div>
        <label class="label" for="petty-item">قلم هزینه</label>
        <input class="input" id="petty-item" name="item_name[]" required maxlength="120" placeholder="مثلاً دستمال کاغذی">
      </div>
      <div>
        <label class="label" for="petty-out-amount">مبلغ</label>
        <input class="input" id="petty-out-amount" name="amount[]" inputmode="numeric" required placeholder="تومان">
      </div>
    </div>
  </div>
  <button class="btn btn-outline" type="button" id="petty-add-line" aria-label="قلم بعدی" style="justify-self:start;min-width:2.75rem;font-size:1.35rem;line-height:1;padding:.45rem .8rem">+</button>
  <?= petty_cash_date_fields((int) $todayY, (int) $todayM, (int) $todayD) ?>
  <div>
    <label class="label" for="petty-receipt">رسید</label>
    <input id="petty-receipt" type="file" name="receipt" accept="image/jpeg,image/png,image/webp,application/pdf" hidden onchange="var n=document.getElementById('petty-receipt-name'); if(n) n.textContent=this.files&&this.files[0]?this.files[0].name:'رسیدی انتخاب نشده';">
    <label class="btn btn-outline" for="petty-receipt" style="justify-self:start;cursor:pointer">انتخاب فایل رسید</label>
    <span class="muted" id="petty-receipt-name">رسیدی انتخاب نشده</span>
  </div>
  <div>
    <label class="label" for="petty-out-note">توضیح</label>
    <input class="input" id="petty-out-note" name="note" placeholder="اختیاری">
  </div>
  <button class="btn btn-primary" type="submit" style="justify-self:start">ثبت هزینه</button>
</form>

<div class="panel" style="margin-top:1rem">
  <h2 style="margin:0 0 .75rem;font-size:1rem">جمع قلم‌ها در <?= e($range['label']) ?></h2>
  <?php if (!$report['items']): ?>
    <p class="muted" style="margin:0">هزینه‌ای در این ماه نیست.</p>
  <?php else: ?>
    <?php foreach ($report['items'] as $item): ?>
      <p style="margin:.35rem 0">
        <?= e((string) $item['item_name']) ?>
        <span class="muted">· <?= e(to_fa_digits((string) (int) $item['n'])) ?> بار · <?= e(format_price((int) $item['total'])) ?></span>
      </p>
    <?php endforeach; ?>
  <?php endif; ?>
</div>

<div class="panel" style="margin-top:1rem">
  <h2 style="margin:0 0 .75rem;font-size:1rem">ریز <?= e($range['label']) ?></h2>
  <?php if (!$report['entries']): ?>
    <p class="muted" style="margin:0">موردی ثبت نشده است.</p>
  <?php else: ?>
    <?php foreach ($report['entries'] as $entry): ?>
      <p style="margin:.55rem 0;line-height:1.7">
        <strong><?= ($entry['kind'] ?? '') === 'IN' ? 'ورودی' : e((string) $entry['item_name']) ?></strong>
        · <?= e(format_price((int) $entry['amount'])) ?>
        <span class="muted">
          · <?= e(to_jalali_label(substr((string) $entry['entry_date'], 0, 10))) ?>
          · <?= e((string) $entry['created_by_name']) ?>
          <?php if (!empty($entry['receipt_path'])): ?>
            · <a href="<?= e(url('/petty-cash/receipt?id=' . rawurlencode((string) $entry['id']))) ?>" target="_blank" rel="noopener">رسید</a>
          <?php endif; ?>
        </span>
      </p>
    <?php endforeach; ?>
  <?php endif; ?>
</div>
<script>
(function () {
  var box = document.getElementById('petty-lines');
  var add = document.getElementById('petty-add-line');
  if (!box || !add) return;
  add.addEventListener('click', function () {
    if (box.querySelectorAll('.petty-line').length >= 30) return;
    var row = document.createElement('div');
    row.className = 'petty-line';
    row.style.cssText = 'display:grid;grid-template-columns:minmax(0,1.6fr) minmax(7.5rem,.9fr) auto;gap:.5rem;align-items:center';
    row.innerHTML = '<input class="input" name="item_name[]" maxlength="120" placeholder="قلم بعدی" aria-label="قلم هزینه">'
      + '<input class="input" name="amount[]" inputmode="numeric" placeholder="تومان" aria-label="مبلغ">'
      + '<button type="button" class="btn btn-outline" aria-label="حذف این قلم" style="min-width:2.75rem;font-size:1.2rem;line-height:1;padding:.45rem .7rem">×</button>';
    var remove = row.querySelector('button');
    if (remove) remove.addEventListener('click', function () { row.remove(); });
    box.appendChild(row);
    var field = row.querySelector('input');
    if (field) field.focus();
  });
})();
</script>
<?php
$html = ob_get_clean();
if ($role === 'DOCTOR') {
    render_doctor_page('تنخواه', $html);
} else {
    render_secretary_page('تنخواه', $html);
}
