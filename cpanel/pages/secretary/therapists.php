<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/secretary_panel.php';
require_once __DIR__ . '/../../includes/doctor_panel.php';
require_once __DIR__ . '/../../includes/doctor_profile_fields.php';
require_once __DIR__ . '/../../includes/collaboration.php';
require_once __DIR__ . '/../../includes/therapist_weekday_panel.php';

$user = require_login(['SECRETARY', 'DOCTOR', 'ADMIN']);
if (!clinic_can_add_therapist($user)) {
    flash_set('error', 'این بخش فقط برای منشی‌ها، شیوا گرانمایه‌پور، عطیه گارسچی و عماد شهابیان است.');
    redirect('/login');
}

$rows = $pdo->query("
  SELECT u.id AS user_id, u.name, u.phone, u.is_disabled,
         dp.id AS profile_id, dp.specialty, dp.is_active, dp.is_approved
  FROM users u
  LEFT JOIN doctor_profiles dp ON dp.user_id = u.id
  WHERE u.role = 'DOCTOR'
  ORDER BY u.name ASC
")->fetchAll() ?: [];
$requests = collaboration_requests($pdo);
$openId = trim((string) ($_GET['open'] ?? ''));
$showAdd = isset($_GET['add']) && (string) $_GET['add'] === '1';
$openRow = null;
foreach ($rows as $row) {
    if ($openId !== '' && (string) ($row['profile_id'] ?? '') === $openId) {
        $openRow = $row;
        break;
    }
}
$maxYear = doctor_current_jalali_year();

ob_start();
?>
<div class="therapist-desk-head">
  <div>
    <h1>درمانگرها</h1>
    <p class="muted" style="margin-top:.35rem">روی نام درمانگر بزنید تا روزها و ساعت حضورش باز شود.</p>
  </div>
  <a class="btn btn-primary" href="<?= e(url('/secretary/therapists?add=1')) ?>">افزودن درمانگر جدید</a>
</div>
<div class="therapist-grid">
  <?php foreach ($rows as $row): ?>
    <?php
      $on = (int) ($row['is_disabled'] ?? 0) === 0
          && (string) ($row['profile_id'] ?? '') !== ''
          && (int) ($row['is_active'] ?? 0) === 1
          && (int) ($row['is_approved'] ?? 0) === 1;
      $profileId = trim((string) ($row['profile_id'] ?? ''));
    ?>
    <article class="panel therapist-card">
      <?php if ($profileId !== ''): ?>
        <a class="therapist-card-hit" href="<?= e(url('/secretary/therapists?open=' . rawurlencode($profileId))) ?>">
          <strong><?= e((string) $row['name']) ?></strong>
          <span><?= e(trim((string) ($row['specialty'] ?? '')) !== '' ? (string) $row['specialty'] : 'بدون تخصص') ?></span>
          <em><?= $on ? 'فعال' : 'غیرفعال' ?></em>
        </a>
      <?php else: ?>
        <div class="therapist-card-hit">
          <strong><?= e((string) $row['name']) ?></strong>
          <span>پروفایل هنوز ساخته نشده</span>
          <em>غیرفعال</em>
        </div>
      <?php endif; ?>
      <form method="post" action="<?= e(url('/secretary/therapists')) ?>">
        <?= csrf_field() ?>
        <input type="hidden" name="user_id" value="<?= e((string) $row['user_id']) ?>">
        <input type="hidden" name="active" value="<?= $on ? '0' : '1' ?>">
        <button class="btn btn-outline btn-sm" type="submit"><?= $on ? 'غیرفعال' : 'فعال' ?></button>
      </form>
    </article>
  <?php endforeach; ?>
  <?php if (!$rows): ?>
    <p class="muted">درمانگری ثبت نشده است.</p>
  <?php endif; ?>
</div>

<h2 style="margin:1.4rem 0 .4rem;font-size:1.05rem">درخواست‌های همکاری</h2>
<?php if (!$requests): ?>
  <p class="muted">درخواستی نرسیده است.</p>
<?php else: ?>
  <div class="stack">
    <?php foreach ($requests as $request): ?>
      <div class="panel" style="margin:0">
        <strong><?= e((string) $request['name']) ?></strong>
        <div class="muted" style="margin-top:.3rem;line-height:1.7">
          <?= e((string) $request['phone']) ?>
          · <?= e((string) $request['specialty']) ?>
          · <?= e(format_fa_datetime((string) $request['created_at'])) ?>
        </div>
        <p style="margin:.45rem 0 0"><?= e((string) $request['message']) ?></p>
      </div>
    <?php endforeach; ?>
  </div>
<?php endif; ?>

<?php if ($openRow): ?>
  <div class="therapist-shadow" id="therapist-days-shadow">
    <a class="therapist-shadow-backdrop" href="<?= e(url('/secretary/therapists')) ?>" aria-label="بستن"></a>
    <div class="therapist-shadow-card" role="dialog" aria-modal="true" aria-labelledby="therapist-days-title">
      <div class="therapist-shadow-head">
        <h2 id="therapist-days-title"><?= e((string) $openRow['name']) ?></h2>
        <a class="therapist-shadow-close" href="<?= e(url('/secretary/therapists')) ?>" aria-label="بستن">×</a>
      </div>
      <?= therapist_weekday_panel_html($pdo, (string) $openRow['profile_id'], (string) $openRow['name']) ?>
    </div>
  </div>
<?php endif; ?>

<?php if ($showAdd): ?>
  <div class="therapist-shadow" id="therapist-add-shadow">
    <a class="therapist-shadow-backdrop" href="<?= e(url('/secretary/therapists')) ?>" aria-label="بستن"></a>
    <div class="therapist-shadow-card" role="dialog" aria-modal="true" aria-labelledby="therapist-add-title">
      <div class="therapist-shadow-head">
        <h2 id="therapist-add-title">درمانگر جدید</h2>
        <a class="therapist-shadow-close" href="<?= e(url('/secretary/therapists')) ?>" aria-label="بستن">×</a>
      </div>
      <p class="muted" style="margin:0 0 .8rem">همان اطلاعاتی که درمانگر هنگام ساخت حساب و تکمیل پروفایل وارد می‌کند.</p>
      <form class="form-stack" method="post" action="<?= e(url('/secretary/therapists')) ?>" enctype="multipart/form-data">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="create">
        <div class="doc-profile-grid">
          <label><span class="label">نام</span><input class="input" name="first_name" required></label>
          <label><span class="label">نام انگلیسی</span><input class="input" name="name_en" required dir="ltr"></label>
          <label><span class="label">نام خانوادگی</span><input class="input" name="last_name" required></label>
          <label><span class="label">نام خانوادگی انگلیسی</span><input class="input" name="surname" required dir="ltr"></label>
          <label><span class="label">موبایل</span><input class="input" name="phone" required dir="ltr" inputmode="tel"></label>
          <label><span class="label">ایمیل</span><input class="input" name="email" type="email" required dir="ltr"></label>
          <label><span class="label">نام کاربری</span><input class="input" name="username" dir="ltr" placeholder="خالی بماند تا از نام انگلیسی ساخته شود"></label>
          <label>
            <span class="label">جنسیت</span>
            <select class="input" name="gender" required>
              <option value="">انتخاب کنید</option>
              <option value="female">زن</option>
              <option value="male">مرد</option>
            </select>
          </label>
          <label><span class="label">رمز عبور</span><input class="input" name="password" type="password" required dir="ltr" minlength="<?= (int) password_min_length() ?>" autocomplete="new-password"></label>
          <label><span class="label">تکرار رمز</span><input class="input" name="password_confirm" type="password" required dir="ltr" minlength="<?= (int) password_min_length() ?>" autocomplete="new-password"></label>
          <label>
            <span class="label">آخرین مدرک</span>
            <select class="input" name="degree" required>
              <option value="">انتخاب کنید</option>
              <?php foreach (doctor_degree_options() as $key => $label): ?>
                <option value="<?= e($key) ?>"><?= e($label) ?></option>
              <?php endforeach; ?>
            </select>
          </label>
          <label><span class="label">شماره پروانه نظام</span><input class="input" name="license_no" required dir="ltr"></label>
          <label><span class="label">سال شروع فعالیت</span><input class="input" name="started_year" type="number" required dir="ltr" min="1330" max="<?= (int) $maxYear ?>"></label>
          <label><span class="label">هزینه جلسه (تومان)</span><input class="input" name="session_price" type="number" required dir="ltr" min="1" value="3000000"></label>
        </div>
        <div>
          <span class="label">رویکرد درمانی</span>
          <?= doctor_chip_picker_html('approaches', doctor_approach_options(), []) ?>
        </div>
        <div>
          <span class="label">حوزه درمان</span>
          <?= doctor_chip_picker_html('domains', doctor_domain_options(), []) ?>
        </div>
        <div>
          <span class="label">زمینه تخصصی</span>
          <?= doctor_chip_picker_html('focus', doctor_focus_options(), []) ?>
        </div>
        <label><span class="label">دوره‌های تخصصی، هر دوره یک خط</span><textarea class="input" name="courses" rows="4" required></textarea></label>
        <label><span class="label">درباره درمانگر</span><textarea class="input" name="bio" rows="4" required minlength="20"></textarea></label>
        <label><span class="label">عکس پروفایل</span><input class="input" type="file" name="avatar" accept="image/jpeg,image/png,image/webp" required></label>
        <button class="btn btn-primary" type="submit">ساخت حساب درمانگر</button>
      </form>
    </div>
  </div>
<?php endif; ?>
<?php
$inner = ob_get_clean();
$pageHead = '<style>
.therapist-desk-head{display:flex;justify-content:space-between;gap:1rem;align-items:flex-end;flex-wrap:wrap}
.therapist-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(14rem,1fr));gap:.75rem;margin-top:1rem}
.therapist-card{margin:0;display:flex;flex-direction:column;gap:.7rem}
.therapist-card-hit{display:flex;flex-direction:column;gap:.2rem;color:inherit;text-decoration:none}
.therapist-card-hit span,.therapist-card-hit em{font-style:normal;color:var(--muted);font-size:.85rem}
.therapist-shadow{position:fixed;inset:0;z-index:80;background:rgba(16,24,22,.55)}
.therapist-shadow-backdrop{position:absolute;inset:0}
.therapist-shadow-card{position:relative;z-index:1;width:min(46rem,calc(100% - 2rem));max-height:min(86vh,48rem);overflow:auto;margin:6vh auto 0;background:#fff;border-radius:1.1rem;box-shadow:0 24px 64px rgba(0,0,0,.28);padding:1.2rem 1.3rem 1.4rem}
.therapist-shadow-head{display:flex;justify-content:space-between;gap:1rem;align-items:flex-start}
.therapist-shadow-head h2{margin:0}
.therapist-shadow-close{font-size:1.6rem;line-height:1;text-decoration:none;color:#4a6670}
.doc-profile-grid{display:grid;grid-template-columns:1fr 1fr;gap:.7rem}
@media(max-width:700px){.doc-profile-grid{grid-template-columns:1fr}}
</style>';
$pageScripts = '
<script>
(function(){
  var buttons = document.querySelectorAll("[data-weekday-open]");
  var panels = document.querySelectorAll("[data-weekday-panel]");
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
  if (hash !== "" && document.querySelector("[data-weekday-panel=\"" + hash + "\"]")) openWeekday(hash);
  document.addEventListener("keydown", function(e){
    if (e.key === "Escape" && document.querySelector(".therapist-shadow")) {
      window.location.href = ' . json_encode(url('/secretary/therapists')) . ';
    }
  });
})();
</script>';

$role = (string) ($user['role'] ?? '');
if ($role === 'SECRETARY') {
    render_secretary_page('درمانگرها', $inner);
} elseif ($role === 'ADMIN') {
    require_once __DIR__ . '/../../includes/admin_panel.php';
    render_admin_page('درمانگرها', $inner);
} else {
    render_doctor_page('درمانگرها', $inner);
}
