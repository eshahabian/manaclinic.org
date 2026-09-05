<?php
declare(strict_types=1);
require_once __DIR__ . '/../../includes/doctor_panel.php';

$ctx = require_doctor_profile($pdo);
staff_close_stale_shifts($pdo);
ensure_secretary_day_reports($pdo);

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
      ORDER BY started_at ASC
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
      LIMIT 80
    ");
    $hist->execute([$uid]);
    $histRows = $hist->fetchAll();
    $reports = $pdo->prepare("
      SELECT report_date, body, updated_at
      FROM secretary_day_reports
      WHERE user_id=?
      ORDER BY report_date DESC
      LIMIT 14
    ");
    $reports->execute([$uid]);
    $reportRows = $reports->fetchAll();
    $reportsByDate = [];
    foreach ($reportRows as $rep) {
        $reportsByDate[(string) $rep['report_date']] = $rep;
    }
    $byUser[] = [
        'user' => $sec,
        'open' => $open,
        'today_seconds' => $todaySeconds,
        'today_rows' => $todayRows,
        'days' => staff_shifts_grouped_by_day($histRows),
        'reports' => $reportRows,
        'reports_by_date' => $reportsByDate,
    ];
}

ob_start();
?>
<h1>ساعت کاری منشی‌ها</h1>
<p class="muted">هر ورود در خط جداست. اگر منشی ۱۰ دقیقه فعال نباشد خارج می‌شود و ورود بعدی جدا دیده می‌شود.</p>

<?php foreach ($byUser as $block): ?>
  <?php $sec = $block['user']; ?>
  <div class="panel stack" style="margin-top:1.25rem">
    <div class="row-between">
      <div>
        <strong><?= e(staff_actor_label($sec)) ?></strong>
        <div class="muted" style="font-size:.85rem;margin-top:.25rem">
          امروز: <?= e(staff_format_duration((int) $block['today_seconds'])) ?>
          · <?= e(to_fa_digits((string) count($block['today_rows']))) ?> بار ورود
        </div>
      </div>
      <?php if ($block['open']): ?>
        <span class="badge">آنلاین از <?= e(format_fa_datetime((string) $block['open']['started_at'])) ?></span>
      <?php else: ?>
        <span class="badge">آفلاین</span>
      <?php endif; ?>
    </div>

    <?php if ($block['today_rows']): ?>
      <div>
        <h3 class="appt-day-title" style="margin-bottom:.45rem">ورودهای امروز</h3>
        <ol class="staff-shift-lines">
          <?php foreach ($block['today_rows'] as $i => $row): ?>
            <li>
              <strong>ورود <?= e(to_fa_digits((string) ($i + 1))) ?></strong>
              از <?= e(format_fa_datetime((string) $row['started_at'])) ?>
              تا <?= !empty($row['ended_at']) ? e(format_fa_datetime((string) $row['ended_at'])) : 'الان' ?>
              · <?= e(staff_format_duration(staff_shift_seconds($row))) ?>
              · <?= e(staff_shift_reason_label($row['end_reason'] ?? null)) ?>
            </li>
          <?php endforeach; ?>
        </ol>
      </div>
    <?php endif; ?>

    <?php
      $todayReport = $block['reports_by_date'][date('Y-m-d')] ?? null;
    ?>
    <?php if ($todayReport): ?>
      <div class="staff-day-report">
        <strong>گزارش پایان امروز</strong>
        <p><?= nl2br(e((string) $todayReport['body'])) ?></p>
      </div>
    <?php endif; ?>

    <?php if (!$block['days']): ?>
      <p class="muted">هنوز سابقه‌ای برای این منشی نیست.</p>
    <?php else: ?>
      <?php foreach ($block['days'] as $day): ?>
        <div class="staff-day-block">
          <h3 class="appt-day-title">
            <?= e((string) $day['label']) ?>
            <span class="muted" style="font-weight:400;font-size:.85rem"> · <?= e(to_fa_digits((string) count($day['items']))) ?> بار ورود</span>
          </h3>
          <ol class="staff-shift-lines">
            <?php foreach ($day['items'] as $i => $row): ?>
              <li>
                ورود <?= e(to_fa_digits((string) ($i + 1))) ?>:
                <?= e(format_fa_datetime((string) $row['started_at'])) ?>
                تا <?= !empty($row['ended_at']) ? e(format_fa_datetime((string) $row['ended_at'])) : '— هنوز باز' ?>
                · <?= e(staff_format_duration(staff_shift_seconds($row))) ?>
                · <?= e(staff_shift_reason_label($row['end_reason'] ?? null)) ?>
              </li>
            <?php endforeach; ?>
          </ol>
          <?php
            $dayReport = $block['reports_by_date'][(string) ($day['date'] ?? '')] ?? null;
          ?>
          <?php if ($dayReport && (string) ($day['date'] ?? '') !== date('Y-m-d')): ?>
            <div class="staff-day-report">
              <strong>گزارش کار</strong>
              <p><?= nl2br(e((string) $dayReport['body'])) ?></p>
            </div>
          <?php endif; ?>
        </div>
      <?php endforeach; ?>
    <?php endif; ?>
  </div>
<?php endforeach; ?>
<?php if (!$byUser): ?>
  <p class="muted" style="margin-top:1rem">حساب منشی‌ای پیدا نشد.</p>
<?php endif; ?>
<?php
render_doctor_page('ساعت کاری منشی‌ها', ob_get_clean());
