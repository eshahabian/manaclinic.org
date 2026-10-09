<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/secretary_panel.php';
require_once __DIR__ . '/../../includes/secretary_daily_tasks.php';

$user = require_login(['SECRETARY']);
$userId = (string) ($user['id'] ?? '');
$today = date('Y-m-d');
$days = secretary_presence_days($pdo, $userId);
if (!in_array($today, $days, true)) {
    array_unshift($days, $today);
}
$ymd = secretary_daily_task_requested_date($pdo, $userId);
$editable = secretary_daily_task_can_edit($pdo, $userId, $ymd, true);

ob_start();
?>
<h1>وظایف</h1>
<p class="muted" style="margin-top:.35rem;line-height:1.8">پس از انجام هر کار، آن را علامت بزنید. اگر کاری امروز لازم نیست، «امروز نیاز نیست» را بزنید.</p>
<?php if (count($days) > 1): ?>
  <form method="get" action="<?= e(url('/secretary/daily-tasks')) ?>" style="margin-top:1rem">
    <label class="label" for="duty-date">روز</label>
    <select class="input" id="duty-date" name="date" onchange="this.form.submit()" style="max-width:18rem">
      <?php foreach ($days as $day): ?>
        <option value="<?= e($day) ?>"<?= $day === $ymd ? ' selected' : '' ?>><?= e(secretary_daily_task_date_label($day)) ?></option>
      <?php endforeach; ?>
    </select>
  </form>
<?php endif; ?>
<?php if (!$editable): ?>
  <p class="muted" style="margin-top:.75rem">این روز فقط برای دیدن است.</p>
<?php endif; ?>
<?= secretary_daily_tasks_table_html($pdo, $user, $ymd, $editable, url('/secretary/daily-tasks')) ?>
<?php
render_secretary_page('وظایف', ob_get_clean());
