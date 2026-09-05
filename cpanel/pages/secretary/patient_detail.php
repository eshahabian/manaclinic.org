<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/secretary_panel.php';

$user = require_login(['SECRETARY']);
$id = trim((string) ($_GET['id'] ?? ''));

$stmt = $pdo->prepare("SELECT id, name, username, phone FROM users WHERE id=? AND role='PATIENT' LIMIT 1");
$stmt->execute([$id]);
$patient = $stmt->fetch();
if (!$patient) {
    flash_set('error', 'مراجعه‌کننده یافت نشد.');
    redirect('/secretary/patients');
}

$apps = $pdo->prepare("
  SELECT a.id, a.starts_at, a.ends_at, a.status, a.notes,
         du.name AS doctor_name,
         cu.name AS actor_name, cu.username AS actor_username,
         p.id AS payment_id, p.amount, p.status AS pay_status, p.receipt_path,
         ru.name AS recorder_name, ru.username AS recorder_username
  FROM appointments a
  JOIN doctor_profiles dp ON dp.id = a.doctor_id
  JOIN users du ON du.id = dp.user_id
  LEFT JOIN users cu ON cu.id = a.created_by_user_id
  LEFT JOIN payments p ON p.appointment_id = a.id
  LEFT JOIN users ru ON ru.id = p.recorded_by_user_id
  WHERE a.patient_id = ?
  ORDER BY a.starts_at ASC
");
$apps->execute([$id]);
$appointments = $apps->fetchAll();

$enrolls = $pdo->prepare("
  SELECT e.id, e.status, e.enrolled_at, e.workshop_id,
         w.title AS workshop_title, w.type, w.starts_at,
         du.name AS doctor_name,
         wp.id AS payment_id, wp.amount, wp.status AS pay_status, wp.receipt_path, wp.ref_id,
         cu.name AS actor_name, cu.username AS actor_username,
         ru.name AS recorder_name, ru.username AS recorder_username
  FROM workshop_enrollments e
  JOIN workshops w ON w.id = e.workshop_id
  JOIN doctor_profiles dp ON dp.id = w.doctor_id
  JOIN users du ON du.id = dp.user_id
  LEFT JOIN workshop_payments wp ON wp.enrollment_id = e.id
  LEFT JOIN users cu ON cu.id = e.created_by_user_id
  LEFT JOIN users ru ON ru.id = wp.recorded_by_user_id
  WHERE e.patient_id = ?
    AND e.status IN ('PENDING_PAYMENT','CONFIRMED','COMPLETED')
  ORDER BY e.enrolled_at DESC
");
$enrolls->execute([$id]);
$enrollments = $enrolls->fetchAll();

$monthPack = group_appointments_by_jalali_month($appointments, false);
$monthGroups = attach_jalali_days_to_month_groups($monthPack['months']);
if ($monthGroups && !isset($monthGroups[$monthPack['default_id']])) {
    $keys = array_keys($monthGroups);
    $monthPack['default_id'] = (string) end($keys);
}
$defaultMonthId = isset($monthGroups[$monthPack['default_id']])
    ? (string) $monthPack['default_id']
    : (string) (array_key_first($monthGroups) ?? '');

$tab = trim((string) ($_GET['tab'] ?? 'appts'));
if (!in_array($tab, ['appts', 'workshops'], true)) {
    $tab = 'appts';
}
$phone = trim((string) ($patient['phone'] ?? ''));
$selfPath = '/secretary/patients/' . $id;

ob_start();
?>
<p class="panel-back">
  <a class="btn btn-outline btn-sm" href="<?= e(url('/secretary/patients')) ?>">بازگشت به فهرست</a>
</p>
<h1><?= e((string) $patient['name']) ?></h1>
<div class="panel stack" style="margin-top:1rem;max-width:36rem">
  <div>
    <div class="muted" style="font-size:.8rem">شماره تماس</div>
    <?php if ($phone !== ''): ?>
      <a href="tel:<?= e($phone) ?>" dir="ltr" style="font-size:1.2rem;font-weight:700;color:inherit"><?= e($phone) ?></a>
    <?php else: ?>
      <div>شماره ثبت نشده</div>
    <?php endif; ?>
  </div>
  <div class="muted" style="font-size:.85rem">نام کاربری: <span dir="ltr"><?= e((string) $patient['username']) ?></span></div>
</div>

<div class="binder-tile" data-binder-tabs data-binder-initial="<?= e($tab) ?>" data-binder-tone="<?= e($tab === 'workshops' ? 'workshops' : 'appts') ?>" style="margin-top:1.5rem">
  <div class="binder-tabs" role="tablist" aria-label="نوبت و کارگاه">
    <button type="button" class="binder-tab binder-tab-appts<?= $tab === 'appts' ? ' is-active' : '' ?>" role="tab" data-binder-tab="appts" data-binder-tone="appts" aria-selected="<?= $tab === 'appts' ? 'true' : 'false' ?>">
      نوبت‌ها <span class="binder-tab-count"><?= count($appointments) ?></span>
    </button>
    <button type="button" class="binder-tab binder-tab-workshops<?= $tab === 'workshops' ? ' is-active' : '' ?>" role="tab" data-binder-tab="workshops" data-binder-tone="workshops" aria-selected="<?= $tab === 'workshops' ? 'true' : 'false' ?>">
      کارگاه‌ها <span class="binder-tab-count"><?= count($enrollments) ?></span>
    </button>
  </div>
  <div class="binder-body">
    <section class="binder-panel<?= $tab === 'appts' ? ' is-active' : '' ?>" data-binder-panel="appts" role="tabpanel"<?= $tab === 'appts' ? '' : ' hidden' ?>>
      <?php if (!$monthGroups): ?>
        <p class="muted">هنوز نوبتی برای این مراجعه‌کننده ثبت نشده است.</p>
      <?php else: ?>
        <div class="binder-tile binder-tile--nested" data-binder-tabs data-binder-hash="0" data-binder-initial="<?= e($defaultMonthId) ?>" data-binder-tone="<?= e((string) ($monthGroups[$defaultMonthId]['tone'] ?? 'appts')) ?>">
          <div class="binder-tabs" role="tablist" aria-label="ماه نوبت‌ها">
            <?php foreach ($monthGroups as $mid => $bucket): ?>
              <button type="button"
                class="binder-tab <?= e((string) ($bucket['class'] ?? 'binder-tab-appts')) ?><?= $defaultMonthId === $mid ? ' is-active' : '' ?>"
                role="tab"
                data-binder-tab="<?= e((string) $mid) ?>"
                data-binder-tone="<?= e((string) ($bucket['tone'] ?? 'appts')) ?>"
                aria-selected="<?= $defaultMonthId === $mid ? 'true' : 'false' ?>">
                <?= e((string) ($bucket['tab_label'] ?? $bucket['short'] ?? $mid)) ?>
                <span class="binder-tab-count"><?= count($bucket['items'] ?? []) ?></span>
              </button>
            <?php endforeach; ?>
          </div>
          <div class="binder-body">
            <?php foreach ($monthGroups as $mid => $bucket): ?>
              <section class="binder-panel<?= $defaultMonthId === $mid ? ' is-active' : '' ?>" data-binder-panel="<?= e((string) $mid) ?>" role="tabpanel"<?= $defaultMonthId === $mid ? '' : ' hidden' ?>>
                <h2 class="binder-sub" style="margin-top:0">نوبت‌های <?= e((string) ($bucket['label'] ?? '')) ?></h2>
                <?php foreach (($bucket['days'] ?? []) as $day): ?>
                  <div class="appt-day-block">
                    <h3 class="appt-day-title"><?= e((string) ($day['label'] ?? '')) ?></h3>
                    <div class="stack">
                      <?php foreach (($day['items'] ?? []) as $a): ?>
                        <?php $time = jalali_day_parts((string) $a['starts_at']); ?>
                        <div class="panel stack">
                          <div class="row-between">
                            <div>
                              <strong>ساعت <?= e($time['time_fa'] ?? format_fa_datetime((string) $a['starts_at'])) ?></strong>
                              <div class="muted" style="font-size:.85rem;margin-top:.3rem">دکتر: <?= e((string) $a['doctor_name']) ?></div>
                              <?php if (!empty($a['actor_name']) || !empty($a['actor_username'])): ?>
                                <?= staff_sign_html(['name' => $a['actor_name'] ?? '', 'username' => $a['actor_username'] ?? ''], 'نوبت ثبت‌شده توسط') ?>
                              <?php endif; ?>
                              <?php if (!empty($a['recorder_name']) || !empty($a['recorder_username'])): ?>
                                <?= staff_sign_html(['name' => $a['recorder_name'] ?? '', 'username' => $a['recorder_username'] ?? ''], 'فیش بارگذاری‌شده توسط') ?>
                              <?php endif; ?>
                            </div>
                            <div style="text-align:left">
                              <span class="badge"><?= e(appointment_status_label((string) $a['status'])) ?></span>
                              <?php if ($a['amount'] !== null): ?>
                                <div class="muted" style="font-size:.8rem;margin-top:.35rem"><?= e(format_price((int) $a['amount'])) ?> — <?= e(payment_status_label((string) $a['pay_status'])) ?></div>
                              <?php endif; ?>
                            </div>
                          </div>
                          <div style="display:flex;gap:.5rem;flex-wrap:wrap;align-items:center">
                            <?= staff_receipt_view_html($a['payment_id'] ?? null, $a['receipt_path'] ?? null, true, $selfPath) ?>
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
      <p style="margin-top:1.25rem">
        <a class="btn btn-primary" href="<?= e(url('/secretary/appointments?tab=new')) ?>">ثبت نوبت جدید</a>
      </p>
    </section>

    <section class="binder-panel<?= $tab === 'workshops' ? ' is-active' : '' ?>" data-binder-panel="workshops" role="tabpanel"<?= $tab === 'workshops' ? '' : ' hidden' ?>>
      <?php if (!$enrollments): ?>
        <p class="muted">این مراجعه‌کننده در کارگاهی ثبت‌نام نکرده است.</p>
      <?php else: ?>
        <div class="stack">
          <?php foreach ($enrollments as $enr): ?>
            <?php $paid = (string) ($enr['pay_status'] ?? '') === 'PAID'; ?>
            <div class="panel stack">
              <div class="row-between">
                <div>
                  <strong><?= e((string) $enr['workshop_title']) ?></strong>
                  <div class="muted" style="font-size:.85rem;margin-top:.3rem">دکتر: <?= e((string) $enr['doctor_name']) ?> · <?= e(workshop_type_label((string) $enr['type'])) ?></div>
                  <div style="font-size:.85rem;margin-top:.25rem"><?= e(format_fa_datetime((string) $enr['enrolled_at'])) ?></div>
                  <?php if (!empty($enr['actor_name']) || !empty($enr['actor_username'])): ?>
                    <?= staff_sign_html(['name' => $enr['actor_name'] ?? '', 'username' => $enr['actor_username'] ?? ''], 'ثبت‌نام توسط') ?>
                  <?php else: ?>
                    <span class="staff-sign">ثبت‌نام آنلاین توسط مراجعه‌کننده</span>
                  <?php endif; ?>
                  <?php if ($paid && (!empty($enr['recorder_name']) || !empty($enr['recorder_username']))): ?>
                    <?= staff_sign_html(['name' => $enr['recorder_name'] ?? '', 'username' => $enr['recorder_username'] ?? ''], 'فیش بارگذاری‌شده توسط') ?>
                  <?php endif; ?>
                </div>
                <div style="text-align:left">
                  <span class="badge"><?= e(enrollment_status_label((string) $enr['status'])) ?></span>
                  <?php if ($enr['amount'] !== null): ?>
                    <div class="muted" style="font-size:.8rem;margin-top:.35rem"><?= e(format_price((int) $enr['amount'])) ?> — <?= $paid ? 'پرداخت شده' : 'بدون پرداخت' ?></div>
                  <?php endif; ?>
                </div>
              </div>
              <div style="display:flex;gap:.5rem;flex-wrap:wrap;align-items:center">
                <?php if ($paid && !empty($enr['payment_id']) && !empty($enr['receipt_path'])): ?>
                  <a class="btn btn-outline btn-sm" href="<?= e(url('/staff/receipt?id=' . $enr['payment_id'] . '&kind=workshop')) ?>" target="_blank" rel="noopener">مشاهده فیش</a>
                <?php endif; ?>
                <?php if (!$paid || empty($enr['receipt_path'])): ?>
                  <form class="staff-receipt-form" method="post" action="<?= e(url('/secretary/workshops')) ?>" enctype="multipart/form-data">
                    <input type="hidden" name="action" value="mark_paid">
                    <input type="hidden" name="enrollment_id" value="<?= e((string) $enr['id']) ?>">
                    <input type="hidden" name="next" value="<?= e($selfPath . '?tab=workshops') ?>">
                    <label class="btn <?= $paid ? 'btn-outline' : 'btn-primary' ?> btn-sm staff-receipt-pick">
                      <?= $paid ? 'بارگذاری فیش' : 'پرداخت شده — بارگذاری فیش' ?>
                      <input type="file" name="receipt" accept="image/jpeg,image/png,image/webp,application/pdf" required onchange="this.form.submit()">
                    </label>
                  </form>
                <?php endif; ?>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </section>
  </div>
</div>
<?php
$inner = ob_get_clean();
$pageScripts = '<script src="' . e(url('/assets/js/binder-tabs.js')) . '?v=20260906f"></script>';
render_secretary_page((string) $patient['name'], $inner);
