<?php
declare(strict_types=1);
require_once __DIR__ . '/../../includes/doctor_panel.php';

$ctx = require_doctor_profile($pdo);
staff_close_stale_shifts($pdo);

$secretaries = $pdo->query("
  SELECT id, name, username
  FROM users
  WHERE role='SECRETARY'
  ORDER BY username ASC
")->fetchAll();

$byUser = [];
foreach ($secretaries as $sec) {
    $uid = (string) $sec['id'];
    $open = staff_current_shift($pdo, $uid);
    $today = $pdo->prepare("
      SELECT * FROM staff_shifts
      WHERE user_id=? AND DATE(started_at)=CURDATE()
      ORDER BY started_at DESC
    ");
    $today->execute([$uid]);
    $todayRows = $today->fetchAll();
    $todaySeconds = 0;
    foreach ($todayRows as $row) {
        $todaySeconds += staff_shift_seconds($row);
    }
    $hist = $pdo->prepare("
      SELECT * FROM staff_shifts
      WHERE user_id=?
      ORDER BY started_at DESC
      LIMIT 25
    ");
    $hist->execute([$uid]);
    $byUser[] = [
        'user' => $sec,
        'open' => $open,
        'today_seconds' => $todaySeconds,
        'rows' => $hist->fetchAll(),
    ];
}

ob_start();
?>
<h1>ساعت کاری منشی‌ها</h1>
<p class="muted">ورود، خروج و مدت حضور هر منشی جداگانه ثبت می‌شود. اگر ۱۰ دقیقه فعالیت نباشد شیفت قطع می‌شود.</p>

<?php foreach ($byUser as $block): ?>
  <?php $sec = $block['user']; ?>
  <div class="panel stack" style="margin-top:1.25rem">
    <div class="row-between">
      <div>
        <strong><?= e(staff_actor_label($sec)) ?></strong>
        <div class="muted" style="font-size:.85rem;margin-top:.25rem">
          امروز: <?= e(staff_format_duration((int) $block['today_seconds'])) ?>
        </div>
      </div>
      <?php if ($block['open']): ?>
        <span class="badge">آنلاین از <?= e(to_fa_digits(date('H:i', strtotime((string) $block['open']['started_at']) ?: time()))) ?></span>
      <?php else: ?>
        <span class="badge">آفلاین</span>
      <?php endif; ?>
    </div>
    <?php if (!$block['rows']): ?>
      <p class="muted">هنوز سابقه‌ای برای این منشی نیست.</p>
    <?php else: ?>
      <table class="staff-hours-table">
        <thead>
          <tr>
            <th>ورود</th>
            <th>خروج</th>
            <th>مدت حضور</th>
            <th>وضعیت</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($block['rows'] as $row): ?>
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
<?php endforeach; ?>
<?php if (!$byUser): ?>
  <p class="muted" style="margin-top:1rem">حساب منشی‌ای پیدا نشد.</p>
<?php endif; ?>
<?php
render_doctor_page('ساعت کاری منشی‌ها', ob_get_clean());
