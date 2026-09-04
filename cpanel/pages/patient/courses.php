<?php
declare(strict_types=1);

$user = require_login(['PATIENT']);
require_once __DIR__ . '/../../includes/patient_panel.php';

$tabParam = trim((string) ($_GET['type'] ?? $_GET['tab'] ?? ''));
if (!in_array($tabParam, ['in-person', 'online', 'offline', 'archive'], true)) {
    $tabParam = 'in-person';
}

$ws = patient_workshop_tab_data($pdo, (string) $user['id']);
$wallet = $ws['wallet'];
$grouped = $ws['grouped'];
$enrollmentsByTab = $ws['enrollmentsByTab'];
$enrollByWorkshop = $ws['enrollByWorkshop'];
$binderTabs = $ws['binderTabs'];

ob_start();
?>
<div class="stack">
  <h1>دوره‌های من</h1>
  <p class="muted">کارگاه‌های همه درمانگران را از تب رنگی ببینید و اگر خواستید ثبت‌نام کنید. کارگاه تمام‌شده به آرشیو می‌رود.</p>
  <p id="course-msg" class="course-flash" style="display:none" role="status"></p>

  <div data-patient-courses data-enroll-url="<?= e($ws['enrollUrl']) ?>" data-pay-url="<?= e($ws['payUrl']) ?>" data-cancel-url="<?= e($ws['cancelUrl']) ?>">
    <?php
      $workshopBinderNested = false;
      $workshopBinderInitial = $tabParam;
      require __DIR__ . '/../../includes/patient_workshop_binder.php';
    ?>
  </div>
</div>
<?php
$coursesContent = ob_get_clean();
$GLOBALS['pageScripts'] = '
<script src="' . e(url('/assets/js/binder-tabs.js')) . '?v=20260904u"></script>
<script src="' . e(url('/assets/js/patient-courses.js')) . '?v=20260904u"></script>';
render_patient_page('دوره‌های من', $coursesContent);
