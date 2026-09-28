<?php
declare(strict_types=1);

$user = require_login(['PATIENT']);
require_once __DIR__ . '/../../includes/patient_panel.php';
require_once __DIR__ . '/../../includes/workshop_overview.php';
require_once __DIR__ . '/../../includes/workshop_path.php';

$path = patient_request_path();
$view = 'ongoing';
if (str_ends_with($path, '/workshops/requested')) {
    $view = 'requested';
} elseif (str_ends_with($path, '/workshops/mine')) {
    $view = 'mine';
} elseif (str_ends_with($path, '/workshops/new')) {
    $view = 'new';
} elseif (str_ends_with($path, '/workshops/ongoing')) {
    $view = 'ongoing';
} else {
    $query = $_GET;
    unset($query['view']);
    $suffix = $query ? ('?' . http_build_query($query)) : '';
    redirect('/dashboard/workshops/ongoing' . $suffix);
}

$tabParam = trim((string) ($_GET['type'] ?? $_GET['tab'] ?? ''));
if (!in_array($tabParam, ['in-person', 'online', 'offline', 'archive'], true) || ($view === 'new' && $tabParam === 'archive')) {
    $tabParam = 'in-person';
}

$ws = patient_workshop_tab_data($pdo, (string) $user['id']);
$wallet = $ws['wallet'];
$grouped = $ws['grouped'];
$enrollmentsByTab = $ws['enrollmentsByTab'];
$enrollByWorkshop = $ws['enrollByWorkshop'];
$sessionsByWorkshop = $ws['sessionsByWorkshop'] ?? [];
$binderTabs = $ws['binderTabs'];

if ($view === 'ongoing' || $view === 'new') {
    $grouped = patient_workshops_for_phase($grouped, $view);
    $binderTabs = patient_workshop_phase_tabs($view);
}

$titles = [
    'ongoing' => 'دوره‌های در حال برگزاری',
    'new' => 'دوره‌های جدید',
    'requested' => 'دوره‌های درخواست داده‌شده',
    'mine' => 'دوره‌های من',
];
$leads = [
    'ongoing' => 'همه کارگاه‌ها و دوره‌هایی که الان در جریان‌اند اینجا هستند. اگر خواستید ثبت‌نام کنید؛ بعد از تأیید در «دوره‌های من» دیده می‌شوند.',
    'new' => 'دوره‌ها و کارگاه‌هایی که هنوز شروع نشده‌اند اینجا هستند. ثبت‌نام از همین فهرست انجام می‌شود.',
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
      $workshopBinderHideArchive = $view === 'new';
      $workshopBinderMode = ($view === 'ongoing' || $view === 'new') ? 'catalog' : $view;
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
render_patient_page($titles[$view], $coursesContent);
