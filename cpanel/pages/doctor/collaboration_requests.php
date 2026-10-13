<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/doctor_panel.php';
require_once __DIR__ . '/../../includes/collaboration.php';

$ctx = require_doctor_profile($pdo);
if (!collaboration_user_can_read($pdo, $ctx['user'] ?? null)) {
    flash_set('error', 'این فهرست برای حساب شما باز نیست.');
    redirect('/doctor/notifications');
}

$rows = collaboration_requests($pdo, 80);

ob_start();
?>
<div class="panel">
  <h1>درخواست‌های همکاری</h1>
  <p class="muted">درخواست‌هایی که از فرم سایت رسیده است.</p>
  <?php if (!$rows): ?>
    <p class="muted" style="margin-top:1rem">درخواستی نرسیده است.</p>
  <?php else: ?>
    <div class="stack" style="margin-top:1rem">
      <?php foreach ($rows as $request): ?>
        <article class="panel" style="margin:0">
          <strong><?= e((string) ($request['name'] ?? '')) ?></strong>
          <p class="muted" style="margin:.35rem 0 0;line-height:1.7">
            <?= e((string) ($request['phone'] ?? '')) ?>
            · <?= e((string) ($request['specialty'] ?? '')) ?>
            · <?= e(format_fa_datetime((string) ($request['created_at'] ?? ''))) ?>
          </p>
          <p style="margin:.45rem 0 0;line-height:1.8"><?= e((string) ($request['message'] ?? '')) ?></p>
        </article>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</div>
<?php
render_doctor_page('درخواست‌های همکاری', ob_get_clean());
