<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/doctor_panel.php';
require_once __DIR__ . '/../../includes/session_complaints.php';

$user = require_login(['DOCTOR']);
if (!session_feedback_can_review($user)) {
    flash_set('error', 'انتقادات و پیشنهادات برای دکتر شیوا گرانمایه‌پور و دکتر عطیه گارسچی است.');
    redirect('/doctor/appointments');
}

$rows = [];
try {
    $rows = session_complaint_list($pdo);
} catch (Throwable $e) {
    $rows = [];
}

ob_start();
?>
<h1>انتقادات و پیشنهادات</h1>
<p class="muted">مواردی که مراجعه‌کننده یا منشی ثبت کرده اینجاست. زمان جلسه اگر خالی باشد یعنی اعلام نشده است.</p>
<?= session_feedback_list_html($rows, 'هنوز انتقاد یا پیشنهادی ثبت نشده است.', '/doctor/feedback', true) ?>
<?php
render_doctor_page('انتقادات و پیشنهادات', ob_get_clean());
