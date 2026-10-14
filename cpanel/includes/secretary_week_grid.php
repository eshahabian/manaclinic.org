<?php
declare(strict_types=1);

require_once __DIR__ . '/availability.php';
require_once __DIR__ . '/appointment_session.php';
require_once __DIR__ . '/appointment_cancel.php';
if (is_file(__DIR__ . '/appointment_payment.php')) {
    require_once __DIR__ . '/appointment_payment.php';
}

/** نوبت انجام‌شده: وضعیت COMPLETED یا ساعت شروع گذشته. لغوشده‌ها این‌جا نیستند. */
function secretary_appointment_is_done_slot(array $row, ?int $now = null): bool
{
    $status = (string) ($row['status'] ?? '');
    if ($status === 'CANCELLED') {
        return false;
    }
    if ($status === 'COMPLETED') {
        return true;
    }
    $now = $now ?? time();
    $start = strtotime(str_replace('T', ' ', (string) ($row['starts_at'] ?? ''))) ?: 0;

    return $start > 0 && $start < $now;
}

/**
 * بعد از ذخیره، همان تب و درمانگر و نوبت بماند.
 * تب خالی یعنی تب فعلی آدرس؛ برای تب انجام‌شده تب را صریح بدهید.
 */
function secretary_appointment_stay_next(string $deskNext, ?string $tab = null, ?string $doctorId = null, string $appointmentId = ''): string
{
    $allowed = ['new', 'reservations', 'upcoming', 'done', 'cancelled'];
    if ($tab === null || $tab === '') {
        $tab = trim((string) ($_GET['tab'] ?? ''));
    }
    if (!in_array($tab, $allowed, true)) {
        $tab = 'upcoming';
    }
    $stayBase = $deskNext;
    $stayParams = [];
    $stayQ = strpos($stayBase, '?');
    if ($stayQ !== false) {
        parse_str((string) substr($stayBase, $stayQ + 1), $stayExisting);
        $stayBase = substr($stayBase, 0, $stayQ);
        foreach ($stayExisting as $stayKey => $stayVal) {
            if (is_scalar($stayVal) && (string) $stayVal !== '') {
                $stayParams[(string) $stayKey] = (string) $stayVal;
            }
        }
    }
    $stayParams['tab'] = $tab;
    if ($doctorId === null) {
        $doctorId = trim((string) ($_GET['doctor_id'] ?? ''));
    }
    if ($doctorId !== '') {
        $stayParams['doctor_id'] = $doctorId;
    }
    foreach (['q', 'day'] as $stayKey) {
        $stayVal = trim((string) ($_GET[$stayKey] ?? ''));
        if ($stayVal !== '') {
            $stayParams[$stayKey] = $stayVal;
        }
    }
    if ($appointmentId !== '') {
        $stayParams['appt'] = $appointmentId;
    }

    return $stayBase . '?' . http_build_query($stayParams);
}

/**
 * نقشه نوبت‌های اشغال برای یک درمانگر در بازه تاریخ (با جزئیات پرداخت و نوع جلسه)
 *
 * @return array<string, array<string, mixed>> key = Y-m-d H:i
 */
function secretary_week_booked_map(PDO $pdo, string $doctorId, string $fromYmd, string $toYmd): array
{
    if (function_exists('ensure_appointment_hold_columns')) {
        ensure_appointment_hold_columns($pdo);
    }
    if (function_exists('appointment_ensure_room_schema')) {
        appointment_ensure_room_schema($pdo);
    }
    $roomSql = function_exists('appointment_room_no_sql') ? appointment_room_no_sql('a') : 'NULL AS room_no';
    $stmt = $pdo->prepare("
      SELECT a.id, a.starts_at, a.ends_at, a.status, a.session_mode,
             a.created_at, a.requested_at, a.reviewed_at, a.payment_confirmed_at, a.staff_payment_note,
             u.id AS patient_id, u.name AS patient_name, u.phone,
             p.id AS payment_id, p.status AS pay_status, p.amount, p.receipt_path, p.ref_id,
             {$roomSql}
      FROM appointments a
      JOIN users u ON u.id = a.patient_id
      LEFT JOIN payments p ON p.appointment_id = a.id
      WHERE a.doctor_id = ?
        AND a.status IN ('PENDING_APPROVAL','PENDING_PAYMENT','CONFIRMED','COMPLETED')
        AND DATE(a.starts_at) BETWEEN ? AND ?
    ");
    $stmt->execute([$doctorId, $fromYmd, $toYmd]);
    $map = [];
    foreach ($stmt->fetchAll() as $row) {
        $raw = str_replace('T', ' ', (string) ($row['starts_at'] ?? ''));
        $key = substr($raw, 0, 16);
        if (strlen($key) >= 16) {
            $map[$key] = $row;
        }
    }

    return $map;
}

/**
 * برنامه ۷ روز آینده یک درمانگر: هر روز + ساعت‌های خالی اعلام‌شده
 *
 * @return list<array{date:string,label:string,weekday:string,slots:list<array<string,mixed>>}>
 */
function secretary_week_schedule(PDO $pdo, string $doctorId, int $days = 7): array
{
    ensure_availability_schema($pdo);
    $days = max(1, min(14, $days));
    $from = date('Y-m-d');
    $to = date('Y-m-d', strtotime('+' . ($days - 1) . ' days') ?: time());
    $booked = secretary_week_booked_map($pdo, $doctorId, $from, $to);

    $avStmt = $pdo->prepare('SELECT * FROM availabilities WHERE doctor_id=? AND date BETWEEN ? AND ?');
    $avStmt->execute([$doctorId, $from, $to]);
    $byDate = [];
    foreach ($avStmt->fetchAll() as $row) {
        $d = substr((string) ($row['date'] ?? ''), 0, 10);
        if ($d !== '') {
            $byDate[$d] = $row;
        }
    }

    $out = [];
    for ($i = 0; $i < $days; $i++) {
        $date = date('Y-m-d', strtotime('+' . $i . ' days') ?: time());
        $parts = jalali_day_parts($date . ' 12:00:00') ?: [];
        $label = (string) ($parts['label'] ?? to_jalali_label($date));
        $weekday = (string) ($parts['weekday'] ?? '');
        $av = $byDate[$date] ?? null;
        $hours = $av ? appointment_availability_hours($av) : [];
        $slots = [];
        foreach (appointment_day_display_slots($date, $hours, $booked) as $slot) {
            $slot['label'] = secretary_week_slot_label_for($slot);
            $slots[] = $slot;
        }
        $out[] = [
            'date' => $date,
            'label' => $label,
            'weekday' => $weekday,
            'slots' => $slots,
        ];
    }

    return $out;
}

/** نام مراجع فقط روی ساعت شروع نوبت می‌آید؛ دنبالهٔ همان جلسه نام را تکرار نمی‌کند. */
function secretary_week_slot_label_for(array $slot): string
{
    if (!empty($slot['continuation'])) {
        return 'پر';
    }
    $booking = $slot['booking'] ?? null;
    if (!is_array($booking)) {
        return appointment_display_slot_face($slot);
    }
    $clock = appointment_clock_from_row($booking);
    $who = trim((string) ($booking['patient_name'] ?? ''));
    $label = $clock !== '' ? to_fa_digits($clock) : appointment_display_slot_face($slot);
    if ($who !== '') {
        $label .= ' · ' . $who;
    }

    return $label;
}

function secretary_room_choice_list(): array
{
    if (!function_exists('clinic_rooms_numbers') && is_file(__DIR__ . '/clinic_rooms.php')) {
        require_once __DIR__ . '/clinic_rooms.php';
    }
    $numbers = function_exists('clinic_rooms_numbers') ? clinic_rooms_numbers() : [1, 2, 3];
    $choices = [];
    foreach ($numbers as $n) {
        $n = (int) $n;
        if ($n < 1) {
            continue;
        }
        $choices[] = [
            'value' => (string) $n,
            'label' => function_exists('clinic_room_label') ? clinic_room_label($n) : ('اتاق ' . $n),
        ];
    }
    if ($choices === []) {
        $choices = [
            ['value' => '1', 'label' => 'اتاق ۱'],
            ['value' => '2', 'label' => 'اتاق ۲'],
            ['value' => '3', 'label' => 'اتاق ۳'],
        ];
    }

    return $choices;
}

function secretary_room_choices_json(): string
{
    $json = json_encode(
        secretary_room_choice_list(),
        JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS
    );

    return is_string($json) ? $json : '[]';
}

/** رادیوهای اتاق داخل همان فرمی که دکمه ثبت اتاق دارد. */
function secretary_room_picker_markup(string $selected = ''): string
{
    $html = '<div class="sec-room-picks" role="radiogroup" aria-label="اتاق">';
    foreach (secretary_room_choice_list() as $choice) {
        $value = (string) ($choice['value'] ?? '');
        $label = (string) ($choice['label'] ?? '');
        if (!in_array($value, ['1', '2', '3'], true) || $label === '') {
            continue;
        }
        $html .= '<label class="sec-room-pick">';
        $html .= '<input type="radio" name="room_no" value="' . $value . '"' . ($value === $selected ? ' checked' : '') . '>';
        $html .= '<span>' . e($label) . '</span>';
        $html .= '</label>';
    }
    $html .= '</div>';

    return $html;
}

/** شنونده‌ای که ارسال فرم را قطع کند اینجا نمی‌ماند. */
function secretary_room_picker_script(): string
{
    return '';
}

function secretary_week_grid_html(array $week, string $doctorId, string $bookBaseUrl, ?string $deskNext = null): string
{
    $deskNext = $deskNext ?: '/secretary/appointments';
    $nextUpcoming = $deskNext . (str_contains($deskNext, '?') ? '&' : '?') . 'tab=upcoming&doctor_id=' . rawurlencode($doctorId);
    ob_start();
    ?>
    <div class="sec-week-grid" data-sec-week data-doctor-id="<?= e($doctorId) ?>">
      <?php foreach ($week as $day): ?>
        <div class="sec-week-day">
          <div class="sec-week-day-head">
            <strong><?= e((string) ($day['weekday'] !== '' ? $day['weekday'] . ' · ' : '') . ($day['label'] ?? '')) ?></strong>
            <span class="muted" style="font-size:.8rem"><?= e(to_fa_digits((string) ($day['date'] ?? ''))) ?></span>
          </div>
          <?php if (empty($day['slots'])): ?>
            <p class="muted" style="margin:0;font-size:.85rem">برای این روز وقت خالی اعلام نشده است.</p>
          <?php else: ?>
            <div class="sec-week-hours">
              <?php foreach ($day['slots'] as $slot): ?>
                <?php
                  $booking = $slot['booking'] ?? null;
                  $isBooked = is_array($booking);
                  $continuation = !empty($slot['continuation']);
                  $time = (string) ($slot['time'] ?? '');
                  $date = (string) ($day['date'] ?? '');
                  if ($isBooked) {
                      $pay = function_exists('appointment_pay_status_display')
                          ? appointment_pay_status_display($booking)
                          : payment_status_label((string) ($booking['pay_status'] ?? ''));
                      $mode = function_exists('appointment_session_mode_label')
                          ? appointment_session_mode_label((string) ($booking['session_mode'] ?? ''))
                          : '';
                      $status = appointment_status_label((string) ($booking['status'] ?? ''));
                      $slotDone = secretary_appointment_is_done_slot($booking);
                      if ($slotDone && function_exists('appointment_status_label_is_reserved') && appointment_status_label_is_reserved($status)) {
                          $status = '';
                      }
                      $amount = isset($booking['amount']) ? format_price((int) $booking['amount']) : '';
                      $canConfirmPay = function_exists('appointment_staff_can_mark_paid')
                          ? appointment_staff_can_mark_paid($booking)
                          : (function_exists('appointment_payment_can_upload_receipt') && appointment_payment_can_upload_receipt($booking));
                      $awaitingApproval = function_exists('appointment_awaiting_secretary_approval')
                          && appointment_awaiting_secretary_approval($booking);
                      $openHold = function_exists('appointment_is_open_hold') && appointment_is_open_hold($booking);
                      $hasReceipt = trim((string) ($booking['receipt_path'] ?? '')) !== '';
                      $roomNo = (int) ($booking['room_no'] ?? 0);
                      $onlineSession = function_exists('appointment_is_online_mode') && appointment_is_online_mode($booking);
                      $roomLabel = '';
                      if ($roomNo > 0) {
                          $roomLabel = function_exists('clinic_room_label') ? clinic_room_label($roomNo) : ('اتاق ' . $roomNo);
                      }
                      $title = trim(
                          (string) ($booking['patient_name'] ?? '')
                          . ($status !== '' ? "\n" . $status : '')
                          . ($pay !== '' ? "\nپرداخت: " . $pay : '')
                          . ($mode !== '' ? "\nنوع: " . $mode : '')
                          . ($roomLabel !== '' ? "\n" . $roomLabel : "\nاتاق مشخص نشده")
                          . ($amount !== '' ? "\n" . $amount : '')
                          . (!empty($booking['phone']) ? "\n" . $booking['phone'] : '')
                      );
                      ?>
                      <div class="sec-week-hour-wrap">
                        <button
                          type="button"
                          class="sec-week-hour is-booked<?= $continuation ? ' is-continuation' : '' ?><?= ($openHold || $awaitingApproval) ? ' is-awaiting' : '' ?>"
                          data-booked="1"
                          data-patient="<?= e((string) ($booking['patient_name'] ?? '')) ?>"
                          data-phone="<?= e((string) ($booking['phone'] ?? '')) ?>"
                          data-status="<?= e($status) ?>"
                          data-pay="<?= e($pay) ?>"
                          data-mode="<?= e($mode) ?>"
                          data-room="<?= e($roomLabel) ?>"
                          data-amount="<?= e($amount) ?>"
                          data-when="<?= e(to_fa_digits(appointment_clock_from_row($booking))) ?>"
                          data-appt="<?= e((string) ($booking['id'] ?? '')) ?>"
                          title="<?= e($title) ?>"
                        ><?php
                          $chip = (string) ($slot['label'] ?? $time);
                          if (!$continuation && $roomLabel !== '') {
                              $chip .= ' · ' . $roomLabel;
                          }
                          echo e($chip);
                        ?></button>
                        <div class="sec-week-slot-live" hidden>
                          <div class="sec-week-shadow-tools">
                            <?php
                              $nextHold = $deskNext . (str_contains($deskNext, '?') ? '&' : '?') . 'tab=reservations&doctor_id=' . rawurlencode($doctorId);
                              $roomNext = $deskNext . (str_contains($deskNext, '?') ? '&' : '?') . 'tab=upcoming&doctor_id=' . rawurlencode($doctorId);
                              $stayNext = secretary_appointment_stay_next($deskNext, null, $doctorId, (string) ($booking['id'] ?? ''));
                            ?>
                            <?php
                              $canEditAppt = !$slotDone;
                              $modeId = 'week-mode-' . preg_replace('/[^a-zA-Z0-9_-]/', '', (string) ($booking['id'] ?? ''));
                            ?>
                            <?php if ($canEditAppt): ?>
                              <details class="sec-week-edit">
                                <summary class="btn btn-outline btn-sm">ویرایش نوبت</summary>
                                <div class="sec-week-edit-body">
                                  <form method="post" action="<?= e(url('/secretary/appointments')) ?>" class="sec-week-edit-row">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="set_session_mode">
                                    <input type="hidden" name="appointment_id" value="<?= e((string) ($booking['id'] ?? '')) ?>">
                                    <input type="hidden" name="next" value="<?= e($roomNext) ?>">
                                    <label class="muted" for="<?= e($modeId) ?>">نوع جلسه</label>
                                    <select class="input" name="session_mode" id="<?= e($modeId) ?>">
                                      <option value="IN_PERSON"<?= appointment_normalize_session_mode((string) ($booking['session_mode'] ?? '')) === 'IN_PERSON' ? ' selected' : '' ?>>حضوری</option>
                                      <option value="ONLINE"<?= appointment_normalize_session_mode((string) ($booking['session_mode'] ?? '')) === 'ONLINE' ? ' selected' : '' ?>>آنلاین</option>
                                    </select>
                                    <button type="submit" class="btn btn-outline btn-sm">ذخیره نوع</button>
                                  </form>
                                  <?= function_exists('appointment_time_edit_form_html') ? appointment_time_edit_form_html($booking, $roomNext) : '' ?>
                                  <form method="post" action="<?= e(url('/secretary/appointments')) ?>" class="sec-room-form sec-week-edit-row">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="set_room">
                                    <input type="hidden" name="appointment_id" value="<?= e((string) ($booking['id'] ?? '')) ?>">
                                    <input type="hidden" name="next" value="<?= e($roomNext) ?>">
                                    <span class="label">اتاق</span>
                                    <?= secretary_room_picker_markup($roomNo > 0 ? (string) $roomNo : '') ?>
                                    <button type="submit" class="btn btn-outline btn-sm">ذخیره اتاق</button>
                                  </form>
                                </div>
                              </details>
                            <?php endif; ?>
                            <?php if (!$slotDone && function_exists('secretary_patient_cancel_form')): ?>
                              <?php $cancelForm = secretary_patient_cancel_form((string) ($booking['id'] ?? ''), (string) ($booking['status'] ?? ''), $roomNext); ?>
                              <?php if ($cancelForm !== ''): ?>
                                <details class="sec-week-edit">
                                  <summary class="btn btn-outline btn-sm">کنسل شدن وقت</summary>
                                  <div class="sec-week-edit-body">
                                    <?= $cancelForm ?>
                                  </div>
                                </details>
                              <?php endif; ?>
                            <?php endif; ?>
                            <?php if (!$slotDone && $roomNo < 1 && empty($onlineSession)): ?>
                              <form method="post" action="<?= e(url('/secretary/appointments')) ?>" class="sec-week-room-form sec-room-form">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="set_room">
                                <input type="hidden" name="appointment_id" value="<?= e((string) ($booking['id'] ?? '')) ?>">
                                <input type="hidden" name="next" value="<?= e($roomNext) ?>">
                                <span class="label" style="display:block;font-size:.75rem;margin:0 0 .45rem"><?= $onlineSession ? 'اتاق این جلسه هنوز مشخص نیست' : 'اتاق این نوبت حضوری هنوز مشخص نیست' ?></span>
                                <?= secretary_room_picker_markup() ?>
                                <button type="submit" class="btn btn-primary btn-sm">ثبت اتاق</button>
                              </form>
                            <?php endif; ?>
                            <?php if (!$slotDone && $awaitingApproval && function_exists('appointment_approval_actions_html')): ?>
                              <?= appointment_approval_actions_html($booking, $nextHold) ?>
                            <?php elseif (!$slotDone && $openHold && function_exists('appointment_hold_reject_form_html')): ?>
                              <?= appointment_hold_reject_form_html($booking, $nextHold) ?>
                            <?php endif; ?>
                            <?= staff_receipt_view_html(
                                isset($booking['payment_id']) ? (string) $booking['payment_id'] : null,
                                $hasReceipt ? (string) $booking['receipt_path'] : null,
                                true,
                                $stayNext
                            ) ?>
                            <?php if (!$slotDone && $canConfirmPay && function_exists('appointment_staff_mark_paid_form_html')): ?>
                              <?= appointment_staff_mark_paid_form_html($booking, $nextHold) ?>
                            <?php endif; ?>
                          </div>
                        </div>
                      </div>
                      <?php
                  } else {
                      $href = $bookBaseUrl
                          . (str_contains($bookBaseUrl, '?') ? '&' : '?')
                          . http_build_query([
                              'tab' => 'new',
                              'doctor_id' => $doctorId,
                              'date' => $date,
                              'time' => $time,
                          ]);
                      ?>
                      <a class="sec-week-hour is-free" href="<?= e($href) ?>" title="رزرو برای این ساعت"><?= e((string) ($slot['label'] ?? $time)) ?></a>
                      <?php
                  }
                ?>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        </div>
      <?php endforeach; ?>
    </div>
    <div class="sec-week-shadow" id="sec-week-shadow" hidden>
      <div class="sec-week-shadow-card">
        <button type="button" class="sec-week-shadow-close" aria-label="بستن">×</button>
        <h3 class="sec-week-shadow-name"></h3>
        <p class="sec-week-shadow-meta muted"></p>
        <div class="sec-week-shadow-actions"></div>
      </div>
    </div>
    <?= secretary_room_picker_script() ?>
    <script>
    (function(){
      var root = document.querySelector("[data-sec-week]");
      if (!root || root.getAttribute("data-inited") === "1") return;
      root.setAttribute("data-inited", "1");
      var shadow = document.getElementById("sec-week-shadow");
      if (!shadow) return;
      if (shadow.parentNode !== document.body) document.body.appendChild(shadow);
      var nameEl = shadow.querySelector(".sec-week-shadow-name");
      var metaEl = shadow.querySelector(".sec-week-shadow-meta");
      var actionsEl = shadow.querySelector(".sec-week-shadow-actions");
      var closeBtn = shadow.querySelector(".sec-week-shadow-close");
      function close(){
        var open = actionsEl ? actionsEl.querySelector(".sec-week-slot-live") : null;
        if (open && open._home && open._home.appendChild) {
          open.hidden = true;
          open._home.appendChild(open);
        }
        shadow.hidden = true;
      }
      if (closeBtn) closeBtn.addEventListener("click", close);
      shadow.addEventListener("click", function(e){ if (e.target === shadow) close(); });
      root.addEventListener("click", function(e){
        if (e.target && e.target.closest && e.target.closest(".sec-week-shadow")) return;
        var btn = e.target && e.target.closest ? e.target.closest(".sec-week-hour.is-booked") : null;
        if (!btn || !root.contains(btn)) return;
        e.preventDefault();
        var lines = [];
        if (btn.getAttribute("data-when")) lines.push("ساعت: " + btn.getAttribute("data-when"));
        if (btn.getAttribute("data-status")) lines.push("وضعیت نوبت: " + btn.getAttribute("data-status"));
        if (btn.getAttribute("data-pay")) lines.push("پرداخت: " + btn.getAttribute("data-pay"));
        if (btn.getAttribute("data-mode")) lines.push("نوع جلسه: " + btn.getAttribute("data-mode"));
        lines.push(btn.getAttribute("data-room") ? ("اتاق: " + btn.getAttribute("data-room")) : "اتاق: مشخص نشده");
        if (btn.getAttribute("data-amount")) lines.push(btn.getAttribute("data-amount"));
        if (btn.getAttribute("data-phone")) lines.push("تلفن: " + btn.getAttribute("data-phone"));
        if (nameEl) nameEl.textContent = btn.getAttribute("data-patient") || "مراجعه‌کننده";
        if (metaEl) metaEl.textContent = lines.join(" · ");
        if (actionsEl) {
          var wrap = btn.closest(".sec-week-hour-wrap");
          if (!btn._slotLive && wrap) btn._slotLive = wrap.querySelector(".sec-week-slot-live");
          var live = btn._slotLive || null;
          var already = actionsEl.querySelector(".sec-week-slot-live");
          if (already && already !== live && already._home) {
            already.hidden = true;
            already._home.appendChild(already);
          }
          actionsEl.querySelectorAll(".sec-week-shadow-dismiss").forEach(function (node) { node.remove(); });
          if (live) {
            if (!live._home && wrap) live._home = wrap;
            live.hidden = false;
            actionsEl.insertBefore(live, actionsEl.firstChild);
          }
          var dismiss = document.createElement("button");
          dismiss.type = "button";
          dismiss.className = "btn btn-outline btn-sm sec-week-shadow-dismiss";
          dismiss.textContent = "بستن";
          dismiss.addEventListener("click", close);
          actionsEl.appendChild(dismiss);
        }
        if (shadow.parentNode !== document.body) document.body.appendChild(shadow);
        shadow.hidden = false;
      });
      var openAppt = "";
      var openTab = "";
      try {
        var openParams = new URLSearchParams(window.location.search);
        openAppt = openParams.get("appt") || "";
        openTab = openParams.get("tab") || "";
      } catch (err) {
        openAppt = "";
        openTab = "";
      }
      if (openAppt && (openTab === "" || openTab === "upcoming")) {
        var bookedButtons = root.querySelectorAll(".sec-week-hour.is-booked");
        for (var i = 0; i < bookedButtons.length; i++) {
          if (bookedButtons[i].getAttribute("data-appt") === openAppt) {
            bookedButtons[i].click();
            break;
          }
        }
      }
    })();
    </script>
    <?php
    return (string) ob_get_clean();
}
