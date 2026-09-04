<?php
declare(strict_types=1);

$user = require_login(['PATIENT']);
require_once __DIR__ . '/../../includes/patient_panel.php';

$stmt = $pdo->prepare("
  SELECT a.*, u.name AS doctor_name
  FROM appointments a
  JOIN doctor_profiles dp ON dp.id = a.doctor_id
  JOIN users u ON u.id = dp.user_id
  WHERE a.patient_id = ?
  ORDER BY a.starts_at DESC
");
$stmt->execute([$user['id']]);
$appointments = $stmt->fetchAll();
$monthPack = group_appointments_by_jalali_month($appointments);
$monthGroups = $monthPack['months'];
$defaultMonthId = $monthPack['default_id'];

$ws = patient_workshop_tab_data($pdo, (string) $user['id']);
$wallet = $ws['wallet'];
$grouped = $ws['grouped'];
$enrollmentsByTab = $ws['enrollmentsByTab'];
$enrollByWorkshop = $ws['enrollByWorkshop'];
$binderTabs = $ws['binderTabs'];
$wsActiveCount = count($grouped['in-person']) + count($grouped['online']) + count($grouped['offline']);

$section = trim((string) ($_GET['section'] ?? ''));
$outerInitial = $section === 'workshops' ? 'workshops' : 'appts';

ob_start();
?>
<div class="stack">
  <h1>سلام <?= e($user['name']) ?></h1>
  <p class="muted">نوبت‌ها را ماه‌به‌ماه ببینید و کارگاه‌ها را از تب رنگی ثبت‌نام کنید.</p>
  <p id="course-msg" class="course-flash" style="display:none" role="status"></p>

  <div class="binder-tile" data-binder-tabs data-binder-initial="<?= e($outerInitial) ?>" data-binder-tone="<?= e($outerInitial) ?>">
    <div class="binder-tabs" role="tablist" aria-label="بخش‌های پنل">
      <button type="button" class="binder-tab binder-tab-appts<?= $outerInitial === 'appts' ? ' is-active' : '' ?>" role="tab" data-binder-tab="appts" data-binder-tone="appts" aria-selected="<?= $outerInitial === 'appts' ? 'true' : 'false' ?>">
        نوبت‌ها
        <span class="binder-tab-count"><?= count($appointments) ?></span>
      </button>
      <button type="button" class="binder-tab binder-tab-workshops<?= $outerInitial === 'workshops' ? ' is-active' : '' ?>" role="tab" data-binder-tab="workshops" data-binder-tone="workshops" aria-selected="<?= $outerInitial === 'workshops' ? 'true' : 'false' ?>">
        کارگاه‌ها
        <span class="binder-tab-count"><?= (int) $wsActiveCount ?></span>
      </button>
    </div>
    <div class="binder-body">
      <section class="binder-panel<?= $outerInitial === 'appts' ? ' is-active' : '' ?>" data-binder-panel="appts" role="tabpanel"<?= $outerInitial === 'appts' ? '' : ' hidden' ?>>
        <div class="patient-dash-panel-head">
          <div>
            <h2 class="binder-sub" style="margin:0">نوبت‌های شما</h2>
            <p class="muted" style="margin:.3rem 0 0;font-size:.85rem">ماه را انتخاب کنید؛ مثلاً شهریور.</p>
          </div>
          <div class="patient-dash-panel-actions">
            <a class="btn btn-outline btn-sm" href="<?= e(url('/dashboard/appointments')) ?>">همه نوبت‌ها</a>
            <a class="btn btn-primary btn-sm" href="<?= e(url('/doctors')) ?>">رزرو جدید</a>
          </div>
        </div>
        <?php
          $monthBinderNested = true;
          $appointmentItemMode = 'simple';
          $monthBinderAria = 'انتخاب ماه نوبت';
          require __DIR__ . '/../../includes/patient_appointments_month_binder.php';
        ?>
      </section>

      <section class="binder-panel<?= $outerInitial === 'workshops' ? ' is-active' : '' ?>" data-binder-panel="workshops" role="tabpanel"<?= $outerInitial === 'workshops' ? '' : ' hidden' ?>>
        <div class="patient-dash-panel-head">
          <div>
            <h2 class="binder-sub" style="margin:0">کارگاه‌ها و دوره‌ها</h2>
            <p class="muted" style="margin:.3rem 0 0;font-size:.85rem">اول نوع کارگاه را انتخاب کنید، بعد لیست همان تب.</p>
          </div>
          <a class="btn btn-outline btn-sm" href="<?= e(url('/dashboard/courses')) ?>">دوره‌های من</a>
        </div>
        <div data-patient-courses data-enroll-url="<?= e($ws['enrollUrl']) ?>" data-pay-url="<?= e($ws['payUrl']) ?>" data-cancel-url="<?= e($ws['cancelUrl']) ?>">
          <?php
            $workshopBinderNested = true;
            $workshopBinderInitial = 'in-person';
            require __DIR__ . '/../../includes/patient_workshop_binder.php';
          ?>
        </div>
      </section>
    </div>
  </div>
</div>
<?php
$dashContent = ob_get_clean();
$GLOBALS['pageScripts'] = '
<script src="' . e(url('/assets/js/binder-tabs.js')) . '?v=20260904u"></script>
<script src="' . e(url('/assets/js/patient-courses.js')) . '?v=20260904u"></script>';
render_patient_page('پنل مراجع', $dashContent);
