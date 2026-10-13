<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/secretary_panel.php';
require_once __DIR__ . '/../../includes/collaboration.php';

require_login(['SECRETARY']);

$rows = $pdo->query("
  SELECT u.id, u.name, u.phone, u.is_disabled, dp.id AS profile_id, dp.specialty, dp.is_active, dp.is_approved
  FROM users u
  LEFT JOIN doctor_profiles dp ON dp.user_id = u.id
  WHERE u.role = 'DOCTOR'
  ORDER BY u.name ASC
")->fetchAll() ?: [];
$requests = collaboration_requests($pdo);

ob_start();
?>
<h1>درمانگرها</h1>
<p class="muted" style="margin-top:.35rem">فعال یعنی در نوبت‌دهی دیده می‌شود. با فعال کردن، اگر پروفایل نداشته باشد ساخته می‌شود.</p>
<div class="panel" style="padding:0;overflow:auto;margin-top:1rem">
  <table class="table">
    <thead>
      <tr>
        <th>نام</th>
        <th>تخصص</th>
        <th>وضعیت</th>
        <th></th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($rows as $row): ?>
        <?php
          $on = (int) ($row['is_disabled'] ?? 0) === 0
              && (string) ($row['profile_id'] ?? '') !== ''
              && (int) ($row['is_active'] ?? 0) === 1
              && (int) ($row['is_approved'] ?? 0) === 1;
        ?>
        <tr>
          <td><?= e((string) $row['name']) ?></td>
          <td><?= e(trim((string) ($row['specialty'] ?? '')) !== '' ? (string) $row['specialty'] : '—') ?></td>
          <td><?= $on ? 'فعال' : 'غیرفعال' ?></td>
          <td>
            <form method="post" action="<?= e(url('/secretary/therapists')) ?>" style="margin:0">
              <?= csrf_field() ?>
              <input type="hidden" name="user_id" value="<?= e((string) $row['id']) ?>">
              <input type="hidden" name="active" value="<?= $on ? '0' : '1' ?>">
              <button class="btn btn-outline btn-sm" type="submit"><?= $on ? 'غیرفعال' : 'فعال' ?></button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$rows): ?>
        <tr><td colspan="4" class="muted" style="text-align:center;padding:1rem">درمانگری ثبت نشده است.</td></tr>
      <?php endif; ?>
    </tbody>
  </table>
</div>

<h2 style="margin:1.4rem 0 .4rem;font-size:1.05rem">درخواست‌های همکاری</h2>
<?php if (!$requests): ?>
  <p class="muted">درخواستی نرسیده است.</p>
<?php else: ?>
  <div class="stack">
    <?php foreach ($requests as $request): ?>
      <div class="panel" style="margin:0">
        <strong><?= e((string) $request['name']) ?></strong>
        <div class="muted" style="margin-top:.3rem;line-height:1.7">
          <?= e((string) $request['phone']) ?>
          · <?= e((string) $request['specialty']) ?>
          · <?= e(format_fa_datetime((string) $request['created_at'])) ?>
        </div>
        <p style="margin:.45rem 0 0"><?= e((string) $request['message']) ?></p>
      </div>
    <?php endforeach; ?>
  </div>
<?php endif; ?>
<?php
render_secretary_page('درمانگرها', ob_get_clean());
