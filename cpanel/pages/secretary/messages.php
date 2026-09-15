<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/secretary_panel.php';
require_once __DIR__ . '/../../includes/admin_staff_messages.php';

$user = require_login(['SECRETARY']);
ensure_workshop_schema($pdo);

$msgTab = trim((string) ($_GET['msg'] ?? 'appointment'));
if (!in_array($msgTab, ['appointment', 'workshop', 'colleague', 'patients', 'admin'], true)) {
    $msgTab = 'appointment';
}

$notifications = fetch_notifications($pdo, (string) $user['id'], 80);
$recentAppointments = secretary_recent_shared_appointments($pdo, 40);
$recentEnrollments = secretary_recent_shared_enrollments($pdo, 40);
$unreadCount = secretary_unread_desk_count($notifications);
$colleague = [
    'peers' => handover_other_secretaries($pdo, (string) $user['id']),
    'inbox' => handover_inbox_for($pdo, (string) $user['id'], 30),
    'sent' => handover_sent_grouped($pdo, (string) $user['id'], 20),
];
$patients = $pdo->query("
  SELECT id, name, username, phone
  FROM users
  WHERE role = 'PATIENT'
  ORDER BY name ASC
")->fetchAll();
$adminMessages = admin_staff_msg_inbox_for($pdo, (string) $user['id'], 40);

ob_start();
?>
<h1>پیام‌ها</h1>
<p class="muted" style="margin-top:.35rem;font-size:.9rem">
  نوبت‌ها، کارگاه‌ها، پیام مدیر، پیام همکار و ارسال به مراجع.<?= $unreadCount ? ' · ' . $unreadCount . ' پیام خوانده‌نشده' : '' ?>
</p>
<?= render_secretary_messages_panel(
    $notifications,
    url('/secretary/notifications/read'),
    $recentAppointments,
    $recentEnrollments,
    $msgTab,
    '/secretary/messages',
    $colleague,
    $patients,
    $adminMessages
) ?>
<?php
$titles = [
    'workshop' => 'پیام‌های کارگاه',
    'colleague' => 'پیام همکار',
    'patients' => 'ارسال به مراجع',
    'admin' => 'پیام مدیر',
    'appointment' => 'پیام‌های نوبت',
];
$pageScripts = '<script src="' . e(url('/assets/js/rich-editor.js')) . '?v=20260916e"></script>
<script>
(function(){
  if (window.initRichEditors) { window.initRichEditors(document); }
  var form = document.getElementById("secretary-handover-form");
  if (!form) return;
  var editor = document.getElementById("handover-editor");
  var hidden = document.getElementById("handover-body");
  form.addEventListener("submit", function(e){
    if (editor && hidden) { hidden.value = editor.innerHTML; }
    var plain = (hidden && hidden.value ? hidden.value.replace(/<[^>]+>/g, " ").replace(/&nbsp;/g, " ").trim() : "");
    if (!plain) {
      e.preventDefault();
      alert("متن پیام را بنویسید.");
      if (editor) editor.focus();
    }
  });
})();
</script>';
$GLOBALS['pageScripts'] = $pageScripts;
render_secretary_page($titles[$msgTab] ?? 'پیام‌ها', ob_get_clean());
