<?php
declare(strict_types=1);
require_once __DIR__ . '/../../includes/doctor_panel.php';
require_once __DIR__ . '/../../includes/doctor_clinical.php';

$ctx = require_doctor_profile($pdo);
ensure_doctor_clinical_tables($pdo);
$doctorId = $ctx['profile']['id'];

$stmt = $pdo->prepare("
  SELECT
    u.id,
    u.name,
    u.username,
    u.phone,
    COUNT(a.id) AS visit_count,
    MAX(a.starts_at) AS last_visit
  FROM users u
  LEFT JOIN appointments a ON a.patient_id = u.id AND a.doctor_id = ?
  WHERE u.role = 'PATIENT'
    AND (
      u.preferred_doctor_id = ?
      OR EXISTS (
        SELECT 1 FROM appointments ax
        WHERE ax.patient_id = u.id AND ax.doctor_id = ?
      )
    )
  GROUP BY u.id, u.name, u.username, u.phone
  ORDER BY last_visit IS NULL ASC, last_visit DESC, u.name ASC
");
$stmt->execute([$doctorId, $doctorId, $doctorId]);
$patients = $stmt->fetchAll();

$noteCounts = [];
$nc = $pdo->prepare('SELECT patient_id, COUNT(*) AS c FROM doctor_session_notes WHERE doctor_id=? GROUP BY patient_id');
$nc->execute([$doctorId]);
foreach ($nc->fetchAll() as $row) {
    $noteCounts[$row['patient_id']] = (int) $row['c'];
}

ob_start();
?>
<h1>پرونده مراجعه‌کنندگان</h1>
<p class="muted">این بخش کاملاً خصوصی است؛ فقط شما می‌توانید شرح حال، یادداشت جلسات و هایلایت‌ها را ببینید.</p>

<div class="stack" style="margin-top:1rem">
<?php foreach ($patients as $p): ?>
  <a class="panel row-between" href="<?= e(url('/doctor/patients/' . $p['id'])) ?>" style="color:inherit">
    <div>
      <strong><?= e($p['name']) ?></strong>
      <div class="muted" style="font-size:.85rem" dir="ltr"><?= e((string)$p['username']) ?><?= $p['phone'] ? ' · ' . e((string)$p['phone']) : '' ?></div>
      <div style="font-size:.85rem;margin-top:.35rem">
        <?= (int)$p['visit_count'] ?> نوبت
        <?php if ($p['last_visit']): ?>
          · آخرین: <?= e(format_fa_datetime($p['last_visit'])) ?>
        <?php endif; ?>
        <?php if (!empty($noteCounts[$p['id']])): ?>
          · <?= (int)$noteCounts[$p['id']] ?> یادداشت جلسه
        <?php endif; ?>
      </div>
    </div>
    <span class="btn btn-outline btn-sm">مشاهده پرونده</span>
  </a>
<?php endforeach; ?>
<?php if (!$patients): ?>
  <p class="muted">هنوز مراجعه‌کننده اختصاص‌یافته یا نوبت‌داری برای شما ثبت نشده است.</p>
<?php endif; ?>
</div>
<?php
render_doctor_page('پرونده مراجعه‌کنندگان', ob_get_clean());
