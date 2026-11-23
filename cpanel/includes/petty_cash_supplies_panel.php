<?php
declare(strict_types=1);

$supplyFields = static function (string $action, string $id) use ($range): void {
    ?>
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="<?= e($action) ?>">
    <input type="hidden" name="supply_id" value="<?= e($id) ?>">
    <input type="hidden" name="jy" value="<?= (int) $range['year'] ?>">
    <input type="hidden" name="jm" value="<?= (int) $range['month'] ?>">
    <?php
};
$requestRow = static function (int $index, bool $removable): void {
    ?>
    <div class="petty-request-row" data-request-item>
      <label>
        <span class="label">قلم مورد نیاز</span>
        <input class="input" name="supply_names[<?= $index ?>]" data-request-name maxlength="120" placeholder="مثلاً دستمال کاغذی" required>
      </label>
      <label class="petty-request-urgent">
        <input type="checkbox" name="supply_urgencies[<?= $index ?>]" value="1" data-request-urgent> ضروری
      </label>
      <label>
        <span class="label">مهلت تهیه</span>
        <input class="input" name="supply_dates[<?= $index ?>]" data-request-date placeholder="مثلاً ۱۴۰۵/۰۹/۱۰" data-jdp data-jdp-only-date autocomplete="off" required>
      </label>
      <input type="hidden" name="supply_dates[<?= $index ?>]" value="urgent" data-request-urgent-date disabled>
      <?php if ($removable): ?>
        <button class="btn btn-outline btn-sm petty-request-remove" type="button" data-request-remove>حذف قلم</button>
      <?php endif; ?>
    </div>
    <?php
};
?>
<?php if ($asSecretary): ?>
<style>
  .petty-request-row { display:grid; grid-template-columns:minmax(0,1.6fr) auto minmax(0,1fr); gap:.5rem; align-items:end; }
  .petty-request-urgent { display:flex; gap:.35rem; align-items:center; align-self:end; min-height:2.75rem; white-space:nowrap; }
  .petty-request-row [data-request-date]:disabled { background:#eee; color:#666; opacity:1; cursor:not-allowed; }
  .petty-request-remove { grid-column:1/-1; justify-self:start; }
  @media (max-width:480px) {
    .petty-request-row { grid-template-columns:minmax(0,1fr) auto; }
    .petty-request-row label:nth-child(3) { grid-column:1/-1; }
  }
</style>
<section class="panel" style="margin-top:1rem">
  <h2 style="margin:0;font-size:1.1rem">لیست خرید</h2>
  <form class="form-stack" id="petty-request-form" method="post" action="<?= e(url($cashPath)) ?>" style="margin-top:.75rem">
    <?php $supplyFields('supply_request', cuid()); ?>
    <div id="petty-request-items" class="form-stack">
      <?php $requestRow(0, false); ?>
    </div>
    <button class="btn btn-outline" type="button" id="petty-request-add" aria-label="افزودن قلم" style="justify-self:start;min-width:2.75rem;font-size:1.35rem;line-height:1;padding:.45rem .8rem">+</button>
    <button class="btn btn-primary" type="submit" style="justify-self:start">ارسال لیست خرید</button>
    <template id="petty-request-item-template"><?php $requestRow(0, true); ?></template>
  </form>
</section>
<script>
(function () {
  const items = document.getElementById('petty-request-items');
  const add = document.getElementById('petty-request-add');
  const template = document.getElementById('petty-request-item-template');
  let nextIndex = 1;

  function syncDate(row) {
    const urgent = row.querySelector('[data-request-urgent]').checked;
    const date = row.querySelector('[data-request-date]');
    date.disabled = urgent;
    date.required = !urgent;
    row.querySelector('[data-request-urgent-date]').disabled = !urgent;
  }

  add.addEventListener('click', function () {
    if (items.children.length >= 30) return;
    const row = template.content.firstElementChild.cloneNode(true);
    row.querySelectorAll('input[name]').forEach(function (input) {
      input.name = input.name.replace('[0]', '[' + nextIndex + ']');
    });
    nextIndex++;
    items.append(row);
    add.disabled = items.children.length >= 30;
    syncDate(row);
    row.querySelector('[data-request-name]').focus();
  });
  items.addEventListener('change', function (event) {
    if (event.target.matches('[data-request-urgent]')) {
      syncDate(event.target.closest('[data-request-item]'));
    }
  });
  items.addEventListener('click', function (event) {
    if (!event.target.matches('[data-request-remove]')) return;
    event.target.closest('[data-request-item]').remove();
    add.disabled = false;
  });
  function syncDates() {
    items.querySelectorAll('[data-request-item]').forEach(syncDate);
  }
  items.closest('form').addEventListener('submit', syncDates);
  window.addEventListener('pageshow', syncDates);
  syncDates();
})();
</script>
<?php else: ?>
<section id="petty-pane-requests" data-petty-pane="requests"<?= $requestsActive ? '' : ' hidden' ?> style="margin-top:1rem">
  <?php if (!$pettySupplies): ?>
    <p class="muted">درخواست بازی برای خرید وجود ندارد.</p>
  <?php endif; ?>
  <?php foreach ($pettySupplies as $item): ?>
    <div style="border-top:1px solid var(--line);padding:.75rem 0">
      <strong><?= e($item['item_name']) ?></strong>
      <?php if ($item['is_urgent']): ?>
        <strong style="color:var(--danger)">ضروری</strong>
      <?php else: ?>
        <span class="muted">مهلت تهیه <?= e(to_jalali_label($item['due_date'])) ?></span>
      <?php endif; ?>
      <?php if (!empty($item['requester_name'])): ?>
        <span class="muted">درخواست‌کننده <?= e($item['requester_name']) ?></span>
      <?php endif; ?>
      <details style="margin-top:.45rem">
        <summary>ثبت خرید</summary>
        <form class="form-stack" method="post" action="<?= e(url($cashPath)) ?>" style="margin-top:.75rem">
          <?php $supplyFields('supply_purchase', $item['id']); ?>
          <div>
            <label class="label" for="petty-buy-<?= e($item['id']) ?>">مبلغ خرج‌شده</label>
            <input class="input" id="petty-buy-<?= e($item['id']) ?>" name="amount" inputmode="numeric" placeholder="تومان" data-money required>
          </div>
          <?= petty_cash_select_html('pay_account', 'petty-buy-account-' . $item['id'], 'حساب تنخواه', petty_cash_in_accounts()) ?>
          <button class="btn btn-primary" type="submit" style="justify-self:start">ثبت خرید و کسر از مانده</button>
        </form>
      </details>
      <form method="post" action="<?= e(url($cashPath)) ?>" style="margin-top:.5rem" onsubmit="return confirm('این درخواست خرید رد شود؟')">
        <?php $supplyFields('supply_reject', $item['id']); ?>
        <button class="btn btn-outline btn-sm" type="submit">رد درخواست</button>
      </form>
    </div>
  <?php endforeach; ?>
</section>
<?php endif; ?>
