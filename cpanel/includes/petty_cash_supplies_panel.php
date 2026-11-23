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
?>
<?php if ($asSecretary): ?>
<section class="panel" style="margin-top:1rem">
  <h2 style="margin:0;font-size:1.1rem">لیست خرید</h2>
  <form class="form-stack" id="petty-request-form" method="post" action="<?= e(url($cashPath)) ?>" style="margin-top:.75rem">
    <?php $supplyFields('supply_request', cuid()); ?>
    <div id="petty-request-items" class="form-stack">
      <div class="stack" data-request-item>
        <input class="input" name="supply_names[0]" aria-label="نام قلم" maxlength="120" placeholder="نام قلم مورد نیاز" required>
        <label><input type="checkbox" name="supply_urgencies[0]" value="1" data-request-urgent> ضروری</label>
      </div>
    </div>
    <div id="petty-request-deadline">
      <label class="label" for="petty-request-date">مهلت تهیه اقلام عادی (پایان روز)</label>
      <input class="input" id="petty-request-date" name="supply_due_date" placeholder="تاریخ شمسی" data-jdp data-jdp-only-date autocomplete="off" required>
    </div>
    <button class="btn btn-outline" type="button" id="petty-request-add" aria-label="افزودن قلم" style="justify-self:start">+</button>
    <button class="btn btn-primary" type="submit" style="justify-self:start">ارسال لیست خرید</button>
    <template id="petty-request-item-template">
      <div class="stack" data-request-item>
        <input class="input" aria-label="نام قلم" maxlength="120" placeholder="نام قلم مورد نیاز" required>
        <label><input type="checkbox" value="1" data-request-urgent> ضروری</label>
        <button class="btn btn-outline btn-sm" type="button" data-request-remove>حذف قلم</button>
      </div>
    </template>
  </form>
</section>
<script>
(function () {
  const form = document.getElementById('petty-request-form');
  const items = document.getElementById('petty-request-items');
  const deadline = document.getElementById('petty-request-deadline');
  const date = document.getElementById('petty-request-date');
  const add = document.getElementById('petty-request-add');
  const template = document.getElementById('petty-request-item-template');
  let nextIndex = 1;

  function refresh() {
    const normal = items.querySelector('[data-request-urgent]:not(:checked)') !== null;
    deadline.hidden = date.disabled = !normal;
    date.required = normal;
    add.disabled = items.children.length >= 30;
  }

  add.addEventListener('click', function () {
    const row = template.content.firstElementChild.cloneNode(true);
    const name = row.querySelector('input[type="text"], input:not([type])');
    name.name = 'supply_names[' + nextIndex + ']';
    row.querySelector('[data-request-urgent]').name = 'supply_urgencies[' + nextIndex + ']';
    nextIndex++;
    items.append(row);
    refresh();
    name.focus();
  });
  items.addEventListener('change', refresh);
  items.addEventListener('click', function (event) {
    if (!event.target.matches('[data-request-remove]')) return;
    event.target.closest('[data-request-item]').remove();
    refresh();
  });
  form.addEventListener('submit', refresh);
  window.addEventListener('pageshow', refresh);
  refresh();
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
    </div>
  <?php endforeach; ?>
</section>
<?php endif; ?>
