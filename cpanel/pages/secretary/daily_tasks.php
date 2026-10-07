<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/secretary_panel.php';
require_once __DIR__ . '/../../includes/secretary_daily_tasks.php';

require_login(['SECRETARY']);

ob_start();
?>
<h1>کارهای روزانه</h1>
<ol style="margin:1rem 0 0;padding-inline-start:1.5rem;line-height:2">
  <?php foreach (secretary_daily_task_catalog() as $label): ?>
    <li><?= e($label) ?></li>
  <?php endforeach; ?>
</ol>
<?php
render_secretary_page('کارهای روزانه', ob_get_clean());
