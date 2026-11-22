<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/doctor_panel.php';
require_once __DIR__ . '/../../includes/session_complaints.php';

$user = require_login(['DOCTOR']);
if (!session_feedback_can_review($user)) {
    flash_set('error', 'انتقادات و پیشنهادات برای دکتر شیوا گرانمایه‌پور و دکتر عطیه گارسچی است.');
    redirect('/doctor/appointments');
}

$canSubmit = session_feedback_can_submit_as_doctor($user);
$rows = [];
try {
    $rows = session_complaint_list($pdo, null, $user);
} catch (Throwable $e) {
    $rows = [];
}

ob_start();
?>
<h1>انتقادات و پیشنهادات</h1>
<?php if ($canSubmit): ?>
  <?= session_feedback_form_html('/doctor/feedback', false, [], (string) ($user['name'] ?? ''), '', '') ?>
<?php endif; ?>
<p class="muted">بازخوردهای ثبت‌شده و نتیجهٔ پیگیری آن‌ها را در فهرست زیر ببینید.</p>
<?= session_feedback_list_html($rows, 'هنوز انتقاد یا پیشنهادی ثبت نشده است.', '/doctor/feedback', true, $canSubmit) ?>
<?php
if ($canSubmit) {
    $GLOBALS['pageScripts'] = session_feedback_assets(false);
}
render_doctor_page('انتقادات و پیشنهادات', ob_get_clean());
