<?php
declare(strict_types=1);
require_once __DIR__ . '/../../includes/admin_panel.php';
require_once __DIR__ . '/../../includes/user_cleanup.php';
require_once __DIR__ . '/../../includes/secretary_patient.php';
require_login(['ADMIN']);

ensure_users_password_plain_schema($pdo);
if (function_exists('users_backfill_created_by')) {
    users_backfill_created_by($pdo);
} elseif (function_exists('ensure_staff_desk_schema')) {
    ensure_staff_desk_schema($pdo);
}
$users = $pdo->query("
  SELECT u.id, u.username, u.name, u.role, u.created_at, u.password_plain,
         cu.name AS created_by_name, cu.username AS created_by_username, cu.role AS created_by_role
  FROM users u
  LEFT JOIN users cu ON cu.id = u.created_by_user_id
  ORDER BY u.created_at DESC
")->fetchAll();
$cleanupTargets = find_cleanup_test_users($pdo);
$appointmentCount = (int) $pdo->query('SELECT COUNT(*) FROM appointments')->fetchColumn();
$patients = array_values(array_filter($users, static fn($u) => $u['role'] === 'PATIENT'));

ob_start();
?>
<h1>کاربران و رمز عبور</h1>
<p class="muted" style="margin-top:.35rem;line-height:1.8">
  ستون «رمز فعلی» فقط برای ادمین است. رمزهایی که از این به‌بعد ثبت یا عوض شوند اینجا دیده می‌شوند؛ رمزهای خیلی قدیمی که قبل از این قابلیت ذخیره نشده‌اند قابل بازیابی نیستند.
</p>

<div class="panel" style="margin-top:1rem">
  <h2 style="margin:0 0 .5rem;font-size:1rem">تغییر رمز هر کاربر</h2>
  <p class="muted" style="font-size:.9rem;line-height:1.8;margin:0 0 .75rem">
    رمز هر حساب را از اینجا عوض کنید. اگر «در ورود بعدی عوض شود» را بزنید، همان کاربر با رمز جدید وارد می‌شود و باید رمز خودش را بسازد.
  </p>
  <form method="post" action="<?= e(url('/admin/users')) ?>" class="form-stack" autocomplete="off">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="set_password">
    <div>
      <label class="label">کاربر</label>
      <select class="input" name="user_id" required>
        <option value="">انتخاب کنید</option>
        <?php foreach ($users as $u): ?>
          <option value="<?= e($u['id']) ?>">
            <?= e($u['name']) ?> — <?= e(role_label($u['role'])) ?>
            <?php if (!empty($u['username'])): ?> (<?= e((string) $u['username']) ?>)<?php endif; ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>
    <div>
      <label class="label">رمز جدید</label>
      <input class="input" type="password" name="new_password" required minlength="6" dir="ltr" autocomplete="new-password">
    </div>
    <div>
      <label class="label">تکرار رمز جدید</label>
      <input class="input" type="password" name="new_password_confirm" required minlength="6" dir="ltr" autocomplete="new-password">
    </div>
    <label style="display:flex;gap:.5rem;align-items:center;font-size:.9rem">
      <input type="checkbox" name="must_change_password" value="1" checked>
      در ورود بعدی رمز را عوض کند
    </label>
    <button type="submit" class="btn btn-primary">ذخیره رمز</button>
  </form>
</div>

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
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="cleanup_named_and_appointments">
    <button type="submit" class="btn btn-danger">حذف کاربران پیدا شده + تمام نوبت‌ها</button>
  </form>
</div>

<?php if ($patients): ?>
<div class="panel" style="margin-top:1rem">
  <h2 style="margin:0 0 .75rem;font-size:1rem">حذف دستی مراجعه‌کنندگان (لیست منشی از اینجا می‌آید)</h2>
  <form method="post" action="<?= e(url('/admin/users')) ?>" onsubmit="return confirm('مراجعه‌کنندگان انتخاب‌شده حذف شوند؟');">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="delete_selected">
    <div class="stack" style="margin-bottom:1rem">
      <?php foreach ($patients as $p): ?>
        <label style="display:flex;gap:.6rem;align-items:center;padding:.45rem 0;border-bottom:1px solid var(--line);font-size:.95rem;flex-wrap:wrap">
          <input type="checkbox" name="user_ids[]" value="<?= e($p['id']) ?>">
          <span><?= e($p['name']) ?></span>
          <span class="muted" dir="ltr" style="font-size:.8rem"><?= e((string)$p['username']) ?></span>
          <span class="muted" style="font-size:.8rem;margin-inline-start:auto">
            <?= e(user_created_by_label([
                'name' => $p['created_by_name'] ?? '',
                'username' => $p['created_by_username'] ?? '',
                'role' => $p['created_by_role'] ?? '',
            ], 'ثبت‌کننده نامشخص')) ?>
          </span>
        </label>
      <?php endforeach; ?>
    </div>
    <button type="submit" class="btn btn-danger">حذف انتخاب‌شده‌ها</button>
  </form>
</div>
<?php endif; ?>

<div class="panel" style="padding:0;overflow:auto;margin-top:1rem">
  <table class="table">
    <thead>
      <tr>
        <th>نام</th>
        <th>نام کاربری</th>
        <th>نقش</th>
        <th>ثبت توسط</th>
        <th>رمز فعلی</th>
        <th>عضویت</th>
        <th>عملیات</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($users as $u): ?>
        <?php
          $plain = trim((string) ($u['password_plain'] ?? ''));
          $creator = user_created_by_label([
              'name' => $u['created_by_name'] ?? '',
              'username' => $u['created_by_username'] ?? '',
              'role' => $u['created_by_role'] ?? '',
          ], '—');
          $uid = (string) ($u['id'] ?? '');
          $editId = 'admin-edit-' . preg_replace('/[^a-zA-Z0-9_-]/', '', $uid);
        ?>
        <tr>
          <td><?= e($u['name']) ?></td>
          <td dir="ltr"><?= e((string) $u['username']) ?></td>
          <td><?= e(role_label($u['role'])) ?></td>
          <td style="font-size:.85rem"><?= e($creator) ?></td>
          <td>
            <?php if ($plain !== ''): ?>
              <code class="admin-password-plain" dir="ltr"><?= e($plain) ?></code>
            <?php else: ?>
              <span class="muted" style="font-size:.85rem">ثبت نشده</span>
            <?php endif; ?>
          </td>
          <td><?= e(format_fa_datetime($u['created_at'])) ?></td>
          <td class="admin-user-ops">
            <div class="admin-user-ops-top">
              <form method="post" action="<?= e(url('/admin/users')) ?>" class="admin-pass-form" autocomplete="off">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="set_password">
                <input type="hidden" name="user_id" value="<?= e($uid) ?>">
                <input class="input" type="password" name="new_password" required minlength="6" dir="ltr" placeholder="رمز جدید" autocomplete="new-password" aria-label="رمز جدید">
                <button type="submit" class="btn btn-outline btn-sm">ثبت رمز</button>
              </form>
              <div class="admin-user-actions">
                <button type="button" class="btn btn-outline btn-sm" data-admin-edit-toggle="<?= e($editId) ?>">ویرایش پروفایل</button>
                <?php if ($u['role'] !== 'ADMIN'): ?>
                  <form method="post" action="<?= e(url('/admin/users')) ?>" style="margin:0" onsubmit="return confirm('این کاربر و نوبت‌هایش حذف شود؟');">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="delete_user">
                    <input type="hidden" name="user_id" value="<?= e($uid) ?>">
                    <button type="submit" class="btn btn-outline btn-sm" style="color:var(--danger)">حذف</button>
                  </form>
                <?php endif; ?>
              </div>
            </div>
            <form method="post" action="<?= e(url('/admin/users')) ?>" class="admin-edit-form" id="<?= e($editId) ?>" hidden autocomplete="off">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="update_profile">
              <input type="hidden" name="user_id" value="<?= e($uid) ?>">
              <div>
                <label class="label" for="<?= e($editId) ?>-name">نام</label>
                <input class="input" id="<?= e($editId) ?>-name" type="text" name="name" required value="<?= e((string) $u['name']) ?>" autocomplete="off">
              </div>
              <div>
                <label class="label" for="<?= e($editId) ?>-user">نام کاربری</label>
                <input class="input" id="<?= e($editId) ?>-user" type="text" name="username" required dir="ltr" value="<?= e((string) $u['username']) ?>" pattern="[a-zA-Z0-9._\-]{3,32}" autocomplete="off">
              </div>
              <div class="admin-edit-form-actions">
                <button type="submit" class="btn btn-primary btn-sm">ذخیره پروفایل</button>
                <button type="button" class="btn btn-outline btn-sm" data-admin-edit-cancel="<?= e($editId) ?>">انصراف</button>
              </div>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>
<script>
(function(){
  document.querySelectorAll("[data-admin-edit-toggle]").forEach(function(btn){
    btn.addEventListener("click", function(){
      var id = btn.getAttribute("data-admin-edit-toggle");
      var form = id ? document.getElementById(id) : null;
      if (!form) return;
      var open = form.hasAttribute("hidden");
      document.querySelectorAll(".admin-edit-form").forEach(function(f){ f.hidden = true; });
      form.hidden = !open;
      if (open) {
        var first = form.querySelector("input[name=name]");
        if (first) first.focus();
      }
    });
  });
  document.querySelectorAll("[data-admin-edit-cancel]").forEach(function(btn){
    btn.addEventListener("click", function(){
      var id = btn.getAttribute("data-admin-edit-cancel");
      var form = id ? document.getElementById(id) : null;
      if (form) form.hidden = true;
    });
  });
})();
</script>
<?php
render_admin_page('کاربران و رمز عبور', ob_get_clean());
