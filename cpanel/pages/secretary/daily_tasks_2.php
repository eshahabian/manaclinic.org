<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/secretary_panel.php';
require_once __DIR__ . '/../../includes/secretary_daily_tasks.php';

$user = require_login(['SECRETARY']);
$me = [
    'id' => (string) ($user['id'] ?? ''),
    'name' => (string) ($user['name'] ?? ''),
    'username' => (string) ($user['username'] ?? ''),
];

$reviewBase = '/secretary/daily-tasks-2';
$today = date('Y-m-d');
$ymd = trim((string) ($_GET['date'] ?? $today));
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $ymd) || $ymd > $today) {
    $ymd = $today;
}
$prev = date('Y-m-d', strtotime($ymd . ' -1 day') ?: time());
$next = date('Y-m-d', strtotime($ymd . ' +1 day') ?: time());
$editable = secretary_daily_task_can_edit($pdo, $me['id'], $ymd, true);

ob_start();
?>
<h1>کارهای روزانه</h1>
<p class="muted" style="margin-top:.35rem;line-height:1.8">فقط فهرست خودت را می‌بینی.</p>
<div style="display:flex;gap:.5rem;align-items:center;flex-wrap:wrap;margin-top:1rem">
  <a class="btn btn-outline btn-sm" href="<?= e(url($reviewBase . '?date=' . rawurlencode($prev))) ?>">روز قبل</a>
  <strong><?= e(secretary_daily_task_date_label($ymd)) ?></strong>
  <?php if ($next <= $today): ?>
    <a class="btn btn-outline btn-sm" href="<?= e(url($reviewBase . '?date=' . rawurlencode($next))) ?>">روز بعد</a>
  <?php endif; ?>
</div>
<div class="stack" style="margin-top:1rem">
  <?= secretary_daily_tasks_html($pdo, $me, $ymd, $editable, url('/secretary/daily-tasks')) ?>
</div>
<?php
render_secretary_page('کارهای روزانه ۲', ob_get_clean());
