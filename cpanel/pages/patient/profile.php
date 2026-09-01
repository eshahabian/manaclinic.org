<?php
declare(strict_types=1);

$user = require_login(['PATIENT']);
require_once __DIR__ . '/../../includes/patient_panel.php';

$stmt = $pdo->prepare('SELECT * FROM users WHERE id=?');
$stmt->execute([$user['id']]);
$profile = $stmt->fetch();

ob_start();
?>
<div style="max-width:28rem">
  <h1>پروفایل</h1>
  <form class="panel form-stack" method="post" action="<?= e(url('/dashboard/profile')) ?>">
    <div>
      <label class="label">نام</label>
      <input class="input" name="name" value="<?= e($profile['name']) ?>" required>
    </div>
    <div>
      <label class="label">نام کاربری</label>
      <input class="input" value="<?= e((string)$profile['username']) ?>" disabled dir="ltr">
    </div>
    <div>
      <label class="label">موبایل</label>
      <input class="input" name="phone" value="<?= e((string)$profile['phone']) ?>" dir="ltr">
    </div>
    <button class="btn btn-primary" type="submit">ذخیره تغییرات</button>
  </form>
</div>
<?php
render_patient_page('پروفایل', ob_get_clean());
