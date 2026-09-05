<?php
declare(strict_types=1);
require_once __DIR__ . '/../../includes/admin_panel.php';
require_once __DIR__ . '/../../includes/user_cleanup.php';
require_login(['ADMIN']);

$users = $pdo->query('SELECT id,username,name,role,created_at FROM users ORDER BY created_at DESC')->fetchAll();
$cleanupTargets = find_cleanup_test_users($pdo);
$appointmentCount = (int) $pdo->query('SELECT COUNT(*) FROM appointments')->fetchColumn();
$patients = array_values(array_filter($users, static fn($u) => $u['role'] === 'PATIENT'));

ob_start();
?>
<h1>کاربران</h1>

<div class="panel" style="margin-top:1rem;border-color:var(--danger)">
  <h2 style="margin:0 0 .5rem;font-size:1rem">پاک‌سازی سریع</h2>
  <p class="muted" style="font-size:.9rem;line-height:1.8;margin:0 0 .75rem">
    هدف: برهان شاوردی، عماد، علی رضایی
    · پیدا شده: <strong><?= count($cleanupTargets) ?></strong>
    · نوبت‌های ثبت‌شده: <strong><?= $appointmentCount ?></strong>
  </p>
  <?php if ($cleanupTargets): ?>
    <ul style="margin:0 0 .75rem;padding-right:1.2rem;font-size:.9rem">
      <?php foreach ($cleanupTargets as $t): ?>
        <li><?= e($t['name']) ?> <span class="muted" dir="ltr">(<?= e((string)$t['username']) ?>)</span></li>
      <?php endforeach; ?>
    </ul>
  <?php else: ?>
    <p class="muted" style="font-size:.85rem">با الگو پیدا نشد؛ از لیست پایین تیک بزن و حذف کن.</p>
  <?php endif; ?>
  <form method="post" action="<?= e(url('/admin/users')) ?>" style="display:inline" onsubmit="return confirm('کاربران پیدا شده + تمام نوبت‌ها حذف شوند؟');">
    <input type="hidden" name="action" value="cleanup_named_and_appointments">
    <button type="submit" class="btn btn-danger">حذف کاربران پیدا شده + تمام نوبت‌ها</button>
  </form>
</div>

<?php if ($patients): ?>
<div class="panel" style="margin-top:1rem">
  <h2 style="margin:0 0 .75rem;font-size:1rem">حذف دستی مراجعه‌کنندگان (لیست منشی از اینجا می‌آید)</h2>
  <form method="post" action="<?= e(url('/admin/users')) ?>" onsubmit="return confirm('مراجعه‌کنندگان انتخاب‌شده حذف شوند؟');">
    <input type="hidden" name="action" value="delete_selected">
    <div class="stack" style="margin-bottom:1rem">
      <?php foreach ($patients as $p): ?>
        <label style="display:flex;gap:.6rem;align-items:center;padding:.45rem 0;border-bottom:1px solid var(--line);font-size:.95rem">
          <input type="checkbox" name="user_ids[]" value="<?= e($p['id']) ?>">
          <span><?= e($p['name']) ?></span>
          <span class="muted" dir="ltr" style="font-size:.8rem"><?= e((string)$p['username']) ?></span>
        </label>
      <?php endforeach; ?>
    </div>
    <button type="submit" class="btn btn-danger">حذف انتخاب‌شده‌ها</button>
  </form>
</div>
<?php endif; ?>

<div class="panel" style="padding:0;overflow:auto;margin-top:1rem">
  <table class="table">
    <thead><tr><th>نام</th><th>نام کاربری</th><th>نقش</th><th>عضویت</th><th></th></tr></thead>
    <tbody>
      <?php foreach ($users as $u): ?>
        <tr>
          <td><?= e($u['name']) ?></td>
          <td dir="ltr"><?= e((string)$u['username']) ?></td>
          <td><?= e(role_label($u['role'])) ?></td>
          <td><?= e(format_fa_datetime($u['created_at'])) ?></td>
          <td>
            <?php if ($u['role'] !== 'ADMIN'): ?>
              <form method="post" action="<?= e(url('/admin/users')) ?>" style="margin:0" onsubmit="return confirm('این کاربر و نوبت‌هایش حذف شود؟');">
                <input type="hidden" name="action" value="delete_user">
                <input type="hidden" name="user_id" value="<?= e($u['id']) ?>">
                <button type="submit" class="btn btn-outline btn-sm" style="color:var(--danger)">حذف</button>
              </form>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php
render_admin_page('کاربران', ob_get_clean());
