<?php
declare(strict_types=1);

require_once __DIR__ . '/clinic_excel_reports.php';
if (!clinic_excel_reports_allowed(current_user())) {
    return;
}
?>
<link rel="stylesheet" href="<?= e(url('/assets/css/clinic-excel-dialog.css')) ?>?v=1">
<dialog id="clinic-excel-dialog" class="clinic-excel-dialog" dir="rtl" aria-labelledby="clinic-excel-title">
  <div class="clinic-excel-dialog-panel">
    <header class="clinic-excel-dialog-header">
      <h2 id="clinic-excel-title">خروجی اکسل</h2>
      <form method="dialog">
        <button class="btn btn-outline" type="submit" autofocus>بستن</button>
      </form>
    </header>
    <iframe title="تنظیمات خروجی اکسل"></iframe>
  </div>
</dialog>
<script src="<?= e(url('/assets/js/clinic-excel-dialog.js')) ?>?v=1" defer></script>
