<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/secretary_panel.php';

$user = require_login(['SECRETARY']);
ensure_workshop_schema($pdo);

$msgTab = trim((string) ($_GET['msg'] ?? 'appointment'));
if (!in_array($msgTab, ['appointment', 'workshop'], true)) {
    $msgTab = 'appointment';
}

$notifications = fetch_notifications($pdo, (string) $user['id'], 80);
$recentAppointments = secretary_recent_shared_appointments($pdo, 40);
$recentEnrollments = secretary_recent_shared_enrollments($pdo, 40);
$unreadCount = secretary_unread_desk_count($notifications);

ob_start();
?>
<h1>پیام‌ها</h1>
<p class="muted" style="margin-top:.35rem;font-size:.9rem">
  نوبت‌ها و ثبت‌نام کارگاه‌ها جدا هستند.<?= $unreadCount ? ' · ' . $unreadCount . ' پیام خوانده‌نشده' : '' ?>
</p>
<?= render_secretary_messages_panel(
    $notifications,
    url('/secretary/notifications/read'),
    $recentAppointments,
    $recentEnrollments,
    $msgTab,
    '/secretary/messages'
) ?>
<?php
render_secretary_page($msgTab === 'workshop' ? 'پیام‌های کارگاه' : 'پیام‌های نوبت', ob_get_clean());
