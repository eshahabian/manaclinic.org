<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/admin_panel.php';
require_once __DIR__ . '/../../includes/clinic_rooms.php';

clinic_rooms_require_user();

$day = clinic_rooms_parse_day((string) ($_GET['day'] ?? ''));
$roomDays = [];
$roomMeta = [];
foreach (clinic_rooms_numbers() as $roomNo) {
    $rawDay = trim((string) ($_GET['d' . $roomNo] ?? ''));
    $roomDay = $rawDay !== '' ? clinic_rooms_parse_day($rawDay) : $day;
    $roomDays[$roomNo] = $roomDay;
    $parts = jalali_day_parts($roomDay . ' 12:00:00') ?: ['label' => $roomDay];
    $weekday = '';
    foreach (clinic_rooms_week_days($roomDay) as $item) {
        if (($item['date'] ?? '') === $roomDay) {
            $weekday = (string) ($item['weekday'] ?? '');
            break;
        }
    }
    [$roomGy, $roomGm, $roomGd] = array_map('intval', explode('-', $roomDay));
    [$roomJy, $roomJm, $roomJd] = gregorian_to_jalali($roomGy, $roomGm, $roomGd);
    $roomMeta[$roomNo] = [
        'day' => $roomDay,
        'jalali' => sprintf('%04d/%02d/%02d', $roomJy, $roomJm, $roomJd),
        'weekday' => $weekday,
        'label' => (string) ($parts['label'] ?? $roomDay),
    ];
}
$byRoom = [];
foreach (clinic_rooms_numbers() as $roomNo) {
    $byRoom[$roomNo] = [];
}
$bookingsByDay = [];
foreach ($roomDays as $roomNo => $roomDay) {
    if (!isset($bookingsByDay[$roomDay])) {
        $bookingsByDay[$roomDay] = clinic_rooms_between(
            $pdo,
            $roomDay . ' 00:00:00',
            date('Y-m-d H:i:s', strtotime($roomDay . ' +1 day') ?: time())
        );
    }
    foreach ($bookingsByDay[$roomDay] as $row) {
        if ((int) ($row['room_no'] ?? 0) === (int) $roomNo) {
            $byRoom[$roomNo][] = $row;
        }
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
$outDay = array_key_exists('out', $_GET) ? clinic_rooms_parse_day((string) $_GET['out']) : $day;
$outRoom = (int) ($_GET['room'] ?? 1);
if (!in_array($outRoom, clinic_rooms_numbers(), true)) {
    $outRoom = 1;
}
[$outGy, $outGm, $outGd] = array_map('intval', explode('-', $outDay));
[$outJy, $outJm, $outJd] = gregorian_to_jalali($outGy, $outGm, $outGd);
$outJalali = sprintf('%04d/%02d/%02d', $outJy, $outJm, $outJd);
$outParts = jalali_day_parts($outDay . ' 12:00:00') ?: ['label' => $outDay];
$outWeekday = '';
foreach (clinic_rooms_week_days($outDay) as $item) {
    if ($item['date'] === $outDay) {
        $outWeekday = (string) $item['weekday'];
    }
}
$outByRoom = [];
foreach (clinic_rooms_numbers() as $roomNo) {
    $outByRoom[$roomNo] = [];
}
foreach (clinic_rooms_on_day($pdo, $outDay) as $row) {
    $roomNo = (int) ($row['room_no'] ?? 0);
    if (isset($outByRoom[$roomNo])) {
        $outByRoom[$roomNo][] = $row;
    }
}

$roomsQuery = [];
foreach ($roomDays as $roomNo => $roomDay) {
    $roomsQuery['d' . (int) $roomNo] = $roomDay;
}
if (array_key_exists('out', $_GET)) {
    $roomsQuery['out'] = $outDay;
}
if (array_key_exists('room', $_GET) && in_array($outRoom, clinic_rooms_numbers(), true)) {
    $roomsQuery['room'] = $outRoom;
}
$roomsAction = url('/admin/rooms') . '?' . http_build_query($roomsQuery);

ob_start();
?>
<div class="stack room-desk">
  <div>
    <h1>اتاق‌های کلینیک</h1>
    <p class="muted" style="margin:.35rem 0 0">هر اتاق تاریخ رزرو خودش را داخل همان باکس دارد. ساعت را بنویسید یا از فهرست انتخاب کنید، بعد نوع جلسه را مشخص کنید. ساعت از قبل پر نمی‌شود.</p>
  </div>

  <datalist id="room-clock-options" hidden>
    <?php foreach ($clockChoices as $clock): ?>
      <option value="<?= e($clock) ?>"></option>
    <?php endforeach; ?>
  </datalist>

  <div class="grid-3 room-grid">
    <?php foreach (clinic_rooms_numbers() as $roomNo): ?>
      <?php
        $rows = $byRoom[$roomNo];
        $meta = $roomMeta[$roomNo];
        $roomWeekday = (string) ($meta['weekday'] ?? '');
      ?>
      <section class="panel room-col room-col-<?= (int) $roomNo ?>" id="room-<?= (int) $roomNo ?>">
        <h2><?= e(clinic_room_label($roomNo)) ?></h2>

        <form class="room-book" method="post" action="<?= e($roomsAction) ?>" data-room-book>
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="assign">
          <input type="hidden" name="day" value="<?= e((string) $meta['day']) ?>" data-room-day-value="<?= (int) $roomNo ?>">
          <input type="hidden" name="room_no" value="<?= (int) $roomNo ?>">
          <label>
            <span class="label" for="room-day-<?= (int) $roomNo ?>">تاریخ رزرو</span>
            <input
              class="input"
              type="text"
              id="room-day-<?= (int) $roomNo ?>"
              name="booking_day"
              data-jdp
              data-jdp-only-date
              data-room-day="<?= (int) $roomNo ?>"
              readonly
              autocomplete="off"
              placeholder="کلیک کنید تا تقویم باز شود"
              style="cursor:pointer;max-width:18rem"
              value="<?= e((string) $meta['jalali']) ?>"
            >
          </label>
          <p class="muted" style="margin:0;font-size:.85rem"><?= e(trim($roomWeekday . ' ' . (string) ($meta['label'] ?? ''))) ?> — <?= $rows ? e(to_fa_digits((string) count($rows))) . ' رزرو در این روز' : 'در این روز هنوز رزروی نیست' ?></p>
          <label>
            <span class="label">از ساعت</span>
            <input class="input" name="start_time" data-clock inputmode="numeric" autocomplete="off" placeholder="بنویسید یا انتخاب کنید" required>
          </label>
          <label>
            <span class="label">تا ساعت</span>
            <input class="input" name="end_time" data-clock inputmode="numeric" autocomplete="off" placeholder="بنویسید یا انتخاب کنید" required>
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
            <span>تکرار هر هفته، همین روز (<?= e($roomWeekday !== '' ? $roomWeekday : 'این روز') ?>)</span>
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
                    <form class="room-book" method="post" action="<?= e($roomsAction) ?>" data-room-book>
                      <?= csrf_field() ?>
                      <input type="hidden" name="action" value="update">
                      <input type="hidden" name="booking_id" value="<?= e((string) $row['id']) ?>">
                      <input type="hidden" name="day" value="<?= e((string) $meta['day']) ?>">
                      <label>
                        <span class="label">اتاق</span>
                        <select class="input" name="room_no">
                          <?php foreach (clinic_rooms_numbers() as $editRoom): ?>
                            <option value="<?= (int) $editRoom ?>" <?= (int) $row['room_no'] === $editRoom ? 'selected' : '' ?>><?= e(clinic_room_label($editRoom)) ?></option>
                          <?php endforeach; ?>
                        </select>
                      </label>
                      <label>
                        <span class="label">تاریخ</span>
                        <input class="input" type="text" name="booking_day" data-jdp data-jdp-only-date readonly autocomplete="off" required style="cursor:pointer;max-width:16rem" value="<?= e(clinic_rooms_jalali_input(substr((string) $row['starts_at'], 0, 10))) ?>">
                      </label>
                      <div class="room-form-grid">
                        <label>
                          <span class="label">از ساعت</span>
                          <input class="input" name="start_time" value="<?= e($editStart) ?>" data-clock inputmode="numeric" autocomplete="off" required>
                        </label>
                        <label>
                          <span class="label">تا ساعت</span>
                          <input class="input" name="end_time" value="<?= e($editEnd) ?>" data-clock inputmode="numeric" autocomplete="off" required>
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
                          <span>همین تغییر، از جمله تاریخ، برای هفته‌های بعدی این تکرار</span>
                        </label>
                      <?php endif; ?>
                      <button type="submit" class="btn btn-primary btn-sm">ذخیره ویرایش</button>
                    </form>
                  </details>
                  <form method="post" action="<?= e($roomsAction) ?>">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="release">
                    <input type="hidden" name="booking_id" value="<?= e((string) $row['id']) ?>">
                    <input type="hidden" name="day" value="<?= e((string) $meta['day']) ?>">
                    <button type="submit" class="btn btn-outline btn-sm" onclick="return confirm('این رزرو لغو و ساعت آزاد شود؟');">آزاد کردن</button>
                  </form>
                  <?php if (trim((string) ($row['series_id'] ?? '')) !== ''): ?>
                    <form method="post" action="<?= e($roomsAction) ?>">
                      <?= csrf_field() ?>
                      <input type="hidden" name="action" value="release_series">
                      <input type="hidden" name="booking_id" value="<?= e((string) $row['id']) ?>">
                      <input type="hidden" name="day" value="<?= e((string) $meta['day']) ?>">
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
    <h2 style="margin-top:0">خروجی اتاق‌ها</h2>
    <p class="muted" style="margin:0 0 1rem;font-size:.9rem">هر اتاق تب خودش را دارد. تاریخ را از تقویم انتخاب کنید تا ساعت‌های رزرو همان اتاق در آن روز دیده شود؛ رزروهای آینده، مثل جمعه، هم همین‌جا می‌آیند.</p>
    <div class="room-out-tabs" role="tablist">
      <?php foreach (clinic_rooms_numbers() as $roomNo): ?>
        <button type="button" class="btn <?= $outRoom === (int) $roomNo ? 'btn-primary' : 'btn-outline' ?>" data-room-out-tab="<?= (int) $roomNo ?>" aria-selected="<?= $outRoom === (int) $roomNo ? 'true' : 'false' ?>"><?= e(clinic_room_label($roomNo)) ?></button>
      <?php endforeach; ?>
    </div>
    <?php foreach (clinic_rooms_numbers() as $roomNo): ?>
      <?php $outRows = $outByRoom[$roomNo]; ?>
      <div class="room-out-panel" data-room-out-panel="<?= (int) $roomNo ?>" <?= $outRoom === (int) $roomNo ? '' : 'hidden' ?>>
        <label class="label" for="room-out-view-<?= (int) $roomNo ?>">تاریخ <?= e(clinic_room_label($roomNo)) ?></label>
        <input
          class="input"
          type="text"
          id="room-out-view-<?= (int) $roomNo ?>"
          data-jdp
          data-jdp-only-date
          data-room-out="<?= (int) $roomNo ?>"
          readonly
          autocomplete="off"
          placeholder="کلیک کنید تا تقویم باز شود"
          style="cursor:pointer;max-width:18rem"
          value="<?= e($outJalali) ?>"
        >
        <p class="muted" style="margin:.55rem 0 .85rem;font-size:.9rem"><?= e($outWeekday !== '' ? $outWeekday . ' ' : '') ?><?= e((string) ($outParts['label'] ?? $outDay)) ?> — <?= $outRows ? e(to_fa_digits((string) count($outRows))) . ' رزرو' : 'رزروی نیست' ?></p>
        <?php if (!$outRows): ?>
          <p class="muted">در این تاریخ برای <?= e(clinic_room_label($roomNo)) ?> ساعت رزروشده‌ای نیست.</p>
        <?php else: ?>
          <div class="room-report-table">
            <div class="room-report-head room-report-head-hours">
              <span>ساعت</span>
              <span>کار</span>
              <span>وضعیت</span>
            </div>
            <?php foreach ($outRows as $row): ?>
              <?php
                $startFa = to_fa_digits(date('H:i', strtotime((string) $row['starts_at']) ?: time()));
                $endFa = to_fa_digits(date('H:i', strtotime((string) $row['ends_at']) ?: time()));
              ?>
              <div class="room-report-row room-report-row-hours">
                <span><?= e($startFa) ?> تا <?= e($endFa) ?></span>
                <span><?= e(clinic_room_purpose($row)) ?></span>
                <span><?= e(clinic_room_outcome_label($row)) ?></span>
              </div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>
    <?php endforeach; ?>
  </section>
</div>
<script src="https://cdn.jsdelivr.net/npm/jalaali-js@1.2.7/dist/jalaali.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/@majidh1/jalalidatepicker/dist/jalalidatepicker.min.js"></script>
<script src="<?= e(url('/assets/js/search-select.js')) ?>?v=20260929rooms"></script>
<script>
(function () {
  var roomBase = <?= json_encode(url('/admin/rooms'), JSON_UNESCAPED_UNICODE) ?>;
  var todayKey = <?= json_encode(date('Y-m-d')) ?>;
  function faToEn(str){
    return String(str || "").replace(/[۰-۹]/g, function (d) { return String("۰۱۲۳۴۵۶۷۸۹".indexOf(d)); });
  }
  function pad(n){ return (n < 10 ? "0" : "") + n; }
  if (typeof jalaliDatepicker !== "undefined") {
    jalaliDatepicker.startWatch({
      selector: "[data-jdp]",
      time: false,
      hideAfterChange: true,
      showTodayBtn: true,
      showEmptyBtn: true,
      autoReadOnlyInput: true,
      persianDigits: true,
      zIndex: 100000,
      container: "body"
    });
    document.querySelectorAll("[data-room-out]").forEach(function (outView) {
      outView.addEventListener("jdp:change", function () {
        var t = faToEn(outView.value).replace(/-/g, "/").trim();
        var roomNo = outView.getAttribute("data-room-out") || "1";
        var params = new URLSearchParams(window.location.search);
        params.set("room", roomNo);
        if (t === "") {
          params.delete("out");
          window.location.href = roomBase + "?" + params.toString();
          return;
        }
        var p = t.split("/");
        if (p.length !== 3 || typeof jalaali === "undefined") return;
        var g = jalaali.toGregorian(parseInt(p[0], 10), parseInt(p[1], 10), parseInt(p[2], 10));
        params.set("out", g.gy + "-" + pad(g.gm) + "-" + pad(g.gd));
        window.location.href = roomBase + "?" + params.toString();
      });
    });
    document.querySelectorAll("[data-room-out-tab]").forEach(function (btn) {
      btn.addEventListener("click", function () {
        var roomNo = btn.getAttribute("data-room-out-tab");
        document.querySelectorAll("[data-room-out-tab]").forEach(function (other) {
          var on = other === btn;
          other.classList.toggle("btn-primary", on);
          other.classList.toggle("btn-outline", !on);
          other.setAttribute("aria-selected", on ? "true" : "false");
        });
        document.querySelectorAll("[data-room-out-panel]").forEach(function (panel) {
          panel.hidden = panel.getAttribute("data-room-out-panel") !== roomNo;
        });
        var params = new URLSearchParams(window.location.search);
        params.set("room", roomNo || "1");
        history.replaceState(null, "", roomBase + "?" + params.toString());
      });
    });
    document.querySelectorAll("[data-room-day]").forEach(function (input) {
      input.addEventListener("jdp:change", function () {
        var roomNo = input.getAttribute("data-room-day") || "";
        var hidden = roomNo ? document.querySelector("[data-room-day-value=\"" + roomNo + "\"]") : null;
        var current = hidden ? hidden.value : "";
        var t = faToEn(input.value).replace(/-/g, "/").trim();
        var params = new URLSearchParams(window.location.search);
        document.querySelectorAll("[data-room-day-value]").forEach(function (field) {
          var n = field.getAttribute("data-room-day-value") || "";
          if (n && field.value) params.set("d" + n, field.value);
        });
        params.delete("day");
        var key = todayKey;
        if (t !== "") {
          var p = t.split("/");
          if (p.length !== 3 || typeof jalaali === "undefined") return;
          var g = jalaali.toGregorian(parseInt(p[0], 10), parseInt(p[1], 10), parseInt(p[2], 10));
          key = g.gy + "-" + pad(g.gm) + "-" + pad(g.gd);
        }
        if (hidden) hidden.value = key;
        if (roomNo) params.set("d" + roomNo, key);
        if (key !== current) {
          window.location.href = roomBase + "?" + params.toString() + "#room-" + roomNo;
        }
      });
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
    input.removeAttribute("list");
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
