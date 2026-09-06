<?php
declare(strict_types=1);
require_once __DIR__ . '/../../includes/doctor_panel.php';
require_once __DIR__ . '/../../includes/user_cleanup.php';
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

$tabParam = trim((string) ($_GET['tab'] ?? ''));
$binderInitial = in_array($tabParam, ['upcoming', 'done'], true) ? $tabParam : 'upcoming';

if (!function_exists('doctor_appointment_cards')) {
    function doctor_appointment_cards(array $list, string $empty): void
    {
        if (!$list) {
            echo '<p class="muted binder-empty">' . e($empty) . '</p>';
            return;
        }
        echo '<div class="stack">';
        foreach ($list as $a) {
            $time = jalali_day_parts((string) $a['starts_at']);
            ?>
      <div class="panel appt-card">
        <div class="appt-card-top">
          <div>
            <strong><?= e($a['patient_name']) ?></strong>
            <div class="muted" style="font-size:.85rem"><?= e((string) ($a['phone'] ?: $a['email'])) ?></div>
            <div style="margin-top:.35rem;font-size:.9rem">
              <?= e(format_fa_datetime((string) $a['starts_at'])) ?>
              <?php if (!empty($time['time_fa'])): ?>
                · ساعت <?= e((string) $time['time_fa']) ?>
              <?php endif; ?>
            </div>
            <?= staff_sign_html(['name' => $a['actor_name'] ?? '', 'username' => $a['actor_username'] ?? '']) ?>
          </div>
          <div class="appt-card-meta">
            <span class="badge"><?= e(appointment_status_label($a['status'])) ?></span>
            <?php if ($a['amount']): ?>
              <div class="muted"><?= e(format_price((int) $a['amount'])) ?> — <?= e(payment_status_label((string) $a['pay_status'])) ?></div>
            <?php endif; ?>
          </div>
        </div>
        <div class="appt-card-actions">
          <?= staff_receipt_view_html($a['payment_id'] ?? null, $a['receipt_path'] ?? null, false) ?>
          <a class="btn btn-outline btn-sm" href="<?= e(url('/doctor/patients/' . $a['patient_id'])) ?>">پرونده مراجعه‌کننده</a>
          <?= appointment_cancel_form((string) $a['id'], (string) $a['status'], '/doctor/appointments', '/doctor/appointments') ?>
          <?= admin_appointment_delete_form((string) $a['id'], '/doctor/appointments') ?>
          <?php if ($a['status'] === 'CONFIRMED'): ?>
            <form method="post" action="<?= e(url('/doctor/appointments')) ?>">
              <input type="hidden" name="id" value="<?= e($a['id']) ?>">
              <input type="hidden" name="status" value="COMPLETED">
              <input type="hidden" name="next" value="/doctor/appointments">
              <button class="btn btn-outline btn-sm" type="submit">انجام شد</button>
            </form>
          <?php endif; ?>
        </div>
      </div>
            <?php
        }
        echo '</div>';
    }
}

ob_start();
?>
<h1>نوبت‌های مراجعه‌کنندگان</h1>
<p class="muted" style="margin-top:.35rem">نوبت‌های پیش‌رو و انجام‌شده جدا هستند.</p>

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
      <?php doctor_appointment_cards($upcoming, 'نوبت پیش‌رویی نیست.'); ?>
    </section>
    <section class="binder-panel<?= $binderInitial === 'done' ? ' is-active' : '' ?>" data-binder-panel="done" role="tabpanel"<?= $binderInitial === 'done' ? '' : ' hidden' ?>>
      <p class="muted" style="margin:0 0 .85rem;font-size:.9rem">نوبت‌های برگزارشده، گذشته یا لغو شده در این بخش هستند.</p>
      <?php doctor_appointment_cards($done, 'نوبت انجام‌شده‌ای نیست.'); ?>
    </section>
  </div>
</div>
<?php
$pageScripts = '<script src="' . e(url('/assets/js/binder-tabs.js')) . '?v=20260906o"></script>';
$GLOBALS['pageScripts'] = $pageScripts;
render_doctor_page('نوبت‌ها', ob_get_clean());
