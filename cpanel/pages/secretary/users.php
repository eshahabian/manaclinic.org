<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/secretary_panel.php';
require_once __DIR__ . '/../../includes/secretary_patient.php';
require_once __DIR__ . '/../../includes/user_referral.php';

require_login(['SECRETARY']);

ensure_user_referral_schema($pdo);
if (function_exists('users_backfill_created_by')) {
    users_backfill_created_by($pdo);
}

$users = $pdo->query("
  SELECT u.id, u.username, u.name, u.role, u.phone, u.created_at, u.last_login_at,
         u.referral_source, u.preferred_doctor_id,
         cu.name AS created_by_name, cu.username AS created_by_username, cu.role AS created_by_role
  FROM users u
  LEFT JOIN users cu ON cu.id = u.created_by_user_id
  WHERE u.is_disabled = 0
    AND (
      u.role = 'PATIENT'
      OR LOWER(u.username) = 'eshahabian'
      OR (u.name LIKE '%عماد%' AND u.name LIKE '%شهابیان%')
    )
  ORDER BY u.created_at DESC
")->fetchAll() ?: [];
$doctors = secretary_active_doctors($pdo);
$lastTherapy = function_exists('patient_last_therapy_map') ? patient_last_therapy_map($pdo) : [];
$patientCount = count(array_filter($users, static fn($u) => ($u['role'] ?? '') === 'PATIENT'));

ob_start();
?>
<h1>کاربران</h1>
<p class="muted" style="margin-top:.35rem;line-height:1.8">از اینجا فقط درمانگر، معرف و موبایل هر کاربر عوض می‌شود.</p>

<div class="grid-2" style="margin-top:1rem">
  <div class="panel" style="margin:0">
    <div class="muted">مراجعه‌کننده</div>
    <strong style="font-size:1.7rem;line-height:1.4"><?= e(to_fa_digits((string) $patientCount)) ?></strong>
  </div>
  <div class="panel" style="margin:0">
    <div class="muted">درمانگر</div>
    <strong style="font-size:1.7rem;line-height:1.4"><?= e(to_fa_digits((string) count($doctors))) ?></strong>
  </div>
</div>

<div class="panel admin-users-board" style="padding:0;margin-top:1rem;overflow:auto">
  <table class="table">
    <thead>
      <tr>
        <th>نام</th>
        <th>نام کاربری</th>
        <th>نقش</th>
        <th>ثبت توسط</th>
        <th>درمانگر</th>
        <th>معرف</th>
        <th>موبایل</th>
        <th>عضویت</th>
        <th>آخرین ورود</th>
        <th>آخرین تراپی</th>
        <th></th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($users as $u): ?>
        <?php
          $uid = (string) ($u['id'] ?? '');
          $formId = 'sec-user-' . preg_replace('/[^a-zA-Z0-9_-]/', '', $uid);
          $creator = user_created_by_label([
              'name' => $u['created_by_name'] ?? '',
              'username' => $u['created_by_username'] ?? '',
              'role' => $u['created_by_role'] ?? '',
          ], '—');
          $compact = 'min-width:9rem;padding:.35rem .5rem;font-size:.85rem';
        ?>
        <tr>
          <td><?= e((string) $u['name']) ?></td>
          <td dir="ltr"><?= e((string) $u['username']) ?></td>
          <td><?= e(role_label((string) ($u['role'] ?? ''))) ?></td>
          <td style="font-size:.85rem"><?= e($creator) ?></td>
          <td>
            <select class="input" name="preferred_doctor_id" form="<?= e($formId) ?>" required aria-label="درمانگر" style="<?= e($compact) ?>">
              <option value="">انتخاب درمانگر</option>
              <?php foreach ($doctors as $doctor): ?>
                <option value="<?= e((string) $doctor['id']) ?>"<?= ((string) ($u['preferred_doctor_id'] ?? '') === (string) $doctor['id']) ? ' selected' : '' ?>><?= e((string) $doctor['name']) ?></option>
              <?php endforeach; ?>
            </select>
          </td>
          <td>
            <select class="input" name="referral_source" form="<?= e($formId) ?>" aria-label="معرف" style="<?= e($compact) ?>">
              <option value="">انتخاب معرف</option>
              <option value="clinic"<?= (($u['referral_source'] ?? '') === 'clinic') ? ' selected' : '' ?>>ارجاع از سمت کلینیک</option>
              <option value="therapist"<?= (($u['referral_source'] ?? '') === 'therapist') ? ' selected' : '' ?>>ارجاع از سمت درمانگر</option>
            </select>
          </td>
          <td>
            <input class="input" name="phone" form="<?= e($formId) ?>" dir="ltr" inputmode="tel" value="<?= e((string) ($u['phone'] ?? '')) ?>" required aria-label="موبایل" style="min-width:8rem;padding:.35rem .5rem;font-size:.85rem">
          </td>
          <td><?= e(format_fa_datetime((string) ($u['created_at'] ?? ''))) ?></td>
          <td><?= !empty($u['last_login_at']) ? e(format_fa_datetime((string) $u['last_login_at'])) : '—' ?></td>
          <td>
            <?php if (($u['role'] ?? '') === 'PATIENT' && !empty($lastTherapy[$uid])): ?>
              <?= e(format_fa_datetime((string) $lastTherapy[$uid])) ?>
            <?php else: ?>
              —
            <?php endif; ?>
          </td>
          <td>
            <form id="<?= e($formId) ?>" method="post" action="<?= e(url('/secretary/users')) ?>" style="margin:0">
              <?= csrf_field() ?>
              <input type="hidden" name="user_id" value="<?= e($uid) ?>">
              <button class="btn btn-primary btn-sm" type="submit">ذخیره</button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$users): ?>
        <tr><td colspan="11" class="muted" style="text-align:center;padding:1.2rem">کاربری برای نمایش نیست.</td></tr>
      <?php endif; ?>
    </tbody>
  </table>
</div>
<?php
render_secretary_page('کاربران', ob_get_clean());
