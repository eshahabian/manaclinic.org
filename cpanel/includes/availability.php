<?php
declare(strict_types=1);

function ensure_availability_schema(PDO $pdo): void
{
    static $ready = false;
    if ($ready) {
        return;
    }
    $col = $pdo->query("SHOW COLUMNS FROM availabilities LIKE 'available_hours'")->fetch();
    if (!$col) {
        $pdo->exec('ALTER TABLE availabilities ADD COLUMN available_hours VARCHAR(128) NULL AFTER slot_minutes');
    } else {
        try {
            $pdo->exec('ALTER TABLE availabilities MODIFY available_hours VARCHAR(128) NULL');
        } catch (Throwable $ignored) {
        }
    }

    $span = appointment_hours_span();
    $defaultHours = appointment_hours_encode(appointment_booking_hours());
    $pdo->prepare("
      UPDATE availabilities
      SET start_time = ?,
          end_time = ?,
          slot_minutes = 60,
          available_hours = ?
      WHERE available_hours IS NULL
         OR TRIM(available_hours) = ''
    ")->execute([$span['start'], $span['end'], $defaultHours]);

    $pdo->prepare("
      UPDATE availabilities
      SET start_time = ?, end_time = ?
      WHERE start_time IN ('10:00', '12:00')
    ")->execute([$span['start'], $span['end']]);

    $ready = true;
}

/** ساعت‌های مجاز رزرو — ۶ صبح تا ۶ صبح فردا (۲۴ ساعت) */
function appointment_booking_hours(): array
{
    return [6, 7, 8, 9, 10, 11, 12, 13, 14, 15, 16, 17, 18, 19, 20, 21, 22, 23, 0, 1, 2, 3, 4, 5];
}

function appointment_hour_is_next_day(int $hour): bool
{
    return $hour < 6;
}

function appointment_slot_starts_at(string $availabilityDate, int|string $hourOrTime): string
{
    $hour = is_int($hourOrTime)
        ? $hourOrTime
        : (int) explode(':', (string) $hourOrTime)[0];
    $date = $availabilityDate;
    if (appointment_hour_is_next_day($hour)) {
        $date = date('Y-m-d', strtotime($availabilityDate . ' +1 day') ?: time());
    }
    return $date . ' ' . appointment_hour_to_time($hour) . ':00';
}

function appointment_hour_chip_label(int $hour): string
{
    $n = to_fa_digits((string) $hour);
    if ($hour === 0) {
        return '۱۲ شب';
    }
    if ($hour === 12) {
        return $n . ' ظهر';
    }
    if ($hour < 12) {
        return $n . ' صبح';
    }
    if ($hour < 17) {
        return $n . ' بعدازظهر';
    }
    if ($hour < 20) {
        return $n . ' عصر';
    }
    return $n . ' شب';
}

function appointment_short_jalali_date(string $ymd): string
{
    $parts = jalali_day_parts($ymd . ' 12:00:00');
    return $parts ? (string) $parts['label'] : to_jalali_label($ymd);
}

function appointment_hour_date_for(string $availabilityDate, int $hour): string
{
    $starts = appointment_slot_starts_at($availabilityDate, $hour);
    return appointment_short_jalali_date(substr($starts, 0, 10));
}

function appointment_hours_span(): array
{
    return [
        'start' => '06:00',
        'end' => '23:59',
    ];
}

function appointment_slot_minutes(): int
{
    return 60;
}

function appointment_hour_to_time(int $hour): string
{
    return sprintf('%02d:00', $hour);
}

function appointment_time_to_hour_label(string $time): string
{
    if (preg_match('/^(\d{1,2}):\d{2}$/', $time, $m)) {
        return (string) (int) $m[1];
    }
    return $time;
}

function appointment_hours_encode(array $hours): string
{
    $picked = array_map('intval', $hours);
    $filtered = [];
    foreach (appointment_booking_hours() as $h) {
        if (in_array((int) $h, $picked, true)) {
            $filtered[] = (int) $h;
        }
    }
    return implode(',', $filtered);
}

function appointment_hours_decode(?string $raw): array
{
    if ($raw === null || trim($raw) === '') {
        return [];
    }
    $picked = array_map('intval', explode(',', $raw));
    $out = [];
    foreach (appointment_booking_hours() as $h) {
        if (in_array((int) $h, $picked, true)) {
            $out[] = (int) $h;
        }
    }
    return $out;
}

function appointment_availability_hours(array $availability): array
{
    $hours = appointment_hours_decode($availability['available_hours'] ?? null);
    if ($hours) {
        return $hours;
    }

    return appointment_booking_hours();
}

function appointment_slots_from_availability(array $availability): array
{
    $slots = [];
    foreach (appointment_availability_hours($availability) as $hour) {
        $slots[] = appointment_hour_to_time($hour);
    }

    return $slots;
}

function appointment_hours_display_fa(array $hours): string
{
    if (!$hours) {
        return '—';
    }

    return implode(' · ', array_map(
        static fn (int $h): string => appointment_time_to_hour_label(appointment_hour_to_time($h)),
        $hours
    ));
}

/**
 * نوبت‌های اشغال‌کننده ساعت، با کلید starts_at.
 *
 * @return array<string, array<string, mixed>>
 */
function doctor_availability_booked_map(PDO $pdo, string $doctorId): array
{
    $stmt = $pdo->prepare("
      SELECT a.id, a.starts_at, a.status, u.id AS patient_id, u.name AS patient_name, u.phone
      FROM appointments a
      JOIN users u ON u.id = a.patient_id
      WHERE a.doctor_id = ?
        AND a.status IN ('PENDING_PAYMENT','CONFIRMED','COMPLETED')
    ");
    $stmt->execute([$doctorId]);
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
 * گروه‌بندی روزهای خالی دکتر بر اساس ماه شمسی.
 *
 * @param array<int, array<string, mixed>> $items
 * @return array{months: array<string, array<string, mixed>>, default_id: string}
 */
function doctor_availability_month_groups(array $items): array
{
    $current = jalali_current_month_meta();
    $months = [];
    foreach ($items as $item) {
        $date = substr((string) ($item['date'] ?? ''), 0, 10);
        $meta = jalali_month_meta_from_datetime($date . ' 12:00:00');
        if (!$meta) {
            continue;
        }
        $id = (string) $meta['id'];
        if (!isset($months[$id])) {
            $months[$id] = $meta + ['items' => []];
        }
        $months[$id]['items'][] = $item;
    }
    $jy = (int) ($current['year'] ?? 0);
    $from = (int) ($current['month'] ?? 1);
    for ($jm = $from; $jm <= 12; $jm++) {
        $meta = jalali_month_meta_from_parts($jy, $jm);
        if (!isset($months[$meta['id']])) {
            $months[$meta['id']] = $meta + ['items' => []];
        }
    }
    foreach ($months as $id => $bucket) {
        usort($months[$id]['items'], static fn(array $a, array $b): int => strcmp((string) ($a['date'] ?? ''), (string) ($b['date'] ?? '')));
    }
    uasort($months, static function (array $a, array $b): int {
        return ((int) $a['sort']) <=> ((int) $b['sort']);
    });

    $defaultId = (string) ($current['id'] ?? '');
    if ($defaultId === '' || !isset($months[$defaultId])) {
        $defaultId = (string) (array_key_first($months) ?? '');
    }

    return ['months' => $months, 'default_id' => $defaultId];
}

function doctor_availability_month_range_label(array $bucket): string
{
    $base = trim((string) ($bucket['tab_label'] ?? $bucket['short'] ?? 'ماه'));
    if ($base === '') {
        $base = 'ماه';
    }

    return str_starts_with($base, 'کل ') ? $base : ('کل ' . $base);
}

/** @return array{year: int, month: int, key: string, id: string}|null */
function jalali_month_key_parts(string $key): ?array
{
    if (!preg_match('/^(?:m-)?(\d{4})-(\d{2})$/', trim($key), $m)) {
        return null;
    }
    $jy = (int) $m[1];
    $jm = (int) $m[2];
    if ($jy < 1300 || $jm < 1 || $jm > 12) {
        return null;
    }

    return [
        'year' => $jy,
        'month' => $jm,
        'key' => sprintf('%04d-%02d', $jy, $jm),
        'id' => sprintf('m-%04d-%02d', $jy, $jm),
    ];
}

function doctor_availability_upsert(PDO $pdo, string $doctorId, string $date, array $hours): bool
{
    $date = substr($date, 0, 10);
    $hours = appointment_hours_decode(appointment_hours_encode($hours));
    if ($doctorId === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || $hours === []) {
        return false;
    }
    $span = appointment_hours_span();
    $encoded = appointment_hours_encode($hours);
    $minutes = appointment_slot_minutes();
    $exists = $pdo->prepare('SELECT id FROM availabilities WHERE doctor_id=? AND date=?');
    $exists->execute([$doctorId, $date]);
    $row = $exists->fetch();
    if ($row) {
        $pdo->prepare('
          UPDATE availabilities
          SET start_time=?, end_time=?, slot_minutes=?, available_hours=?
          WHERE id=?
        ')->execute([$span['start'], $span['end'], $minutes, $encoded, $row['id']]);
    } else {
        $pdo->prepare('
          INSERT INTO availabilities (id,doctor_id,date,start_time,end_time,slot_minutes,available_hours)
          VALUES (?,?,?,?,?,?,?)
        ')->execute([
            cuid(),
            $doctorId,
            $date,
            $span['start'],
            $span['end'],
            $minutes,
            $encoded,
        ]);
    }

    return true;
}

function doctor_availability_apply_month(PDO $pdo, string $doctorId, int $jy, int $jm, array $hours): int
{
    $hours = appointment_hours_decode(appointment_hours_encode($hours));
    if ($doctorId === '' || $hours === [] || $jy < 1300 || $jm < 1 || $jm > 12) {
        return 0;
    }
    $today = date('Y-m-d');
    $len = jalali_month_length($jy, $jm);
    $n = 0;
    $pdo->beginTransaction();
    try {
        for ($jd = 1; $jd <= $len; $jd++) {
            $date = jalali_ymd($jy, $jm, $jd);
            if ($date < $today) {
                continue;
            }
            if (doctor_availability_upsert($pdo, $doctorId, $date, $hours)) {
                $n++;
            }
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }

    return $n;
}

/**
 * @param array<int, array<string, mixed>> $items
 * @return array<string, array<string, mixed>>
 */
function doctor_availability_items_by_date(array $items): array
{
    $map = [];
    foreach ($items as $item) {
        if (!is_array($item)) {
            continue;
        }
        $key = substr((string) ($item['date'] ?? ''), 0, 10);
        if ($key !== '') {
            $map[$key] = $item;
        }
    }

    return $map;
}

/**
 * @param array<int, int> $bookingHours
 * @param array<string, array<string, mixed>> $bookedMap
 */
function doctor_availability_render_day_card(
    array $bookingHours,
    array $bookedMap,
    string $dayDate,
    ?array $item
): string {
    $savedHours = $item ? appointment_availability_hours($item) : [];
    $hasItem = is_array($item) && (string) ($item['id'] ?? '') !== '';

    ob_start();
    ?>
              <div class="panel avail-day-card">
                <div class="row-between">
                  <div>
                    <strong><?= e(to_jalali_label($dayDate)) ?></strong>
                    <div class="muted" style="font-size:.85rem;margin-top:.35rem">
                      <?= $hasItem ? 'روی ساعت بزنید تا وضعیت رزرو را ببینید.' : 'این روز هنوز اعلام نشده.' ?>
                    </div>
                  </div>
                  <?php if ($hasItem): ?>
                    <form method="post" action="<?= e(url('/doctor/availability')) ?>">
                      <input type="hidden" name="action" value="delete">
                      <input type="hidden" name="id" value="<?= e((string) $item['id']) ?>">
                      <button class="btn btn-danger btn-sm" type="submit">حذف</button>
                    </form>
                  <?php endif; ?>
                </div>
                <?php if ($hasItem): ?>
                <div class="hour-picker hour-picker-readonly" style="margin-top:.75rem">
                  <?php foreach ($bookingHours as $hour): ?>
                    <?php
                      $hour = (int) $hour;
                      $startsAt = appointment_slot_starts_at($dayDate, $hour);
                      $slotKey = substr(str_replace('T', ' ', $startsAt), 0, 16);
                      $booked = is_array($bookedMap[$slotKey] ?? null) ? $bookedMap[$slotKey] : null;
                      $isOpen = in_array($hour, $savedHours, true);
                      $state = !$isOpen ? 'off' : ($booked ? 'booked' : 'free');
                      $patientName = is_array($booked) ? trim((string) ($booked['patient_name'] ?? '')) : '';
                      $firstName = $patientName !== '' ? (preg_split('/\s+/u', $patientName)[0] ?? $patientName) : '';
                      $statusLabel = is_array($booked) ? appointment_row_status_label($booked) : '';
                      $patientHref = is_array($booked) ? url('/doctor/patients/' . (string) ($booked['patient_id'] ?? '')) : '';
                    ?>
                    <button type="button"
                      class="hour-chip is-<?= e($state) ?>"
                      data-avail-hour
                      data-state="<?= e($state) ?>"
                      data-label="<?= e(appointment_hour_chip_label($hour)) ?>"
                      data-date-label="<?= e(appointment_hour_date_for($dayDate, $hour)) ?>"
                      data-patient="<?= e($patientName) ?>"
                      data-phone="<?= e(is_array($booked) ? (string) ($booked['phone'] ?? '') : '') ?>"
                      data-status="<?= e($statusLabel) ?>"
                      data-href="<?= e($patientHref) ?>">
                      <span class="hour-chip-time"><?= e(appointment_hour_chip_label($hour)) ?></span>
                      <span class="hour-chip-date"><?= e(appointment_hour_date_for($dayDate, $hour)) ?></span>
                      <?php if ($state === 'booked' && $firstName !== ''): ?>
                        <span class="hour-chip-who"><?= e((string) $firstName) ?></span>
                      <?php elseif ($state === 'free'): ?>
                        <span class="hour-chip-who">خالی</span>
                      <?php endif; ?>
                    </button>
                  <?php endforeach; ?>
                </div>
                <div class="avail-hour-detail" hidden>
                  <strong class="avail-hour-detail-title"></strong>
                  <p class="avail-hour-detail-body muted" style="margin:.35rem 0 0;font-size:.9rem;line-height:1.7"></p>
                  <a class="btn btn-outline btn-sm avail-hour-detail-link" hidden href="#">پرونده مراجعه‌کننده</a>
                </div>
                <?php endif; ?>
              </div>
    <?php
    return (string) ob_get_clean();
}

function appointment_normalize_posted_hours(mixed $posted): array
{
    if (!is_array($posted)) {
        return [];
    }

    return appointment_hours_decode(appointment_hours_encode($posted));
}

/**
 * ساعت‌های خالی اعلام‌شده توسط درمانگر/منشی در یک بازه.
 *
 * @return array<int, array<string, mixed>>
 */
function patient_open_slots_between(PDO $pdo, string $fromYmd, string $toYmd): array
{
    ensure_availability_schema($pdo);
    $pdo->exec("
      UPDATE appointments SET status='CANCELLED'
      WHERE status='PENDING_PAYMENT' AND created_at < (NOW() - INTERVAL 20 MINUTE)
    ");

    $stmt = $pdo->prepare("
      SELECT av.*, u.name AS doctor_name, dp.specialty, dp.session_price
      FROM availabilities av
      JOIN doctor_profiles dp ON dp.id = av.doctor_id AND dp.is_active = 1 AND dp.is_approved = 1
      JOIN users u ON u.id = dp.user_id
      WHERE av.date >= ? AND av.date <= ?
      ORDER BY av.date ASC, u.name ASC
    ");
    $stmt->execute([$fromYmd, $toYmd]);
    $rows = $stmt->fetchAll();

    $takenStmt = $pdo->prepare("
      SELECT doctor_id, DATE(starts_at) AS d, DATE_FORMAT(starts_at, '%H:%i') AS t
      FROM appointments
      WHERE DATE(starts_at) BETWEEN ? AND ?
        AND status IN ('PENDING_PAYMENT','CONFIRMED','COMPLETED')
    ");
    $takenEnd = date('Y-m-d', strtotime($toYmd . ' +1 day') ?: time());
    $takenStmt->execute([$fromYmd, $takenEnd]);
    $taken = [];
    foreach ($takenStmt->fetchAll() as $row) {
        $taken[(string) $row['doctor_id'] . '|' . $row['d'] . '|' . $row['t']] = true;
    }

    $now = time();
    $out = [];
    foreach ($rows as $availability) {
        $doctorId = (string) ($availability['doctor_id'] ?? '');
        $date = (string) ($availability['date'] ?? '');
        foreach (appointment_availability_hours($availability) as $hour) {
            $startsAt = appointment_slot_starts_at($date, $hour);
            $slot = appointment_hour_to_time($hour);
            $slotDate = substr($startsAt, 0, 10);
            if (isset($taken[$doctorId . '|' . $slotDate . '|' . $slot])) {
                continue;
            }
            $ts = strtotime($startsAt);
            if (!$ts || $ts <= $now) {
                continue;
            }
            $out[] = [
                'doctor_id' => $doctorId,
                'doctor_name' => (string) ($availability['doctor_name'] ?? ''),
                'specialty' => (string) ($availability['specialty'] ?? ''),
                'price' => (int) ($availability['session_price'] ?? 0),
                'date' => $slotDate,
                'time' => $slot,
                'label' => appointment_hour_chip_label($hour),
                'date_label' => appointment_short_jalali_date($slotDate),
                'starts_at' => $startsAt,
            ];
        }
    }
    return $out;
}

/**
 * @param array<string, array<string, mixed>> $months
 * @param array<int, array<string, mixed>> $slots
 * @return array<string, array<string, mixed>>
 */
function attach_open_slots_to_month_groups(array $months, array $slots, string $preferredDoctorId = ''): array
{
    if ($preferredDoctorId !== '') {
        usort($slots, static function (array $a, array $b) use ($preferredDoctorId): int {
            $ap = (($a['doctor_id'] ?? '') === $preferredDoctorId) ? 0 : 1;
            $bp = (($b['doctor_id'] ?? '') === $preferredDoctorId) ? 0 : 1;
            if ($ap !== $bp) {
                return $ap <=> $bp;
            }
            return strcmp((string) ($a['starts_at'] ?? ''), (string) ($b['starts_at'] ?? ''));
        });
    }
    foreach ($slots as $slot) {
        $meta = jalali_month_meta_from_datetime((string) ($slot['starts_at'] ?? ''));
        if (!$meta) {
            continue;
        }
        $id = $meta['id'];
        if (!isset($months[$id])) {
            continue;
        }
        if (!isset($months[$id]['open_slots']) || !is_array($months[$id]['open_slots'])) {
            $months[$id]['open_slots'] = [];
        }
        $months[$id]['open_slots'][] = $slot;
    }
    return $months;
}

function patient_month_groups_with_open_slots(PDO $pdo, array $appointments, string $preferredDoctorId = ''): array
{
    $pack = group_appointments_by_jalali_month($appointments, true);
    $range = jalali_remaining_year_gregorian_range();
    $slots = patient_open_slots_between($pdo, $range['start'], $range['end']);
    $pack['months'] = attach_open_slots_to_month_groups($pack['months'], $slots, $preferredDoctorId);
    return $pack;
}

function attach_open_slots_to_ymd_pack(array $pack, array $slots, string $preferredDoctorId = ''): array
{
    if ($preferredDoctorId !== '') {
        usort($slots, static function (array $a, array $b) use ($preferredDoctorId): int {
            $ap = (($a['doctor_id'] ?? '') === $preferredDoctorId) ? 0 : 1;
            $bp = (($b['doctor_id'] ?? '') === $preferredDoctorId) ? 0 : 1;
            if ($ap !== $bp) {
                return $ap <=> $bp;
            }
            return strcmp((string) ($a['starts_at'] ?? ''), (string) ($b['starts_at'] ?? ''));
        });
    }
    $prefix = (string) ($pack['prefix'] ?? 'ymd');
    $today = date('Y-m-d');
    $years = is_array($pack['years'] ?? null) ? $pack['years'] : [];
    foreach ($slots as $slot) {
        if (!is_array($slot)) {
            continue;
        }
        $datetime = (string) ($slot['starts_at'] ?? '');
        $meta = jalali_month_meta_from_datetime($datetime);
        if (!$meta) {
            continue;
        }
        $yearId = $prefix . '-y-' . (int) $meta['year'];
        $monthId = $prefix . '-m-' . sprintf('%04d-%02d', (int) $meta['year'], (int) $meta['month']);
        if (!isset($years[$yearId]['months'][$monthId]) || !is_array($years[$yearId]['months'][$monthId])) {
            continue;
        }
        if (!isset($years[$yearId]['months'][$monthId]['open_slots']) || !is_array($years[$yearId]['months'][$monthId]['open_slots'])) {
            $years[$yearId]['months'][$monthId]['open_slots'] = [];
        }
        $years[$yearId]['months'][$monthId]['open_slots'][] = $slot;
        $day = ymd_new_day_bucket($prefix, $datetime, $today);
        $dayId = (string) $day['id'];
        if (!isset($years[$yearId]['months'][$monthId]['days'][$dayId])) {
            $years[$yearId]['months'][$monthId]['days'][$dayId] = $day;
        }
        if (!isset($years[$yearId]['months'][$monthId]['days'][$dayId]['open_slots']) || !is_array($years[$yearId]['months'][$monthId]['days'][$dayId]['open_slots'])) {
            $years[$yearId]['months'][$monthId]['days'][$dayId]['open_slots'] = [];
        }
        $years[$yearId]['months'][$monthId]['days'][$dayId]['open_slots'][] = $slot;
    }
    foreach ($years as $yearId => $year) {
        $months = is_array($year['months'] ?? null) ? $year['months'] : [];
        $yearCount = (int) ($year['count'] ?? 0);
        foreach ($months as $monthId => $month) {
            $jy = (int) ($month['year'] ?? ($year['year'] ?? 0));
            $jm = (int) ($month['month'] ?? 0);
            if ($jy > 0 && $jm > 0) {
                $month['days'] = ymd_pad_month_days($prefix, $jy, $jm, is_array($month['days'] ?? null) ? $month['days'] : [], $today);
            } else {
                $month['days'] = appointment_finalize_day_groups(is_array($month['days'] ?? null) ? $month['days'] : []);
            }
            $open = is_array($month['open_slots'] ?? null) ? $month['open_slots'] : [];
            $month['open_slots'] = $open;
            $month['count'] = count($month['items'] ?? []) + count($open);
            $months[$monthId] = $month;
            $yearCount += count($open);
        }
        $years[$yearId]['months'] = $months;
        $years[$yearId]['count'] = $yearCount;
    }
    $pack['years'] = $years;
    $pack['halves'] = $years;
    return $pack;
}

function patient_ymd_groups_with_open_slots(PDO $pdo, array $appointments, string $preferredDoctorId = ''): array
{
    $pack = group_appointments_by_jalali_ymd($appointments, 'pat', 'current', ['fill' => 'rest']);
    $range = jalali_remaining_year_gregorian_range();
    $slots = patient_open_slots_between($pdo, $range['start'], $range['end']);
    return attach_open_slots_to_ymd_pack($pack, $slots, $preferredDoctorId);
}

function doctor_availability_ymd_groups(array $items): array
{
    $mapped = [];
    foreach ($items as $item) {
        if (!is_array($item)) {
            continue;
        }
        $date = substr((string) ($item['date'] ?? ''), 0, 10);
        $item['starts_at'] = $date !== '' ? $date . ' 12:00:00' : '';
        $mapped[] = $item;
    }
    return group_appointments_by_jalali_ymd($mapped, 'avail', 'current', ['fill' => 'rest']);
}
