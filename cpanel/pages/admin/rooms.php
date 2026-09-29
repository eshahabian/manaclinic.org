<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/admin_panel.php';
require_once __DIR__ . '/../../includes/clinic_rooms.php';

clinic_rooms_require_user();

$day = clinic_rooms_parse_day((string) ($_GET['day'] ?? ''));
$week = clinic_rooms_week_days($day);
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
$hours = clinic_rooms_hour_range($dayBookings);
$assignments = clinic_rooms_active_assignments($pdo);
$openAppointments = clinic_rooms_open_appointments($pdo);
$openWorkshops = clinic_rooms_open_workshop_sessions($pdo);
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

$prevDay = date('Y-m-d', strtotime($day . ' -1 day') ?: time());
$nextDay = date('Y-m-d', strtotime($day . ' +1 day') ?: time());
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
    <p class="muted" style="margin:.35rem 0 0">اتاق ۱، ۲ و ۳. هر نوبت حضوری یا جلسه کارگاه یک اتاق می‌گیرد. ساعت‌های پر و خالی همین روز را ببینید.</p>
  </div>

  <div class="room-daybar">
    <a class="btn btn-outline btn-sm" href="<?= e(url('/admin/rooms?day=' . $prevDay)) ?>">روز قبل</a>
    <strong><?= e($weekdayName !== '' ? $weekdayName . ' · ' : '') ?><?= e((string) ($dayParts['label'] ?? $day)) ?></strong>
    <a class="btn btn-outline btn-sm" href="<?= e(url('/admin/rooms?day=' . $nextDay)) ?>">روز بعد</a>
    <a class="btn btn-outline btn-sm" href="<?= e(url('/admin/rooms?day=' . date('Y-m-d'))) ?>">امروز</a>
  </div>
  <div class="room-week">
    <?php foreach ($week as $item): ?>
      <a class="room-week-day<?= $item['date'] === $day ? ' is-on' : '' ?>" href="<?= e(url('/admin/rooms?day=' . $item['date'])) ?>">
        <span><?= e((string) $item['weekday']) ?></span>
        <strong><?= e((string) $item['label']) ?></strong>
      </a>
    <?php endforeach; ?>
  </div>

  <div class="grid-3 room-grid">
    <?php foreach (clinic_rooms_numbers() as $roomNo): ?>
      <?php
        $rows = $byRoom[$roomNo];
        $seen = [];
      ?>
      <section class="panel room-col room-col-<?= (int) $roomNo ?>" id="room-<?= (int) $roomNo ?>">
        <h2><?= e(clinic_room_label($roomNo)) ?></h2>
        <p class="muted" style="margin:0 0 .75rem;font-size:.85rem"><?= e(to_fa_digits((string) count($rows))) ?> رزرو در این روز</p>
        <div class="room-hours">
          <?php foreach ($hours as $hour): ?>
            <?php
              $hit = clinic_rooms_booking_at_hour($rows, $day, $hour);
              $hid = $hit ? (string) ($hit['id'] ?? '') : '';
              $continued = $hid !== '' && isset($seen[$hid]);
              if ($hid !== '') {
                  $seen[$hid] = true;
              }
              $clock = to_fa_digits(sprintf('%02d:00', $hour));
            ?>
            <div class="room-hour<?= $hit ? ' is-busy' : ' is-free' ?>">
              <span class="room-hour-time"><?= e($clock) ?></span>
              <?php if ($hit): ?>
                <span class="room-hour-use"><?= e($continued ? 'ادامه · ' . clinic_room_purpose($hit) : clinic_room_purpose($hit)) ?></span>
              <?php else: ?>
                <span class="room-hour-use muted">آزاد</span>
              <?php endif; ?>
            </div>
          <?php endforeach; ?>
        </div>
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
                  <form method="post" action="<?= e(url('/admin/rooms')) ?>">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="release">
                    <input type="hidden" name="booking_id" value="<?= e((string) $row['id']) ?>">
                    <input type="hidden" name="day" value="<?= e($day) ?>">
                    <button type="submit" class="btn btn-outline btn-sm" onclick="return confirm('این رزرو لغو و ساعت آزاد شود؟');">آزاد کردن</button>
                  </form>
                <?php endif; ?>
              </div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </section>
    <?php endforeach; ?>
  </div>

  <form class="panel form-stack" method="post" action="<?= e(url('/admin/rooms')) ?>" id="room-book-form">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="assign">
    <input type="hidden" name="day" value="<?= e($day) ?>">
    <h2 style="margin:0">رزرو برای جلسهٔ پیش‌رو</h2>
    <p class="muted" style="margin:0;font-size:.85rem">نوبت حضوری و جلسه کارگاه ساعت خودشان را دارند. «سایر» برای همین روزی است که بالا انتخاب کرده‌اید. اگر همان جلسه قبلاً اتاق داشته باشد، به اتاق جدید منتقل می‌شود.</p>
    <div class="room-form-grid">
      <label>
        <span class="label">اتاق</span>
        <select class="input" name="room_no" required>
          <?php foreach (clinic_rooms_numbers() as $roomNo): ?>
            <option value="<?= (int) $roomNo ?>"><?= e(clinic_room_label($roomNo)) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label>
        <span class="label">جلسه</span>
        <select class="input" name="target" id="room-target" required>
          <option value="">انتخاب کنید</option>
          <optgroup label="نوبت حضوری">
            <?php foreach ($openAppointments as $appt): ?>
              <?php
                $key = 'a:' . (string) $appt['id'];
                $where = isset($assignments[$key]) ? ' — الان ' . clinic_room_label((int) $assignments[$key]) : '';
                $label = format_fa_datetime((string) $appt['starts_at']) . ' · ' . (string) $appt['doctor_name'] . ' با ' . (string) $appt['patient_name'] . $where;
              ?>
              <option value="<?= e($key) ?>"><?= e($label) ?></option>
            <?php endforeach; ?>
          </optgroup>
          <optgroup label="کارگاه حضوری">
            <?php foreach ($openWorkshops as $sess): ?>
              <?php
                $key = 'w:' . (string) $sess['id'];
                $where = isset($assignments[$key]) ? ' — الان ' . clinic_room_label((int) $assignments[$key]) : '';
                $label = format_fa_datetime((string) $sess['span_start']) . ' · ' . (string) $sess['workshop_title'] . ' · ' . (string) $sess['doctor_name'] . $where;
              ?>
              <option value="<?= e($key) ?>"><?= e($label) ?></option>
            <?php endforeach; ?>
          </optgroup>
          <option value="block">سایر — جلسه یا کاری که نوبت ثبت‌شده ندارد</option>
        </select>
      </label>
    </div>
    <div id="room-block-fields" hidden>
      <label>
        <span class="label">عنوان</span>
        <input class="input" name="block_title" maxlength="255" placeholder="مثلاً جلسه تیم یا مصاحبه">
      </label>
      <div class="room-form-grid">
        <label>
          <span class="label">از ساعت</span>
          <input class="input" type="time" name="block_start" value="09:00">
        </label>
        <label>
          <span class="label">تا ساعت</span>
          <input class="input" type="time" name="block_end" value="11:00">
        </label>
      </div>
    </div>
    <label>
      <span class="label">یادداشت (اختیاری)</span>
      <input class="input" name="note" maxlength="255">
    </label>
    <div>
      <button type="submit" class="btn btn-primary">ثبت رزرو</button>
    </div>
  </form>

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
            <span><?= e($kind === 'WORKSHOP' ? 'کارگاه' : ($kind === 'APPOINTMENT' ? 'نوبت' : 'سایر')) ?></span>
            <span><?= e($doctor !== '' ? $doctor : '—') ?></span>
            <span><?= e($with !== '' ? $with : '—') ?></span>
            <span><?= e((string) ($row['booked_by_name'] ?? '—')) ?></span>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </section>
</div>
<script>
(function () {
  var sel = document.getElementById("room-target");
  var block = document.getElementById("room-block-fields");
  if (!sel || !block) return;
  function sync() { block.hidden = sel.value !== "block"; }
  sel.addEventListener("change", sync);
  sync();
})();
</script>
<?php
render_admin_page('اتاق‌ها', ob_get_clean());
