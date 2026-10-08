<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/secretary_daily_tasks.php';

/** @var PDO $pdo */
/** @var callable $renderReview */
$user = current_user();
if (!secretary_daily_tasks_can_review($user)) {
    flash_set('error', 'پیگیری کارهای روزانه منشی فقط برای دکتر شیوا گرانمایه‌پور، دکتر عطیه گارسچی و eshahabian است.');
    $role = (string) ($user['role'] ?? '');
    redirect($role === 'ADMIN' ? '/admin' : ($role === 'SECRETARY' ? '/secretary/messages' : '/doctor/notifications'));
}

$today = date('Y-m-d');
$ymd = trim((string) ($_GET['date'] ?? $today));
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $ymd) || $ymd > $today) {
    $ymd = $today;
}
$prev = date('Y-m-d', strtotime($ymd . ' -1 day') ?: time());
$next = date('Y-m-d', strtotime($ymd . ' +1 day') ?: time());
$secretaries = secretary_daily_task_secretaries($pdo);
$present = [];
$absent = [];
foreach ($secretaries as $sec) {
    $secId = (string) ($sec['id'] ?? '');
    $wasPresent = secretary_was_present($pdo, $secId, $ymd);
    $hasTick = false;
    if (!$wasPresent) {
        foreach (secretary_daily_task_states($pdo, $secId, $ymd) as $state) {
            if (!empty($state['done'])) {
                $hasTick = true;
                break;
            }
        }
    }
    if ($wasPresent || $hasTick) {
        $present[] = $sec;
    } else {
        $absent[] = $sec;
    }
}

ob_start();
?>
<h1><?= e((string) ($reviewTitle ?? 'وظایف منشی‌ها')) ?></h1>
<p class="muted" style="margin-top:.35rem;line-height:1.8">ساعت کنار هر وظیفه، لحظه‌ای است که منشی «انجام دادم» را زده. تیک را خود منشی می‌زند.</p>
<div style="display:flex;gap:.5rem;align-items:center;flex-wrap:wrap;margin-top:1rem">
  <a class="btn btn-outline btn-sm" href="<?= e(url((string) $reviewBase . '?date=' . rawurlencode($prev))) ?>">روز قبل</a>
  <strong><?= e(secretary_daily_task_date_label($ymd)) ?></strong>
  <?php if ($next <= $today): ?>
    <a class="btn btn-outline btn-sm" href="<?= e(url((string) $reviewBase . '?date=' . rawurlencode($next))) ?>">روز بعد</a>
  <?php endif; ?>
</div>
<?php if (!$present): ?>
  <p class="muted" style="margin-top:1rem">در این روز هیچ منشی‌ای حضور ثبت‌شده ندارد. فهرست وظایف:</p>
  <?= secretary_daily_tasks_table_html($pdo, ['id' => '', 'name' => '', 'username' => ''], $ymd, false, '', true) ?>
<?php else: ?>
  <?php foreach ($present as $sec): ?>
    <?= secretary_daily_tasks_table_html($pdo, $sec, $ymd, false, '', false) ?>
  <?php endforeach; ?>
<?php endif; ?>
<?php if ($absent): ?>
  <p class="muted" style="margin-top:1rem;font-size:.85rem">این روز حضور نداشته‌اند: <?= e(implode('، ', array_map(static fn (array $s): string => (string) ($s['name'] ?: $s['username']), $absent))) ?></p>
<?php endif; ?>
<?php
$html = ob_get_clean();
$renderReview((string) ($reviewTitle ?? 'وظایف منشی‌ها'), $html);
