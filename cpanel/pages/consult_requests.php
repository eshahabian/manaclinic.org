<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/consult_requests.php';
require_once __DIR__ . '/../includes/secretary_panel.php';
require_once __DIR__ . '/../includes/doctor_panel.php';
require_once __DIR__ . '/../includes/admin_panel.php';

$user = require_login(['SECRETARY', 'DOCTOR', 'ADMIN']);
$rows = consult_request_list($pdo);
$back = consult_panel_path($user);
$asSecretary = (string) ($user['role'] ?? '') === 'SECRETARY'
    || (function_exists('user_is_eemadian') && user_is_eemadian($user) && str_starts_with((string) ($GLOBALS['path'] ?? ''), '/secretary'));
if ($asSecretary && str_starts_with((string) ($GLOBALS['path'] ?? ''), '/secretary')) {
    $back = '/secretary/consult-requests';
}
$openRows = [];
$doneRows = [];
foreach ($rows as $row) {
    if (consult_request_is_open($row)) {
        $openRows[] = $row;
    } else {
        $doneRows[] = $row;
    }
}
$newCount = count($openRows);

ob_start();
?>
<h1>درخواست مشاوره</h1>
<p class="muted" style="margin-top:.35rem;line-height:1.8">
  این‌ها را از فرم پایین سایت فرستاده‌اند. روی شماره بزنید تا تماس بگیرید.
  <?= $newCount ? ' · ' . e(to_fa_digits((string) $newCount)) . ' درخواست پیگیری نشده' : '' ?>
</p>
<?php if (!$rows): ?>
  <div class="panel" style="margin-top:1rem">
    <p class="muted" style="margin:0">هنوز درخواست مشاوره‌ای از سایت نرسیده است.</p>
  </div>
<?php else: ?>
  <?php if ($openRows): ?>
    <h2 style="font-size:1.05rem;margin:1.25rem 0 .35rem">پیگیری نشده <span class="consult-badge"><?= e(to_fa_digits((string) $newCount)) ?></span></h2>
  <?php endif; ?>
  <div class="consult-list">
    <?php foreach (array_merge($openRows, $doneRows) as $row): ?>
      <?php
        $isNew = consult_request_is_open($row);
        $phone = (string) ($row['phone'] ?? '');
        $name = trim((string) ($row['name'] ?? ''));
      ?>
      <?php if (!$isNew && $openRows && $row === $doneRows[0]): ?>
        <h2 style="font-size:1.05rem;margin:1.25rem 0 .35rem">پیگیری شد</h2>
      <?php endif; ?>
      <article class="panel consult-card<?= $isNew ? ' is-new' : '' ?>">
        <header class="consult-card-head">
          <strong><?= e($name !== '' ? $name : 'بدون نام') ?></strong>
          <?php if ($isNew): ?><span class="consult-badge">پیگیری نشده</span><?php else: ?><span class="muted">پیگیری شد</span><?php endif; ?>
          <time class="muted"><?= e(format_fa_datetime((string) ($row['created_at'] ?? ''))) ?></time>
        </header>
        <a class="consult-phone" href="tel:<?= e($phone) ?>" dir="ltr"><?= e(to_fa_digits($phone)) ?></a>
        <p class="consult-message"><?= e((string) ($row['message'] ?? '')) ?></p>
        <?php if ($isNew): ?>
          <form method="post" action="<?= e(url('/consult-requests/done')) ?>">
            <?= csrf_field() ?>
            <input type="hidden" name="id" value="<?= e((string) $row['id']) ?>">
            <input type="hidden" name="next" value="<?= e($back) ?>">
            <button type="submit" class="btn btn-outline btn-sm">تماس گرفته شد</button>
          </form>
        <?php else: ?>
          <form method="post" action="<?= e(url('/consult-requests/reopen')) ?>">
            <?= csrf_field() ?>
            <input type="hidden" name="id" value="<?= e((string) $row['id']) ?>">
            <input type="hidden" name="next" value="<?= e($back) ?>">
            <button type="submit" class="btn btn-outline btn-sm">برگرداندن به پیگیری‌نشده</button>
          </form>
        <?php endif; ?>
      </article>
    <?php endforeach; ?>
  </div>
<?php endif; ?>
<?php
$inner = ob_get_clean();
$role = (string) ($user['role'] ?? '');
if ($role === 'SECRETARY' || $asSecretary) {
    render_secretary_page('درخواست مشاوره', $inner);
    return;
}
if ($role === 'DOCTOR') {
    require_doctor_profile($pdo);
    render_doctor_page('درخواست مشاوره', $inner);
    return;
}
render_admin_page('درخواست مشاوره', $inner);
