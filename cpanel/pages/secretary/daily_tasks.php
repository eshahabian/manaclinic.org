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
<table style="width:auto;max-width:100%;margin-top:1rem;border-collapse:collapse;direction:rtl;text-align:right">
  <tbody>
    <?php foreach (secretary_daily_task_catalog() as $key => $label): ?>
      <?php
        $checked = !empty($states[$key]['done']);
        $doneAt = (string) ($states[$key]['done_at'] ?? '');
        $timeLabel = ($checked && $doneAt !== '') ? format_fa_time($doneAt) : '';
      ?>
      <tr style="border-bottom:1px solid #d5e0da">
        <td style="padding:10px 0;vertical-align:middle;text-align:center">
          <?php if ($editable): ?>
            <form class="daytask-form" method="post" action="<?= e($postUrl) ?>" style="margin:0">
              <?= csrf_field() ?>
              <input type="hidden" name="task_date" value="<?= e($today) ?>">
              <input type="hidden" name="task_key" value="<?= e($key) ?>">
              <input type="hidden" name="done" value="0">
              <input type="checkbox" name="done" value="1" aria-label="انجام شد" style="width:18px;height:18px"<?= $checked ? ' checked' : '' ?>>
            </form>
          <?php else: ?>
            <input type="checkbox" disabled aria-label="انجام شد" style="width:18px;height:18px"<?= $checked ? ' checked' : '' ?>>
          <?php endif; ?>
        </td>
        <td class="daytask-text" style="padding:10px 8px;vertical-align:middle;line-height:1.8;text-align:right;white-space:normal;word-spacing:normal;<?= $checked ? 'text-decoration:line-through;color:#5a6f66' : '' ?>"><?= e($label) ?></td>
        <td class="daytask-time" style="padding:10px 0 10px 12px;vertical-align:middle;color:#1f6b45;font-weight:700;white-space:nowrap;text-align:right"><?= $timeLabel !== '' ? 'ساعت انجام: ' . e($timeLabel) : '' ?></td>
      </tr>
    <?php endforeach; ?>
  </tbody>
</table>
<?php if ($editable): ?>
<script>
(function(){
  document.addEventListener("change", function(e){
    var input = e.target;
    if (!input || input.type !== "checkbox" || !input.form || !input.form.classList.contains("daytask-form")) return;
    var form = input.form;
    var row = form.closest("tr");
    var data = new FormData(form);
    data.set("done", input.checked ? "1" : "0");
    fetch(form.action, {method:"POST", body:data, credentials:"same-origin", headers:{"X-Requested-With":"XMLHttpRequest","Accept":"application/json"}})
      .then(function(r){ if (!r.ok) throw new Error(); return r.json(); })
      .then(function(res){
        var text = row ? row.querySelector(".daytask-text") : null;
        var time = row ? row.querySelector(".daytask-time") : null;
        var on = !!input.checked && !!(res && res.time);
        if (text) {
          text.style.textDecoration = on ? "line-through" : "none";
          text.style.color = on ? "#5a6f66" : "";
        }
        if (time) time.textContent = on ? "ساعت انجام: " + res.time : "";
      })
      .catch(function(){ input.checked = !input.checked; });
  });
})();
</script>
<?php endif; ?>
<?php
render_secretary_page('کارهای روزانه', ob_get_clean());
