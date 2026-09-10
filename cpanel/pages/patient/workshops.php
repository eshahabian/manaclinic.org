<?php
declare(strict_types=1);

$user = require_login(['PATIENT']);
require_once __DIR__ . '/../../includes/patient_panel.php';
require_once __DIR__ . '/../../includes/workshop_overview.php';
require_once __DIR__ . '/../../includes/workshop_path.php';

$path = patient_request_path();
$view = 'catalog';
if (str_ends_with($path, '/workshops/requested')) {
    $view = 'requested';
} elseif (str_ends_with($path, '/workshops/mine')) {
    $view = 'mine';
}

$tabParam = trim((string) ($_GET['type'] ?? $_GET['tab'] ?? ''));
if (!in_array($tabParam, ['in-person', 'online', 'offline', 'archive'], true)) {
    $tabParam = 'in-person';
}

$ws = patient_workshop_tab_data($pdo, (string) $user['id']);
$wallet = $ws['wallet'];
$grouped = $ws['grouped'];
$enrollmentsByTab = $ws['enrollmentsByTab'];
$enrollByWorkshop = $ws['enrollByWorkshop'];
$sessionsByWorkshop = $ws['sessionsByWorkshop'] ?? [];
$binderTabs = $ws['binderTabs'];

$titles = [
    'catalog' => 'کارگاه‌ها',
    'requested' => 'دوره‌های درخواست داده‌شده',
    'mine' => 'دوره‌های من',
];
$leads = [
    'catalog' => 'همه کارگاه‌های منتشرشده را ببینید و اگر خواستید ثبت‌نام کنید. بعد از ثبت‌نام، درخواست به «دوره‌های درخواست داده‌شده» می‌رود و پس از تأیید در «دوره‌های من» دیده می‌شود.',
    'requested' => 'کارگاه‌هایی که ثبت‌نام کرده‌اید و هنوز تأیید نشده‌اند اینجا هستند. بعد از تأیید منشی، درمانگر یا مدیر به «دوره‌های من» می‌روند.',
    'mine' => 'کارگاه‌هایی که عضویت‌شان تأیید شده اینجا هستند. مسیر هفته‌به‌هفته و یادداشت جلسه از همین بخش باز می‌شود. دوره آفلاین با تالار گفتگوی همگانی داخل همان پنل پخش می‌شود.',
];

ob_start();
?>
<div class="stack">
  <h1><?= e($titles[$view]) ?></h1>
  <p class="muted"><?= e($leads[$view]) ?></p>
  <p id="course-msg" class="course-flash" style="display:none" role="status"></p>

  <div data-patient-courses
       data-enroll-url="<?= e($ws['enrollUrl']) ?>"
       data-pay-url="<?= e($ws['payUrl']) ?>"
       data-cancel-url="<?= e($ws['cancelUrl']) ?>"
       data-after-enroll-url="<?= e(url('/dashboard/workshops/requested')) ?>">
    <?php
      $workshopBinderNested = false;
      $workshopBinderInitial = $tabParam;
      $workshopBinderMode = $view === 'catalog' ? 'catalog' : $view;
      require __DIR__ . '/../../includes/patient_workshop_binder.php';
    ?>
  </div>
</div>
<?php
$coursesContent = ob_get_clean();
$GLOBALS['pageScripts'] = '
<script src="' . e(url('/assets/js/binder-tabs.js')) . '?v=20260906v"></script>
<script src="' . e(url('/assets/js/ymd-cascade.js')) . '?v=20260910q"></script>
<script src="' . e(url('/assets/js/patient-courses.js')) . '?v=20260906w"></script>';
render_patient_page($titles[$view], $coursesContent);
