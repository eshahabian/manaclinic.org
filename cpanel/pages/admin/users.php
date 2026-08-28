<?php
declare(strict_types=1);
require_once __DIR__ . '/../../includes/admin_panel.php';
require_login(['ADMIN']);
$users = $pdo->query('SELECT id,username,name,role,created_at FROM users ORDER BY created_at DESC')->fetchAll();
ob_start();
?>
<h1>کاربران</h1>
<div class="panel" style="padding:0;overflow:auto;margin-top:1rem">
  <table class="table">
    <thead><tr><th>نام</th><th>نام کاربری</th><th>نقش</th><th>عضویت</th></tr></thead>
    <tbody>
      <?php foreach ($users as $u): ?>
        <tr>
          <td><?= e($u['name']) ?></td>
          <td dir="ltr"><?= e((string)$u['username']) ?></td>
          <td><?= e(role_label($u['role'])) ?></td>
          <td><?= e(format_fa_datetime($u['created_at'])) ?></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php
render_admin_page('کاربران', ob_get_clean());
