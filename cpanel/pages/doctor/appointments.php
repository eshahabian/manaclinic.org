<?php
declare(strict_types=1);
require_once __DIR__ . '/../../includes/doctor_panel.php';
require_once __DIR__ . '/../../includes/user_cleanup.php';
require_once __DIR__ . '/../../includes/appointment_cancel.php';
require_once __DIR__ . '/../../includes/doctor_profile_fields.php';
require_once __DIR__ . '/../../includes/appointment_list_helpers.php';

$ctx = require_doctor_profile($pdo);
$doctorId = (string) ($ctx['profile']['id'] ?? '');

/** دکتر شیوا گرانمایه‌پور (و پنل ادمین روی همان حساب): همه نوبت‌ها؛ بقیه فقط نوبت‌های خودشان */
$seeAllAppointments = doctor_is_shiva([
    'name' => (string) ($ctx['profile']['name'] ?? ($ctx['user']['name'] ?? '')),
    'username' => (string) ($ctx['user']['username'] ?? ''),
]) || doctor_is_shiva($ctx['user'] ?? null);

$searchQ = trim((string) ($_GET['q'] ?? ''));
$searchDay = trim((string) ($_GET['day'] ?? ''));
if ($searchDay !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $searchDay)) {
    $searchDay = '';
}
$searchJalali = '';
if ($searchDay !== '') {
    [$gy, $gm, $gd] = array_map('intval', explode('-', $searchDay));
    if ($gy > 0 && $gm > 0 && $gd > 0) {
        [$jy, $jm, $jd] = gregorian_to_jalali($gy, $gm, $gd);
        $searchJalali = $jy . '/' . $jm . '/' . $jd;
    }
}

$sql = "
  SELECT a.*, u.name AS patient_name, u.phone, u.email,
         du.name AS doctor_name,
         p.id AS payment_id, p.amount, p.status AS pay_status, p.receipt_path,
         cu.name AS actor_name, cu.username AS actor_username, cu.role AS actor_role
  FROM appointments a
  JOIN users u ON u.id = a.patient_id
  JOIN doctor_profiles dp ON dp.id = a.doctor_id
  JOIN users du ON du.id = dp.user_id
  LEFT JOIN payments p ON p.appointment_id = a.id
  LEFT JOIN users cu ON cu.id = a.created_by_user_id
";
$params = [];
if (!$seeAllAppointments) {
    $sql .= ' WHERE a.doctor_id = ?';
    $params[] = $doctorId;
}
$sql .= ' ORDER BY a.starts_at ASC';
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();

$upcoming = [];
$done = [];
$cancelled = [];
$now = time();
if (function_exists('appointment_restore_auto_cancelled_unpaid')) {
    appointment_restore_auto_cancelled_unpaid($pdo);
}
foreach ($rows as $row) {
    $status = (string) ($row['status'] ?? '');
    $start = strtotime((string) ($row['starts_at'] ?? '')) ?: 0;
    if ($status === 'CANCELLED') {
        $cancelled[] = $row;
    } elseif ($status === 'COMPLETED' || $start < $now) {
        $done[] = $row;
    } else {
        $upcoming[] = $row;
    }
}

$upcomingFiltered = appointment_list_apply_search($upcoming, $searchQ, $searchDay);
$doneFiltered = appointment_list_apply_search($done, $searchQ, $searchDay);
$cancelledFiltered = appointment_list_apply_search($cancelled, $searchQ, $searchDay);

usort($upcomingFiltered, static fn(array $a, array $b): int => strcmp((string) $a['starts_at'], (string) $b['starts_at']));
usort($doneFiltered, static fn(array $a, array $b): int => strcmp((string) $b['starts_at'], (string) $a['starts_at']));
usort($cancelledFiltered, static fn(array $a, array $b): int => strcmp((string) $b['starts_at'], (string) $a['starts_at']));

$tabParam = trim((string) ($_GET['tab'] ?? ''));
$binderInitial = in_array($tabParam, ['upcoming', 'done', 'cancelled'], true) ? $tabParam : 'upcoming';

$showDoctorOnCards = $seeAllAppointments;
$filterActive = $searchQ !== '' || $searchDay !== '';
$filterHintParts = [];
if ($searchQ !== '') {
    $filterHintParts[] = 'نام «' . $searchQ . '»';
}
if ($searchDay !== '') {
    $filterHintParts[] = 'تاریخ ' . to_jalali_label($searchDay);
}
$filterHint = implode(' · ', $filterHintParts);
$namePlaceholder = $seeAllAppointments ? 'نام مراجعه‌کننده یا درمانگر…' : 'نام مراجعه‌کننده خودتان…';
$deskAction = url('/doctor/appointments');

ob_start();
?>
<h1>نوبت‌های مراجعه‌کنندگان</h1>
<p class="muted" style="margin-top:.35rem">
  <?php if ($seeAllAppointments): ?>
    شما می‌توانید نوبت همه درمانگرها را ببینید. با نام یا تاریخ جستجو کنید.
  <?php else: ?>
    فقط نوبت‌های مراجعه‌کنندگان خودتان. با نام یا تاریخ جستجو کنید.
  <?php endif; ?>
</p>

<div class="binder-tile" data-binder-tabs data-binder-hash="0" data-binder-initial="<?= e($binderInitial) ?>" data-binder-tone="<?= e($binderInitial === 'cancelled' ? 'archive' : ($binderInitial === 'done' ? 'archive' : 'appts')) ?>" style="margin-top:1.25rem">
  <div class="binder-tabs" role="tablist" aria-label="دسته‌بندی نوبت‌ها">
    <button type="button"
      class="binder-tab binder-tab-appts<?= $binderInitial === 'upcoming' ? ' is-active' : '' ?>"
      role="tab"
      data-binder-tab="upcoming"
      data-binder-tone="appts"
      aria-selected="<?= $binderInitial === 'upcoming' ? 'true' : 'false' ?>">
      نوبت‌های پیش‌رو
      <span class="binder-tab-count"><?= count($upcomingFiltered) ?></span>
    </button>
    <button type="button"
      class="binder-tab binder-tab-archive<?= $binderInitial === 'done' ? ' is-active' : '' ?>"
      role="tab"
      data-binder-tab="done"
      data-binder-tone="archive"
      aria-selected="<?= $binderInitial === 'done' ? 'true' : 'false' ?>">
      نوبت‌های انجام‌شده
      <span class="binder-tab-count"><?= count($doneFiltered) ?></span>
    </button>
    <button type="button"
      class="binder-tab binder-tab-cancelled<?= $binderInitial === 'cancelled' ? ' is-active' : '' ?>"
      role="tab"
      data-binder-tab="cancelled"
      data-binder-tone="cancelled"
      aria-selected="<?= $binderInitial === 'cancelled' ? 'true' : 'false' ?>">
      نوبت‌های لغو شده
      <span class="binder-tab-count"><?= count($cancelledFiltered) ?></span>
    </button>
  </div>
  <div class="binder-body">
    <section class="binder-panel<?= $binderInitial === 'upcoming' ? ' is-active' : '' ?>" data-binder-panel="upcoming" role="tabpanel"<?= $binderInitial === 'upcoming' ? '' : ' hidden' ?>>
      <?= appointment_search_form_html($deskAction, 'upcoming', $searchQ, $searchDay, $searchJalali, $filterActive, 'up', $namePlaceholder) ?>
      <?php if ($filterActive): ?>
        <p class="muted" style="margin:0 0 .85rem;font-size:.85rem">
          نتیجه: <?= e($filterHint) ?> — <?= to_fa_digits((string) count($upcomingFiltered)) ?> نوبت
        </p>
      <?php endif; ?>
      <?php
        $appointmentList = $upcomingFiltered;
        $appointmentEmpty = $filterActive ? 'با این جستجو نوبت پیش‌رویی پیدا نشد.' : 'نوبت پیش‌رویی نیست.';
        $appointmentShowDoctor = $showDoctorOnCards;
        require __DIR__ . '/../../includes/doctor_appointment_cards.php';
      ?>
    </section>
    <section class="binder-panel<?= $binderInitial === 'done' ? ' is-active' : '' ?>" data-binder-panel="done" role="tabpanel"<?= $binderInitial === 'done' ? '' : ' hidden' ?>>
      <p class="muted" style="margin:0 0 .85rem;font-size:.9rem">نوبت‌های برگزارشده یا گذشته.</p>
      <?= appointment_search_form_html($deskAction, 'done', $searchQ, $searchDay, $searchJalali, $filterActive, 'dn', $namePlaceholder) ?>
      <?php if ($filterActive): ?>
        <p class="muted" style="margin:0 0 .85rem;font-size:.85rem">
          نتیجه: <?= e($filterHint) ?> — <?= to_fa_digits((string) count($doneFiltered)) ?> نوبت
        </p>
      <?php endif; ?>
      <?php
        $appointmentList = $doneFiltered;
        $appointmentEmpty = $filterActive ? 'با این جستجو نوبت انجام‌شده‌ای پیدا نشد.' : 'نوبت انجام‌شده‌ای نیست.';
        $appointmentShowDoctor = $showDoctorOnCards;
        require __DIR__ . '/../../includes/doctor_appointment_cards.php';
      ?>
    </section>
    <section class="binder-panel<?= $binderInitial === 'cancelled' ? ' is-active' : '' ?>" data-binder-panel="cancelled" role="tabpanel"<?= $binderInitial === 'cancelled' ? '' : ' hidden' ?>>
      <p class="muted" style="margin:0 0 .85rem;font-size:.9rem">نوبت‌هایی که لغو شده‌اند (توسط مراجع، درمانگر یا سیستم).</p>
      <?= appointment_search_form_html($deskAction, 'cancelled', $searchQ, $searchDay, $searchJalali, $filterActive, 'cn', $namePlaceholder) ?>
      <?php if ($filterActive): ?>
        <p class="muted" style="margin:0 0 .85rem;font-size:.85rem">
          نتیجه: <?= e($filterHint) ?> — <?= to_fa_digits((string) count($cancelledFiltered)) ?> نوبت
        </p>
      <?php endif; ?>
      <?php
        $appointmentList = $cancelledFiltered;
        $appointmentEmpty = $filterActive ? 'با این جستجو نوبت لغوشده‌ای پیدا نشد.' : 'نوبت لغوشده‌ای نیست.';
        $appointmentShowDoctor = $showDoctorOnCards;
        require __DIR__ . '/../../includes/doctor_appointment_cards.php';
      ?>
    </section>
  </div>
</div>
<?php
$pageHead = '<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@majidh1/jalalidatepicker/dist/jalalidatepicker.min.css">';
$pageScripts = '<script src="' . e(url('/assets/js/binder-tabs.js')) . '?v=20260920a"></script>'
    . appointment_search_datepicker_script(['up', 'dn', 'cn']);
$GLOBALS['pageHead'] = $pageHead;
$GLOBALS['pageScripts'] = $pageScripts;
render_doctor_page('نوبت‌ها', ob_get_clean());
