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
        $pdo->exec('ALTER TABLE availabilities ADD COLUMN available_hours VARCHAR(64) NULL AFTER slot_minutes');
    }

    $defaultHours = appointment_hours_encode(appointment_booking_hours());
    $pdo->prepare("
      UPDATE availabilities
      SET start_time = '10:00',
          end_time = '18:00',
          slot_minutes = 60,
          available_hours = ?
      WHERE available_hours IS NULL OR TRIM(available_hours) = ''
    ")->execute([$defaultHours]);

    $ready = true;
}

/** ساعت‌های مجاز رزرو نوبت */
function appointment_booking_hours(): array
{
    return [10, 11, 12, 13, 14, 15, 16, 17];
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
    $allowed = appointment_booking_hours();
    $filtered = array_values(array_unique(array_map('intval', $hours)));
    sort($filtered);
    $filtered = array_values(array_filter($filtered, static fn (int $h): bool => in_array($h, $allowed, true)));

    return implode(',', $filtered);
}

function appointment_hours_decode(?string $raw): array
{
    if ($raw === null || trim($raw) === '') {
        return [];
    }
    $allowed = appointment_booking_hours();
    $hours = array_map('intval', explode(',', $raw));

    return array_values(array_filter($hours, static fn (int $h): bool => in_array($h, $allowed, true)));
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

function appointment_normalize_posted_hours(mixed $posted): array
{
    if (!is_array($posted)) {
        return [];
    }

    return appointment_hours_decode(appointment_hours_encode($posted));
}
