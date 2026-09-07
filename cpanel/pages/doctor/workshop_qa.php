<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/doctor_panel.php';
require_once __DIR__ . '/../../includes/workshops.php';
require_once __DIR__ . '/../../includes/workshop_qa.php';

$ctx = require_doctor_profile($pdo);
ensure_workshop_schema($pdo);
$workshopId = trim((string) ($_GET['id'] ?? ''));
if ($workshopId === '' || !workshop_qa_doctor_owns($pdo, (string) ($ctx['profile']['id'] ?? ''), $workshopId)) {
    flash_set('error', 'پرسش و پاسخ این دوره در دسترس نیست.');
    redirect('/doctor/workshops');
}

$stmt = $pdo->prepare('SELECT title FROM workshops WHERE id=? LIMIT 1');
$stmt->execute([$workshopId]);
$title = trim((string) ($stmt->fetchColumn() ?: 'دوره آفلاین'));
$threads = workshop_qa_list($pdo, $workshopId);
$GLOBALS['pageRobots'] = 'noindex,nofollow';

ob_start();
?>
<div class="stack workshop-path-page">
  <a href="<?= e(url('/doctor/workshops')) ?>" style="font-size:.9rem;color:var(--primary)">← بازگشت به کارگاه‌ها</a>
  <h1>پرسش و پاسخ — <?= e($title) ?></h1>
  <p class="muted" style="margin-top:.35rem;line-height:1.7">این بخش همگانی است؛ همهٔ شرکت‌کننده‌های دوره آفلاین سؤال و پاسخ را می‌بینند.</p>
  <?= workshop_qa_render($threads, [
      'post_url' => url('/doctor/workshops/qa'),
      'workshop_id' => $workshopId,
      'can_post' => true,
      'ask_label' => 'پیام برای همه شرکت‌کننده‌ها',
      'reply_label' => 'پاسخ درمانگر',
  ]) ?>
</div>
<?php
render_doctor_page('پرسش و پاسخ — ' . $title, ob_get_clean());
