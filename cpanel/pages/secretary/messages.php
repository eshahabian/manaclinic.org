<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/secretary_panel.php';

$user = require_login(['SECRETARY']);
ensure_workshop_schema($pdo);

$msgTab = trim((string) ($_GET['msg'] ?? 'appointment'));
if ($msgTab === 'colleague') {
    redirect('/secretary/colleague-messages');
}
if ($msgTab === 'admin') {
    redirect('/secretary/profile#admin-site-messages');
}
if (!in_array($msgTab, ['appointment', 'workshop', 'patients'], true)) {
    $msgTab = 'appointment';
}

$notifications = fetch_notifications($pdo, (string) $user['id'], 80);
$recentAppointments = secretary_recent_shared_appointments($pdo, 40);
$recentEnrollments = secretary_recent_shared_enrollments($pdo, 40);
$unreadCount = secretary_unread_desk_count($notifications);
$patients = $pdo->query("
  SELECT id, name, username, phone
  FROM users
  WHERE role = 'PATIENT'
  ORDER BY name ASC
")->fetchAll();

ob_start();
?>
<h1>پیام‌ها</h1>
<p class="muted" style="margin-top:.35rem;font-size:.9rem">
  نوبت‌ها، کارگاه‌ها و ارسال به مراجع.
  برای پیام همکار و پیام مدیر از منوی کناری استفاده کنید.
  <?= $unreadCount ? ' · ' . $unreadCount . ' پیام خوانده‌نشده' : '' ?>
</p>
<?= render_secretary_messages_panel(
    $notifications,
    url('/secretary/notifications/read'),
    $recentAppointments,
    $recentEnrollments,
    $msgTab,
    '/secretary/messages',
    [],
    $patients,
    []
) ?>
<?php
$titles = [
    'workshop' => 'پیام‌های کارگاه',
    'patients' => 'ارسال به مراجع',
    'appointment' => 'پیام‌های نوبت',
];
render_secretary_page($titles[$msgTab] ?? 'پیام‌ها', ob_get_clean());
