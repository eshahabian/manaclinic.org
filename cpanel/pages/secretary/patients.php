<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/secretary_panel.php';
require_once __DIR__ . '/../../includes/secretary_patient.php';
require_once __DIR__ . '/../../includes/user_referral.php';
require_once __DIR__ . '/../../includes/outreach.php';

$user = require_login(['SECRETARY']);

users_backfill_created_by($pdo);

$doctors = secretary_active_doctors($pdo);
$nameDict = build_name_transliterations_client_map($pdo);
ensure_outreach_schema($pdo);
ensure_user_referral_schema($pdo);
$smsPending = 0;
try {
    $smsPending = (int) $pdo->query("SELECT COUNT(*) FROM sms_outbox WHERE status='PENDING'")->fetchColumn();
} catch (Throwable $ignored) {
    $smsPending = 0;
}
$accountUsers = secretary_patient_directory($pdo);

$doctorNameById = [];
$activeDoctorIds = [];
foreach ($doctors as $doctor) {
    $doctorId = (string) ($doctor['id'] ?? '');
    if ($doctorId === '') {
        continue;
    }
    $doctorNameById[$doctorId] = (string) ($doctor['name'] ?? '');
    $activeDoctorIds[] = $doctorId;
}
$accountIds = [];
foreach ($accountUsers as $row) {
    if (($row['kind'] ?? '') === 'user' && (string) ($row['id'] ?? '') !== '') {
        $accountIds[] = (string) $row['id'];
    }
}
$patientNotes = patient_secretary_notes_grouped($pdo, $accountIds);
$patientAppts = patient_secretary_appointment_briefs($pdo, $accountIds);
$patientMore = [];
$activatePeople = [];
foreach ($accountUsers as $row) {
    $pid = (string) ($row['id'] ?? '');
    $profileGaps = secretary_patient_profile_gaps($row, $activeDoctorIds);
    if ($pid !== '' && secretary_patient_is_inactive($row) && secretary_patient_profile_missing($row, $activeDoctorIds)) {
        $parts = secretary_person_name_parts((string) ($row['name'] ?? ''));
        $activatePeople[$pid] = [
            'kind' => (string) ($row['kind'] ?? 'user'),
            'id' => $pid,
            'name' => (string) ($row['name'] ?? ''),
            'first' => $parts['first'],
            'last' => $parts['last'],
            'phone' => trim((string) ($row['phone'] ?? '')),
            'doctor' => (string) ($row['preferred_doctor_id'] ?? ''),
            'referral' => (string) ($row['referral_source'] ?? ''),
            'missing' => ($row['kind'] ?? '') === 'outreach' ? [] : $profileGaps,
        ];
    }
    if (($row['kind'] ?? '') !== 'user') {
        continue;
    }
    $docId = (string) ($row['preferred_doctor_id'] ?? '');
    $createdAt = trim((string) ($row['created_at'] ?? ''));
    $lastLogin = trim((string) ($row['last_login_at'] ?? ''));
    $brief = $patientAppts[$pid] ?? ['next' => '', 'last' => '', 'upcoming' => to_fa_digits('0')];
    $patientMore[$pid] = [
        'name' => (string) ($row['name'] ?? ''),
        'username' => (string) ($row['username'] ?? ''),
        'phone' => trim((string) ($row['phone'] ?? '')),
        'doctor' => (string) ($doctorNameById[$docId] ?? ''),
        'referral' => user_referral_label((string) ($row['referral_source'] ?? '')),
        'createdBy' => user_created_by_label([
            'name' => $row['created_by_name'] ?? '',
            'username' => $row['created_by_username'] ?? '',
            'role' => $row['created_by_role'] ?? '',
        ], 'نامشخص'),
        'createdAt' => $createdAt !== '' ? format_fa_datetime($createdAt) : '',
        'lastLogin' => $lastLogin !== '' ? format_fa_datetime($lastLogin) : '',
        'next' => (string) ($brief['next'] ?? ''),
        'last' => (string) ($brief['last'] ?? ''),
        'upcoming' => (string) ($brief['upcoming'] ?? to_fa_digits('0')),
        'profile' => strtoupper((string) ($row['role'] ?? '')) === 'PATIENT'
            ? url('/secretary/patients/' . rawurlencode($pid))
            : '',
        'notes' => $patientNotes[$pid] ?? [],
    ];
}

ob_start();
?>
<div class="secretary-patients-head">
  <div>
    <h1>مراجعه‌کنندگان</h1>
    <p class="muted" style="margin-top:.35rem;font-size:.9rem">همه در همین فهرست هستند. دکمه روشن، وضعیت فعلی است. کاربر غیرفعال تا فعال نشود و پروفایلش ساخته نشود نوبت نمی‌گیرد.</p>
  </div>
</div>

<div id="patients-active">
<form class="panel form-stack secretary-add-patient" method="post" action="<?= e(url('/secretary/patients')) ?>" id="secretary-patient-form">
  <?= csrf_field() ?>
  <p style="margin:0;font-weight:600">مراجعه‌کننده جدید</p>
  <?php
    $secretaryPatientHint = 'بدون گرفتن نوبت هم می‌توانید نفر جدید ثبت کنید. با تایپ نام فارسی، معادل انگلیسی پیشنهاد می‌شود. اگر صفحه بسته شود یا نت قطع شود، اطلاعات همین‌جا می‌ماند.';
    $secretaryPatientKindSwitch = true;
    require __DIR__ . '/../../includes/secretary_new_patient_fields.php';
  ?>
  <p id="sec-patient-error" style="color:var(--danger);display:none;font-size:.9rem"></p>
  <button class="btn btn-primary" type="submit" id="patient-entry-submit">ثبت مراجعه‌کننده</button>
  <?php if (!empty($secretaryPatientKindSwitch)): ?></fieldset><?php endif; ?>
</form>
<div id="patient-entry-inactive" hidden>
  <p class="muted" style="margin:.85rem 0 0;line-height:1.8">
    فقط نام و شماره ذخیره می‌شود و همان نفر در فهرست با وضعیت غیرفعال دیده می‌شود.
    پیامک در صف: <?= e(to_fa_digits((string) $smsPending)) ?>
  </p>
  <div class="grid-2" style="margin-top:1rem">
    <form class="panel" method="post" action="<?= e(url('/secretary/patients')) ?>" style="margin:0">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="add_inactive">
      <h2 style="margin:0 0 .75rem;font-size:1rem">یک نفر</h2>
      <label>
        <span class="label">نام</span>
        <input class="input" name="name" required>
      </label>
      <label style="display:block;margin-top:.6rem">
        <span class="label">شماره تماس</span>
        <input class="input" name="phone" required dir="ltr" inputmode="tel">
      </label>
      <label style="display:block;margin-top:.6rem">
        <span class="label">یادداشت</span>
        <input class="input" name="note">
      </label>
      <button class="btn btn-primary" type="submit" style="margin-top:.8rem">ذخیره</button>
    </form>
    <form class="panel" method="post" action="<?= e(url('/secretary/patients')) ?>" style="margin:0">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="import_inactive">
      <h2 style="margin:0 0 .75rem;font-size:1rem">چند نفر با هم</h2>
      <p class="muted" style="margin:0 0 .5rem;font-size:.85rem">هر خط: نام و بعد شماره. مثلاً «مهراد بابایی ۰۹۱۲۱۲۳۴۵۶۷»</p>
      <textarea class="input" name="lines" rows="6" required></textarea>
      <button class="btn btn-primary" type="submit" style="margin-top:.8rem">افزودن فهرست</button>
    </form>
  </div>
  <form class="panel" method="post" action="<?= e(url('/secretary/patients')) ?>" style="margin-top:1rem">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="queue_inactive">
    <h2 style="margin:0 0 .5rem;font-size:1rem">پیامک دستی به همین فهرست</h2>
    <textarea class="input" name="body" rows="3" required placeholder="متن پیامک، مثلاً اعلام یک کارگاه"></textarea>
    <button class="btn btn-primary" type="submit" style="margin-top:.75rem">گذاشتن در صف پیامک</button>
  </form>
</div>

<div class="patient-list-filters">
  <div>
    <label class="label" for="patient-filter">جستجو</label>
    <input class="input" type="search" id="patient-filter" placeholder="نام، فامیل، نام کاربری یا موبایل" autocomplete="off" aria-controls="secretary-account-edit">
  </div>
  <div>
    <label class="label" for="patient-status-filter">وضعیت</label>
    <select class="input" id="patient-status-filter" aria-controls="secretary-account-edit">
      <option value="">همه</option>
      <option value="active">فعال</option>
      <option value="inactive">غیرفعال</option>
    </select>
  </div>
  <div>
    <label class="label" for="patient-doctor-filter">درمانگر</label>
    <select class="input" id="patient-doctor-filter" aria-controls="secretary-account-edit">
      <option value="">همه درمانگرها</option>
      <?php foreach ($doctors as $doctor): ?>
        <option value="<?= e((string) ($doctor['id'] ?? '')) ?>"><?= e((string) ($doctor['name'] ?? '')) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
</div>

<h2 style="margin:1rem 0 .35rem;font-size:1.05rem">فهرست مراجعه‌کنندگان</h2>
<p class="muted" style="margin:0 0 .75rem;font-size:.9rem">درمانگر، معرف و موبایل حساب‌های ساخته‌شده از اینجا عوض می‌شود. برای کسی که هنوز حساب ندارد، دکمه فعال فرم ثبت‌نام را باز می‌کند. اگر حساب دارد و دوباره فعال می‌شود، فقط موردهای ناقص پروفایل پرسیده می‌شود.</p>
<div class="panel admin-users-board" style="padding:0;overflow:auto">
  <table class="table" id="secretary-account-edit">
    <thead>
      <tr>
        <th>نام</th>
        <th>نام کاربری</th>
        <th>درمانگر</th>
        <th>معرف</th>
        <th>موبایل</th>
        <th></th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($accountUsers as $u): ?>
        <?php
          $uid = (string) ($u['id'] ?? '');
          $formId = 'sec-user-' . preg_replace('/[^a-zA-Z0-9_-]/', '', $uid);
          $compact = 'min-width:9rem;padding:.35rem .5rem;font-size:.85rem';
          $accountName = trim((string) ($u['name'] ?? ''));
          $accountParts = preg_split('/\s+/u', $accountName, -1, PREG_SPLIT_NO_EMPTY) ?: [];
          $accountFirst = (string) ($accountParts[0] ?? '');
          $accountLast = count($accountParts) > 1 ? implode(' ', array_slice($accountParts, 1)) : '';
          $accountPhone = trim((string) ($u['phone'] ?? ''));
          $accountPhoneDigits = preg_replace('/\D+/u', '', $accountPhone) ?? '';
          $accountSearch = mb_strtolower(trim(
              $accountName . ' ' . $accountFirst . ' ' . $accountLast . ' ' .
              (string) ($u['username'] ?? '') . ' ' . $accountPhone . ' ' . $accountPhoneDigits
          ));
        ?>
        <?php
          $isUser = ($u['kind'] ?? '') === 'user';
          $isPatient = strtoupper((string) ($u['role'] ?? '')) === 'PATIENT';
          $inactive = secretary_patient_is_inactive($u);
          $needsProfile = secretary_patient_profile_missing($u, $activeDoctorIds);
          $canToggle = $isPatient || !$isUser;
        ?>
        <tr class="secretary-account-row<?= $inactive ? ' is-inactive-person' : '' ?>" data-search="<?= e($accountSearch) ?>" data-status="<?= $inactive ? 'inactive' : 'active' ?>" data-doctor="<?= e((string) ($u['preferred_doctor_id'] ?? '')) ?>">
          <td>
            <div class="patient-name-cell">
              <?php if ($isUser): ?>
                <button type="button" class="patient-more-btn" data-patient-open="<?= e($uid) ?>" aria-label="توضیحات بیشتر درباره <?= e((string) $u['name']) ?>">⋯</button>
              <?php endif; ?>
              <span><?= e((string) $u['name']) ?></span>
            </div>
          </td>
          <td dir="ltr"><?= $isUser ? e((string) $u['username']) : '<span class="muted">بدون حساب</span>' ?></td>
          <?php if ($isUser): ?>
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
              <option value="therapist"<?= (($u['referral_source'] ?? '') === 'therapist') ? ' selected' : '' ?>>درمانگر</option>
              <option value="clinic"<?= (($u['referral_source'] ?? '') === 'clinic') ? ' selected' : '' ?>>کلینیک</option>
            </select>
          </td>
          <td>
            <input class="input" name="phone" form="<?= e($formId) ?>" dir="ltr" inputmode="tel" value="<?= e((string) ($u['phone'] ?? '')) ?>" required aria-label="موبایل" style="min-width:8rem;padding:.35rem .5rem;font-size:.85rem">
          </td>
          <?php else: ?>
          <td class="muted">بعد از فعال‌سازی</td>
          <td class="muted">بعد از فعال‌سازی</td>
          <td dir="ltr"><?= e($accountPhone) ?></td>
          <?php endif; ?>
          <td>
            <?php if ($isUser): ?>
            <form id="<?= e($formId) ?>" method="post" action="<?= e(url('/secretary/users')) ?>" style="margin:0 0 .35rem">
              <?= csrf_field() ?>
              <input type="hidden" name="user_id" value="<?= e($uid) ?>">
              <button class="btn btn-primary btn-sm sec-account-action" type="submit">ذخیره</button>
            </form>
            <?php endif; ?>
            <?php if ($canToggle): ?>
            <div class="sec-state-switch" role="group" aria-label="وضعیت <?= e($accountName) ?>">
              <?php if (!$inactive): ?>
                <span class="sec-state-btn is-on">فعال</span>
                <form method="post" action="<?= e(url('/secretary/users')) ?>">
                  <?= csrf_field() ?>
                  <input type="hidden" name="action" value="set_disabled">
                  <input type="hidden" name="user_id" value="<?= e($uid) ?>">
                  <input type="hidden" name="disabled" value="1">
                  <button class="sec-state-btn" type="submit">غیرفعال</button>
                </form>
              <?php else: ?>
                <?php if ($needsProfile): ?>
                  <button class="sec-state-btn" type="button" data-open-activate="<?= e($uid) ?>">فعال</button>
                <?php else: ?>
                  <form method="post" action="<?= e(url('/secretary/users')) ?>">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="set_disabled">
                    <input type="hidden" name="user_id" value="<?= e($uid) ?>">
                    <input type="hidden" name="disabled" value="0">
                    <button class="sec-state-btn" type="submit">فعال</button>
                  </form>
                <?php endif; ?>
                <span class="sec-state-btn is-on is-inactive">غیرفعال</span>
              <?php endif; ?>
            </div>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$accountUsers): ?>
        <tr><td colspan="6" class="muted" style="text-align:center;padding:1.2rem">مراجعه‌کننده‌ای نیست.</td></tr>
      <?php endif; ?>
    </tbody>
  </table>
</div>
<p class="muted" id="patient-filter-empty" hidden>موردی با این جستجو یا فیلتر نیست.</p>
</div>
<script type="application/json" id="patient-more-data"><?= json_encode($patientMore, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_FORCE_OBJECT) ?></script>
<div class="patient-more-modal" id="patient-more-modal" hidden>
  <div class="patient-more-backdrop" data-patient-more-close></div>
  <div class="patient-more-panel" role="dialog" aria-modal="true" aria-labelledby="patient-more-title">
    <div class="patient-more-head">
      <h2 id="patient-more-title"></h2>
      <button type="button" class="patient-more-close" data-patient-more-close aria-label="بستن">×</button>
    </div>
    <dl class="patient-more-facts">
      <div><dt>نام کاربری</dt><dd id="patient-more-username" dir="ltr"></dd></div>
      <div><dt>موبایل</dt><dd id="patient-more-phone" dir="ltr"></dd></div>
      <div><dt>درمانگر</dt><dd id="patient-more-doctor"></dd></div>
      <div><dt>معرف</dt><dd id="patient-more-referral"></dd></div>
      <div><dt>ثبت‌کننده</dt><dd id="patient-more-created-by"></dd></div>
      <div><dt>تاریخ ساخت حساب</dt><dd id="patient-more-created-at"></dd></div>
      <div><dt>آخرین ورود</dt><dd id="patient-more-login"></dd></div>
      <div><dt>نوبت‌های پیش‌رو</dt><dd id="patient-more-upcoming"></dd></div>
      <div><dt>نوبت بعدی</dt><dd id="patient-more-next"></dd></div>
      <div><dt>آخرین نوبت</dt><dd id="patient-more-last"></dd></div>
    </dl>
    <p style="margin:.85rem 0 0"><a class="btn btn-outline btn-sm" id="patient-more-profile" href="#">پرونده نوبت‌ها و کارگاه‌ها</a></p>
    <h3 class="patient-more-notes-title">یادداشت‌ها</h3>
    <div id="patient-more-notes"></div>
    <form class="patient-more-note-form" method="post" action="<?= e(url('/secretary/users')) ?>">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="add_patient_note">
      <input type="hidden" name="user_id" id="patient-more-user" value="">
      <label>
        <span class="label">افزودن مورد</span>
        <textarea class="input" name="body" rows="3" maxlength="1000" required placeholder="یادداشت درباره این مراجعه‌کننده"></textarea>
      </label>
      <button class="btn btn-primary btn-sm" type="submit">ثبت یادداشت</button>
    </form>
  </div>
</div>
<script type="application/json" id="patient-activate-data"><?= json_encode($activatePeople, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_FORCE_OBJECT) ?></script>
<div class="patient-more-modal" id="patient-activate-modal" hidden>
  <div class="patient-more-backdrop" data-activate-close></div>
  <div class="patient-more-panel" role="dialog" aria-modal="true" aria-labelledby="patient-activate-title">
    <div class="patient-more-head">
      <h2 id="patient-activate-title">ثبت‌نام و ساخت پروفایل</h2>
      <button type="button" class="patient-more-close" data-activate-close aria-label="بستن">×</button>
    </div>
    <p class="muted" id="patient-activate-lead" style="margin:.7rem 0 0;line-height:1.7">تا این فرم ذخیره نشود، کاربر فعال نمی‌شود و نوبت برایش ثبت نمی‌شود.</p>
    <form id="patient-activate-register" class="form-stack" method="post" action="<?= e(url('/secretary/patients')) ?>" hidden style="display:none;margin-top:1rem">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="activate_contact">
      <input type="hidden" name="outreach_id" id="act-outreach-id" value="">
      <div class="grid-2">
        <div>
          <label class="label" for="act_first_name">نام</label>
          <input class="input" name="new_first_name" id="act_first_name" required>
        </div>
        <div>
          <label class="label" for="act_name_en">name</label>
          <input class="input" name="new_name_en" id="act_name_en" dir="ltr" required>
        </div>
        <div>
          <label class="label" for="act_last_name">نام خانوادگی</label>
          <input class="input" name="new_last_name" id="act_last_name" required>
        </div>
        <div>
          <label class="label" for="act_surname">surname</label>
          <input class="input" name="new_surname" id="act_surname" dir="ltr" required>
        </div>
        <div style="grid-column:1/-1">
          <label class="label" for="act_doctor">درمانگر</label>
          <select class="input" name="new_preferred_doctor_id" id="act_doctor" required>
            <option value="">انتخاب درمانگر</option>
            <?php foreach ($doctors as $doctor): ?>
              <option value="<?= e((string) $doctor['id']) ?>"><?= e((string) $doctor['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div style="grid-column:1/-1">
          <label class="label" for="act_referral">معرف</label>
          <?php echo user_referral_options_html('new_referral_source', 'act_referral', '', true); ?>
        </div>
        <div style="grid-column:1/-1">
          <label class="label" for="act_phone">موبایل</label>
          <input class="input" name="new_phone" id="act_phone" dir="ltr" inputmode="tel" required>
        </div>
        <div style="grid-column:1/-1">
          <label class="label" for="act_username">نام کاربری</label>
          <div style="display:flex;gap:.5rem">
            <input class="input" name="new_username" id="act_username" dir="ltr" required style="flex:1">
            <button type="button" class="btn btn-outline" id="act-suggest-username">پیشنهاد</button>
          </div>
          <p class="muted" id="act-username-hint" style="margin:.4rem 0 0;font-size:.8rem"></p>
        </div>
        <div>
          <label class="label" for="act_password">رمز عبور</label>
          <input class="input" name="new_password" id="act_password" type="password" dir="ltr" minlength="6" required>
        </div>
        <div>
          <label class="label" for="act_password_confirm">تکرار رمز عبور</label>
          <input class="input" name="new_password_confirm" id="act_password_confirm" type="password" dir="ltr" minlength="6" required>
        </div>
      </div>
      <p id="act-register-error" style="color:var(--danger);display:none;font-size:.9rem"></p>
      <button class="btn btn-primary" type="submit">ساخت پروفایل و فعال‌سازی</button>
    </form>
    <form id="patient-activate-profile" class="form-stack" method="post" action="<?= e(url('/secretary/patients')) ?>" hidden style="display:none;margin-top:1rem">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="activate_profile">
      <input type="hidden" name="user_id" id="act-user-id" value="">
      <label class="act-gap" data-gap="phone">
        <span class="label">موبایل</span>
        <input class="input" name="legacy_phone" id="act-legacy-phone" dir="ltr" inputmode="tel">
      </label>
      <label class="act-gap" data-gap="doctor">
        <span class="label">درمانگر</span>
        <select class="input" name="legacy_doctor_id" id="act-legacy-doctor">
          <option value="">انتخاب درمانگر</option>
          <?php foreach ($doctors as $doctor): ?>
            <option value="<?= e((string) $doctor['id']) ?>"><?= e((string) $doctor['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label class="act-gap" data-gap="referral">
        <span class="label">معرف</span>
        <?php echo user_referral_options_html('legacy_referral', 'act-legacy-referral', '', false); ?>
      </label>
      <button class="btn btn-primary" type="submit" style="margin-top:.9rem">ذخیره پروفایل و فعال‌سازی</button>
    </form>
  </div>
</div>
<?php
$inner = ob_get_clean();
$pageScripts = '
<script src="' . e(url('/assets/js/search-select.js')) . '?v=20260906p"></script>
<script src="' . e(url('/assets/js/name-transliterate.js')) . '?v=20260906p"></script>
<script src="' . e(url('/assets/js/form-draft.js')) . '?v=20260906p"></script>
<script src="' . e(url('/assets/js/secretary-patient-form.js')) . '?v=20261007ref"></script>
<script>
(function(){
  var form = document.getElementById("secretary-patient-form");
  var errEl = document.getElementById("sec-patient-error");
  bindSecretaryPatientFields({
    form: form,
    transliterateUrl: ' . json_encode(url('/api/transliterate-name')) . ',
    nameDict: ' . json_encode($nameDict, JSON_UNESCAPED_UNICODE) . ',
    draftKey: "mana.secretary.patient.create",
    clearParams: ["added"]
  });
  var activeBox = document.getElementById("patient-entry-active");
  var inactiveBox = document.getElementById("patient-entry-inactive");
  var submitBtn = document.getElementById("patient-entry-submit");
  function showEntryKind(kind) {
    var active = kind !== "inactive";
    if (activeBox) {
      activeBox.hidden = !active;
      activeBox.disabled = !active;
    }
    if (inactiveBox) inactiveBox.hidden = active;
    if (submitBtn) submitBtn.hidden = !active;
    if (errEl && !active) errEl.style.display = "none";
    document.querySelectorAll("[data-entry-kind]").forEach(function (btn) {
      var on = btn.getAttribute("data-entry-kind") === (active ? "active" : "inactive");
      btn.classList.toggle("btn-primary", on);
      btn.classList.toggle("btn-outline", !on);
      btn.setAttribute("aria-selected", on ? "true" : "false");
    });
  }
  document.querySelectorAll("[data-entry-kind]").forEach(function (btn) {
    btn.addEventListener("click", function () {
      showEntryKind(btn.getAttribute("data-entry-kind") || "active");
    });
  });
  var startInactive = location.hash === "#patient-entry-inactive" || location.hash === "#outreach-tools";
  if (startInactive) showEntryKind("inactive");
  if (startInactive && inactiveBox && inactiveBox.scrollIntoView) {
    inactiveBox.scrollIntoView({ block: "nearest" });
  }
  if (form) {
    form.addEventListener("submit", function(e){
      if (inactiveBox && !inactiveBox.hidden) {
        e.preventDefault();
        return;
      }
      if (!validateSecretaryNewPatient(errEl)) e.preventDefault();
    });
  }

  var input = document.getElementById("patient-filter");
  var statusFilter = document.getElementById("patient-status-filter");
  var doctorFilter = document.getElementById("patient-doctor-filter");
  var rows = document.querySelectorAll("#secretary-account-edit .secretary-account-row");
  var empty = document.getElementById("patient-filter-empty");
  if (!input && !statusFilter && !doctorFilter) return;
  function faToEn(str){
    return String(str || "").replace(/[۰-۹]/g, function(d){ return String("۰۱۲۳۴۵۶۷۸۹".indexOf(d)); })
      .replace(/[٠-٩]/g, function(d){ return String("٠١٢٣٤٥٦٧٨٩".indexOf(d)); });
  }
  function norm(str){
    return faToEn(str).trim().toLowerCase().replace(/ي/g, "ی").replace(/ك/g, "ک");
  }
  function applyAccountFilter(){
    var q = norm(input ? input.value : "");
    var qDigits = q.replace(/\D+/g, "");
    var status = statusFilter ? statusFilter.value : "";
    var doctor = doctorFilter ? doctorFilter.value : "";
    var shown = 0;
    rows.forEach(function(row){
      var hay = norm(row.getAttribute("data-search") || "");
      var hayDigits = hay.replace(/\D+/g, "");
      var textOn = !q || hay.indexOf(q) !== -1 || (qDigits.length >= 3 && hayDigits.indexOf(qDigits) !== -1);
      var statusOn = !status || (row.getAttribute("data-status") || "") === status;
      var doctorOn = !doctor || (row.getAttribute("data-doctor") || "") === doctor;
      var on = textOn && statusOn && doctorOn;
      row.classList.toggle("is-search-hidden", !on);
      row.hidden = !on;
      row.style.display = on ? "" : "none";
      if (on) shown++;
    });
    var filtering = !!q || !!status || !!doctor;
    if (empty) empty.hidden = !filtering || shown > 0 || rows.length === 0;
  }
  if (input) {
    input.addEventListener("input", applyAccountFilter);
    input.addEventListener("search", applyAccountFilter);
  }
  if (statusFilter) statusFilter.addEventListener("change", applyAccountFilter);
  if (doctorFilter) doctorFilter.addEventListener("change", applyAccountFilter);
  rows.forEach(function(row){
    var doctorSelect = row.querySelector('select[name="preferred_doctor_id"]');
    if (!doctorSelect) return;
    doctorSelect.addEventListener("change", function(){
      row.setAttribute("data-doctor", doctorSelect.value || "");
      applyAccountFilter();
    });
  });
})();
</script>
<script>
(function(){
  var dataEl = document.getElementById("patient-more-data");
  var modal = document.getElementById("patient-more-modal");
  var notesBox = document.getElementById("patient-more-notes");
  var userInput = document.getElementById("patient-more-user");
  var noteForm = modal ? modal.querySelector("form") : null;
  var more = {};
  if (!dataEl || !modal) return;
  try { more = JSON.parse(dataEl.textContent || "{}") || {}; } catch (err) { more = {}; }

  function show(id, value, empty) {
    var el = document.getElementById(id);
    if (!el) return;
    el.textContent = value ? String(value) : empty;
  }

  function closeMore() {
    modal.hidden = true;
    modal.classList.remove("is-open");
    document.body.classList.remove("patient-more-locked");
  }

  function openMore(id) {
    var row = more[id];
    if (!row) return;
    show("patient-more-title", row.name, "مراجعه‌کننده");
    show("patient-more-username", row.username, "—");
    show("patient-more-phone", row.phone, "ثبت نشده");
    show("patient-more-doctor", row.doctor, "انتخاب نشده");
    show("patient-more-referral", row.referral, "انتخاب نشده");
    show("patient-more-created-by", row.createdBy, "نامشخص");
    show("patient-more-created-at", row.createdAt, "—");
    show("patient-more-login", row.lastLogin, "هنوز وارد نشده");
    show("patient-more-upcoming", row.upcoming, "۰");
    show("patient-more-next", row.next, "نوبت آینده‌ای نیست");
    show("patient-more-last", row.last, "نوبت قبلی ثبت نشده");
    var profile = document.getElementById("patient-more-profile");
    if (profile) {
      var href = String(row.profile || "");
      var ok = href.charAt(0) === "/" || href.indexOf("http") === 0;
      profile.hidden = !ok;
      profile.setAttribute("href", ok ? href : "#");
    }
    if (userInput) userInput.value = id;
    if (noteForm) {
      var area = noteForm.querySelector("textarea");
      if (area) area.value = "";
    }
    if (notesBox) {
      notesBox.textContent = "";
      var notes = row.notes || [];
      if (!notes.length) {
        var empty = document.createElement("p");
        empty.className = "muted";
        empty.textContent = "یادداشتی ثبت نشده. از فرم پایین می‌توانید موردی اضافه کنید.";
        notesBox.appendChild(empty);
      } else {
        notes.forEach(function(note){
          var item = document.createElement("div");
          item.className = "patient-more-note";
          var body = document.createElement("p");
          body.textContent = note && note.body ? String(note.body) : "";
          var meta = document.createElement("div");
          meta.className = "muted";
          var bits = [];
          if (note && note.by) bits.push(String(note.by));
          if (note && note.at) bits.push(String(note.at));
          meta.textContent = bits.join(" · ");
          item.appendChild(body);
          item.appendChild(meta);
          notesBox.appendChild(item);
        });
      }
    }
    modal.hidden = false;
    modal.classList.add("is-open");
    document.body.classList.add("patient-more-locked");
  }

  document.querySelectorAll("[data-patient-open]").forEach(function(btn){
    btn.addEventListener("click", function(){
      openMore(btn.getAttribute("data-patient-open") || "");
    });
  });
  modal.querySelectorAll("[data-patient-more-close]").forEach(function(el){
    el.addEventListener("click", closeMore);
  });
  document.addEventListener("keydown", function(e){
    if (e.key === "Escape" && !modal.hidden) closeMore();
  });
  var params = new URLSearchParams(window.location.search);
  var openId = params.get("open") || "";
  if (openId && more[openId]) openMore(openId);
})();
</script>
<script>
(function(){
  var dataEl = document.getElementById("patient-activate-data");
  var modal = document.getElementById("patient-activate-modal");
  var registerForm = document.getElementById("patient-activate-register");
  var profileForm = document.getElementById("patient-activate-profile");
  var title = document.getElementById("patient-activate-title");
  var lead = document.getElementById("patient-activate-lead");
  var people = {};
  if (!dataEl || !modal || !registerForm || !profileForm) return;
  try { people = JSON.parse(dataEl.textContent || "{}") || {}; } catch (err) { people = {}; }
  if (window.bindNameTransliteration) {
    bindNameTransliteration({
      firstName: document.getElementById("act_first_name"),
      lastName: document.getElementById("act_last_name"),
      nameEn: document.getElementById("act_name_en"),
      surname: document.getElementById("act_surname"),
      username: document.getElementById("act_username"),
      usernameHint: document.getElementById("act-username-hint"),
      suggestBtn: document.getElementById("act-suggest-username"),
      transliterateUrl: ' . json_encode(url('/api/transliterate-name')) . ',
      nameDict: ' . json_encode($nameDict, JSON_UNESCAPED_UNICODE) . '
    });
  }
  if (window.enhanceSearchSelect) {
    enhanceSearchSelect(document.getElementById("act_doctor"), { placeholder: "جستجو یا انتخاب درمانگر" });
    enhanceSearchSelect(document.getElementById("act-legacy-doctor"), { placeholder: "جستجو یا انتخاب درمانگر" });
  }
  function joinFa(items) {
    if (!items.length) return "";
    if (items.length === 1) return items[0];
    if (items.length === 2) return items[0] + " و " + items[1];
    return items.slice(0, -1).join("، ") + " و " + items[items.length - 1];
  }
  function closeActivate() {
    modal.hidden = true;
    modal.classList.remove("is-open");
    if (!document.getElementById("patient-more-modal") || document.getElementById("patient-more-modal").hidden) {
      document.body.classList.remove("patient-more-locked");
    }
  }
  function setActivateForm(form, show) {
    if (!form) return;
    form.hidden = !show;
    form.style.setProperty("display", show ? "grid" : "none", "important");
  }
  function openActivate(id) {
    var row = people[id];
    if (!row) return;
    var outreach = row.kind === "outreach";
    setActivateForm(registerForm, outreach);
    setActivateForm(profileForm, !outreach);
    if (title) title.textContent = outreach ? "ثبت‌نام و ساخت پروفایل" : "تکمیل پروفایل و فعال‌سازی";
    if (lead && outreach) {
      lead.textContent = "این نفر هنوز حساب ندارد. تا این فرم ذخیره نشود فعال نمی‌شود و نوبت برایش ثبت نمی‌شود.";
    }
    if (outreach) {
      var outreachId = document.getElementById("act-outreach-id");
      var first = document.getElementById("act_first_name");
      var last = document.getElementById("act_last_name");
      var phone = document.getElementById("act_phone");
      if (outreachId) outreachId.value = row.id || "";
      if (first) first.value = row.first || "";
      if (last) last.value = row.last || "";
      if (phone) phone.value = row.phone || "";
      if (first) first.dispatchEvent(new Event("input", { bubbles: true }));
      if (last) last.dispatchEvent(new Event("input", { bubbles: true }));
    } else {
      var userId = document.getElementById("act-user-id");
      var legacyPhone = document.getElementById("act-legacy-phone");
      var legacyDoctor = document.getElementById("act-legacy-doctor");
      var legacyReferral = document.getElementById("act-legacy-referral");
      if (userId) userId.value = row.id || "";
      if (legacyPhone) legacyPhone.value = row.phone || "";
      if (legacyDoctor) legacyDoctor.value = row.doctor || "";
      if (legacyReferral) legacyReferral.value = row.referral || "";
      var gapLabels = { phone: "موبایل", doctor: "درمانگر", referral: "معرف" };
      var missing = Array.isArray(row.missing) ? row.missing : ["phone", "doctor", "referral"];
      var shown = [];
      profileForm.querySelectorAll("[data-gap]").forEach(function (box) {
        var key = box.getAttribute("data-gap") || "";
        var show = missing.indexOf(key) !== -1;
        box.hidden = !show;
        if (show && gapLabels[key]) shown.push(gapLabels[key]);
        box.querySelectorAll("input, select, textarea").forEach(function (el) {
          el.disabled = !show;
          if (!el.classList.contains("search-select-input")) el.required = show;
        });
      });
      if (lead) {
        lead.textContent = shown.length === 1
          ? "در پروفایل این حساب، " + shown[0] + " ناقص است. فقط همین را کامل کنید تا فعال شود."
          : (shown.length
            ? "در پروفایل این حساب، " + joinFa(shown) + " ناقص هستند. فقط همین‌ها را کامل کنید تا فعال شود."
            : "پروفایل این حساب کامل است.");
      }
    }
    modal.hidden = false;
    modal.classList.add("is-open");
    document.body.classList.add("patient-more-locked");
  }
  document.querySelectorAll("[data-open-activate]").forEach(function(btn){
    btn.addEventListener("click", function(){
      openActivate(btn.getAttribute("data-open-activate") || "");
    });
  });
  modal.querySelectorAll("[data-activate-close]").forEach(function(el){
    el.addEventListener("click", closeActivate);
  });
  document.addEventListener("keydown", function(e){
    if (e.key === "Escape" && !modal.hidden) closeActivate();
  });
  registerForm.addEventListener("submit", function(e){
    var err = document.getElementById("act-register-error");
    function fail(msg, el) {
      e.preventDefault();
      if (err) { err.textContent = msg; err.style.display = "block"; }
      if (el && el.focus) el.focus();
    }
    var first = document.getElementById("act_first_name");
    var last = document.getElementById("act_last_name");
    var nameEn = document.getElementById("act_name_en");
    var surname = document.getElementById("act_surname");
    var doctor = document.getElementById("act_doctor");
    var referral = document.getElementById("act_referral");
    var phone = document.getElementById("act_phone");
    var username = document.getElementById("act_username");
    var pass = document.getElementById("act_password");
    var pass2 = document.getElementById("act_password_confirm");
    if (!first || !first.value.trim() || !last || !last.value.trim()) return fail("نام و نام خانوادگی لازم است.", first && first.value.trim() ? last : first);
    if (!nameEn || !nameEn.value.trim() || !surname || !surname.value.trim()) return fail("name و surname لازم است.", nameEn);
    if (!doctor || !doctor.value) return fail("درمانگر را انتخاب کنید.", doctor);
    if (!referral || !referral.value) return fail("معرف را انتخاب کنید.", referral);
    if (!phone || !window.manaIsValidPhone || !manaIsValidPhone(phone.value.trim())) return fail("موبایل معتبر وارد کنید.", phone);
    var user = username ? username.value.trim().toLowerCase() : "";
    if (!/^[a-z0-9._-]{3,32}$/.test(user)) return fail("نام کاربری انگلیسی، ۳ تا ۳۲ کاراکتر.", username);
    if (!pass || pass.value.length < 6) return fail("رمز حداقل ۶ کاراکتر باشد.", pass);
    if (!pass2 || pass.value !== pass2.value) return fail("رمز و تکرار آن یکسان نیست.", pass2);
    if (err) err.style.display = "none";
  });
})();
</script>
';
$pageHead = ($pageHead ?? '') . '<style>
.patient-list-filters{display:grid;grid-template-columns:minmax(14rem,1.5fr) minmax(9rem,.7fr) minmax(12rem,1fr);gap:.75rem;margin-top:1.25rem;align-items:end}
@media (max-width:760px){.patient-list-filters{grid-template-columns:1fr}}
#secretary-account-edit .sec-account-action{width:6.25rem;height:2.4rem;flex:0 0 6.25rem;box-sizing:border-box;display:inline-flex;align-items:center;justify-content:center;text-align:center;white-space:nowrap;line-height:1.2;padding:.35rem .5rem}
.sec-state-switch{display:inline-flex;border:1px solid rgba(26,46,40,.16);border-radius:999px;overflow:hidden;background:#f3f6f5}
.sec-state-switch form{margin:0;display:flex}
.sec-state-btn{border:0;background:transparent;color:#3d5560;padding:.4rem .75rem;font:inherit;font-size:.82rem;cursor:pointer;min-width:4.4rem}
.sec-state-btn.is-on{background:#1a5c4a;color:#fff;cursor:default}
.sec-state-btn.is-on.is-inactive{background:#8b9390;color:#fff}
.patient-name-cell{display:flex;align-items:center;justify-content:flex-start;gap:.35rem}
.patient-more-btn{border:0;background:transparent;color:#3d5560;font-size:1.25rem;line-height:1;padding:.1rem .35rem;border-radius:.45rem;cursor:pointer;letter-spacing:.08em}
.patient-more-btn:hover,.patient-more-btn:focus-visible{background:rgba(26,92,74,.1);outline:none}
body.patient-more-locked{overflow:hidden}
.patient-more-modal{position:fixed;inset:0;z-index:80;display:none}
.patient-more-modal.is-open{display:block}
.patient-more-backdrop{position:absolute;inset:0;background:rgba(16,24,22,.55)}
.patient-more-panel{position:relative;z-index:1;width:min(36rem,calc(100% - 2rem));max-height:min(82vh,42rem);overflow:auto;margin:8vh auto 0;background:#fff;border-radius:1.1rem;box-shadow:0 24px 64px rgba(0,0,0,.28);padding:1.25rem 1.35rem 1.4rem}
.patient-more-head{display:flex;align-items:flex-start;justify-content:space-between;gap:1rem}
.patient-more-head h2{margin:0;font-size:1.2rem;line-height:1.5}
.patient-more-close{border:0;background:transparent;font-size:1.6rem;line-height:1;cursor:pointer;color:#4a6670}
.patient-more-facts{display:grid;grid-template-columns:1fr 1fr;gap:.65rem .9rem;margin:1rem 0 0}
.patient-more-facts div{margin:0}
.patient-more-facts dt{margin:0;font-size:.75rem;color:#5c726c}
.patient-more-facts dd{margin:.15rem 0 0;font-weight:650}
.patient-more-notes-title{margin:1.1rem 0 .5rem;font-size:1rem}
.patient-more-note{background:#f4f7f6;border-radius:.7rem;padding:.65rem .75rem;margin:0 0 .45rem}
.patient-more-note p{margin:0;white-space:pre-wrap;line-height:1.7}
.patient-more-note .muted{font-size:.78rem;margin-top:.3rem}
.patient-more-note-form{display:grid;gap:.55rem;margin-top:.85rem}
.act-gap{display:block;margin-top:.6rem}
.act-gap[hidden]{display:none !important}
#patient-activate-register[hidden],
#patient-activate-profile[hidden]{display:none !important}
@media (max-width:640px){.patient-more-facts{grid-template-columns:1fr}}
</style>';
render_secretary_page('مراجعه‌کنندگان', $inner);
