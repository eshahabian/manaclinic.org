<?php
declare(strict_types=1);

$user = require_login(['PATIENT']);
require_once __DIR__ . '/../../includes/patient_panel.php';
require_once __DIR__ . '/../../includes/session_complaints.php';

$doctorName = '';
try {
    $stmt = $pdo->prepare('
      SELECT du.name
      FROM users u
      LEFT JOIN doctor_profiles dp ON dp.id = u.preferred_doctor_id
      LEFT JOIN users du ON du.id = dp.user_id
      WHERE u.id = ?
      LIMIT 1
    ');
    $stmt->execute([(string) ($user['id'] ?? '')]);
    $doctorName = trim((string) ($stmt->fetchColumn() ?: ''));
} catch (Throwable $e) {
    $doctorName = '';
}
$mine = [];
try {
    $mine = session_complaint_list($pdo, (string) ($user['id'] ?? ''));
} catch (Throwable $e) {
    $mine = [];
}

ob_start();
?>
<h1>انتقادات و پیشنهادات</h1>
<p class="muted">انتقاد یا پیشنهادتان برای دکتر شیوا گرانمایه‌پور و دکتر عطیه گارسچی فرستاده می‌شود. پایین صفحه فقط وضعیت را می‌بینید: هنوز دیده نشده، دیده شده و در حال پیگیری، یا پیگیری شد.</p>
<?= session_feedback_form_html(
    '/dashboard/feedback',
    false,
    [],
    trim((string) ($user['name'] ?? '')),
    trim((string) ($user['phone'] ?? '')),
    $doctorName
) ?>
<?= session_feedback_list_html($mine, 'هنوز موردی ثبت نکرده‌اید.') ?>
<?php
$GLOBALS['pageScripts'] = session_feedback_assets(false);
render_patient_page('انتقادات و پیشنهادات', ob_get_clean());
