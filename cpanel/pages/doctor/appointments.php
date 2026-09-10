<?php
declare(strict_types=1);
require_once __DIR__ . '/../../includes/doctor_panel.php';
require_once __DIR__ . '/../../includes/user_cleanup.php';
require_once __DIR__ . '/../../includes/appointment_cancel.php';
$ctx = require_doctor_profile($pdo);
$stmt = $pdo->prepare("
  SELECT a.*, u.name AS patient_name, u.phone, u.email,
         p.id AS payment_id, p.amount, p.status AS pay_status, p.receipt_path,
         cu.name AS actor_name, cu.username AS actor_username
  FROM appointments a
  JOIN users u ON u.id=a.patient_id
  LEFT JOIN payments p ON p.appointment_id=a.id
  LEFT JOIN users cu ON cu.id = a.created_by_user_id
  WHERE a.doctor_id=?
  ORDER BY a.starts_at ASC
");
$stmt->execute([$ctx['profile']['id']]);
$rows = $stmt->fetchAll();

$upcoming = [];
$done = [];
$now = time();
foreach ($rows as $row) {
    $status = (string) ($row['status'] ?? '');
    $start = strtotime((string) ($row['starts_at'] ?? '')) ?: 0;
    $isUpcoming = !in_array($status, ['CANCELLED', 'COMPLETED'], true) && $start >= $now;
    if ($isUpcoming) {
        $upcoming[] = $row;
    } else {
        $done[] = $row;
    }
}
usort($upcoming, static fn(array $a, array $b): int => strcmp((string) $a['starts_at'], (string) $b['starts_at']));
usort($done, static fn(array $a, array $b): int => strcmp((string) $b['starts_at'], (string) $a['starts_at']));

$upcomingYmd = group_appointments_by_jalali_ymd($upcoming, 'doc-up', 'current');
$doneYmd = group_appointments_by_jalali_ymd($done, 'doc-dn', 'latest');

$tabParam = trim((string) ($_GET['tab'] ?? ''));
$binderInitial = in_array($tabParam, ['upcoming', 'done'], true) ? $tabParam : 'upcoming';

$ymdRenderDoctor = static function (array $list): void {
    $appointmentList = $list;
    $appointmentEmpty = 'نوبتی در این بازه نیست.';
    require __DIR__ . '/../../includes/doctor_appointment_cards.php';
};

ob_start();
?>
<h1>نوبت‌های مراجعه‌کنندگان</h1>
<p class="muted" style="margin-top:.35rem">نوبت منشی و رزرو آنلاین اینجاست. سال، ماه و روز را بزنید تا همه افراد همان بازه را ببینید.</p>

<div class="binder-tile" data-binder-tabs data-binder-hash="0" data-binder-initial="<?= e($binderInitial) ?>" data-binder-tone="<?= e($binderInitial === 'done' ? 'archive' : 'appts') ?>" style="margin-top:1.25rem">
  <div class="binder-tabs" role="tablist" aria-label="دسته‌بندی نوبت‌ها">
    <button type="button"
      class="binder-tab binder-tab-appts<?= $binderInitial === 'upcoming' ? ' is-active' : '' ?>"
      role="tab"
      data-binder-tab="upcoming"
      data-binder-tone="appts"
      aria-selected="<?= $binderInitial === 'upcoming' ? 'true' : 'false' ?>">
      نوبت‌های پیش‌رو
      <span class="binder-tab-count"><?= count($upcoming) ?></span>
    </button>
    <button type="button"
      class="binder-tab binder-tab-archive<?= $binderInitial === 'done' ? ' is-active' : '' ?>"
      role="tab"
      data-binder-tab="done"
      data-binder-tone="archive"
      aria-selected="<?= $binderInitial === 'done' ? 'true' : 'false' ?>">
      نوبت‌های انجام‌شده
      <span class="binder-tab-count"><?= count($done) ?></span>
    </button>
  </div>
  <div class="binder-body">
    <section class="binder-panel<?= $binderInitial === 'upcoming' ? ' is-active' : '' ?>" data-binder-panel="upcoming" role="tabpanel"<?= $binderInitial === 'upcoming' ? '' : ' hidden' ?>>
      <?php
        $ymdPack = $upcomingYmd;
        $ymdEmpty = 'نوبت پیش‌رویی نیست.';
        $ymdRenderItems = $ymdRenderDoctor;
        require __DIR__ . '/../../includes/appointment_ymd_binder.php';
      ?>
    </section>
    <section class="binder-panel<?= $binderInitial === 'done' ? ' is-active' : '' ?>" data-binder-panel="done" role="tabpanel"<?= $binderInitial === 'done' ? '' : ' hidden' ?>>
      <p class="muted" style="margin:0 0 .85rem;font-size:.9rem">نوبت‌های برگزارشده، گذشته یا لغو شده. از تب ماه می‌توانید ببینید ماه پیش با چه کسانی وقت داشته‌اید.</p>
      <?php
        $ymdPack = $doneYmd;
        $ymdEmpty = 'نوبت انجام‌شده‌ای نیست.';
        $ymdRenderItems = $ymdRenderDoctor;
        require __DIR__ . '/../../includes/appointment_ymd_binder.php';
      ?>
    </section>
  </div>
</div>
<?php
$pageScripts = '<script src="' . e(url('/assets/js/binder-tabs.js')) . '?v=20260910p"></script>'
    . '<script src="' . e(url('/assets/js/ymd-cascade.js')) . '?v=20260910r"></script>';
$GLOBALS['pageScripts'] = $pageScripts;
render_doctor_page('نوبت‌ها', ob_get_clean());
