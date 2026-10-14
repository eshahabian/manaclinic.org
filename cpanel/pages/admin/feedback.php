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
<p class="muted">همه موارد اینجاست. دکمه پیگیری و نتیجه آن فقط برای منشی، دکتر شیوا گرانمایه‌پور و دکتر عطیه گارسچی است.</p>
<?= session_feedback_list_html($rows, 'هنوز انتقاد یا پیشنهادی ثبت نشده است.') ?>
<?php
render_admin_page('انتقادات و پیشنهادات', ob_get_clean());
