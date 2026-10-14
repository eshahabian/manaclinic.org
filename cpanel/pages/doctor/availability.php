<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/doctor_panel.php';
require_once __DIR__ . '/../../includes/availability.php';
require_once __DIR__ . '/../../includes/therapist_weekday_panel.php';

$ctx = require_doctor_profile($pdo);
ensure_availability_schema($pdo);
ensure_doctor_weekly_hours_schema($pdo);
if (function_exists('doctor_availability_resync_weekly')) {
    doctor_availability_resync_weekly($pdo, (string) ($ctx['profile']['id'] ?? ''), 12);
}

ob_start();
?>
<h1>روزهای خالی</h1>
<section class="panel avail-week-panel" style="margin-top:1rem">
  <h2 class="avail-week-title">روزهای هفته</h2>
  <?= therapist_weekday_panel_html(
      $pdo,
      (string) ($ctx['profile']['id'] ?? ''),
      (string) ($ctx['profile']['name'] ?? ''),
      [
          'post_url' => '/doctor/availability',
          'include_doctor_id' => false,
          'show_summary' => true,
          'patient_base' => '/doctor/patients',
      ]
  ) ?>
</section>
<?php
$inner = ob_get_clean();
$pageScripts = '
<script>
(function(){
  var form = document.querySelector("[data-avail-draft]");
  if (!form) return;
  var buttons = form.querySelectorAll("[data-weekday-open]");
  var panels = form.querySelectorAll("[data-weekday-panel]");
  var summaries = document.querySelectorAll("[data-summary-weekday]");
  var summaryPanel = document.getElementById("avail-summary-panel");
  function openWeekday(id){
    panels.forEach(function(p){
      p.hidden = String(p.getAttribute("data-weekday-panel")) !== String(id);
    });
    buttons.forEach(function(b){
      b.classList.toggle("is-active", String(b.getAttribute("data-weekday-open")) === String(id));
    });
    summaries.forEach(function(s){
      s.hidden = String(s.getAttribute("data-summary-weekday")) !== String(id);
    });
    if (summaryPanel) summaryPanel.hidden = false;
  }
  buttons.forEach(function(btn){
    btn.addEventListener("click", function(){
      openWeekday(btn.getAttribute("data-weekday-open"));
    });
  });
  var hash = (location.hash || "").replace("#weekday-", "");
  if (hash !== "" && form.querySelector("[data-weekday-panel=\"" + hash + "\"]")) {
    openWeekday(hash);
  }
})();
</script>
';
render_doctor_page('روزهای خالی', $inner);
