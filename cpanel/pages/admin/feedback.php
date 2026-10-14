<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/admin_panel.php';
require_once __DIR__ . '/../../includes/session_complaints.php';

$user = require_login(['ADMIN']);

$rows = [];
try {
    $rows = session_complaint_list($pdo);
} catch (Throwable $e) {
    $rows = [];
}

ob_start();
?>
<h1>انتقادات و پیشنهادات</h1>
<p class="muted">همه مواردی که مراجعه‌کننده یا منشی ثبت کرده اینجاست. روی هر مورد نوشته شده «در حال پیگیری» است یا «پیگیری شد».</p>
<?= session_feedback_list_html($rows, 'هنوز انتقاد یا پیشنهادی ثبت نشده است.', '/admin/feedback', true) ?>
<?php
render_admin_page('انتقادات و پیشنهادات', ob_get_clean());
