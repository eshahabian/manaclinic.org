<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/admin_panel.php';
require_once __DIR__ . '/../../includes/staff_desk.php';

require_login(['ADMIN']);

$rows = [];
try {
    $stmt = $pdo->query("
      SELECT l.created_at, l.action, l.note, u.name, u.username
      FROM secretary_action_log l
      JOIN users u ON u.id = l.user_id
      WHERE u.role = 'SECRETARY'
        AND l.action IN ('workshop_media_delete', 'workshop_media_download')
      ORDER BY l.created_at DESC
      LIMIT 200
    ");
    $rows = $stmt ? $stmt->fetchAll() : [];
} catch (Throwable $ignored) {
    $rows = [];
}

$GLOBALS['pageRobots'] = 'noindex,nofollow';
$GLOBALS['pageTitle'] = 'حذف و دانلود فایل کارگاه';

ob_start();
?>
<div class="stack">
  <h1>حذف و دانلود فایل کارگاه</h1>
  <p class="muted">اگر منشی فایلی را پاک کند یا دانلود کند، نام خودش و مشخصات فایل اینجا می‌ماند.</p>
  <?php if (!$rows): ?>
    <div class="panel"><p class="muted" style="margin:0">هنوز موردی ثبت نشده است.</p></div>
  <?php else: ?>
    <div class="panel" style="overflow:auto">
      <table class="table" style="width:100%;font-size:.9rem">
        <thead>
          <tr>
            <th>زمان</th>
            <th>منشی</th>
            <th>کار</th>
            <th>جزئیات</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($rows as $row): ?>
            <tr>
              <td style="white-space:nowrap"><?= e(format_fa_datetime((string) ($row['created_at'] ?? ''))) ?></td>
              <td><?= e(trim((string) ($row['name'] ?? '') . ' ' . (string) ($row['username'] ?? ''))) ?></td>
              <td><?= e(staff_action_label((string) ($row['action'] ?? ''))) ?></td>
              <td><?= e((string) ($row['note'] ?? '')) ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</div>
<?php
render_admin_page('حذف و دانلود فایل کارگاه', ob_get_clean());
