<div class="grid-2">
  <label>
    <span class="label">محل اقامت</span>
    <select class="input" name="fee_residence" required>
      <option value="">انتخاب محل اقامت</option>
      <?php foreach (patient_fee_options() as $feeOption => $feeLabel): ?>
        <option value="<?= e($feeOption) ?>"><?= e($feeLabel) ?></option>
      <?php endforeach; ?>
    </select>
  </label>
  <label>
    <span class="label">مبلغ ویزیت به واحد انتخاب‌شده</span>
    <input class="input" name="fee_amount" dir="ltr" inputmode="decimal" maxlength="24" required>
  </label>
</div>
