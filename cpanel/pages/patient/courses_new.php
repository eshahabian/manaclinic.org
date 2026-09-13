<?php
declare(strict_types=1);

$user = require_login(['PATIENT']);
require_once __DIR__ . '/../../includes/patient_panel.php';
require_once __DIR__ . '/../../includes/workshop_overview.php';
require_once __DIR__ . '/../../includes/workshop_path.php';

$patientId = (string) $user['id'];
$ws = patient_workshop_tab_data($pdo, $patientId);
$wallet = $ws['wallet'];
$enrollByWorkshop = $ws['enrollByWorkshop'];
$sessionsByWorkshop = $ws['sessionsByWorkshop'] ?? [];
$binderTabs = $ws['binderTabs'];

$enrollable = [];
foreach (['in-person', 'online', 'offline'] as $tab) {
    $enrollable[$tab] = [];
    foreach ($ws['grouped'][$tab] ?? [] as $workshop) {
        $wid = (string) ($workshop['id'] ?? '');
        if ($wid === '') {
            continue;
        }
        $enr = $enrollByWorkshop[$wid] ?? null;
        $already = $enr && in_array((string) ($enr['status'] ?? ''), ['PENDING_PAYMENT', 'CONFIRMED', 'COMPLETED'], true);
        if ($already || !workshop_can_enroll($workshop)) {
            continue;
        }
        $enrollable[$tab][] = $workshop;
    }
}
$enrollable['archive'] = [];

$availableCount = 0;
foreach ($enrollable as $rows) {
    $availableCount += count($rows);
}

$tabParam = trim((string) ($_GET['type'] ?? $_GET['tab'] ?? ''));
if (!in_array($tabParam, ['in-person', 'online', 'offline', 'archive'], true)) {
    $tabParam = 'in-person';
}

ob_start();
?>
<div class="stack">
  <h1>دوره‌های جدید</h1>
  <p class="muted">فقط کارگاه‌هایی که هنوز ثبت‌نام نکرده‌اید و ثبت‌نام‌شان باز است. بعد از ثبت‌نام، درخواست در «دوره‌های درخواست داده‌شده» دیده می‌شود.</p>
  <?php if ($availableCount > 0): ?>
    <p class="muted" style="margin:0"><?= to_fa_digits((string) $availableCount) ?> دوره برای ثبت‌نام آماده است.</p>
  <?php endif; ?>
  <p id="course-msg" class="course-flash" style="display:none" role="status"></p>

  <div data-patient-courses
       data-enroll-url="<?= e($ws['enrollUrl']) ?>"
       data-pay-url="<?= e($ws['payUrl']) ?>"
       data-cancel-url="<?= e($ws['cancelUrl']) ?>"
       data-after-enroll-url="<?= e(url('/dashboard/workshops/requested')) ?>">
    <?php
      $grouped = $enrollable;
      $enrollmentsByTab = ['in-person' => [], 'online' => [], 'offline' => [], 'archive' => []];
      $workshopBinderNested = false;
      $workshopBinderInitial = $tabParam;
      $workshopBinderMode = 'catalog';
      require __DIR__ . '/../../includes/patient_workshop_binder.php';
    ?>
  </div>
</div>
<?php
$coursesContent = ob_get_clean();
$GLOBALS['pageScripts'] = '
<script src="' . e(url('/assets/js/binder-tabs.js')) . '?v=20260906v"></script>
<script src="' . e(url('/assets/js/ymd-cascade.js')) . '?v=20260910s"></script>
<script src="' . e(url('/assets/js/patient-courses.js')) . '?v=20260906w"></script>';
render_patient_page('دوره‌های جدید', $coursesContent);
