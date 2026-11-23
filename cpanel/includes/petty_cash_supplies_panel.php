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
$supplyForm = static function (?array $item = null) use ($cashPath, $supplyFields): void {
    $id = $item['id'] ?? cuid();
    $nameId = 'petty-supply-name-' . $id;
    $dateId = 'petty-supply-date-' . $id;
    $dateValue = $item === null ? '' : petty_cash_supply_date_input((string) $item['due_date']);
    ?>
    <form class="form-stack" method="post" action="<?= e(url($cashPath)) ?>" style="margin-top:.75rem">
      <?php $supplyFields($item === null ? 'supply_add' : 'supply_edit', $id); ?>
      <div class="grid-2">
        <div>
          <label class="label" for="<?= e($nameId) ?>">قلم مورد نیاز</label>
          <input class="input" id="<?= e($nameId) ?>" name="supply_name" value="<?= e($item['item_name'] ?? '') ?>" maxlength="120" placeholder="مثلاً دستمال کاغذی" required>
        </div>
        <div>
          <label class="label" for="<?= e($dateId) ?>">مهلت تهیه</label>
          <input class="input" id="<?= e($dateId) ?>" name="supply_due_date" value="<?= e($dateValue) ?>" placeholder="تاریخ شمسی" data-jdp data-jdp-only-date autocomplete="off" required>
        </div>
      </div>
      <label><input type="checkbox" name="supply_urgent" value="1"<?= !empty($item['is_urgent']) ? ' checked' : '' ?>> خرید ضروری</label>
      <div><button class="btn btn-primary" type="submit"><?= $item === null ? 'افزودن قلم' : 'ذخیره تغییرات' ?></button></div>
    </form>
    <?php
};
?>
<section class="panel" id="petty-supplies" style="margin-top:1rem">
  <h2 style="margin:0;font-size:1.1rem">لیست مایحتاج</h2>

  <?php if ($pettySupplies === []): ?>
    <p class="muted" style="margin:.8rem 0 0">قلمی در انتظار خرید نیست.</p>
  <?php else: ?>
    <ul class="stack" style="padding:0;list-style:none;margin:.9rem 0 0">
      <?php foreach ($pettySupplies as $item): ?>
        <li style="border-top:1px solid var(--line);padding-top:.75rem">
          <div style="display:flex;flex-wrap:wrap;align-items:center;gap:.55rem">
            <strong><?= e($item['item_name']) ?></strong>
            <?php if ($item['is_urgent']): ?><strong style="color:var(--danger)">ضروری</strong><?php endif; ?>
            <span class="muted">مهلت تهیه <?= e(to_jalali_label($item['due_date'])) ?></span>
            <?php if ($item['due_date'] < date('Y-m-d')): ?><span style="color:var(--danger)">مهلت گذشته</span><?php endif; ?>
            <form method="post" action="<?= e(url($cashPath)) ?>">
              <?php $supplyFields('supply_done', $item['id']); ?>
              <button class="btn btn-outline btn-sm" type="submit">تهیه شد</button>
            </form>
          </div>
          <details style="margin-top:.45rem">
            <summary>ویرایش قلم</summary>
            <?php $supplyForm($item); ?>
          </details>
        </li>
      <?php endforeach; ?>
    </ul>
  <?php endif; ?>
</section>
