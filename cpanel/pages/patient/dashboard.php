<?php
declare(strict_types=1);

$user = require_login(['PATIENT']);
require_once __DIR__ . '/../../includes/patient_panel.php';
require_once __DIR__ . '/../../includes/booking_terms.php';
require_once __DIR__ . '/../../includes/availability.php';
require_once __DIR__ . '/../../includes/workshop_overview.php';

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
$ymdPack = patient_ymd_groups_with_open_slots($pdo, $appointments, (string) ($user['preferred_doctor_id'] ?? ''));

$ws = patient_workshop_tab_data($pdo, (string) $user['id']);
$wallet = $ws['wallet'];
$grouped = $ws['grouped'];
$enrollmentsByTab = $ws['enrollmentsByTab'];
$enrollByWorkshop = $ws['enrollByWorkshop'];
$sessionsByWorkshop = $ws['sessionsByWorkshop'] ?? [];
$binderTabs = $ws['binderTabs'];
$wsActiveCount = count($grouped['in-person']) + count($grouped['online']) + count($grouped['offline']);

$section = trim((string) ($_GET['section'] ?? ''));
$outerInitial = $section === 'workshops' ? 'workshops' : 'appts';

ob_start();
?>
<div class="stack">
  <h1>سلام <?= e($user['name']) ?></h1>
  <?php if (function_exists('mana_path_user_allowed') && mana_path_user_allowed($user)): ?>
    <p class="mpath-dash-link">
      <a class="btn btn-primary btn-sm" href="<?= e(url('/dashboard/path')) ?>">مسیر مانا</a>
      <span class="muted">همراه روزانه تمرین، اتاق ذهن و مسیر اختصاصی — نسخه آزمایشی.</span>
    </p>
  <?php endif; ?>
  <p class="muted">نوبت‌ها و کارگاه‌ها را با انتخاب سال، ماه و روز ببینید.</p>
  <p id="course-msg" class="course-flash" style="display:none" role="status"></p>
  <p id="dash-book-msg" class="course-flash" style="display:none" role="status"></p>

  <div class="binder-tile binder-tile--patient-dash" data-binder-tabs data-binder-initial="<?= e($outerInitial) ?>" data-binder-tone="<?= e($outerInitial) ?>">
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
          <div class="patient-dash-panel-actions">
            <a class="btn btn-outline btn-sm" href="<?= e(url('/dashboard/appointments')) ?>">همه نوبت‌ها</a>
            <a class="btn btn-primary btn-sm" href="<?= e(url('/doctors')) ?>">رزرو جدید</a>
          </div>
        </div>
        <?= booking_terms_acceptance_html('terms-accept-dash') ?>
        <?php
          if (!function_exists('appointment_session_mode_pick_html') && is_file(__DIR__ . '/../../includes/appointment_session.php')) {
              require_once __DIR__ . '/../../includes/appointment_session.php';
          }
          if (function_exists('ensure_appointment_session_schema')) {
              ensure_appointment_session_schema($pdo);
          }
          echo function_exists('appointment_session_mode_pick_html')
              ? '<div style="margin:.5rem 0 0.85rem">' . appointment_session_mode_pick_html('session_mode', 'IN_PERSON', 'home-sm') . '</div>'
              : '';
        ?>
        <div data-patient-book data-book-url="<?= e(url('/book')) ?>" data-after-url="<?= e(url('/dashboard/appointments?booked=1')) ?>" data-terms-id="terms-accept-dash">
        <?php
          $appointmentItemMode = 'simple';
          require __DIR__ . '/../../includes/patient_appointments_month_binder.php';
        ?>
        </div>
      </section>

      <section class="binder-panel<?= $outerInitial === 'workshops' ? ' is-active' : '' ?>" data-binder-panel="workshops" role="tabpanel"<?= $outerInitial === 'workshops' ? '' : ' hidden' ?>>
        <div class="patient-dash-panel-head">
          <div class="patient-dash-panel-actions">
            <a class="btn btn-primary btn-sm" href="<?= e(url('/services')) ?>">دوره‌ها و کارگاه‌ها (خدمات)</a>
            <a class="btn btn-outline btn-sm" href="<?= e(url('/dashboard/workshops/requested')) ?>">درخواست‌ها</a>
            <a class="btn btn-outline btn-sm" href="<?= e(url('/dashboard/workshops/mine')) ?>">دوره‌های من</a>
          </div>
        </div>
        <p class="muted" style="margin:0 0 .85rem;font-size:.9rem">
          دوره‌ها و کارگاه‌های جدید در بخش خدمات اعلام می‌شوند. اگر دوره تازه‌ای شروع شود، در «پیام‌ها» هم لینک ثبت‌نام برایتان می‌آید.
        </p>
        <div data-patient-courses data-enroll-url="<?= e($ws['enrollUrl']) ?>" data-pay-url="<?= e($ws['payUrl']) ?>" data-cancel-url="<?= e($ws['cancelUrl']) ?>" data-after-enroll-url="<?= e(url('/dashboard/workshops/requested')) ?>">
          <?php
            $workshopBinderNested = true;
            $workshopBinderInitial = 'in-person';
            $workshopBinderMode = 'mine';
            $enrollmentsByTab = patient_enrollments_filter_status($enrollmentsByTab, ['CONFIRMED', 'COMPLETED']);
            require __DIR__ . '/../../includes/patient_workshop_binder.php';
          ?>
        </div>
      </section>
    </div>
  </div>
</div>
<?= booking_terms_modal_html() ?>
<?= booking_terms_styles() ?>
<?php
$dashContent = ob_get_clean();
if (function_exists('mana_path_user_allowed') && mana_path_user_allowed($user)) {
    require_once __DIR__ . '/../../includes/mana_path_ui.php';
    $GLOBALS['pageHead'] = '<link rel="stylesheet" href="' . e(mana_path_css_href()) . '">';
}
$GLOBALS['pageScripts'] = '
<script src="' . e(url('/assets/js/binder-tabs.js')) . '?v=20260904u"></script>
<script src="' . e(url('/assets/js/ymd-cascade.js')) . '?v=20260910s"></script>
<script src="' . e(url('/assets/js/patient-courses.js')) . '?v=20260906w"></script>
<script src="' . e(url('/assets/js/patient-book-slots.js')) . '?v=20260917a"></script>'
  . booking_terms_script('terms-accept-dash', '.dash-book-btn');
render_patient_page('پنل مراجعه‌کننده', $dashContent);
