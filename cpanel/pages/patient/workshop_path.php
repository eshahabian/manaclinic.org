<?php
declare(strict_types=1);

$user = require_login(['PATIENT']);
require_once __DIR__ . '/../../includes/patient_panel.php';
require_once __DIR__ . '/../../includes/workshops.php';
require_once __DIR__ . '/../../includes/workshop_path.php';

ensure_workshop_schema($pdo);
$enrollmentId = trim((string) ($_GET['enrollment'] ?? ''));
$ctx = workshop_path_load_patient($pdo, (string) ($user['id'] ?? ''), $enrollmentId);
if (!$ctx) {
    flash_set('error', 'مسیر این دوره در دسترس نیست. عضویت باید تأیید شده باشد.');
    redirect('/dashboard/workshops/mine');
}

$enrollment = is_array($ctx['enrollment'] ?? null) ? $ctx['enrollment'] : [];
$title = trim((string) ($enrollment['title'] ?? 'مسیر دوره'));
$archived = function_exists('workshop_is_archived') && workshop_is_archived([
    'status' => (string) ($enrollment['workshop_status'] ?? ''),
    'type' => (string) ($enrollment['type'] ?? ''),
    'ends_at' => (string) ($enrollment['ends_at'] ?? ''),
]);
$backTab = $archived
    ? 'archive'
    : (function_exists('workshop_courses_tab_for_type')
        ? workshop_courses_tab_for_type((string) ($enrollment['type'] ?? ''))
        : 'in-person');
$backUrl = url('/dashboard/workshops/mine?type=' . $backTab);
$ctx['post_url'] = url('/dashboard/workshops/path-note');

$GLOBALS['pageRobots'] = 'noindex,nofollow';
$GLOBALS['pageTitle'] = 'مسیر دوره — ' . $title;

ob_start();
?>
<div class="stack workshop-path-page">
  <a href="<?= e($backUrl) ?>" style="font-size:.9rem;color:var(--primary)">← بازگشت به دوره‌های من</a>
  <h1><?= e($title) ?></h1>
  <?php if (!empty($enrollment['doctor_name'])): ?>
    <p class="muted" style="margin-top:.25rem">درمانگر: <?= e((string) $enrollment['doctor_name']) ?></p>
  <?php endif; ?>
  <p class="muted" style="margin-top:.35rem;line-height:1.7">هر جلسه یک قدم از مسیر است. بعد از برگزاری همان روز می‌توانید برای خودتان بنویسید. یادداشت درمانگر فقط برای شماست.</p>
  <?= workshop_path_render($ctx, 'patient') ?>
</div>
<?php
render_patient_page('مسیر دوره — ' . $title, ob_get_clean());
