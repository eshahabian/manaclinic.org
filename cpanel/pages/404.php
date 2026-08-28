<?php
declare(strict_types=1);
$pageTitle = 'یافت نشد';
ob_start();
?>
<div class="container-page section" style="text-align:center;min-height:50vh;display:grid;place-content:center">
  <h1>صفحه پیدا نشد</h1>
  <p class="muted">آدرس واردشده معتبر نیست.</p>
  <p><a class="btn btn-primary" href="<?= e(url('/')) ?>">بازگشت به خانه</a></p>
</div>
<?php
$content = ob_get_clean();
require __DIR__ . '/../includes/layout.php';
