<?php
declare(strict_types=1);
require_once __DIR__ . '/../../includes/secretary_panel.php';

$user = require_login(['SECRETARY']);
$shift = staff_current_shift($pdo, (string) $user['id']);

$today = $pdo->prepare("
  SELECT * FROM staff_shifts
  WHERE user_id=? AND DATE(started_at)=CURDATE()
  ORDER BY started_at DESC
");
$today->execute([$user['id']]);
$todayRows = $today->fetchAll();

$history = $pdo->prepare("
  SELECT * FROM staff_shifts
  WHERE user_id=?
  ORDER BY started_at DESC
  LIMIT 40
");
$history->execute([$user['id']]);
$historyRows = $history->fetchAll();

$todaySeconds = 0;
foreach ($todayRows as $row) {
    $todaySeconds += staff_shift_seconds($row);
}

ob_start();
?>
<h1>ساعت کاری من</h1>
<p class="muted">از لحظه ورود تا خروج (یا قطع به‌خاطر ۱۰ دقیقه بی‌فعالیتی) محاسبه می‌شود.</p>

<div class="grid-2" style="margin-top:1rem">
  <div class="panel stack">
    <strong>شیفت فعلی</strong>
    <?php if ($shift): ?>
      <div>ورود: <?= e(format_fa_datetime((string) $shift['started_at'])) ?></div>
      <div>مدت حضور: <?= e(staff_format_duration(staff_shift_seconds($shift))) ?></div>
    <?php else: ?>
      <p class="muted">شیفت بازی نیست.</p>
    <?php endif; ?>
  </div>
  <div class="panel stack">
    <strong>جمع امروز</strong>
    <div><?= e(staff_format_duration($todaySeconds)) ?></div>
  </div>
</div>

<div class="panel" style="margin-top:1.25rem">
  <h2 style="margin:0 0 .75rem;font-size:1.05rem">سابقه ورود و خروج</h2>
  <?php if (!$historyRows): ?>
    <p class="muted">هنوز سابقه‌ای نیست.</p>
  <?php else: ?>
    <table class="staff-hours-table">
      <thead>
        <tr>
          <th>ورود</th>
          <th>خروج</th>
          <th>مدت</th>
          <th>وضعیت</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($historyRows as $row): ?>
          <tr>
            <td><?= e(format_fa_datetime((string) $row['started_at'])) ?></td>
            <td><?= !empty($row['ended_at']) ? e(format_fa_datetime((string) $row['ended_at'])) : '— هنوز باز' ?></td>
            <td><?= e(staff_format_duration(staff_shift_seconds($row))) ?></td>
            <td><?= e(staff_shift_reason_label($row['end_reason'] ?? null)) ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
</div>
<?php
render_secretary_page('ساعت کاری', ob_get_clean());
