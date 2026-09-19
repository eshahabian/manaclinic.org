<?php
declare(strict_types=1);

require_once __DIR__ . '/availability.php';
require_once __DIR__ . '/appointment_session.php';
if (is_file(__DIR__ . '/appointment_payment.php')) {
    require_once __DIR__ . '/appointment_payment.php';
}

/**
 * نقشه نوبت‌های اشغال برای یک درمانگر در بازه تاریخ (با جزئیات پرداخت و نوع جلسه)
 *
 * @return array<string, array<string, mixed>> key = Y-m-d H:i
 */
function secretary_week_booked_map(PDO $pdo, string $doctorId, string $fromYmd, string $toYmd): array
{
    $stmt = $pdo->prepare("
      SELECT a.id, a.starts_at, a.ends_at, a.status, a.session_mode,
             u.id AS patient_id, u.name AS patient_name, u.phone,
             p.id AS payment_id, p.status AS pay_status, p.amount, p.receipt_path
      FROM appointments a
      JOIN users u ON u.id = a.patient_id
      LEFT JOIN payments p ON p.appointment_id = a.id
      WHERE a.doctor_id = ?
        AND a.status IN ('PENDING_PAYMENT','CONFIRMED','COMPLETED')
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
        $slots = [];
        $seenHours = [];
        $av = $byDate[$date] ?? null;
        $hours = $av ? appointment_availability_hours($av) : [];
        foreach ($hours as $hour) {
            $hour = (int) $hour;
            $seenHours[$hour] = true;
            $startsAt = appointment_slot_starts_at($date, $hour);
            $key = substr(str_replace('T', ' ', $startsAt), 0, 16);
            $booking = $booked[$key] ?? null;
            $slots[] = [
                'hour' => $hour,
                'time' => appointment_hour_to_time($hour),
                'label' => appointment_hour_chip_label($hour),
                'starts_at' => $startsAt,
                'booking' => $booking,
            ];
        }
        foreach ($booked as $key => $bookingRow) {
            if (!str_starts_with($key, $date . ' ')) {
                continue;
            }
            $hour = (int) substr($key, 11, 2);
            if (isset($seenHours[$hour])) {
                continue;
            }
            $seenHours[$hour] = true;
            $startsAt = appointment_slot_starts_at($date, $hour);
            $slots[] = [
                'hour' => $hour,
                'time' => appointment_hour_to_time($hour),
                'label' => appointment_hour_chip_label($hour),
                'starts_at' => $startsAt,
                'booking' => $bookingRow,
            ];
        }
        usort($slots, static fn(array $a, array $b): int => ((int) $a['hour']) <=> ((int) $b['hour']));
        $out[] = [
            'date' => $date,
            'label' => $label,
            'weekday' => $weekday,
            'slots' => $slots,
        ];
    }

    return $out;
}

function secretary_week_grid_html(array $week, string $doctorId, string $bookBaseUrl): string
{
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
                      $amount = isset($booking['amount']) ? format_price((int) $booking['amount']) : '';
                      $title = trim(
                          (string) ($booking['patient_name'] ?? '')
                          . "\n" . $status
                          . ($pay !== '' ? "\nپرداخت: " . $pay : '')
                          . ($mode !== '' ? "\nنوع: " . $mode : '')
                          . ($amount !== '' ? "\n" . $amount : '')
                          . (!empty($booking['phone']) ? "\n" . $booking['phone'] : '')
                      );
                      ?>
                      <button
                        type="button"
                        class="sec-week-hour is-booked"
                        data-booked="1"
                        data-patient="<?= e((string) ($booking['patient_name'] ?? '')) ?>"
                        data-phone="<?= e((string) ($booking['phone'] ?? '')) ?>"
                        data-status="<?= e($status) ?>"
                        data-pay="<?= e($pay) ?>"
                        data-mode="<?= e($mode) ?>"
                        data-amount="<?= e($amount) ?>"
                        data-appt="<?= e((string) ($booking['id'] ?? '')) ?>"
                        title="<?= e($title) ?>"
                      ><?= e((string) ($slot['label'] ?? $time)) ?></button>
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
      <div class="sec-week-shadow" id="sec-week-shadow" hidden>
        <div class="sec-week-shadow-card">
          <button type="button" class="sec-week-shadow-close" aria-label="بستن">×</button>
          <h3 class="sec-week-shadow-name"></h3>
          <p class="sec-week-shadow-meta muted"></p>
          <div class="sec-week-shadow-actions"></div>
        </div>
      </div>
    </div>
    <script>
    (function(){
      var root = document.querySelector("[data-sec-week]");
      if (!root || root.getAttribute("data-inited") === "1") return;
      root.setAttribute("data-inited", "1");
      var shadow = document.getElementById("sec-week-shadow");
      if (!shadow) return;
      var nameEl = shadow.querySelector(".sec-week-shadow-name");
      var metaEl = shadow.querySelector(".sec-week-shadow-meta");
      var actionsEl = shadow.querySelector(".sec-week-shadow-actions");
      var closeBtn = shadow.querySelector(".sec-week-shadow-close");
      function close(){ shadow.hidden = true; }
      if (closeBtn) closeBtn.addEventListener("click", close);
      shadow.addEventListener("click", function(e){ if (e.target === shadow) close(); });
      root.addEventListener("click", function(e){
        var btn = e.target && e.target.closest ? e.target.closest(".sec-week-hour.is-booked") : null;
        if (!btn) return;
        e.preventDefault();
        var lines = [];
        if (btn.getAttribute("data-status")) lines.push("وضعیت نوبت: " + btn.getAttribute("data-status"));
        if (btn.getAttribute("data-pay")) lines.push("پرداخت: " + btn.getAttribute("data-pay"));
        if (btn.getAttribute("data-mode")) lines.push("نوع جلسه: " + btn.getAttribute("data-mode"));
        if (btn.getAttribute("data-amount")) lines.push(btn.getAttribute("data-amount"));
        if (btn.getAttribute("data-phone")) lines.push("تلفن: " + btn.getAttribute("data-phone"));
        if (nameEl) nameEl.textContent = btn.getAttribute("data-patient") || "مراجعه‌کننده";
        if (metaEl) metaEl.textContent = lines.join(" · ");
        if (actionsEl) {
          actionsEl.innerHTML = '<button type="button" class="btn btn-outline btn-sm sec-week-shadow-dismiss">بستن</button>';
          var dismiss = actionsEl.querySelector(".sec-week-shadow-dismiss");
          if (dismiss) dismiss.addEventListener("click", close);
        }
        shadow.hidden = false;
      });
    })();
    </script>
    <?php
    return (string) ob_get_clean();
}
