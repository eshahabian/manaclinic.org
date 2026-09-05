<?php
declare(strict_types=1);
require_once __DIR__ . '/../../includes/doctor_panel.php';
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

$monthPack = group_appointments_by_jalali_month($rows, false);
$monthGroups = $monthPack['months'];
foreach ($monthGroups as $id => $bucket) {
    $items = $bucket['items'] ?? [];
    if (!$items) {
        unset($monthGroups[$id]);
        continue;
    }
    usort($items, static fn(array $a, array $b): int => strcmp((string) $a['starts_at'], (string) $b['starts_at']));
    $days = [];
    foreach ($items as $a) {
        $ts = strtotime((string) $a['starts_at']) ?: 0;
        $gkey = $ts ? date('Y-m-d', $ts) : 'other';
        $day = jalali_day_parts((string) $a['starts_at']);
        if (!isset($days[$gkey])) {
            $days[$gkey] = [
                'label' => $day['label'] ?? format_fa_datetime((string) $a['starts_at']),
                'sort' => $gkey,
                'items' => [],
            ];
        }
        $days[$gkey]['items'][] = $a;
    }
    ksort($days);
    $base = (string) ($bucket['tab_label'] ?? $bucket['short'] ?? '');
    $monthGroups[$id]['items'] = $items;
    $monthGroups[$id]['days'] = $days;
    $monthGroups[$id]['tab_label'] = $base;
    $monthGroups[$id]['label'] = 'نوبت‌های ' . (string) ($bucket['label'] ?? $base);
}
if ($monthGroups && !isset($monthGroups[$monthPack['default_id']])) {
    $keys = array_keys($monthGroups);
    $monthPack['default_id'] = (string) end($keys);
}
$defaultMonthId = isset($monthGroups[$monthPack['default_id']])
    ? (string) $monthPack['default_id']
    : (string) (array_key_first($monthGroups) ?? '');

ob_start();
?>
<h1>نوبت‌های مراجعه‌کنندگان</h1>
<p class="muted" style="margin-top:.35rem">نوبت‌ها ماه‌به‌ماه جدا شده‌اند؛ داخل هر ماه روز و ساعت مراجعه مرتب است.</p>

<?php if (!$monthGroups): ?>
  <p class="muted" style="margin-top:1rem">نوبتی نیست.</p>
<?php else: ?>
  <div class="binder-tile" data-binder-tabs data-binder-initial="<?= e($defaultMonthId) ?>" data-binder-tone="<?= e((string) ($monthGroups[$defaultMonthId]['tone'] ?? 'appts')) ?>" style="margin-top:1.25rem">
    <div class="binder-tabs" role="tablist" aria-label="ماه نوبت‌ها">
      <?php foreach ($monthGroups as $id => $bucket): ?>
        <button type="button"
          class="binder-tab <?= e((string) ($bucket['class'] ?? 'binder-tab-appts')) ?><?= $defaultMonthId === $id ? ' is-active' : '' ?>"
          role="tab"
          data-binder-tab="<?= e((string) $id) ?>"
          data-binder-tone="<?= e((string) ($bucket['tone'] ?? 'appts')) ?>"
          aria-selected="<?= $defaultMonthId === $id ? 'true' : 'false' ?>">
          <?= e((string) ($bucket['tab_label'] ?? $bucket['short'] ?? $id)) ?>
          <span class="binder-tab-count"><?= count($bucket['items'] ?? []) ?></span>
        </button>
      <?php endforeach; ?>
    </div>
    <div class="binder-body">
      <?php foreach ($monthGroups as $id => $bucket): ?>
        <section class="binder-panel<?= $defaultMonthId === $id ? ' is-active' : '' ?>" data-binder-panel="<?= e((string) $id) ?>" role="tabpanel"<?= $defaultMonthId === $id ? '' : ' hidden' ?>>
          <h2 class="binder-sub" style="margin-top:0"><?= e((string) ($bucket['label'] ?? '')) ?></h2>
          <?php foreach (($bucket['days'] ?? []) as $day): ?>
            <div class="appt-day-block">
              <h3 class="appt-day-title"><?= e((string) ($day['label'] ?? '')) ?></h3>
              <div class="stack">
                <?php foreach (($day['items'] ?? []) as $a): ?>
                  <?php $time = jalali_day_parts((string) $a['starts_at']); ?>
                  <div class="panel stack">
                    <div class="row-between">
                      <div>
                        <strong><?= e($a['patient_name']) ?></strong>
                        <div class="muted" style="font-size:.85rem"><?= e((string) ($a['phone'] ?: $a['email'])) ?></div>
                        <div style="margin-top:.35rem;font-size:.9rem">
                          ساعت <?= e($time['time_fa'] ?? format_fa_datetime((string) $a['starts_at'])) ?>
                        </div>
                        <?= staff_sign_html(['name' => $a['actor_name'] ?? '', 'username' => $a['actor_username'] ?? '']) ?>
                      </div>
                      <div style="font-size:.85rem">
                        <span class="badge"><?= e(appointment_status_label($a['status'])) ?></span>
                        <?php if ($a['amount']): ?>
                          <div class="muted" style="margin-top:.35rem"><?= e(format_price((int) $a['amount'])) ?> — <?= e(payment_status_label((string) $a['pay_status'])) ?></div>
                        <?php endif; ?>
                      </div>
                    </div>
                    <div style="display:flex;gap:.5rem;flex-wrap:wrap;align-items:center">
                      <?= staff_receipt_view_html($a['payment_id'] ?? null, $a['receipt_path'] ?? null, false) ?>
                      <a class="btn btn-outline btn-sm" href="<?= e(url('/doctor/patients/' . $a['patient_id'])) ?>">پرونده مراجعه‌کننده</a>
                      <?php if ($a['status'] !== 'CANCELLED'): ?>
                        <form method="post" action="<?= e(url('/doctor/appointments')) ?>">
                          <input type="hidden" name="id" value="<?= e($a['id']) ?>">
                          <input type="hidden" name="status" value="CANCELLED">
                          <button class="btn btn-danger btn-sm" type="submit">لغو</button>
                        </form>
                      <?php endif; ?>
                      <?php if ($a['status'] === 'CONFIRMED'): ?>
                        <form method="post" action="<?= e(url('/doctor/appointments')) ?>">
                          <input type="hidden" name="id" value="<?= e($a['id']) ?>">
                          <input type="hidden" name="status" value="COMPLETED">
                          <button class="btn btn-outline btn-sm" type="submit">انجام شد</button>
                        </form>
                      <?php endif; ?>
                    </div>
                  </div>
                <?php endforeach; ?>
              </div>
            </div>
          <?php endforeach; ?>
        </section>
      <?php endforeach; ?>
    </div>
  </div>
<?php endif; ?>
<?php
$pageScripts = '<script src="' . e(url('/assets/js/binder-tabs.js')) . '?v=20260905c"></script>';
render_doctor_page('نوبت‌ها', ob_get_clean());
