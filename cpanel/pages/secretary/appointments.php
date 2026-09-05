<?php
declare(strict_types=1);
require_once __DIR__ . '/../../includes/secretary_panel.php';
require_login(['SECRETARY']);

$rows = $pdo->query("
  SELECT a.*, pu.name AS patient_name, du.name AS doctor_name,
         p.id AS payment_id, p.status AS pay_status, p.amount, p.receipt_path,
         cu.name AS actor_name, cu.username AS actor_username
  FROM appointments a
  JOIN users pu ON pu.id = a.patient_id
  JOIN doctor_profiles dp ON dp.id = a.doctor_id
  JOIN users du ON du.id = dp.user_id
  LEFT JOIN payments p ON p.appointment_id = a.id
  LEFT JOIN users cu ON cu.id = a.created_by_user_id
  ORDER BY a.starts_at DESC
  LIMIT 200
")->fetchAll();

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

$tabParam = trim((string) ($_GET['tab'] ?? ''));
$binderInitial = in_array($tabParam, ['new', 'upcoming', 'done'], true) ? $tabParam : 'upcoming';

$secretaryBookEmbedded = true;
require __DIR__ . '/book.php';

ob_start();
?>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@majidh1/jalalidatepicker/dist/jalalidatepicker.min.css">
<h1>نوبت‌ها</h1>
<p class="muted" style="margin-top:.35rem;font-size:.9rem">نوبت جدید را از تب صورتی ثبت کنید؛ نوبت‌های پیش‌رو و انجام‌شده جدا دیده می‌شوند.</p>

<div class="binder-tile" data-binder-tabs data-binder-initial="<?= e($binderInitial) ?>" data-binder-tone="<?= e($binderInitial) ?>" style="margin-top:1.5rem">
  <div class="binder-tabs" role="tablist" aria-label="دسته‌بندی نوبت‌ها">
    <button type="button" class="binder-tab binder-tab-new<?= $binderInitial === 'new' ? ' is-active' : '' ?>" role="tab" data-binder-tab="new" data-binder-tone="new" aria-selected="<?= $binderInitial === 'new' ? 'true' : 'false' ?>">
      نوبت جدید
    </button>
    <button type="button" class="binder-tab binder-tab-appts<?= $binderInitial === 'upcoming' ? ' is-active' : '' ?>" role="tab" data-binder-tab="upcoming" data-binder-tone="appts" aria-selected="<?= $binderInitial === 'upcoming' ? 'true' : 'false' ?>">
      نوبت‌های پیش‌رو <span class="binder-tab-count"><?= count($upcoming) ?></span>
    </button>
    <button type="button" class="binder-tab binder-tab-archive<?= $binderInitial === 'done' ? ' is-active' : '' ?>" role="tab" data-binder-tab="done" data-binder-tone="archive" aria-selected="<?= $binderInitial === 'done' ? 'true' : 'false' ?>">
      نوبت‌های انجام‌شده <span class="binder-tab-count"><?= count($done) ?></span>
    </button>
  </div>
  <div class="binder-body">
    <section class="binder-panel<?= $binderInitial === 'new' ? ' is-active' : '' ?>" data-binder-panel="new" role="tabpanel"<?= $binderInitial === 'new' ? '' : ' hidden' ?>>
      <?= $secretaryBookFormHtml ?? '' ?>
    </section>
    <section class="binder-panel<?= $binderInitial === 'upcoming' ? ' is-active' : '' ?>" data-binder-panel="upcoming" role="tabpanel"<?= $binderInitial === 'upcoming' ? '' : ' hidden' ?>>
      <?php $appointmentList = $upcoming; $appointmentEmpty = 'نوبت پیش‌رویی نیست.'; require __DIR__ . '/../../includes/secretary_appointment_cards.php'; ?>
    </section>
    <section class="binder-panel<?= $binderInitial === 'done' ? ' is-active' : '' ?>" data-binder-panel="done" role="tabpanel"<?= $binderInitial === 'done' ? '' : ' hidden' ?>>
      <p class="muted" style="margin:0 0 .85rem;font-size:.9rem">نوبت‌های برگزارشده، گذشته یا لغو شده در این بخش هستند.</p>
      <?php $appointmentList = $done; $appointmentEmpty = 'نوبت انجام‌شده‌ای نیست.'; require __DIR__ . '/../../includes/secretary_appointment_cards.php'; ?>
    </section>
  </div>
</div>
<?php
$pageScripts = '<script src="' . e(url('/assets/js/binder-tabs.js')) . '?v=20260905c"></script>' . ($secretaryBookScripts ?? '');
render_secretary_page('نوبت‌ها', ob_get_clean());
