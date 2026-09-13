<?php
declare(strict_types=1);

$user = require_login(['PATIENT']);
require_once __DIR__ . '/../../includes/patient_panel.php';
require_once __DIR__ . '/../../includes/workshops.php';
require_once __DIR__ . '/../../includes/workshop_path.php';
require_once __DIR__ . '/../../includes/workshop_media.php';
require_once __DIR__ . '/../../includes/workshop_sessions.php';
require_once __DIR__ . '/../../includes/workshop_qa.php';

ensure_workshop_schema($pdo);
$enrollmentId = trim((string) ($_GET['enrollment'] ?? ''));
$ctx = workshop_path_load_patient($pdo, (string) ($user['id'] ?? ''), $enrollmentId);
if (!$ctx) {
    flash_set('error', 'مسیر این دوره در دسترس نیست. عضویت باید تأیید شده باشد.');
    redirect('/dashboard/workshops/mine');
}

$enrollment = is_array($ctx['enrollment'] ?? null) ? $ctx['enrollment'] : [];
$title = trim((string) ($enrollment['title'] ?? 'مسیر دوره'));
$offline = !empty($ctx['offline']) || workshop_is_offline((string) ($enrollment['type'] ?? ''));
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

$audioStreams = [];
$qaHtml = '';
if ($offline) {
    $sessions = workshop_sessions_with_media($pdo, (string) ($enrollment['workshop_id'] ?? ''));
    $ctx = workshop_path_attach_media($ctx, $sessions);
    $ctx['user'] = $user;
    $ctx['watermark'] = workshop_media_watermark_for_user($user, $pdo);
    foreach ($sessions as $session) {
        $audio = $session['files']['AUDIO'] ?? null;
        if (is_array($audio) && !empty($audio['id'])) {
            $audioStreams[(string) $audio['id']] = workshop_media_stream_url((string) $audio['id'], $user);
        }
    }
    $qaTab = trim((string) ($_GET['qa'] ?? '')) === 'private' ? 'private' : 'public';
    $workshopId = (string) ($enrollment['workshop_id'] ?? '');
    $pathBase = url('/dashboard/workshops/path?enrollment=' . rawurlencode((string) ($enrollment['id'] ?? '')));
    $qaHtml = workshop_qa_render(workshop_qa_list($pdo, $workshopId, [
        'viewer_id' => (string) ($user['id'] ?? ''),
        'private' => $qaTab === 'private',
    ]), [
        'post_url' => url('/dashboard/workshops/qa'),
        'workshop_id' => $workshopId,
        'enrollment_id' => (string) ($enrollment['id'] ?? ''),
        'can_post' => true,
        'viewer_id' => (string) ($user['id'] ?? ''),
        'tab' => $qaTab,
        'public_url' => $pathBase . '&qa=public#workshop-qa',
        'private_url' => $pathBase . '&qa=private#workshop-qa',
    ]);
}

$GLOBALS['pageRobots'] = 'noindex,nofollow';
$GLOBALS['pageTitle'] = ($offline ? 'دوره — ' : 'مسیر دوره — ') . $title;

ob_start();
?>
<div class="stack workshop-path-page<?= $offline ? ' offline-course-page' : '' ?>">
  <a href="<?= e($backUrl) ?>" style="font-size:.9rem;color:var(--primary)">← بازگشت به دوره‌های من</a>
  <h1><?= e($title) ?></h1>
  <?php if (!empty($enrollment['doctor_name'])): ?>
    <p class="muted" style="margin-top:.25rem">درمانگر: <?= e((string) $enrollment['doctor_name']) ?></p>
  <?php endif; ?>
  <?php if ($offline): ?>
    <p class="muted" style="margin-top:.35rem;line-height:1.7">محتوای دوره همین‌جا در پنل پخش می‌شود — دانلود و ضبط صفحه مجاز نیست. پایین صفحه بخش پرسش و پاسخ است.</p>
  <?php else: ?>
    <p class="muted" style="margin-top:.35rem;line-height:1.7">هر جلسه یک قدم از مسیر است. بعد از برگزاری همان روز می‌توانید برای خودتان بنویسید. یادداشت درمانگر فقط برای شماست.</p>
  <?php endif; ?>
  <?= workshop_path_render($ctx, 'patient') ?>
  <?= $qaHtml ?>
</div>
<?php
$inner = ob_get_clean();
if ($offline) {
    $GLOBALS['pageScripts'] = workshop_offline_protect_script($audioStreams);
}
render_patient_page(($offline ? 'دوره — ' : 'مسیر دوره — ') . $title, $inner);
