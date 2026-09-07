<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/doctor_panel.php';
require_once __DIR__ . '/../../includes/workshops.php';
require_once __DIR__ . '/../../includes/workshop_path.php';

$ctxUser = require_doctor_profile($pdo);
ensure_workshop_schema($pdo);
$enrollmentId = trim((string) ($_GET['enrollment'] ?? ''));
$pathCtx = workshop_path_load_doctor($pdo, (string) ($ctxUser['profile']['id'] ?? ''), $enrollmentId);
if (!$pathCtx) {
    flash_set('error', 'مسیر این ثبت‌نام در دسترس نیست.');
    redirect('/doctor/workshops');
}

$enrollment = is_array($pathCtx['enrollment'] ?? null) ? $pathCtx['enrollment'] : [];
$patientName = trim((string) ($enrollment['patient_name'] ?? 'مراجع'));
$title = trim((string) ($enrollment['title'] ?? 'کارگاه'));
$pathCtx['post_url'] = url('/doctor/workshops/path-note');

$GLOBALS['pageRobots'] = 'noindex,nofollow';

ob_start();
?>
<div class="stack workshop-path-page">
  <a href="<?= e(url('/doctor/workshops')) ?>" style="font-size:.9rem;color:var(--primary)">← بازگشت به کارگاه‌ها</a>
  <h1>مسیر <?= e($patientName) ?></h1>
  <p class="muted" style="margin-top:.25rem"><?= e($title) ?></p>
  <p class="muted" style="margin-top:.35rem;line-height:1.7">یادداشت شما فقط همین نفر را می‌بیند. یادداشت مراجع از همان جلسه اینجاست.</p>
  <?= workshop_path_render($pathCtx, 'doctor') ?>
</div>
<?php
render_doctor_page('مسیر ' . $patientName, ob_get_clean());
