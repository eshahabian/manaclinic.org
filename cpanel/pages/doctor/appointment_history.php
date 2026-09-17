<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/doctor_panel.php';
require_once __DIR__ . '/../../includes/user_cleanup.php';
require_once __DIR__ . '/../../includes/appointment_cancel.php';

$ctx = require_doctor_profile($pdo);
$stmt = $pdo->prepare("
  SELECT a.*, u.name AS patient_name, u.phone, u.email,
         p.id AS payment_id, p.amount, p.status AS pay_status, p.receipt_path,
         cu.name AS actor_name, cu.username AS actor_username, cu.role AS actor_role
  FROM appointments a
  JOIN users u ON u.id = a.patient_id
  LEFT JOIN payments p ON p.appointment_id = a.id
  LEFT JOIN users cu ON cu.id = a.created_by_user_id
  WHERE a.doctor_id = ?
  ORDER BY a.starts_at DESC
");
$stmt->execute([$ctx['profile']['id']]);
$rows = $stmt->fetchAll();

$history = [];
$now = time();
foreach ($rows as $row) {
    $status = (string) ($row['status'] ?? '');
    $start = strtotime((string) ($row['starts_at'] ?? '')) ?: 0;
    // تاریخچه: انجام‌شده، لغو شده، یا گذشته
    if (in_array($status, ['COMPLETED', 'CANCELLED'], true) || $start < $now) {
        $history[] = $row;
    }
}

$historyYmd = group_appointments_by_jalali_ymd($history, 'doc-hist', 'latest');

$ymdRenderHistory = static function (array $list): void {
    $appointmentList = $list;
    $appointmentEmpty = 'نوبتی در این بازه نیست.';
    require __DIR__ . '/../../includes/doctor_appointment_cards.php';
};

ob_start();
?>
<h1>تاریخچه نوبت‌ها</h1>
<p class="muted" style="margin-top:.35rem">
  نوبت‌هایی که داده شده، گذشته یا لغو شده‌اند. برای هر نوبت مشخص است توسط منشی، درمانگر یا خود مراجعه‌کننده ثبت شده.
</p>

<div style="margin-top:1.25rem">
  <?php
    $ymdPack = $historyYmd;
    $ymdEmpty = 'هنوز تاریخچه‌ای ثبت نشده است.';
    $ymdRenderItems = $ymdRenderHistory;
    require __DIR__ . '/../../includes/appointment_ymd_binder.php';
  ?>
</div>
<?php
$pageScripts = '<script src="' . e(url('/assets/js/ymd-cascade.js')) . '?v=20260910r"></script>';
$GLOBALS['pageScripts'] = $pageScripts;
render_doctor_page('تاریخچه نوبت‌ها', ob_get_clean());
