<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/secretary_panel.php';
require_once __DIR__ . '/../../includes/secretary_patient.php';
require_once __DIR__ . '/../../includes/session_complaints.php';

require_login(['SECRETARY']);

$patients = [];
$complaints = [];
try {
    $patients = secretary_bookable_patients($pdo);
} catch (Throwable $e) {
    $patients = [];
}
try {
    $complaints = session_complaint_list($pdo);
} catch (Throwable $e) {
    $complaints = [];
}

ob_start();
?>
<h1>انتقادات و پیشنهادات</h1>
<p class="muted">این فرم را خود مراجعه‌کننده یا منشی پر می‌کند. مورد ثبت‌شده برای دکتر شیوا گرانمایه‌پور و دکتر عطیه گارسچی می‌رود.</p>
<?= session_feedback_form_html('/secretary/complaints', true, $patients, '', '', '') ?>
<?= session_feedback_list_html($complaints, 'هنوز انتقاد یا پیشنهادی نوشته نشده است.') ?>
<?php
$GLOBALS['pageScripts'] = session_feedback_assets(true);
render_secretary_page('انتقادات و پیشنهادات', ob_get_clean());
