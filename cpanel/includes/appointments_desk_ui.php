<?php
declare(strict_types=1);

require_once __DIR__ . '/appointment_list_helpers.php';
require_once __DIR__ . '/secretary_week_grid.php';
require_once __DIR__ . '/secretary_patient.php';
require_once __DIR__ . '/availability.php';

/** میز نوبت مشترک منشی و ادمین */
$rows = $pdo->query("
  SELECT a.*, pu.name AS patient_name, pu.phone, du.name AS doctor_name,
         p.id AS payment_id, p.status AS pay_status, p.amount, p.receipt_path,
         cu.name AS actor_name, cu.username AS actor_username, cu.role AS actor_role
  FROM appointments a
  JOIN users pu ON pu.id = a.patient_id
  JOIN doctor_profiles dp ON dp.id = a.doctor_id
  JOIN users du ON du.id = dp.user_id
  LEFT JOIN payments p ON p.appointment_id = a.id
  LEFT JOIN users cu ON cu.id = a.created_by_user_id
  ORDER BY a.starts_at DESC
")->fetchAll();

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
$filterActive = $searchQ !== '' || $searchDay !== '';
$filterHintParts = [];
if ($searchQ !== '') {
    $filterHintParts[] = 'نام «' . $searchQ . '»';
}
if ($searchDay !== '') {
    $filterHintParts[] = 'تاریخ ' . to_jalali_label($searchDay);
}
$filterHint = implode(' · ', $filterHintParts);

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
usort($upcoming, static fn(array $a, array $b): int => strcmp((string) $a['starts_at'], (string) $b['starts_at']));
usort($done, static fn(array $a, array $b): int => strcmp((string) $b['starts_at'], (string) $a['starts_at']));
usort($cancelled, static fn(array $a, array $b): int => strcmp((string) $b['starts_at'], (string) $a['starts_at']));

$upcomingFiltered = appointment_list_apply_search($upcoming, $searchQ, $searchDay);
$doneFiltered = appointment_list_apply_search($done, $searchQ, $searchDay);
$cancelledFiltered = appointment_list_apply_search($cancelled, $searchQ, $searchDay);

$tabParam = trim((string) ($_GET['tab'] ?? ''));
$binderInitial = in_array($tabParam, ['new', 'upcoming', 'done', 'cancelled'], true) ? $tabParam : 'upcoming';

$doctors = secretary_active_doctors($pdo);
$weekDoctorId = trim((string) ($_GET['doctor_id'] ?? ''));
if ($weekDoctorId === '' || !array_filter($doctors, static fn($d) => (string) ($d['id'] ?? '') === $weekDoctorId)) {
    $weekDoctorId = (string) (($doctors[0]['id'] ?? '') ?: '');
}
$weekSchedule = $weekDoctorId !== '' ? secretary_week_schedule($pdo, $weekDoctorId, 7) : [];

$secretaryBookEmbedded = true;
require __DIR__ . '/../pages/secretary/book.php';

$appointmentsDeskNext = $appointmentsDeskNext ?? '/secretary/appointments';
$deskAction = url($appointmentsDeskNext);

ob_start();
?>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@majidh1/jalalidatepicker/dist/jalalidatepicker.min.css">
<h1>نوبت‌ها</h1>
<p class="muted" style="margin-top:.35rem;font-size:.9rem">نوبت جدید را از تب صورتی ثبت کنید. در پیش‌رو جدول هفت‌روزه را ببینید؛ در انجام‌شده و لغو شده با نام یا تاریخ جستجو کنید.</p>
<?php if (!empty($appointmentsDeskAdminTools)): ?>
  <div class="appt-toolbar">
    <form method="post" action="<?= e(url('/admin/appointments')) ?>" onsubmit="return confirm('همه نوبت‌ها برای همیشه حذف شوند؟');">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="delete_all">
      <button type="submit" class="btn btn-outline btn-sm" style="color:var(--danger)">حذف همه نوبت‌ها</button>
    </form>
  </div>
<?php endif; ?>

<div class="binder-tile" data-binder-tabs data-binder-hash="0" data-binder-initial="<?= e($binderInitial) ?>" data-binder-tone="<?= e($binderInitial) ?>" style="margin-top:1.5rem">
  <div class="binder-tabs" role="tablist" aria-label="دسته‌بندی نوبت‌ها">
    <button type="button" class="binder-tab binder-tab-new<?= $binderInitial === 'new' ? ' is-active' : '' ?>" role="tab" data-binder-tab="new" data-binder-tone="new" aria-selected="<?= $binderInitial === 'new' ? 'true' : 'false' ?>">
      نوبت جدید
    </button>
    <button type="button" class="binder-tab binder-tab-appts<?= $binderInitial === 'upcoming' ? ' is-active' : '' ?>" role="tab" data-binder-tab="upcoming" data-binder-tone="appts" aria-selected="<?= $binderInitial === 'upcoming' ? 'true' : 'false' ?>">
      نوبت‌های پیش‌رو <span class="binder-tab-count"><?= count($filterActive ? $upcomingFiltered : $upcoming) ?></span>
    </button>
    <button type="button" class="binder-tab binder-tab-archive<?= $binderInitial === 'done' ? ' is-active' : '' ?>" role="tab" data-binder-tab="done" data-binder-tone="archive" aria-selected="<?= $binderInitial === 'done' ? 'true' : 'false' ?>">
      نوبت‌های انجام‌شده <span class="binder-tab-count"><?= count($doneFiltered) ?></span>
    </button>
    <button type="button" class="binder-tab binder-tab-cancelled<?= $binderInitial === 'cancelled' ? ' is-active' : '' ?>" role="tab" data-binder-tab="cancelled" data-binder-tone="cancelled" aria-selected="<?= $binderInitial === 'cancelled' ? 'true' : 'false' ?>">
      نوبت‌های لغو شده <span class="binder-tab-count"><?= count($cancelledFiltered) ?></span>
    </button>
  </div>
  <div class="binder-body">
    <section class="binder-panel<?= $binderInitial === 'new' ? ' is-active' : '' ?>" data-binder-panel="new" role="tabpanel"<?= $binderInitial === 'new' ? '' : ' hidden' ?>>
      <?= $secretaryBookFormHtml ?? '' ?>
    </section>
    <section class="binder-panel<?= $binderInitial === 'upcoming' ? ' is-active' : '' ?>" data-binder-panel="upcoming" role="tabpanel"<?= $binderInitial === 'upcoming' ? '' : ' hidden' ?>>
      <?= appointment_search_form_html($deskAction, 'upcoming', $searchQ, $searchDay, $searchJalali, $filterActive, 'up', 'نام مراجعه‌کننده یا درمانگر…') ?>
      <?php if ($filterActive): ?>
        <p class="muted" style="margin:0 0 .85rem;font-size:.85rem">
          نتیجه: <?= e($filterHint) ?> — <?= to_fa_digits((string) count($upcomingFiltered)) ?> نوبت
        </p>
        <?php
          $appointmentList = $upcomingFiltered;
          $appointmentEmpty = 'با این جستجو نوبت پیش‌رویی پیدا نشد.';
          require __DIR__ . '/secretary_appointment_cards.php';
        ?>
      <?php else: ?>
        <form method="get" action="<?= e($deskAction) ?>" class="sec-week-doctor-pick" style="display:flex;flex-wrap:wrap;gap:.55rem;align-items:end;margin:0 0 1rem">
          <input type="hidden" name="tab" value="upcoming">
          <div style="flex:1;min-width:12rem">
            <label class="label" for="week_doctor_id">درمانگر (جدول هفت‌روزه)</label>
            <select class="input" name="doctor_id" id="week_doctor_id" onchange="this.form.submit()">
              <?php foreach ($doctors as $d): ?>
                <option value="<?= e((string) $d['id']) ?>"<?= $weekDoctorId === (string) $d['id'] ? ' selected' : '' ?>>
                  <?= e((string) ($d['name'] ?? '')) ?><?= !empty($d['specialty']) ? ' — ' . e((string) $d['specialty']) : '' ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>
        </form>
        <p class="muted" style="margin:0 0 .85rem;font-size:.85rem">هفت روز آینده · ساعت قرمز = رزرو شده (کلیک برای جزئیات) · ساعت آزاد = کلیک برای رزرو</p>
        <?php if ($weekDoctorId === ''): ?>
          <p class="muted">درمانگری برای نمایش یافت نشد.</p>
        <?php else: ?>
          <?= secretary_week_grid_html($weekSchedule, $weekDoctorId, $deskAction, $appointmentsDeskNext) ?>
        <?php endif; ?>
      <?php endif; ?>
    </section>
    <section class="binder-panel<?= $binderInitial === 'done' ? ' is-active' : '' ?>" data-binder-panel="done" role="tabpanel"<?= $binderInitial === 'done' ? '' : ' hidden' ?>>
      <p class="muted" style="margin:0 0 .85rem;font-size:.9rem">نوبت‌های برگزارشده یا گذشته.</p>
      <?= appointment_search_form_html($deskAction, 'done', $searchQ, $searchDay, $searchJalali, $filterActive, 'dn', 'نام مراجعه‌کننده یا درمانگر…') ?>
      <?php if ($filterActive): ?>
        <p class="muted" style="margin:0 0 .85rem;font-size:.85rem">
          نتیجه: <?= e($filterHint) ?> — <?= to_fa_digits((string) count($doneFiltered)) ?> نوبت
        </p>
      <?php endif; ?>
      <?php
        $appointmentList = $doneFiltered;
        $appointmentEmpty = $filterActive ? 'با این جستجو نوبت انجام‌شده‌ای پیدا نشد.' : 'نوبت انجام‌شده‌ای نیست.';
        require __DIR__ . '/secretary_appointment_cards.php';
      ?>
    </section>
    <section class="binder-panel<?= $binderInitial === 'cancelled' ? ' is-active' : '' ?>" data-binder-panel="cancelled" role="tabpanel"<?= $binderInitial === 'cancelled' ? '' : ' hidden' ?>>
      <p class="muted" style="margin:0 0 .85rem;font-size:.9rem">نوبت‌هایی که لغو شده‌اند.</p>
      <?= appointment_search_form_html($deskAction, 'cancelled', $searchQ, $searchDay, $searchJalali, $filterActive, 'cn', 'نام مراجعه‌کننده یا درمانگر…') ?>
      <?php if ($filterActive): ?>
        <p class="muted" style="margin:0 0 .85rem;font-size:.85rem">
          نتیجه: <?= e($filterHint) ?> — <?= to_fa_digits((string) count($cancelledFiltered)) ?> نوبت
        </p>
      <?php endif; ?>
      <?php
        $appointmentList = $cancelledFiltered;
        $appointmentEmpty = $filterActive ? 'با این جستجو نوبت لغوشده‌ای پیدا نشد.' : 'نوبت لغوشده‌ای نیست.';
        require __DIR__ . '/secretary_appointment_cards.php';
      ?>
    </section>
  </div>
</div>
<?php
$appointmentsDeskHtml = ob_get_clean();
$appointmentsDeskScripts = '<script src="' . e(url('/assets/js/binder-tabs.js')) . '?v=20260920a"></script>'
    . appointment_search_datepicker_script(['up', 'dn', 'cn'])
    . ($secretaryBookScripts ?? '');
