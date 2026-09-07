<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/doctor_panel.php';
require_once __DIR__ . '/../../includes/workshops.php';
require_once __DIR__ . '/../../includes/workshop_qa.php';

$ctx = require_doctor_profile($pdo);
ensure_workshop_schema($pdo);
$workshopId = trim((string) ($_GET['id'] ?? ''));
if ($workshopId === '' || !workshop_qa_doctor_owns($pdo, (string) ($ctx['profile']['id'] ?? ''), $workshopId)) {
    flash_set('error', 'تالار این دوره در دسترس نیست.');
    redirect('/doctor/workshops');
}

$stmt = $pdo->prepare('SELECT title FROM workshops WHERE id=? LIMIT 1');
$stmt->execute([$workshopId]);
$title = trim((string) ($stmt->fetchColumn() ?: 'دوره آفلاین'));
$qaTab = trim((string) ($_GET['qa'] ?? '')) === 'private' ? 'private' : 'public';
$qaBase = url('/doctor/workshops/qa?id=' . rawurlencode($workshopId));
$messages = workshop_qa_list($pdo, $workshopId, [
    'viewer_id' => (string) ($ctx['user']['id'] ?? ''),
    'is_doctor' => true,
    'private' => $qaTab === 'private',
]);
$GLOBALS['pageRobots'] = 'noindex,nofollow';

ob_start();
?>
<div class="stack workshop-path-page">
  <a href="<?= e(url('/doctor/workshops')) ?>" style="font-size:.9rem;color:var(--primary)">← بازگشت به کارگاه‌ها</a>
  <h1>تالار گفتگو — <?= e($title) ?></h1>
  <p class="muted" style="margin-top:.35rem;line-height:1.7">همان چت همگانی شرکت‌کننده‌هاست. با نام خودتان جواب بدهید؛ پیام خصوصی جدا از تالار است.</p>
  <?= workshop_qa_render($messages, [
      'post_url' => url('/doctor/workshops/qa'),
      'workshop_id' => $workshopId,
      'can_post' => true,
      'viewer_id' => (string) ($ctx['user']['id'] ?? ''),
      'is_doctor' => true,
      'tab' => $qaTab,
      'public_url' => $qaBase . '&qa=public#workshop-qa',
      'private_url' => $qaBase . '&qa=private#workshop-qa',
  ]) ?>
</div>
<?php
render_doctor_page('تالار گفتگو — ' . $title, ob_get_clean());
