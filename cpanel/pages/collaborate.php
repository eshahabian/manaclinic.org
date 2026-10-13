<?php
declare(strict_types=1);

$pageTitle = 'درخواست همکاری با مانا کلینیک';
$pageDescription = 'فرم درخواست همکاری درمانگران با مانا کلینیک در سعادت‌آباد. ثبت‌نام درمانگر از سایت بسته است و همکاری از همین فرم بررسی می‌شود.';
$pageCanonical = url('/collaborate');
$pageKeywords = 'همکاری با مانا کلینیک, درخواست همکاری درمانگر, روانشناس سعادت آباد';

require_once __DIR__ . '/../includes/collaboration.php';

ob_start();
?>
<div class="container-page section">
  <h1>درخواست همکاری</h1>
  <p class="muted" style="margin-top:.45rem;line-height:1.9;max-width:40rem">
    حساب درمانگر از سایت ساخته نمی‌شود. اگر مایل به همکاری با مانا کلینیک در سعادت‌آباد هستید، این فرم را بفرستید تا بررسی شود.
  </p>
  <div class="panel consult-form-card" style="margin-top:1rem;max-width:36rem">
    <?php echo collaboration_request_form_html('collaborate'); ?>
  </div>
</div>
<?php
$content = ob_get_clean();
require __DIR__ . '/../includes/layout.php';
