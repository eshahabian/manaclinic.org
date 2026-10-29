<?php
declare(strict_types=1);

/** Return only time ranges, never patient, staff or payment information. */
function booking_room_segments(array $rows, int $room, string $date): array
{
    $zone = new DateTimeZone('Asia/Tehran');
    $day = DateTimeImmutable::createFromFormat('!Y-m-d', $date, $zone);
    if (!$day || $day->format('Y-m-d') !== $date || !in_array($room, [1, 2, 3], true)) return [];
    $from = $day->getTimestamp();
    $to = $day->modify('+1 day')->getTimestamp();
    $busy = [];
    foreach ($rows as $row) {
        if ((int) ($row['room_no'] ?? 0) !== $room) continue;
        if (($row['kind'] ?? '') === 'APPOINTMENT' && ($row['appt_status'] ?? '') === 'CANCELLED') continue;
        if (($row['kind'] ?? '') === 'WORKSHOP' && ($row['workshop_status'] ?? '') === 'CANCELLED') continue;
        try {
            $start = max($from, (new DateTimeImmutable((string) $row['starts_at'], $zone))->getTimestamp());
            $end = min($to, (new DateTimeImmutable((string) $row['ends_at'], $zone))->getTimestamp());
        } catch (Throwable $e) {
            continue;
        }
        if ($end > $start) $busy[] = [$start, $end];
    }
    usort($busy, static fn(array $a, array $b): int => $a[0] <=> $b[0]);
    $merged = [];
    foreach ($busy as [$start, $end]) {
        $last = count($merged) - 1;
        if ($last >= 0 && $start <= $merged[$last][1]) $merged[$last][1] = max($end, $merged[$last][1]);
        else $merged[] = [$start, $end];
    }
    $out = [];
    $clock = static fn(int $stamp): string => (new DateTimeImmutable('@' . $stamp))->setTimezone($zone)->format('H:i');
    $cursor = $from;
    foreach ($merged as [$start, $end]) {
        if ($start > $cursor) $out[] = ['start' => $clock($cursor), 'end' => $clock($start), 'busy' => false];
        $out[] = ['start' => $clock($start), 'end' => $end === $to ? '24:00' : $clock($end), 'busy' => true];
        $cursor = $end;
    }
    if ($cursor < $to) $out[] = ['start' => $clock($cursor), 'end' => '24:00', 'busy' => false];
    return $out;
}
