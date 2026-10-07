<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/secretary_panel.php';
require_once __DIR__ . '/../../includes/secretary_daily_tasks.php';

$user = require_login(['SECRETARY']);
$userId = (string) ($user['id'] ?? '');
$today = date('Y-m-d');
$ymd = trim((string) ($_GET['date'] ?? $today));
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $ymd) || $ymd > $today) {
    $ymd = $today;
}
$prev = date('Y-m-d', strtotime($ymd . ' -1 day') ?: time());
$next = date('Y-m-d', strtotime($ymd . ' +1 day') ?: time());
$wasPresent = $ymd === $today || secretary_was_present($pdo, $userId, $ymd);
$editable = secretary_daily_task_can_edit($pdo, $userId, $ymd, true);

ob_start();
?>
<h1>کارهای روزانه</h1>
<p class="muted" style="margin-top:.35rem;line-height:1.8">فقط فهرست خودتان اینجاست. تیک‌ها را خودتان می‌زنید.</p>
<div class="daywork-nav">
  <a class="btn btn-outline btn-sm" href="<?= e(url('/secretary/daily-tasks?date=' . rawurlencode($prev))) ?>">روز قبل</a>
  <strong><?= e(secretary_daily_task_date_label($ymd)) ?></strong>
  <?php if ($next <= $today): ?>
    <a class="btn btn-outline btn-sm" href="<?= e(url('/secretary/daily-tasks?date=' . rawurlencode($next))) ?>">روز بعد</a>
  <?php endif; ?>
</div>
<?php if (!$wasPresent): ?>
  <p class="muted" style="margin-top:1rem">در این روز حضور ثبت‌شده‌ای برای شما نیست.</p>
<?php else: ?>
  <?= secretary_daily_tasks_own_html($pdo, $user, $ymd, $editable, url('/secretary/daily-tasks')) ?>
<?php endif; ?>
<?php
render_secretary_page('کارهای روزانه', ob_get_clean());
