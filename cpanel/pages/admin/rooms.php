<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/admin_panel.php';
require_once __DIR__ . '/../../includes/clinic_rooms.php';

clinic_rooms_require_user();

$day = clinic_rooms_parse_day((string) ($_GET['day'] ?? ''));
$week = clinic_rooms_week_days($day);
[$roomGy, $roomGm, $roomGd] = array_map('intval', explode('-', $day));
[$roomJy, $roomJm, $roomJd] = gregorian_to_jalali($roomGy, $roomGm, $roomGd);
$roomJalali = sprintf('%04d/%02d/%02d', $roomJy, $roomJm, $roomJd);
$dayStart = $day . ' 00:00:00';
$dayEnd = date('Y-m-d H:i:s', strtotime($day . ' +1 day') ?: time());
$dayBookings = clinic_rooms_between($pdo, $dayStart, $dayEnd);
$byRoom = [];
foreach (clinic_rooms_numbers() as $roomNo) {
    $byRoom[$roomNo] = [];
}
foreach ($dayBookings as $row) {
    $roomNo = (int) ($row['room_no'] ?? 0);
    if (isset($byRoom[$roomNo])) {
        $byRoom[$roomNo][] = $row;
    }
}
$assignments = clinic_rooms_active_assignments($pdo);
$openWorkshops = clinic_rooms_open_workshop_sessions($pdo);
$roomPatients = $pdo->query("SELECT id, name, phone FROM users WHERE role='PATIENT' AND is_disabled = 0 ORDER BY name ASC")->fetchAll();
$roomDoctors = $pdo->query("
  SELECT dp.id, u.name, dp.specialty
  FROM doctor_profiles dp
  JOIN users u ON u.id = dp.user_id
  WHERE dp.is_active = 1 AND dp.is_approved = 1 AND u.is_disabled = 0
  ORDER BY u.name ASC
")->fetchAll();
$clockChoices = [];
for ($h = 6; $h <= 22; $h++) {
    $clockChoices[] = sprintf('%02d:00', $h);
    if ($h < 22) {
        $clockChoices[] = sprintf('%02d:30', $h);
    }
}
$past = clinic_rooms_past($pdo);

$pastByRoom = [];
$pastByPerson = [];
$heldRows = [];
foreach (clinic_rooms_numbers() as $roomNo) {
    $pastByRoom[$roomNo] = ['total' => 0, 'held' => 0];
}
foreach ($past as $row) {
    $roomNo = (int) ($row['room_no'] ?? 0);
    if (!isset($pastByRoom[$roomNo])) {
        continue;
    }
    $pastByRoom[$roomNo]['total']++;
    $who = trim((string) ($row['booked_by_name'] ?? ''));
    if ($who === '') {
        $who = 'نامشخص';
    }
    if (!isset($pastByPerson[$who])) {
        $pastByPerson[$who] = 0;
    }
    $pastByPerson[$who]++;
    if (clinic_room_was_held($row)) {
        $pastByRoom[$roomNo]['held']++;
        $heldRows[] = $row;
    }
}
arsort($pastByPerson);
$workshopIds = [];
foreach ($heldRows as $row) {
    if ((string) ($row['kind'] ?? '') === 'WORKSHOP' && !empty($row['workshop_id'])) {
        $workshopIds[] = (string) $row['workshop_id'];
    }
}
$workshopPatients = clinic_rooms_workshop_patient_names($pdo, $workshopIds);

$dayParts = jalali_day_parts($day . ' 12:00:00') ?: ['label' => $day];
$weekdayName = '';
foreach ($week as $item) {
    if ($item['date'] === $day) {
        $weekdayName = (string) $item['weekday'];
    }
}

ob_start();
?>
<div class="stack room-desk">
  <div>
    <h1>اتاق‌های کلینیک</h1>
    <p class="muted" style="margin:.35rem 0 0">روی هر اتاق ساعت را بنویسید یا از فهرست انتخاب کنید. بعد نوع جلسه را مشخص کنید و برای همان شخص یا کارگاه رزرو کنید. ساعت از قبل پر نمی‌شود.</p>
  </div>

  <section class="panel room-date">
    <label class="label" for="room-day-view">تاریخ رزرو</label>
    <input
      class="input"
      type="text"
      id="room-day-view"
      data-jdp
      data-jdp-only-date
      readonly
      autocomplete="off"
      placeholder="کلیک کنید تا تقویم باز شود"
      style="cursor:pointer;max-width:18rem"
      value="<?= e($roomJalali) ?>"
    >
    <p class="muted" style="margin:.55rem 0 0;font-size:.9rem">روز انتخاب‌شده: <?= e($weekdayName !== '' ? $weekdayName . ' ' : '') ?><?= e((string) ($dayParts['label'] ?? $day)) ?></p>
  </section>

  <datalist id="room-clock-options">
    <?php foreach ($clockChoices as $clock): ?>
      <option value="<?= e($clock) ?>"></option>
    <?php endforeach; ?>
  </datalist>

  <div class="grid-3 room-grid">
    <?php foreach (clinic_rooms_numbers() as $roomNo): ?>
      <?php $rows = $byRoom[$roomNo]; ?>
      <section class="panel room-col room-col-<?= (int) $roomNo ?>" id="room-<?= (int) $roomNo ?>">
        <h2><?= e(clinic_room_label($roomNo)) ?></h2>
        <p class="muted" style="margin:0 0 .75rem;font-size:.85rem"><?= $rows ? e(to_fa_digits((string) count($rows))) . ' رزرو در این روز' : 'در این روز هنوز رزروی نیست' ?></p>

        <form class="room-book" method="post" action="<?= e(url('/admin/rooms')) ?>" data-room-book>
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="assign">
          <input type="hidden" name="day" value="<?= e($day) ?>">
          <input type="hidden" name="room_no" value="<?= (int) $roomNo ?>">
          <label>
            <span class="label">از ساعت</span>
            <input class="input" name="start_time" data-clock list="room-clock-options" inputmode="numeric" autocomplete="off" placeholder="بنویسید یا انتخاب کنید" required>
          </label>
          <label>
            <span class="label">تا ساعت</span>
            <input class="input" name="end_time" data-clock list="room-clock-options" inputmode="numeric" autocomplete="off" placeholder="بنویسید یا انتخاب کنید" required>
          </label>
          <label>
            <span class="label">این ساعت برای چیست؟</span>
            <select class="input" name="purpose" data-purpose required>
              <option value="">انتخاب کنید</option>
              <option value="therapy">تراپی</option>
              <option value="workshop">کارگاه</option>
              <option value="block">سایر</option>
            </select>
          </label>
          <div data-for="therapy" hidden>
            <label>
              <span class="label">مراجعه‌کننده</span>
              <select class="input" name="patient_id" data-search id="room-<?= (int) $roomNo ?>-patient">
                <option value="">جستجو یا انتخاب مراجعه‌کننده</option>
                <?php foreach ($roomPatients as $person): ?>
                  <option value="<?= e((string) $person['id']) ?>" data-search="<?= e((string) $person['name'] . ' ' . (string) ($person['phone'] ?? '')) ?>"><?= e((string) $person['name']) ?><?= !empty($person['phone']) ? ' — ' . e((string) $person['phone']) : '' ?></option>
                <?php endforeach; ?>
              </select>
            </label>
            <label>
              <span class="label">درمانگر</span>
              <select class="input" name="doctor_id" data-search id="room-<?= (int) $roomNo ?>-doctor">
                <option value="">جستجو یا انتخاب درمانگر</option>
                <?php foreach ($roomDoctors as $doctor): ?>
                  <option value="<?= e((string) $doctor['id']) ?>"><?= e((string) $doctor['name']) ?><?= !empty($doctor['specialty']) ? ' — ' . e((string) $doctor['specialty']) : '' ?></option>
                <?php endforeach; ?>
              </select>
            </label>
          </div>
          <div data-for="workshop" hidden>
            <label>
              <span class="label">کارگاه</span>
              <select class="input" name="workshop_session_id" data-search id="room-<?= (int) $roomNo ?>-workshop">
                <option value="">جستجو یا انتخاب کارگاه</option>
                <?php foreach ($openWorkshops as $sess): ?>
                  <?php
                    $key = 'w:' . (string) $sess['id'];
                    $where = isset($assignments[$key]) ? ' — الان ' . clinic_room_label((int) $assignments[$key]) : '';
                    $label = (string) $sess['workshop_title'] . ' · ' . (string) $sess['doctor_name'] . ' · ' . format_fa_datetime((string) $sess['span_start']) . $where;
                  ?>
                  <option value="<?= e((string) $sess['id']) ?>"><?= e($label) ?></option>
                <?php endforeach; ?>
              </select>
            </label>
          </div>
          <div data-for="block" hidden>
            <label>
              <span class="label">عنوان کار</span>
              <input class="input" name="block_title" maxlength="255" placeholder="مثلاً جلسه تیم">
            </label>
          </div>
          <label class="room-repeat">
            <input type="checkbox" name="repeat_weekly" value="1" data-repeat>
            <span>تکرار هر هفته، همین روز (<?= e($weekdayName !== '' ? $weekdayName : 'این روز') ?>)</span>
          </label>
          <div data-repeat-fields hidden>
            <label>
              <span class="label">چند هفته پشت‌سرهم</span>
              <input class="input" name="repeat_weeks" inputmode="numeric" autocomplete="off" placeholder="مثلاً ۸" disabled>
            </label>
            <p class="muted" style="margin:0;font-size:.78rem">از همین روز، هر ۷ روز یک‌بار. مثلاً اگر دوشنبه باشد و ۸ بنویسید، هشت دوشنبهٔ بعد ساعت ۹ تا ۱۲ رزرو می‌شود.</p>
          </div>
          <label>
            <span class="label">یادداشت (اختیاری)</span>
            <input class="input" name="note" maxlength="255">
          </label>
          <button type="submit" class="btn btn-primary btn-sm">رزرو <?= e(clinic_room_label($roomNo)) ?></button>
        </form>

        <?php if ($rows): ?>
          <div class="room-day-list">
            <?php foreach ($rows as $row): ?>
              <div class="room-day-item">
                <div>
                  <strong><?= e(to_fa_digits(date('H:i', strtotime((string) $row['starts_at']) ?: time()))) ?>–<?= e(to_fa_digits(date('H:i', strtotime((string) $row['ends_at']) ?: time()))) ?></strong>
                  <div><?= e(clinic_room_purpose($row)) ?></div>
                  <div class="muted" style="font-size:.78rem">رزرو توسط <?= e((string) ($row['booked_by_name'] ?? '—')) ?> · <?= e(clinic_room_outcome_label($row)) ?></div>
                </div>
                <?php if ((strtotime((string) $row['ends_at']) ?: 0) >= time()): ?>
                  <?php
                    $editKind = (string) ($row['kind'] ?? '');
                    $editPurpose = $editKind === 'WORKSHOP' ? 'workshop' : ($editKind === 'APPOINTMENT' ? 'therapy' : 'block');
                    $editStart = date('H:i', strtotime((string) $row['starts_at']) ?: time());
                    $editEnd = date('H:i', strtotime((string) $row['ends_at']) ?: time());
                    $editId = 'room-edit-' . preg_replace('/[^a-zA-Z0-9_-]/', '', (string) $row['id']);
                    $editPatientId = trim((string) ($row['patient_id'] ?? ''));
                    if ($editPatientId === '') {
                        $editPatientId = trim((string) ($row['appt_patient_id'] ?? ''));
                    }
                    $editDoctorId = trim((string) ($row['doctor_id'] ?? ''));
                    if ($editDoctorId === '') {
                        $editDoctorId = trim((string) ($row['appt_doctor_id'] ?? ''));
                    }
                  ?>
                  <details class="room-edit">
                    <summary class="btn btn-outline btn-sm">ویرایش</summary>
                    <form class="room-book" method="post" action="<?= e(url('/admin/rooms')) ?>" data-room-book>
                      <?= csrf_field() ?>
                      <input type="hidden" name="action" value="update">
                      <input type="hidden" name="booking_id" value="<?= e((string) $row['id']) ?>">
                      <input type="hidden" name="day" value="<?= e($day) ?>">
                      <label>
                        <span class="label">اتاق</span>
                        <select class="input" name="room_no">
                          <?php foreach (clinic_rooms_numbers() as $editRoom): ?>
                            <option value="<?= (int) $editRoom ?>" <?= (int) $row['room_no'] === $editRoom ? 'selected' : '' ?>><?= e(clinic_room_label($editRoom)) ?></option>
                          <?php endforeach; ?>
                        </select>
                      </label>
                      <div class="room-form-grid">
                        <label>
                          <span class="label">از ساعت</span>
                          <input class="input" name="start_time" value="<?= e($editStart) ?>" data-clock list="room-clock-options" inputmode="numeric" autocomplete="off" required>
                        </label>
                        <label>
                          <span class="label">تا ساعت</span>
                          <input class="input" name="end_time" value="<?= e($editEnd) ?>" data-clock list="room-clock-options" inputmode="numeric" autocomplete="off" required>
                        </label>
                      </div>
                      <label>
                        <span class="label">نوع</span>
                        <select class="input" name="purpose" data-purpose>
                          <option value="therapy" <?= $editPurpose === 'therapy' ? 'selected' : '' ?>>تراپی</option>
                          <option value="workshop" <?= $editPurpose === 'workshop' ? 'selected' : '' ?>>کارگاه</option>
                          <option value="block" <?= $editPurpose === 'block' ? 'selected' : '' ?>>سایر</option>
                        </select>
                      </label>
                      <div data-for="therapy" <?= $editPurpose === 'therapy' ? '' : 'hidden' ?>>
                        <label>
                          <span class="label">مراجعه‌کننده</span>
                          <select class="input" name="patient_id" data-search id="<?= e($editId) ?>-patient">
                            <option value="">انتخاب کنید</option>
                            <?php foreach ($roomPatients as $patient): ?>
                              <option value="<?= e((string) $patient['id']) ?>" <?= $editPatientId === (string) $patient['id'] ? 'selected' : '' ?>><?= e((string) $patient['name']) ?></option>
                            <?php endforeach; ?>
                          </select>
                        </label>
                        <label>
                          <span class="label">درمانگر</span>
                          <select class="input" name="doctor_id" data-search id="<?= e($editId) ?>-doctor">
                            <option value="">انتخاب کنید</option>
                            <?php foreach ($roomDoctors as $doctor): ?>
                              <option value="<?= e((string) $doctor['id']) ?>" <?= $editDoctorId === (string) $doctor['id'] ? 'selected' : '' ?>><?= e((string) $doctor['name']) ?></option>
                            <?php endforeach; ?>
                          </select>
                        </label>
                      </div>
                      <div data-for="workshop" <?= $editPurpose === 'workshop' ? '' : 'hidden' ?>>
                        <label>
                          <span class="label">کارگاه</span>
                          <select class="input" name="workshop_session_id" data-search id="<?= e($editId) ?>-workshop">
                            <option value="">انتخاب کنید</option>
                            <?php foreach ($openWorkshops as $sess): ?>
                              <option value="<?= e((string) $sess['id']) ?>" <?= (string) ($row['workshop_session_id'] ?? '') === (string) $sess['id'] ? 'selected' : '' ?>><?= e((string) $sess['workshop_title'] . ' · ' . (string) $sess['doctor_name']) ?></option>
                            <?php endforeach; ?>
                          </select>
                        </label>
                      </div>
                      <div data-for="block" <?= $editPurpose === 'block' ? '' : 'hidden' ?>>
                        <label>
                          <span class="label">عنوان کار</span>
                          <input class="input" name="block_title" maxlength="255" value="<?= e($editPurpose === 'block' ? (string) ($row['title'] ?? '') : '') ?>">
                        </label>
                      </div>
                      <label>
                        <span class="label">یادداشت</span>
                        <input class="input" name="note" maxlength="255" value="<?= e((string) ($row['note'] ?? '')) ?>">
                      </label>
                      <?php if (trim((string) ($row['series_id'] ?? '')) !== ''): ?>
                        <label class="room-repeat">
                          <input type="checkbox" name="apply_series" value="1">
                          <span>همین تغییر برای هفته‌های بعدی این تکرار</span>
                        </label>
                      <?php endif; ?>
                      <button type="submit" class="btn btn-primary btn-sm">ذخیره ویرایش</button>
                    </form>
                  </details>
                  <form method="post" action="<?= e(url('/admin/rooms')) ?>">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="release">
                    <input type="hidden" name="booking_id" value="<?= e((string) $row['id']) ?>">
                    <input type="hidden" name="day" value="<?= e($day) ?>">
                    <button type="submit" class="btn btn-outline btn-sm" onclick="return confirm('این رزرو لغو و ساعت آزاد شود؟');">آزاد کردن</button>
                  </form>
                  <?php if (trim((string) ($row['series_id'] ?? '')) !== ''): ?>
                    <form method="post" action="<?= e(url('/admin/rooms')) ?>">
                      <?= csrf_field() ?>
                      <input type="hidden" name="action" value="release_series">
                      <input type="hidden" name="booking_id" value="<?= e((string) $row['id']) ?>">
                      <input type="hidden" name="day" value="<?= e($day) ?>">
                      <button type="submit" class="btn btn-outline btn-sm" onclick="return confirm('این هفته و هفته‌های بعدیِ همین تکرار آزاد شود؟');">آزاد کردن تکرار</button>
                    </form>
                  <?php endif; ?>
                <?php endif; ?>
              </div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </section>
    <?php endforeach; ?>
  </div>

  <section class="panel" id="room-report">
    <h2 style="margin-top:0">خروجی گذشته</h2>
    <p class="muted" style="margin:0 0 1rem;font-size:.9rem">چند بار هر اتاق رزرو شده، چه کسی رزرو کرده، و اگر جلسه برگزار شده درمانگر و مراجعه‌کننده چه کسانی بوده‌اند.</p>
    <div class="grid-3" style="margin-bottom:1rem">
      <?php foreach (clinic_rooms_numbers() as $roomNo): ?>
        <div class="room-stat">
          <strong><?= e(clinic_room_label($roomNo)) ?></strong>
          <div><?= e(to_fa_digits((string) $pastByRoom[$roomNo]['total'])) ?> رزرو گذشته</div>
          <div class="muted"><?= e(to_fa_digits((string) $pastByRoom[$roomNo]['held'])) ?> جلسه برگزار شده</div>
        </div>
      <?php endforeach; ?>
    </div>
    <?php if ($pastByPerson): ?>
      <h3 style="font-size:1rem">رزروها بر اساس کسی که اتاق را گرفته</h3>
      <ul class="room-people">
        <?php foreach ($pastByPerson as $name => $count): ?>
          <li><strong><?= e((string) $name) ?></strong> — <?= e(to_fa_digits((string) $count)) ?> بار</li>
        <?php endforeach; ?>
      </ul>
    <?php else: ?>
      <p class="muted">هنوز رزرو گذشته‌ای ثبت نشده است.</p>
    <?php endif; ?>

    <h3 style="font-size:1rem;margin-top:1.25rem">جلسه‌های برگزارشده</h3>
    <?php if (!$heldRows): ?>
      <p class="muted">جلسه برگزارشده‌ای در اتاق‌ها ثبت نشده است.</p>
    <?php else: ?>
      <div class="room-report-table">
        <div class="room-report-head">
          <span>اتاق</span>
          <span>زمان</span>
          <span>کار</span>
          <span>درمانگر</span>
          <span>مراجعه‌کننده</span>
          <span>رزرو توسط</span>
        </div>
        <?php foreach ($heldRows as $row): ?>
          <?php
            $kind = (string) ($row['kind'] ?? '');
            $doctor = $kind === 'WORKSHOP' ? (string) ($row['workshop_doctor_name'] ?? '') : (string) ($row['doctor_name'] ?? '');
            if ($kind === 'APPOINTMENT') {
                $with = (string) ($row['patient_name'] ?? '');
            } elseif ($kind === 'WORKSHOP') {
                $wid = (string) ($row['workshop_id'] ?? '');
                $people = $workshopPatients[$wid] ?? [];
                $with = (string) ($row['workshop_title'] ?? 'کارگاه');
                if ($people) {
                    $shown = array_slice($people, 0, 8);
                    $with .= ' — ' . implode('، ', $shown);
                    if (count($people) > count($shown)) {
                        $with .= ' و ' . to_fa_digits((string) (count($people) - count($shown))) . ' نفر دیگر';
                    }
                }
            } else {
                $doctor = '—';
                $with = (string) ($row['title'] ?? 'سایر');
            }
          ?>
          <div class="room-report-row">
            <span><?= e(clinic_room_label((int) ($row['room_no'] ?? 0))) ?></span>
            <span><?= e(format_fa_datetime((string) $row['starts_at'])) ?></span>
            <span><?= e($kind === 'WORKSHOP' ? 'کارگاه' : ($kind === 'APPOINTMENT' ? (trim((string) ($row['appointment_id'] ?? '')) === '' ? 'تراپی' : 'نوبت') : 'سایر')) ?></span>
            <span><?= e($doctor !== '' ? $doctor : '—') ?></span>
            <span><?= e($with !== '' ? $with : '—') ?></span>
            <span><?= e((string) ($row['booked_by_name'] ?? '—')) ?></span>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </section>
</div>
<script src="https://cdn.jsdelivr.net/npm/jalaali-js@1.2.7/dist/jalaali.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/@majidh1/jalalidatepicker/dist/jalalidatepicker.min.js"></script>
<script src="<?= e(url('/assets/js/search-select.js')) ?>?v=20260929rooms"></script>
<script>
(function () {
  var roomBase = <?= json_encode(url('/admin/rooms'), JSON_UNESCAPED_UNICODE) ?>;
  var currentDay = <?= json_encode($day) ?>;
  function faToEn(str){
    return String(str || "").replace(/[۰-۹]/g, function (d) { return String("۰۱۲۳۴۵۶۷۸۹".indexOf(d)); });
  }
  function pad(n){ return (n < 10 ? "0" : "") + n; }
  var dayView = document.getElementById("room-day-view");
  if (dayView && typeof jalaliDatepicker !== "undefined") {
    jalaliDatepicker.startWatch({
      selector: "#room-day-view",
      time: false,
      hideAfterChange: true,
      showTodayBtn: true,
      showEmptyBtn: true,
      autoReadOnlyInput: true,
      persianDigits: true,
      zIndex: 100000,
      container: "body"
    });
    dayView.addEventListener("jdp:change", function () {
      var t = faToEn(dayView.value).replace(/-/g, "/").trim();
      if (t === "") {
        if (currentDay !== <?= json_encode(date('Y-m-d')) ?>) {
          window.location.href = roomBase;
        }
        return;
      }
      var p = t.split("/");
      if (p.length !== 3 || typeof jalaali === "undefined") return;
      var g = jalaali.toGregorian(parseInt(p[0], 10), parseInt(p[1], 10), parseInt(p[2], 10));
      var key = g.gy + "-" + pad(g.gm) + "-" + pad(g.gd);
      if (key !== currentDay) {
        window.location.href = roomBase + "?day=" + key;
      }
    });
  }

  var clocks = [];
  var dataList = document.getElementById("room-clock-options");
  if (dataList) {
    clocks = Array.prototype.map.call(dataList.options, function (opt) { return opt.value; });
  }
  function normClock(value) {
    return String(value || "")
      .replace(/[۰-۹]/g, function (d) { return String("۰۱۲۳۴۵۶۷۸۹".indexOf(d)); })
      .replace(/[٠-٩]/g, function (d) { return String("٠١٢٣٤٥٦٧٨٩".indexOf(d)); })
      .replace(/\s/g, "");
  }
  document.querySelectorAll("[data-clock]").forEach(function (input) {
    var wrap = document.createElement("div");
    wrap.className = "search-select";
    input.parentNode.insertBefore(wrap, input);
    wrap.appendChild(input);
    input.classList.add("search-select-input");
    input.setAttribute("role", "combobox");
    input.setAttribute("aria-expanded", "false");
    input.setAttribute("autocomplete", "off");
    var list = document.createElement("ul");
    list.className = "search-select-list";
    list.hidden = true;
    wrap.appendChild(list);
    function close() {
      list.hidden = true;
      input.setAttribute("aria-expanded", "false");
      wrap.classList.remove("is-open");
    }
    function open() {
      var q = normClock(input.value);
      list.innerHTML = "";
      clocks.forEach(function (value) {
        if (q && normClock(value).indexOf(q) === -1 && value.indexOf(q) === -1) return;
        var li = document.createElement("li");
        li.className = "search-select-option";
        li.textContent = value;
        li.addEventListener("mousedown", function (e) {
          e.preventDefault();
          input.value = value;
          close();
        });
        list.appendChild(li);
      });
      if (!list.children.length) {
        var empty = document.createElement("li");
        empty.className = "search-select-empty";
        empty.textContent = "همین متن ثبت می‌شود";
        list.appendChild(empty);
      }
      list.hidden = false;
      input.setAttribute("aria-expanded", "true");
      wrap.classList.add("is-open");
    }
    input.addEventListener("focus", open);
    input.addEventListener("click", open);
    input.addEventListener("input", open);
    input.addEventListener("blur", function () { window.setTimeout(close, 120); });
    input.addEventListener("keydown", function (e) {
      if (e.key === "Escape") close();
    });
  });

  document.querySelectorAll("[data-room-book]").forEach(function (form) {
    var purpose = form.querySelector("[data-purpose]");
    function sync() {
      var value = purpose ? purpose.value : "";
      form.querySelectorAll("[data-for]").forEach(function (box) {
        var on = box.getAttribute("data-for") === value;
        box.hidden = !on;
        box.querySelectorAll("input, select, textarea").forEach(function (field) {
          field.disabled = !on;
        });
      });
    }
    var repeat = form.querySelector("[data-repeat]");
    var repeatBox = form.querySelector("[data-repeat-fields]");
    function syncRepeat() {
      var on = !!(repeat && repeat.checked);
      if (repeatBox) {
        repeatBox.hidden = !on;
        repeatBox.querySelectorAll("input").forEach(function (field) { field.disabled = !on; });
      }
    }
    if (repeat) repeat.addEventListener("change", syncRepeat);
    syncRepeat();
    if (purpose) purpose.addEventListener("change", sync);
    if (window.enhanceSearchSelect) {
      form.querySelectorAll("select[data-search]").forEach(function (sel) {
        enhanceSearchSelect(sel);
      });
    }
    sync();
  });
})();
</script>
<?php
$GLOBALS['pageHead'] = '<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@majidh1/jalalidatepicker/dist/jalalidatepicker.min.css">';
clinic_desk_render_page('اتاق‌ها', ob_get_clean());
