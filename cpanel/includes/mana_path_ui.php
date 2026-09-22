<?php
declare(strict_types=1);

function mana_path_css_href(): string
{
    return url('/assets/css/mana-path.css') . '?v=20260923d';
}

function mana_path_room_photo_src(): string
{
    return url('/assets/img/mpath-room.jpg');
}

function mana_path_pick_line(array $profile, string $state): string
{
    $rest = (int) ($profile['rest_days'] ?? 0);
    $lines = mana_path_companion_lines($state, $rest);
    return $lines[array_rand($lines)];
}

function mana_path_room_html(array $profile, string $state): string
{
    $line = mana_path_pick_line($profile, $state);
    ob_start();
    ?>
    <section class="mpath-room" data-state="<?= e($state) ?>" aria-label="اتاق ذهن">
      <img class="mpath-room-photo" src="<?= e(mana_path_room_photo_src()) ?>" alt="اتاق ذهن مسیر مانا">
      <p class="mpath-bubble"><?= e($line) ?></p>
    </section>
    <?php
    return (string) ob_get_clean();
}

function mana_path_step_status(array $steps, array $completed, int $current): array
{
    $out = [];
    foreach ($steps as $i => $step) {
        $id = (string) $step['id'];
        if (in_array($id, $completed, true)) {
            $st = 'done';
        } elseif ($i <= $current) {
            $st = 'open';
        } else {
            $st = 'locked';
        }
        $out[] = $step + ['status' => $st, 'index' => $i];
    }
    return $out;
}

function mana_path_kind_icon(string $kind): string
{
    return match ($kind) {
        'screen' => '◎',
        'lesson' => '✦',
        'practice' => '●',
        'real' => '○',
        'checkin' => '◐',
        'chest' => '🎁',
        'clinic' => '👩‍⚕️',
        default => '•',
    };
}
