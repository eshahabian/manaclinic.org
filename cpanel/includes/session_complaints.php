<?php
declare(strict_types=1);

function ensure_session_complaints_schema(PDO $pdo): void
{
    static $ready = false;
    if ($ready) {
        return;
    }
    $pdo->exec("
      CREATE TABLE IF NOT EXISTS session_complaints (
        id VARCHAR(32) PRIMARY KEY,
        appointment_id VARCHAR(32) NULL,
        patient_id VARCHAR(32) NOT NULL,
        doctor_id VARCHAR(32) NOT NULL,
        session_at DATETIME NULL,
        reason VARCHAR(80) NOT NULL DEFAULT '',
        body TEXT NOT NULL,
        created_by VARCHAR(32) NOT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_session_complaint_created (created_at)
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    foreach ([
        'session_at' => 'ALTER TABLE session_complaints ADD COLUMN session_at DATETIME NULL AFTER doctor_id',
        'reason' => "ALTER TABLE session_complaints ADD COLUMN reason VARCHAR(80) NOT NULL DEFAULT '' AFTER session_at",
        'kind' => "ALTER TABLE session_complaints ADD COLUMN kind VARCHAR(20) NOT NULL DEFAULT 'criticism' AFTER id",
    ] as $column => $sql) {
        $has = $pdo->query("SHOW COLUMNS FROM session_complaints LIKE " . $pdo->quote($column))->fetch();
        if (!$has) {
            $pdo->exec($sql);
        }
    }
    try {
        $pdo->exec('ALTER TABLE session_complaints MODIFY appointment_id VARCHAR(32) NULL');
        $pdo->exec('ALTER TABLE session_complaints MODIFY doctor_id VARCHAR(32) NULL');
    } catch (Throwable $ignored) {
    }
    $ready = true;
}

/** @return array<string, string> */
function session_feedback_kinds(): array
{
    return [
        'criticism' => 'انتقاد',
        'suggestion' => 'پیشنهاد',
    ];
}

function session_feedback_kind(string $kind): string
{
    return isset(session_feedback_kinds()[$kind]) ? $kind : '';
}

/** @return list<string> */
function session_complaint_reasons(): array
{
    return [
        'تأخیر در شروع جلسه',
        'برخورد نامناسب',
        'برخورد نامناسب درمانگر',
        'برخورد نامناسب پرسنل',
        'عدم پیگیری مناسب منشی',
        'بی‌نظمی در هماهنگی جلسات',
        'کوتاه شدن زمان جلسه',
        'بی‌توجهی به صحبت مراجع',
        'لغو یا جابه‌جایی ناگهانی',
        'نارضایتی از روند درمان',
        'سایر',
    ];
}

function session_complaint_create(
    PDO $pdo,
    string $actorId,
    string $patientId,
    string $kind,
    string $sessionAt,
    string $reason,
    string $body
): void {
    ensure_session_complaints_schema($pdo);
    $kind = session_feedback_kind($kind);
    if ($kind === '') {
        throw new RuntimeException('انتقاد یا پیشنهاد را انتخاب کنید.');
    }
    $body = trim($body);
    $reason = trim($reason);
    if ($kind === 'criticism' && !in_array($reason, session_complaint_reasons(), true)) {
        throw new RuntimeException('علت را انتخاب کنید.');
    }
    if ($kind === 'suggestion') {
        $reason = in_array($reason, session_complaint_reasons(), true) ? $reason : '';
    }
    if ($body === '') {
        throw new RuntimeException('توضیحات را بنویسید.');
    }
    if (mb_strlen($body) > 4000) {
        throw new RuntimeException('توضیحات طولانی است.');
    }
    $sessionStored = session_complaint_parse_when($sessionAt);
    $patient = $pdo->prepare("
      SELECT u.id, u.preferred_doctor_id, u.name
      FROM users u
      WHERE u.id = ? AND u.is_disabled = 0
        AND (
          u.role = 'PATIENT'
          OR LOWER(u.username) = 'eshahabian'
          OR (u.name LIKE '%عماد%' AND u.name LIKE '%شهابیان%')
        )
      LIMIT 1
    ");
    $patient->execute([$patientId]);
    $person = $patient->fetch();
    if (!$person) {
        throw new RuntimeException('مراجعه‌کننده را انتخاب کنید.');
    }
    $doctorId = trim((string) ($person['preferred_doctor_id'] ?? ''));
    if ($doctorId !== '') {
        $doctor = $pdo->prepare('SELECT id FROM doctor_profiles WHERE id = ? LIMIT 1');
        $doctor->execute([$doctorId]);
        if (!$doctor->fetch()) {
            $doctorId = '';
        }
    }
    $pdo->prepare('
      INSERT INTO session_complaints (id, kind, appointment_id, patient_id, doctor_id, session_at, reason, body, created_by)
      VALUES (?,?,?,?,?,?,?,?,?)
    ')->execute([
        cuid(),
        $kind,
        null,
        $patientId,
        $doctorId !== '' ? $doctorId : null,
        $sessionStored,
        $reason,
        $body,
        $actorId,
    ]);
    session_feedback_notify_owners($pdo, $kind, (string) ($person['name'] ?? ''));
}

function session_complaint_parse_when(string $raw): ?string
{
    $raw = strtr(trim($raw), [
        '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4',
        '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
        '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
        '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
    ]);
    $raw = str_replace(['/', 'T', '،'], ['-', ' ', ' '], $raw);
    $raw = trim(preg_replace('/\s+/', ' ', $raw) ?? $raw);
    if ($raw === '') {
        return null;
    }
    if (!preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})(?: (\d{1,2}):(\d{2})(?::(\d{2}))?)?$/', $raw, $m)) {
        throw new RuntimeException('زمان جلسه معتبر نیست.');
    }
    $year = (int) $m[1];
    $month = (int) $m[2];
    $day = (int) $m[3];
    $hour = isset($m[4]) && $m[4] !== '' ? (int) $m[4] : 0;
    $minute = isset($m[5]) && $m[5] !== '' ? (int) $m[5] : 0;
    if ($hour > 23 || $minute > 59) {
        throw new RuntimeException('ساعت جلسه معتبر نیست.');
    }
    if ($year >= 1200 && $year < 1700) {
        if ($month < 1 || $month > 12 || $day < 1 || $day > 31) {
            throw new RuntimeException('زمان جلسه معتبر نیست.');
        }
        [$year, $month, $day] = jalali_to_gregorian($year, $month, $day);
    }
    if (!checkdate($month, $day, $year)) {
        throw new RuntimeException('زمان جلسه معتبر نیست.');
    }

    return sprintf('%04d-%02d-%02d %02d:%02d:00', $year, $month, $day, $hour, $minute);
}

function session_feedback_can_review(?array $user): bool
{
    if (!$user) {
        return false;
    }
    if ((!function_exists('doctor_is_shiva') || !function_exists('doctor_is_garsichi')) && is_file(__DIR__ . '/doctor_profile_fields.php')) {
        require_once __DIR__ . '/doctor_profile_fields.php';
    }

    return (function_exists('doctor_is_shiva') && doctor_is_shiva($user))
        || (function_exists('doctor_is_garsichi') && doctor_is_garsichi($user));
}

function session_feedback_notify_owners(PDO $pdo, string $kind, string $patientName): void
{
    if (!function_exists('notify_doctor_profile') && is_file(__DIR__ . '/notifications.php')) {
        require_once __DIR__ . '/notifications.php';
    }
    if (!function_exists('notify_doctor_profile') || !function_exists('doctor_is_shiva')) {
        return;
    }
    $label = session_feedback_kinds()[$kind] ?? 'بازخورد';
    $who = trim($patientName) !== '' ? trim($patientName) : 'یک مراجعه‌کننده';
    $rows = $pdo->query("
      SELECT dp.id, u.name, u.username
      FROM doctor_profiles dp
      JOIN users u ON u.id = dp.user_id
      WHERE u.role = 'DOCTOR' AND u.is_disabled = 0
    ")->fetchAll() ?: [];
    foreach ($rows as $row) {
        $person = ['name' => (string) ($row['name'] ?? ''), 'username' => (string) ($row['username'] ?? ''), 'role' => 'DOCTOR'];
        if (!doctor_is_shiva($person) && !doctor_is_garsichi($person)) {
            continue;
        }
        notify_doctor_profile(
            $pdo,
            (string) $row['id'],
            $label . ' جدید',
            $who . ' یک ' . $label . ' ثبت کرد.',
            '/doctor/feedback',
            'feedback'
        );
    }
}

/** @return list<array<string, mixed>> */
function session_complaint_list(PDO $pdo, ?string $patientId = null): array
{
    ensure_session_complaints_schema($pdo);
    $sql = "
      SELECT c.id, c.kind, c.body, c.reason, c.created_at, c.session_at,
             p.name AS patient_name, p.phone AS patient_phone,
             d.name AS doctor_name,
             s.name AS secretary_name
      FROM session_complaints c
      JOIN users p ON p.id = c.patient_id
      LEFT JOIN doctor_profiles dp ON dp.id = c.doctor_id
      LEFT JOIN users d ON d.id = dp.user_id
      LEFT JOIN users s ON s.id = c.created_by
    ";
    $params = [];
    if ($patientId !== null && $patientId !== '') {
        $sql .= ' WHERE c.patient_id = ?';
        $params[] = $patientId;
    }
    $sql .= ' ORDER BY c.created_at DESC LIMIT 80';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    return $stmt->fetchAll() ?: [];
}

function session_feedback_list_html(array $rows, string $empty): string
{
    ob_start();
    ?>
    <div class="stack" style="margin-top:1.25rem">
      <h2 style="margin:0;font-size:1.05rem">ثبت‌شده‌ها</h2>
      <?php if (!$rows): ?>
        <p class="muted"><?= e($empty) ?></p>
      <?php else: ?>
        <?php foreach ($rows as $row): ?>
          <?php $kindLabel = session_feedback_kinds()[(string) ($row['kind'] ?? 'criticism')] ?? 'انتقاد'; ?>
          <article class="panel" style="margin-top:.75rem">
            <strong><?= e($kindLabel) ?></strong>
            <span class="muted"> · <?= e((string) ($row['patient_name'] ?? '')) ?></span>
            <p class="muted" style="margin:.35rem 0 0;font-size:.85rem;line-height:1.7">
              <?php if (trim((string) ($row['patient_phone'] ?? '')) !== ''): ?>
                موبایل <?= e((string) $row['patient_phone']) ?> ·
              <?php endif; ?>
              درمانگر <?= trim((string) ($row['doctor_name'] ?? '')) !== '' ? e((string) $row['doctor_name']) : 'انتخاب نشده' ?>
              <?php if (!empty($row['session_at'])): ?>
                · جلسه <?= e(format_fa_datetime((string) $row['session_at'])) ?>
              <?php endif; ?>
              <?php if (trim((string) ($row['reason'] ?? '')) !== ''): ?>
                · <?= e((string) $row['reason']) ?>
              <?php endif; ?>
              · ثبت <?= e(format_fa_datetime((string) $row['created_at'])) ?>
            </p>
            <p style="margin:.6rem 0 0;line-height:1.8;white-space:pre-wrap"><?= e((string) ($row['body'] ?? '')) ?></p>
          </article>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>
    <?php
    return (string) ob_get_clean();
}

function session_feedback_form_html(string $action, bool $pickPatient, array $patients, string $selfName, string $selfPhone, string $selfDoctor): string
{
    ob_start();
    ?>
    <div class="panel" id="feedback-box" style="margin-top:1rem">
      <style>#feedback-box [hidden]{display:none !important}</style>
      <div class="binder-tabs" role="tablist" aria-label="انتقادات و پیشنهادات">
        <button type="button" class="binder-tab is-active" data-feedback-tab="criticism" role="tab" aria-selected="true">انتقاد</button>
        <button type="button" class="binder-tab" data-feedback-tab="suggestion" role="tab" aria-selected="false">پیشنهاد</button>
      </div>
      <form class="form-stack" method="post" action="<?= e(url($action)) ?>" style="margin-top:1rem">
        <?= csrf_field() ?>
        <input type="hidden" name="kind" id="feedback-kind" value="criticism">
        <?php if ($pickPatient): ?>
          <div>
            <label class="label" for="feedback-patient">اسم شخص</label>
            <select class="input" id="feedback-patient" name="patient_id" required>
              <option value="">انتخاب از فهرست</option>
              <?php foreach ($patients as $row): ?>
                <?php
                  $pname = trim((string) ($row['name'] ?? ''));
                  $uname = trim((string) ($row['username'] ?? ''));
                  $phone = trim((string) ($row['phone'] ?? ''));
                  $doctor = trim((string) ($row['doctor_name'] ?? ''));
                  $label = $pname !== '' ? $pname : $uname;
                  $search = trim($pname . ' ' . $uname . ' ' . $phone . ' ' . $doctor);
                ?>
                <option value="<?= e((string) ($row['id'] ?? '')) ?>" data-search="<?= e($search) ?>" data-name="<?= e($label) ?>" data-phone="<?= e($phone) ?>" data-doctor="<?= e($doctor) ?>"><?= e($label) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <p class="muted" id="feedback-person" style="margin:0;line-height:1.8">بعد از انتخاب، نام، شماره تماس و درمانگر همین‌جا دیده می‌شود.</p>
        <?php else: ?>
          <p class="muted" style="margin:0;line-height:1.8">
            <?= e($selfName !== '' ? $selfName : 'مراجعه‌کننده') ?>
            <?php if ($selfPhone !== ''): ?> · <?= e($selfPhone) ?><?php endif; ?>
            · درمانگر <?= e($selfDoctor !== '' ? $selfDoctor : 'انتخاب نشده') ?>
          </p>
        <?php endif; ?>
        <div>
          <label class="label" for="feedback-session-date">زمان انجام جلسه</label>
          <div style="display:grid;grid-template-columns:minmax(0,1fr) 8.5rem;gap:.5rem">
            <input class="input" id="feedback-session-date" name="session_date" type="text" data-jdp data-jdp-only-date autocomplete="off" readonly placeholder="تاریخ، اختیاری" style="cursor:pointer">
            <input class="input" id="feedback-session-time" name="session_time" type="time" dir="ltr" aria-label="ساعت جلسه، اختیاری">
          </div>
          <p class="muted" style="margin:.35rem 0 0;font-size:.82rem">تاریخ و ساعت اجباری نیست.</p>
        </div>
        <div id="feedback-reason-box">
          <label class="label" for="feedback-reason">علت</label>
          <select class="input" id="feedback-reason" name="reason" required>
            <option value="">انتخاب علت</option>
            <?php foreach (session_complaint_reasons() as $reason): ?>
              <option value="<?= e($reason) ?>"><?= e($reason) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div>
          <label class="label" for="feedback-body">توضیحات</label>
          <textarea class="input" id="feedback-body" name="body" rows="5" required placeholder="جزئیات را بنویسید"></textarea>
        </div>
        <button class="btn btn-primary" type="submit" id="feedback-submit" style="justify-self:start">ثبت انتقاد</button>
      </form>
    </div>
    <?php
    return (string) ob_get_clean();
}

function session_feedback_assets(bool $withPatientSearch): string
{
    $watch = '["feedback-reason"]';
    if ($withPatientSearch) {
        $watch = '["feedback-patient","feedback-reason"]';
    }

    return '<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@majidh1/jalalidatepicker/dist/jalalidatepicker.min.css">'
        . '<script src="' . e(url('/assets/js/search-select.js')) . '?v=20261014fb"></script>'
        . '<script src="https://cdn.jsdelivr.net/npm/@majidh1/jalalidatepicker/dist/jalalidatepicker.min.js"></script>'
        . '<script>
(function () {
  ' . $watch . '.forEach(function (id) {
    var el = document.getElementById(id);
    if (el && window.enhanceSearchSelect) enhanceSearchSelect(el);
  });
  if (window.jalaliDatepicker) {
    jalaliDatepicker.startWatch({
      selector: "#feedback-session-date",
      time: false,
      hideAfterChange: true,
      showTodayBtn: true,
      showEmptyBtn: true,
      autoReadOnlyInput: true,
      persianDigits: true,
      zIndex: 100000,
      container: "body"
    });
  }
  document.querySelectorAll("[data-feedback-tab]").forEach(function (tab) {
    tab.addEventListener("click", function () {
      var kind = tab.getAttribute("data-feedback-tab") || "criticism";
      document.querySelectorAll("[data-feedback-tab]").forEach(function (other) {
        var on = other === tab;
        other.classList.toggle("is-active", on);
        other.setAttribute("aria-selected", on ? "true" : "false");
      });
      var kindInput = document.getElementById("feedback-kind");
      if (kindInput) kindInput.value = kind;
      var reasonBox = document.getElementById("feedback-reason-box");
      var reason = document.getElementById("feedback-reason");
      var criticism = kind === "criticism";
      if (reasonBox) reasonBox.hidden = !criticism;
      if (reason) {
        reason.required = criticism;
        reason.disabled = !criticism;
      }
      var submit = document.getElementById("feedback-submit");
      if (submit) submit.textContent = criticism ? "ثبت انتقاد" : "ثبت پیشنهاد";
    });
  });
  var patient = document.getElementById("feedback-patient");
  var person = document.getElementById("feedback-person");
  function paintPerson() {
    if (!patient || !person) return;
    var opt = patient.options[patient.selectedIndex];
    if (!opt || !opt.value) {
      person.textContent = "بعد از انتخاب، نام، شماره تماس و درمانگر همین‌جا دیده می‌شود.";
      return;
    }
    var name = opt.getAttribute("data-name") || opt.textContent || "";
    var phone = opt.getAttribute("data-phone") || "";
    var doctor = opt.getAttribute("data-doctor") || "";
    person.textContent = "نام: " + name + " · شماره تماس: " + (phone || "ثبت نشده") + " · درمانگر: " + (doctor || "انتخاب نشده");
  }
  if (patient) {
    patient.addEventListener("change", paintPerson);
    paintPerson();
  }
})();
</script>';
}
