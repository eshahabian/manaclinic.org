<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/secretary_panel.php';
require_once __DIR__ . '/../../includes/secretary_daily_tasks.php';

require_login(['SECRETARY']);

ob_start();
?>
<h1>کارهای روزانه</h1>
<ul style="list-style:none;margin:1rem 0 0;padding:0;line-height:2;text-align:right">
  <?php foreach (secretary_daily_task_catalog() as $label): ?>
    <li style="margin:0 0 .35rem"><?= e($label) ?></li>
  <?php endforeach; ?>
</ul>
<?php
render_secretary_page('کارهای روزانه', ob_get_clean());
