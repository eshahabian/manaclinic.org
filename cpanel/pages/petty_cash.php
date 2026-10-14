<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/petty_cash.php';
require_once __DIR__ . '/../includes/doctor_profile_fields.php';

$embedded = !empty($pettyCashEmbedded);
$user = $embedded && is_array($pettyCashUser ?? null) ? $pettyCashUser : require_login();
if (!petty_cash_user_allowed($user)) {
    flash_set('error', 'تنخواه فقط برای منشی، حسابدار، دکتر شیوا گرانمایه‌پور و دکتر عطیه گارسچی است.');
    redirect(((string) ($user['role'] ?? '')) === 'DOCTOR' ? '/doctor/appointments' : '/');
}

$role = (string) ($user['role'] ?? '');
$asSecretary = $role === 'SECRETARY';
$asAccountant = $role === 'ACCOUNTANT';
if ($embedded) {
    $cashPath = '/app/petty-cash';
} elseif ($asAccountant) {
    $cashPath = '/accountant/petty-cash';
} elseif ($asSecretary) {
    $cashPath = '/secretary/petty-cash';
} else {
    $cashPath = '/doctor/petty-cash';
}
if (!$embedded) {
    $requestPath = (string) ($GLOBALS['path'] ?? '');
    if ($asSecretary && !str_contains($requestPath, '/secretary/')) {
        redirect('/secretary/petty-cash');
    }
    if ($asAccountant && !str_contains($requestPath, '/accountant/')) {
        redirect('/accountant/petty-cash');
    }
    if ($role === 'DOCTOR' && !$asSecretary && !str_contains($requestPath, '/doctor/')) {
        redirect('/doctor/petty-cash');
    }
    if ($role === 'DOCTOR' && !$asSecretary) {
        require_once __DIR__ . '/../includes/doctor_panel.php';
        require_doctor_profile($pdo);
    } elseif ($asAccountant) {
        require_once __DIR__ . '/../includes/accountant.php';
        require_login(['ACCOUNTANT']);
    } else {
        require_once __DIR__ . '/../includes/secretary_panel.php';
        require_login(['SECRETARY']);
    }
}

ensure_petty_cash_schema($pdo);
$meta = jalali_current_month_meta();
[$todayY, $todayM, $todayD] = gregorian_to_jalali((int) date('Y'), (int) date('n'), (int) date('j'));
$jy = (int) ($_GET['jy'] ?? ($meta['year'] ?? $todayY));
$jm = (int) ($_GET['jm'] ?? ($meta['month'] ?? $todayM));
$range = petty_cash_month_range($jy, $jm);
$report = petty_cash_month_report($pdo, $range['start'], $range['end']);
$balances = petty_cash_account_balances($pdo, $range['start'], $range['end']);
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
<div class="grid-2" style="margin-top:.75rem">
  <div class="panel" style="margin:0">
    <div class="muted">مانده این ماه · بلو بانک</div>
    <strong style="font-size:1.3rem;line-height:1.6"><?= e(format_price((int) $balances['blue'])) ?></strong>
  </div>
  <div class="panel" style="margin:0">
    <div class="muted">مانده این ماه · پارسیان</div>
    <strong style="font-size:1.3rem;line-height:1.6"><?= e(format_price((int) $balances['parsian'])) ?></strong>
  </div>
</div>

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

<div class="panel" id="petty-entry-box" style="margin-top:1rem">
  <style>#petty-entry-box [hidden]{display:none !important}</style>
  <div class="binder-tabs" role="tablist" aria-label="ثبت تنخواه">
    <button type="button" class="binder-tab is-active" data-petty-tab="in" role="tab" aria-selected="true" aria-controls="petty-pane-in">مبلغ ورودی</button>
    <button type="button" class="binder-tab" data-petty-tab="out" role="tab" aria-selected="false" aria-controls="petty-pane-out">هزینه‌ها</button>
  </div>

  <form class="form-stack" id="petty-pane-in" data-petty-pane="in" method="post" action="<?= e(url($cashPath)) ?>" style="margin-top:1rem">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="in">
    <input type="hidden" name="jy" value="<?= (int) $range['year'] ?>">
    <input type="hidden" name="jm" value="<?= (int) $range['month'] ?>">
    <?= petty_cash_select_html('pay_account', 'petty-in-account', 'حساب ورودی', petty_cash_in_accounts()) ?>
    <div>
      <label class="label" for="petty-in-amount">مبلغ</label>
      <input class="input" id="petty-in-amount" name="amount" inputmode="numeric" required placeholder="تومان" data-money>
      <p class="muted" data-money-words style="margin:.35rem 0 0;font-size:.85rem"></p>
    </div>
    <?= petty_cash_date_fields((int) $todayY, (int) $todayM, (int) $todayD) ?>
    <div>
      <label class="label" for="petty-in-note">توضیح</label>
      <input class="input" id="petty-in-note" name="note" placeholder="اختیاری">
    </div>
    <button class="btn btn-primary" type="submit" style="justify-self:start">ثبت ورودی</button>
  </form>

  <form class="form-stack" id="petty-pane-out" data-petty-pane="out" method="post" action="<?= e(url($cashPath)) ?>" enctype="multipart/form-data" hidden style="margin-top:1rem">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="out">
    <input type="hidden" name="jy" value="<?= (int) $range['year'] ?>">
    <input type="hidden" name="jm" value="<?= (int) $range['month'] ?>">
    <div id="petty-lines" style="display:grid;gap:.65rem">
    <div class="petty-line" style="display:grid;grid-template-columns:minmax(0,1.6fr) minmax(7.5rem,.9fr);gap:.5rem;align-items:end">
      <div>
        <label class="label" for="petty-item">قلم هزینه</label>
        <input class="input" id="petty-item" name="item_name[]" required maxlength="120" placeholder="مثلاً دستمال کاغذی">
      </div>
      <div>
        <label class="label" for="petty-out-amount">مبلغ</label>
        <input class="input" id="petty-out-amount" name="amount[]" inputmode="numeric" required placeholder="تومان" data-money>
      </div>
    </div>
  </div>
  <button class="btn btn-outline" type="button" id="petty-add-line" aria-label="قلم بعدی" style="justify-self:start;min-width:2.75rem;font-size:1.35rem;line-height:1;padding:.45rem .8rem">+</button>
  <?= petty_cash_select_html('pay_account', 'petty-pay-account', 'از چه حسابی پرداخت شده', petty_cash_pay_accounts()) ?>
  <?= petty_cash_select_html('approver', 'petty-approver', 'تاییدکننده', petty_cash_approvers()) ?>
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
</div>

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
      <div style="display:flex;gap:.75rem;align-items:flex-start;justify-content:space-between;margin:.55rem 0">
        <p style="margin:0;line-height:1.7">
          <strong><?= ($entry['kind'] ?? '') === 'IN' ? 'ورودی' : e((string) $entry['item_name']) ?></strong>
          · <?= e(format_price((int) $entry['amount'])) ?>
          <span class="muted">
            · <?= e(to_jalali_label(substr((string) $entry['entry_date'], 0, 10))) ?>
            · <?= e((string) $entry['created_by_name']) ?>
            <?php
              $accountLabel = petty_cash_entry_account_label((string) ($entry['kind'] ?? ''), (string) ($entry['pay_account'] ?? ''));
              $approverLabel = petty_cash_option_label(petty_cash_approvers(), (string) ($entry['approver'] ?? ''));
            ?>
            <?php if ($accountLabel !== ''): ?>
              · حساب: <?= e($accountLabel) ?>
            <?php endif; ?>
            <?php if ($approverLabel !== ''): ?>
              · تایید: <?= e($approverLabel) ?>
            <?php endif; ?>
            <?php if (!empty($entry['receipt_path'])): ?>
              · <a href="<?= e(url('/petty-cash/receipt?id=' . rawurlencode((string) $entry['id']))) ?>" target="_blank" rel="noopener">رسید</a>
            <?php endif; ?>
          </span>
        </p>
        <form method="post" action="<?= e(url($cashPath)) ?>" onsubmit="return confirm('این مورد از تنخواه حذف شود؟')">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="delete">
          <input type="hidden" name="id" value="<?= e((string) $entry['id']) ?>">
          <input type="hidden" name="jy" value="<?= (int) $range['year'] ?>">
          <input type="hidden" name="jm" value="<?= (int) $range['month'] ?>">
          <button class="btn btn-outline" type="submit" style="padding:.35rem .7rem">حذف</button>
        </form>
      </div>
    <?php endforeach; ?>
  <?php endif; ?>
</div>
<script>
(function () {
  var ones = ["", "یک", "دو", "سه", "چهار", "پنج", "شش", "هفت", "هشت", "نه"];
  var teens = ["ده", "یازده", "دوازده", "سیزده", "چهارده", "پانزده", "شانزده", "هفده", "هجده", "نوزده"];
  var tens = ["", "", "بیست", "سی", "چهل", "پنجاه", "شصت", "هفتاد", "هشتاد", "نود"];
  var hundreds = ["", "صد", "دویست", "سیصد", "چهارصد", "پانصد", "ششصد", "هفتصد", "هشتصد", "نهصد"];
  var scales = ["", "هزار", "میلیون", "میلیارد"];
  function faDigits(s) {
    return String(s).replace(/[0-9]/g, function (d) { return "۰۱۲۳۴۵۶۷۸۹"[d]; });
  }
  function three(n) {
    var parts = [];
    var h = Math.floor(n / 100);
    var r = n % 100;
    if (h) parts.push(hundreds[h]);
    if (r >= 10 && r < 20) parts.push(teens[r - 10]);
    else {
      var t = Math.floor(r / 10);
      var o = r % 10;
      if (t) parts.push(tens[t]);
      if (o) parts.push(ones[o]);
    }
    return parts.join(" و ");
  }
  function words(n) {
    if (!n) return "";
    var parts = [];
    var i = 0;
    while (n > 0 && i < scales.length) {
      var chunk = n % 1000;
      if (chunk) parts.unshift(three(chunk) + (scales[i] ? " " + scales[i] : ""));
      n = Math.floor(n / 1000);
      i++;
    }
    return parts.join(" و ") + " تومان";
  }
  function bindMoney(input) {
    if (!input || input.getAttribute("data-money-bound") === "1") return;
    input.setAttribute("data-money-bound", "1");
    var out = input.parentNode ? input.parentNode.querySelector("[data-money-words]") : null;
    if (!out) {
      out = document.createElement("p");
      out.className = "muted";
      out.setAttribute("data-money-words", "");
      out.style.cssText = "margin:.35rem 0 0;font-size:.85rem";
      input.insertAdjacentElement("afterend", out);
    }
    function paint() {
      var digits = String(input.value || "").replace(/[^\d۰-۹٠-٩]/g, "").replace(/[۰-۹]/g, function (d) { return "۰۱۲۳۴۵۶۷۸۹".indexOf(d); }).replace(/[٠-٩]/g, function (d) { return "٠١٢٣٤٥٦٧٨٩".indexOf(d); });
      if (!digits) {
        out.textContent = "";
        return;
      }
      var grouped = digits.replace(/\B(?=(\d{3})+(?!\d))/g, "٬");
      input.value = faDigits(grouped);
      out.textContent = words(parseInt(digits, 10));
    }
    input.addEventListener("input", paint);
  }
  document.querySelectorAll("[data-money]").forEach(bindMoney);
  window.manaBindMoney = bindMoney;
  document.querySelectorAll('[data-petty-tab]').forEach(function (tab) {
    tab.addEventListener('click', function () {
      var id = tab.getAttribute('data-petty-tab');
      document.querySelectorAll('[data-petty-tab]').forEach(function (other) {
        var on = other === tab;
        other.classList.toggle('is-active', on);
        other.setAttribute('aria-selected', on ? 'true' : 'false');
      });
      document.querySelectorAll('[data-petty-pane]').forEach(function (pane) {
        pane.hidden = pane.getAttribute('data-petty-pane') !== id;
      });
    });
  });
  var box = document.getElementById('petty-lines');
  var add = document.getElementById('petty-add-line');
  if (!box || !add) return;
  add.addEventListener('click', function () {
    if (box.querySelectorAll('.petty-line').length >= 30) return;
    var row = document.createElement('div');
    row.className = 'petty-line';
    row.style.cssText = 'display:grid;grid-template-columns:minmax(0,1.6fr) minmax(7.5rem,.9fr) auto;gap:.5rem;align-items:center';
    row.innerHTML = '<input class="input" name="item_name[]" maxlength="120" placeholder="قلم بعدی" aria-label="قلم هزینه">'
      + '<span><input class="input" name="amount[]" inputmode="numeric" placeholder="تومان" aria-label="مبلغ" data-money></span>'
      + '<button type="button" class="btn btn-outline" aria-label="حذف این قلم" style="min-width:2.75rem;font-size:1.2rem;line-height:1;padding:.45rem .7rem">×</button>';
    var remove = row.querySelector('button');
    if (remove) remove.addEventListener('click', function () { row.remove(); });
    box.appendChild(row);
    if (window.manaBindMoney) row.querySelectorAll("[data-money]").forEach(window.manaBindMoney);
    var field = row.querySelector('input');
    if (field) field.focus();
  });
})();
</script>
<?= petty_cash_datepicker_assets() ?>
<?php
$html = ob_get_clean();
if ($embedded) {
    $pettyCashHtml = $html;
    return;
}
if ($role === 'DOCTOR' && !$asSecretary) {
    render_doctor_page('تنخواه', $html);
} elseif ($asAccountant) {
    render_accountant_page('تنخواه', $html);
} else {
    render_secretary_page('تنخواه', $html);
}
