<?php
declare(strict_types=1);
require_once __DIR__ . '/../../includes/secretary_panel.php';
$user = require_login(['SECRETARY']);

$upcoming = $pdo->query("
  SELECT a.*, pu.name AS patient_name, du.name AS doctor_name,
         cu.name AS actor_name, cu.username AS actor_username
  FROM appointments a
  JOIN users pu ON pu.id = a.patient_id
  JOIN doctor_profiles dp ON dp.id = a.doctor_id
  JOIN users du ON du.id = dp.user_id
  LEFT JOIN users cu ON cu.id = a.created_by_user_id
  WHERE a.starts_at >= NOW() AND a.status IN ('CONFIRMED','PENDING_PAYMENT')
  ORDER BY a.starts_at ASC
  LIMIT 8
")->fetchAll();

$notifications = fetch_notifications($pdo, (string) $user['id'], 15);
$unreadCount = count_unread_notifications($pdo, (string) $user['id']);

ob_start();
?>
<h1>سلام <?= e($user['name']) ?></h1>
<p class="muted">از اینجا می‌توانید برای بیماران نوبت ثبت کنید.<?= $unreadCount ? ' · ' . $unreadCount . ' پیام خوانده‌نشده' : '' ?></p>
<p style="margin-top:1rem"><a class="btn btn-primary" href="<?= e(url('/secretary/book')) ?>">رزرو نوبت برای بیمار</a></p>
<?= render_notifications_panel($notifications, url('/secretary/notifications/read')) ?>
<div class="panel stack" style="margin-top:1.5rem">
  <h2 style="margin:0;font-size:1.1rem">نوبت‌های پیش‌رو</h2>
  <?php foreach ($upcoming as $a): ?>
    <div class="row-between" style="border:1px solid var(--line);border-radius:.75rem;padding:.75rem">
      <div>
        <strong><?= e($a['patient_name']) ?></strong>
        <div class="muted" style="font-size:.85rem">دکتر: <?= e($a['doctor_name']) ?></div>
        <div style="font-size:.85rem;margin-top:.25rem"><?= e(format_fa_datetime($a['starts_at'])) ?></div>
        <?= staff_sign_html(['name' => $a['actor_name'] ?? '', 'username' => $a['actor_username'] ?? '']) ?>
      </div>
      <span class="badge"><?= e(appointment_status_label($a['status'])) ?></span>
    </div>
  <?php endforeach; ?>
  <?php if (!$upcoming): ?><p class="muted">نوبت پیش‌رویی نیست.</p><?php endif; ?>
</div>
<?php
render_secretary_page('پنل منشی', ob_get_clean());
