<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/secretary_panel.php';
require_once __DIR__ . '/../../includes/doctor_panel.php';
require_once __DIR__ . '/../../includes/doctor_profile_fields.php';
require_once __DIR__ . '/../../includes/collaboration.php';
require_once __DIR__ . '/../../includes/therapist_weekday_panel.php';
require_once __DIR__ . '/../../includes/availability.php';

if (!function_exists('secretary_therapist_presence_span')) {
    /** بازهٔ نمایشی از ساعت‌های ذخیره‌شده. پایان، ساعت بعد از آخرین ساعت کامل است. */
    function secretary_therapist_presence_span(array $hours): string
    {
        $picked = [];
        foreach ($hours as $hour) {
            $hour = (int) $hour;
            if ($hour >= 0 && $hour <= 23) {
                $picked[$hour] = $hour;
            }
        }
        if ($picked === []) {
            return '';
        }
        $picked = array_values($picked);
        sort($picked);
        $parts = [];
        $start = $picked[0];
        $prev = $picked[0];
        $count = count($picked);
        for ($i = 1; $i <= $count; $i++) {
            $hour = $i < $count ? $picked[$i] : null;
            if ($hour !== null && $hour === $prev + 1) {
                $prev = $hour;
                continue;
            }
            $from = sprintf('%02d:00', $start);
            $endHour = $prev + 1;
            $to = $endHour >= 24 ? '24:00' : sprintf('%02d:00', $endHour);
            $parts[] = to_fa_digits($from) . ' تا ' . to_fa_digits($to);
            if ($hour === null) {
                break;
            }
            $start = $hour;
            $prev = $hour;
        }

        return implode('، ', $parts);
    }
}

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
$presenceByProfile = [];
$presenceProfileIds = [];
foreach ($rows as $presenceRow) {
    $presenceProfileId = trim((string) ($presenceRow['profile_id'] ?? ''));
    if ($presenceProfileId !== '') {
        $presenceProfileIds[$presenceProfileId] = $presenceProfileId;
    }
}
if ($presenceProfileIds !== []) {
    ensure_doctor_weekly_hours_schema($pdo);
    $presenceMarks = implode(',', array_fill(0, count($presenceProfileIds), '?'));
    $presenceStmt = $pdo->prepare('SELECT doctor_id, weekday, available_hours FROM doctor_weekly_hours WHERE doctor_id IN (' . $presenceMarks . ')');
    $presenceStmt->execute(array_values($presenceProfileIds));
    $presenceWeekdays = doctor_weekdays_sat_first();
    $presenceGrouped = [];
    foreach ($presenceStmt->fetchAll() ?: [] as $presenceHourRow) {
        $presenceDoctorId = (string) ($presenceHourRow['doctor_id'] ?? '');
        $presenceWeekday = (int) ($presenceHourRow['weekday'] ?? -1);
        if ($presenceDoctorId === '' || !isset($presenceWeekdays[$presenceWeekday])) {
            continue;
        }
        $presenceSpan = secretary_therapist_presence_span(appointment_hours_decode((string) ($presenceHourRow['available_hours'] ?? '')));
        if ($presenceSpan === '') {
            continue;
        }
        $presenceGrouped[$presenceDoctorId][$presenceWeekday] = $presenceSpan;
    }
    foreach ($presenceGrouped as $presenceDoctorId => $presenceByDay) {
        ksort($presenceByDay);
        $presenceLines = [];
        $presenceRunDays = [];
        $presenceRunSpan = '';
        $presencePush = static function () use (&$presenceLines, &$presenceRunDays, &$presenceRunSpan, $presenceWeekdays): void {
            if ($presenceRunDays === [] || $presenceRunSpan === '') {
                $presenceRunDays = [];
                $presenceRunSpan = '';
                return;
            }
            $presenceFirst = (string) ($presenceWeekdays[$presenceRunDays[0]] ?? '');
            $presenceLast = (string) ($presenceWeekdays[$presenceRunDays[count($presenceRunDays) - 1]] ?? '');
            if ($presenceFirst === '') {
                $presenceRunDays = [];
                $presenceRunSpan = '';
                return;
            }
            $presenceDayLabel = count($presenceRunDays) > 1 && $presenceLast !== ''
                ? $presenceFirst . ' تا ' . $presenceLast
                : $presenceFirst;
            $presenceLines[] = $presenceDayLabel . ' ' . $presenceRunSpan;
            $presenceRunDays = [];
            $presenceRunSpan = '';
        };
        foreach ($presenceByDay as $presenceWeekday => $presenceSpan) {
            $presencePrev = $presenceRunDays === [] ? null : $presenceRunDays[count($presenceRunDays) - 1];
            if ($presenceRunSpan !== '' && $presenceSpan === $presenceRunSpan && $presencePrev !== null && (int) $presenceWeekday === (int) $presencePrev + 1) {
                $presenceRunDays[] = (int) $presenceWeekday;
                continue;
            }
            $presencePush();
            $presenceRunDays = [(int) $presenceWeekday];
            $presenceRunSpan = $presenceSpan;
        }
        $presencePush();
        if ($presenceLines !== []) {
            $presenceByProfile[$presenceDoctorId] = $presenceLines;
        }
    }
}
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
if (!function_exists('build_name_transliterations_client_map')) {
    require_once __DIR__ . '/../../includes/name_transliterations.php';
}
$nameDict = build_name_transliterations_client_map($pdo);
ensure_doctor_profile_schema($pdo);
if (function_exists('ensure_users_gender_schema')) {
    ensure_users_gender_schema($pdo);
}
$editId = trim((string) ($_GET['edit'] ?? ''));
$editForm = null;
if ($editId !== '') {
    $editStmt = $pdo->prepare("SELECT id, username, name, email, phone, gender FROM users WHERE id=? AND role='DOCTOR' LIMIT 1");
    $editStmt->execute([$editId]);
    $editUser = $editStmt->fetch();
    if (!$editUser) {
        flash_set('error', 'درمانگر پیدا نشد.');
        redirect('/secretary/therapists');
    }
    $profileStmt = $pdo->prepare('SELECT * FROM doctor_profiles WHERE user_id=? LIMIT 1');
    $profileStmt->execute([$editId]);
    $editProfile = $profileStmt->fetch() ?: [];
    $editName = trim((string) ($editUser['name'] ?? ''));
    $editParts = preg_split('/\s+/u', $editName, 2, PREG_SPLIT_NO_EMPTY) ?: [];
    $editFirst = (string) ($editParts[0] ?? '');
    $editLast = (string) ($editParts[1] ?? '');
    $editNameEn = ($editFirst !== '' && function_exists('transliterate_persian_name'))
        ? (string) transliterate_persian_name($pdo, $editFirst, 'first')
        : '';
    $editSurname = ($editLast !== '' && function_exists('transliterate_persian_name'))
        ? (string) transliterate_persian_name($pdo, $editLast, 'last')
        : '';
    $editYear = (int) ($editProfile['started_year'] ?? 0);
    $editPrice = (int) ($editProfile['session_price'] ?? 0);
    $editForm = [
        'id' => $editId,
        'first_name' => $editFirst,
        'last_name' => $editLast,
        'name_en' => $editNameEn,
        'surname' => $editSurname,
        'phone' => (string) ($editUser['phone'] ?? ''),
        'email' => (string) ($editUser['email'] ?? ''),
        'username' => (string) ($editUser['username'] ?? ''),
        'gender' => (string) ($editUser['gender'] ?? ''),
        'degree' => (string) ($editProfile['degree'] ?? ''),
        'license_no' => (string) ($editProfile['license_no'] ?? ''),
        'started_year' => $editYear > 0 ? (string) $editYear : '',
        'session_price' => $editPrice > 0 ? to_fa_digits(number_format($editPrice)) : '',
        'courses' => (string) ($editProfile['courses'] ?? ''),
        'bio' => (string) ($editProfile['bio'] ?? ''),
        'avatar_url' => (string) ($editProfile['avatar_url'] ?? ''),
        'approaches' => doctor_profile_json_list($editProfile['approaches_json'] ?? ''),
        'domains' => doctor_profile_json_list($editProfile['domains_json'] ?? ''),
        'focus' => doctor_profile_json_list($editProfile['focus_json'] ?? ''),
    ];
}
$showForm = $editForm !== null || $showAdd;

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
          <?php $presenceLines = $presenceByProfile[$profileId] ?? []; ?>
          <?php if ($presenceLines): ?>
            <ul class="therapist-card-hours">
              <?php foreach ($presenceLines as $presenceLine): ?>
                <li><?= e($presenceLine) ?></li>
              <?php endforeach; ?>
            </ul>
          <?php endif; ?>
          <em><?= $on ? 'فعال' : 'غیرفعال' ?></em>
        </a>
      <?php else: ?>
        <div class="therapist-card-hit">
          <strong><?= e((string) $row['name']) ?></strong>
          <span>پروفایل هنوز ساخته نشده</span>
          <em>غیرفعال</em>
        </div>
      <?php endif; ?>
      <div class="therapist-card-actions">
        <a class="btn btn-outline btn-sm" href="<?= e(url('/secretary/therapists?edit=' . rawurlencode((string) $row['user_id']))) ?>">ویرایش</a>
        <form method="post" action="<?= e(url('/secretary/therapists')) ?>">
          <?= csrf_field() ?>
          <input type="hidden" name="user_id" value="<?= e((string) $row['user_id']) ?>">
          <input type="hidden" name="active" value="<?= $on ? '0' : '1' ?>">
          <button class="btn btn-outline btn-sm" type="submit"><?= $on ? 'غیرفعال' : 'فعال' ?></button>
        </form>
      </div>
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

<?php if ($showForm): ?>
  <?php
    $isEdit = $editForm !== null;
    $formValue = static function (string $key) use ($editForm): string {
        return $editForm !== null ? (string) ($editForm[$key] ?? '') : '';
    };
    $selectedApproaches = $isEdit ? (array) $editForm['approaches'] : [];
    $selectedDomains = $isEdit ? (array) $editForm['domains'] : [];
    $selectedFocus = $isEdit ? (array) $editForm['focus'] : [];
    $hasAvatar = $isEdit && trim((string) $editForm['avatar_url']) !== '';
  ?>
  <div class="therapist-shadow" id="therapist-add-shadow">
    <a class="therapist-shadow-backdrop" href="<?= e(url('/secretary/therapists')) ?>" aria-label="بستن"></a>
    <div class="therapist-shadow-card" role="dialog" aria-modal="true" aria-labelledby="therapist-add-title">
      <div class="therapist-shadow-head">
        <h2 id="therapist-add-title"><?= $isEdit ? 'ویرایش درمانگر' : 'درمانگر جدید' ?></h2>
        <a class="therapist-shadow-close" href="<?= e(url('/secretary/therapists')) ?>" aria-label="بستن">×</a>
      </div>
      <p class="muted" style="margin:0 0 .8rem"><?= $isEdit ? 'فقط نام، نام خانوادگی و موبایل لازم است. بقیه را اگر خواستید پر کنید.' : 'فقط نام، نام خانوادگی و موبایل لازم است. بقیه فیلدها اختیاری است.' ?></p>
      <form class="form-stack" method="post" action="<?= e(url('/secretary/therapists')) ?>" enctype="multipart/form-data"<?= $isEdit ? ' data-therapist-edit="1"' : '' ?>>
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="<?= $isEdit ? 'update' : 'create' ?>">
        <?php if ($isEdit): ?>
          <input type="hidden" name="user_id" value="<?= e($formValue('id')) ?>">
        <?php endif; ?>
        <div class="doc-profile-grid">
          <label><span class="label">نام</span><input class="input" name="first_name" id="th_first_name" required value="<?= e($formValue('first_name')) ?>"></label>
          <label><span class="label">نام انگلیسی</span><input class="input" name="name_en" id="th_name_en" dir="ltr" lang="en" autocomplete="off" placeholder="name" value="<?= e($formValue('name_en')) ?>"></label>
          <label><span class="label">نام خانوادگی</span><input class="input" name="last_name" id="th_last_name" required value="<?= e($formValue('last_name')) ?>"></label>
          <label><span class="label">نام خانوادگی انگلیسی</span><input class="input" name="surname" id="th_surname" dir="ltr" lang="en" autocomplete="off" placeholder="surname" value="<?= e($formValue('surname')) ?>"></label>
          <label><span class="label">موبایل</span><input class="input" name="phone" required dir="ltr" inputmode="tel" value="<?= e($formValue('phone')) ?>"></label>
          <label><span class="label">ایمیل</span><input class="input" name="email" type="email" dir="ltr" value="<?= e($formValue('email')) ?>"></label>
          <label><span class="label">نام کاربری</span><input class="input" name="username" id="th_username" dir="ltr" placeholder="خالی بماند تا ساخته شود" value="<?= e($formValue('username')) ?>"></label>
          <label>
            <span class="label">جنسیت</span>
            <select class="input" name="gender">
              <option value="">انتخاب کنید</option>
              <option value="female"<?= $formValue('gender') === 'female' ? ' selected' : '' ?>>زن</option>
              <option value="male"<?= $formValue('gender') === 'male' ? ' selected' : '' ?>>مرد</option>
            </select>
          </label>
          <label>
            <span class="label">رمز عبور</span>
            <span style="display:flex;gap:.5rem;align-items:stretch">
              <input class="input" name="password" id="th_password" type="password" dir="ltr" minlength="<?= (int) password_min_length() ?>" autocomplete="new-password" style="flex:1" placeholder="اختیاری">
              <button type="button" class="btn btn-outline" data-password-suggest data-password-target="th_password" data-password-confirm-target="th_password_confirm">پیشنهاد</button>
            </span>
          </label>
          <label><span class="label">تکرار رمز</span><input class="input" name="password_confirm" id="th_password_confirm" type="password" dir="ltr" minlength="<?= (int) password_min_length() ?>" autocomplete="new-password" placeholder="اختیاری"></label>
          <label>
            <span class="label">آخرین مدرک</span>
            <select class="input" name="degree">
              <option value="">انتخاب کنید</option>
              <?php foreach (doctor_degree_options() as $key => $label): ?>
                <option value="<?= e($key) ?>"<?= $formValue('degree') === $key ? ' selected' : '' ?>><?= e($label) ?></option>
              <?php endforeach; ?>
            </select>
          </label>
          <label><span class="label">شماره پروانه نظام</span><input class="input" name="license_no" dir="ltr" value="<?= e($formValue('license_no')) ?>"></label>
          <label><span class="label">سال شروع فعالیت</span><input class="input" name="started_year" type="text" dir="ltr" inputmode="numeric" autocomplete="off" placeholder="مثلاً <?= e(to_fa_digits((string) max(1330, $maxYear - 8))) ?>" value="<?= e($formValue('started_year')) ?>"></label>
          <label>
            <span class="label">هزینه جلسه (تومان)</span>
            <input class="input" name="session_price" type="text" dir="ltr" inputmode="numeric" autocomplete="off" placeholder="مبلغ را بنویسید" data-money value="<?= e($formValue('session_price')) ?>">
          </label>
        </div>
        <?php
          $presenceHours = appointment_presence_hours();
        ?>
        <div>
          <span class="label">ساعات حضور</span>
          <p class="muted" style="margin:.2rem 0 .6rem;font-size:.85rem;line-height:1.7">روزها را انتخاب کنید و ساعت شروع و پایان را از دکمه‌ها بزنید. از ۹ صبح تا ۸ شب. اگر خالی بماند، ساعتی ثبت نمی‌شود.</p>
          <div class="therapist-presence-days">
            <?php foreach (doctor_weekdays_sat_first() as $presenceDay => $presenceLabel): ?>
              <label class="pick-chip">
                <input type="checkbox" name="presence_days[]" value="<?= (int) $presenceDay ?>">
                <span><?= e($presenceLabel) ?></span>
              </label>
            <?php endforeach; ?>
          </div>
          <div class="avail-hour-range" data-presence-range style="margin-top:.7rem">
            <input type="hidden" name="presence_from" id="presence_from" value="">
            <input type="hidden" name="presence_to" id="presence_to" value="">
            <div>
              <span class="label">از ساعت</span>
              <div class="avail-hour-chips" data-presence-role="from" role="listbox" aria-label="از ساعت">
                <?php foreach ($presenceHours as $presenceHour): ?>
                  <?php $presenceSlot = sprintf('%02d:00', (int) $presenceHour); ?>
                  <button type="button" class="avail-hour-chip" data-presence-time="<?= e($presenceSlot) ?>"><?= e(appointment_hour_chip_label((int) $presenceHour)) ?></button>
                <?php endforeach; ?>
              </div>
            </div>
            <div style="margin-top:.75rem">
              <span class="label">تا ساعت</span>
              <div class="avail-hour-chips" data-presence-role="to" role="listbox" aria-label="تا ساعت">
                <?php foreach ($presenceHours as $presenceHour): ?>
                  <?php $presenceSlot = sprintf('%02d:00', (int) $presenceHour); ?>
                  <button type="button" class="avail-hour-chip" data-presence-time="<?= e($presenceSlot) ?>"><?= e(appointment_hour_chip_label((int) $presenceHour)) ?></button>
                <?php endforeach; ?>
              </div>
            </div>
          </div>
        </div>
        <div>
          <span class="label">رویکرد درمانی</span>
          <?= doctor_chip_picker_html('approaches', doctor_approach_options(), $selectedApproaches) ?>
        </div>
        <div>
          <span class="label">حوزه درمان</span>
          <?= doctor_chip_picker_html('domains', doctor_domain_options(), $selectedDomains) ?>
        </div>
        <div>
          <span class="label">زمینه تخصصی</span>
          <?= doctor_chip_picker_html('focus', doctor_focus_options(), $selectedFocus) ?>
        </div>
        <label><span class="label">دوره‌های تخصصی، هر دوره یک خط</span><textarea class="input" name="courses" rows="4"><?= e($formValue('courses')) ?></textarea></label>
        <label><span class="label">درباره درمانگر</span><textarea class="input" name="bio" rows="4"><?= e($formValue('bio')) ?></textarea></label>
        <label>
          <span class="label">عکس پروفایل</span>
          <input class="input" type="file" name="avatar" accept="image/jpeg,image/png,image/webp">
          <?php if ($hasAvatar): ?>
            <span class="muted" style="display:block;margin-top:.35rem;font-size:.82rem">عکس فعلی می‌ماند، مگر عکس جدید انتخاب کنید.</span>
          <?php endif; ?>
        </label>
        <button class="btn btn-primary" type="submit"><?= $isEdit ? 'ذخیره تغییرات' : 'ساخت حساب درمانگر' ?></button>
      </form>
    </div>
  </div>
<?php endif; ?>
<?php
$inner = ob_get_clean();
$pageHead = '<style>
.therapist-desk-head{display:flex;justify-content:space-between;gap:1rem;align-items:flex-end;flex-wrap:wrap}
.therapist-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(14rem,1fr));gap:.75rem;margin-top:1rem;align-items:stretch}
.therapist-card{margin:0;height:100%;display:flex;flex-direction:column;gap:.7rem}
.therapist-card-actions{display:flex;gap:.4rem;align-items:center;flex-wrap:wrap;margin-top:auto;padding-top:.35rem}
.therapist-card-actions form{margin:0}
.therapist-card-hit{display:flex;flex-direction:column;gap:.2rem;color:inherit;text-decoration:none;flex:1 1 auto}
.therapist-card-hit span,.therapist-card-hit em{font-style:normal;color:var(--muted);font-size:.85rem}
.therapist-card-hours{display:flex;flex-direction:column;gap:.12rem;margin:.35rem 0 0;padding:0;list-style:none}
.therapist-card-hours li{color:var(--fg);font-size:.8rem;line-height:1.6}
.therapist-shadow{position:fixed;inset:0;z-index:80;background:rgba(16,24,22,.55)}
.therapist-shadow-backdrop{position:absolute;inset:0}
.therapist-shadow-card{position:relative;z-index:1;width:min(46rem,calc(100% - 2rem));max-height:min(86vh,48rem);overflow:auto;margin:6vh auto 0;background:#fff;border-radius:1.1rem;box-shadow:0 24px 64px rgba(0,0,0,.28);padding:1.2rem 1.3rem 1.4rem}
.therapist-shadow-head{display:flex;justify-content:space-between;gap:1rem;align-items:flex-start}
.therapist-shadow-head h2{margin:0}
.therapist-shadow-close{font-size:1.6rem;line-height:1;text-decoration:none;color:#4a6670}
.doc-profile-grid{display:grid;grid-template-columns:1fr 1fr;gap:.7rem}
.therapist-presence-days{display:flex;flex-wrap:wrap;gap:.4rem}
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
  var presenceRoot = document.querySelector("[data-presence-range]");
  if (presenceRoot) {
    var fromInput = document.getElementById("presence_from");
    var toInput = document.getElementById("presence_to");
    function syncPresence(role, value){
      var box = presenceRoot.querySelector("[data-presence-role=\"" + role + "\"]");
      var input = role === "from" ? fromInput : toInput;
      if (input) input.value = value || "";
      if (!box) return;
      box.querySelectorAll("[data-presence-time]").forEach(function(btn){
        btn.classList.toggle("is-on", btn.getAttribute("data-presence-time") === value);
      });
    }
    presenceRoot.addEventListener("click", function(e){
      var btn = e.target && e.target.closest ? e.target.closest("[data-presence-time]") : null;
      if (!btn || !presenceRoot.contains(btn)) return;
      e.preventDefault();
      var roleBox = btn.parentNode;
      var role = roleBox ? roleBox.getAttribute("data-presence-role") : "";
      if (!role) return;
      syncPresence(role, btn.getAttribute("data-presence-time") || "");
    });
  }
})();
</script>
<script src="' . e(url('/assets/js/name-transliterate.js')) . '?v=20261014t"></script>
<script src="' . e(url('/assets/js/money-words.js')) . '?v=20261014m"></script>
<script>
(function(){
  if (!window.bindNameTransliteration) return;
  if (!document.getElementById("th_first_name")) return;
  bindNameTransliteration({
    firstName: document.getElementById("th_first_name"),
    lastName: document.getElementById("th_last_name"),
    nameEn: document.getElementById("th_name_en"),
    surname: document.getElementById("th_surname"),
    username: document.getElementById("th_username"),
    transliterateUrl: ' . json_encode(url('/api/transliterate-name')) . ',
    nameDict: ' . json_encode($nameDict, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) . '
  });
  if (document.querySelector("[data-therapist-edit]")) {
    var savedUser = document.getElementById("th_username");
    if (savedUser && savedUser.value) savedUser.dispatchEvent(new Event("input", { bubbles: true }));
    ["th_name_en", "th_surname"].forEach(function (id) {
      var el = document.getElementById(id);
      if (el && el.value) el.dispatchEvent(new Event("input", { bubbles: true }));
    });
  }
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
