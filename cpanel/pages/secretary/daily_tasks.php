<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/secretary_panel.php';
require_once __DIR__ . '/../../includes/secretary_daily_tasks.php';

$user = require_login(['SECRETARY']);
$userId = (string) ($user['id'] ?? '');
$today = date('Y-m-d');
$requested = trim((string) ($_GET['date'] ?? $today));
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $requested) || $requested > $today) {
    $requested = $today;
}
$presence = secretary_presence_days($pdo, $userId);
if (!in_array($today, $presence, true)) {
    array_unshift($presence, $today);
}
if (!in_array($requested, $presence, true) && $requested !== $today) {
    $requested = $today;
}
$editable = secretary_daily_task_can_edit($pdo, $userId, $requested, true);
$present = $requested === $today || secretary_was_present($pdo, $userId, $requested);

ob_start();
?>
<h1>لیست انجام کارهای روزانه</h1>
<p class="muted" style="margin-top:.35rem;line-height:1.8">هر روزی که در کلینیک حضور دارید این فهرست را تیک بزنید. نام، روز و عنوان ثابت‌اند؛ بقیه کارها با تیک «انجام شد» می‌شوند.</p>
<?php if (count($presence) > 1): ?>
  <form method="get" action="<?= e(url('/secretary/daily-tasks')) ?>" style="margin-top:1rem;display:flex;gap:.5rem;align-items:end;flex-wrap:wrap">
    <div>
      <label class="label" for="sec-daily-date">روز حضور</label>
      <select class="input" id="sec-daily-date" name="date" onchange="this.form.submit()">
        <?php foreach ($presence as $day): ?>
          <option value="<?= e($day) ?>"<?= $day === $requested ? ' selected' : '' ?>><?= e(secretary_daily_task_date_label($day)) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
  </form>
<?php endif; ?>
<?php if (!$present && $requested !== $today): ?>
  <p class="muted" style="margin-top:1rem">برای این روز حضوری در کلینیک ثبت نشده است.</p>
<?php else: ?>
  <div style="margin-top:1rem">
    <?= secretary_daily_tasks_html($pdo, $user, $requested, $editable && $present, url('/secretary/daily-tasks')) ?>
  </div>
<?php endif; ?>
<?php
render_secretary_page('کارهای روزانه', ob_get_clean());
