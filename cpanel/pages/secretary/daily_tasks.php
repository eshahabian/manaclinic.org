<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/secretary_panel.php';
require_once __DIR__ . '/../../includes/secretary_daily_tasks.php';

$user = require_login(['SECRETARY']);
$userId = (string) ($user['id'] ?? '');
$today = date('Y-m-d');
try {
    $states = secretary_daily_task_states($pdo, $userId, $today);
} catch (Throwable $e) {
    $states = [];
}
$editable = secretary_daily_task_can_edit($pdo, $userId, $today, true);
$postUrl = url('/secretary/daily-tasks');

ob_start();
?>
<h1>کارهای روزانه</h1>
<ul style="list-style:none;margin:1rem 0 0;padding:0">
  <?php foreach (secretary_daily_task_catalog() as $key => $label): ?>
    <?php $checked = !empty($states[$key]['done']); ?>
    <li style="margin:0 0 .7rem;line-height:1.9;text-align:right">
      <?php if ($editable): ?>
        <form class="daytask-form" method="post" action="<?= e($postUrl) ?>" style="margin:0;text-align:right">
          <?= csrf_field() ?>
          <input type="hidden" name="task_date" value="<?= e($today) ?>">
          <input type="hidden" name="task_key" value="<?= e($key) ?>">
          <input type="hidden" name="done" value="0">
          <input type="checkbox" name="done" value="1" style="display:inline-block;width:18px;height:18px;margin:0 .4rem 0 0;vertical-align:middle"<?= $checked ? ' checked' : '' ?>>
          <?= e($label) ?>
        </form>
      <?php else: ?>
        <input type="checkbox" disabled style="display:inline-block;width:18px;height:18px;margin:0 .4rem 0 0;vertical-align:middle"<?= $checked ? ' checked' : '' ?>>
        <?= e($label) ?>
      <?php endif; ?>
    </li>
  <?php endforeach; ?>
</ul>
<?php if ($editable): ?>
<script>
(function(){
  document.addEventListener("change", function(e){
    var input = e.target;
    if (!input || input.type !== "checkbox" || !input.form || !input.form.classList.contains("daytask-form")) return;
    var data = new FormData(input.form);
    data.set("done", input.checked ? "1" : "0");
    fetch(input.form.action, {method:"POST", body:data, credentials:"same-origin", headers:{"X-Requested-With":"XMLHttpRequest","Accept":"application/json"}})
      .catch(function(){ input.checked = !input.checked; });
  });
})();
</script>
<?php endif; ?>
<?php
render_secretary_page('کارهای روزانه', ob_get_clean());
