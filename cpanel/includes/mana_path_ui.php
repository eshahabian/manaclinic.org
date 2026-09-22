<?php
declare(strict_types=1);

function mana_path_css_href(): string
{
    return url('/assets/css/mana-path.css') . '?v=20260923n';
}

function mana_path_asset(string $file): string
{
    return url('/assets/img/mind-room/' . $file) . '?v=20260923j';
}

function mana_path_gender_assets(string $gender): array
{
    $female = $gender === 'female';
    return [
        'gender' => $female ? 'female' : 'male',
        'room' => mana_path_asset('room-bg.png'),
        'avatar' => mana_path_asset($female ? 'female-avatar.png' : 'male-avatar.png'),
    ];
}

function mana_path_pick_line(array $profile, string $state): string
{
    $rest = (int) ($profile['rest_days'] ?? 0);
    $lines = mana_path_companion_lines($state, $rest);
    return $lines[array_rand($lines)];
}

function mana_path_room_html(array $profile, string $state): string
{
    $gender = mana_path_normalize_gender((string) ($profile['companion_gender'] ?? 'male')) ?: 'male';
    $assets = mana_path_gender_assets($gender);
    $mood = (int) ($profile['mood_today'] ?? 0);
    ob_start();
    ?>
    <section class="mr-stage" data-state="<?= e($state) ?>" data-gender="<?= e($assets['gender']) ?>" data-mood="<?= (int) $mood ?>" aria-label="اتاق ذهن">
      <img class="mr-bg" src="<?= e($assets['room']) ?>" alt="">
      <img class="mr-buddy" src="<?= e($assets['avatar']) ?>" alt="">
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

function mana_path_gender_picker_html(string $post, string $current = ''): string
{
    $male = mana_path_gender_assets('male');
    $female = mana_path_gender_assets('female');
    ob_start();
    ?>
    <fieldset class="mr-gender">
      <legend>همراه اتاق ذهن کی باشد؟</legend>
      <p class="muted">این انتخاب اتاق و آدمک را عوض می‌کند. هر وقت بخواهی از تنظیمات می‌توانی عوضش کنی.</p>
      <div class="mr-gender-grid">
        <label class="mr-gender-card<?= $current === 'male' ? ' is-on' : '' ?>">
          <input type="radio" name="gender" value="male"<?= $current === 'male' ? ' checked' : '' ?> required>
          <img src="<?= e($male['avatar']) ?>" alt="شخصیت مرد">
          <strong>مرد</strong>
        </label>
        <label class="mr-gender-card<?= $current === 'female' ? ' is-on' : '' ?>">
          <input type="radio" name="gender" value="female"<?= $current === 'female' ? ' checked' : '' ?> required>
          <img src="<?= e($female['avatar']) ?>" alt="شخصیت زن">
          <strong>زن</strong>
        </label>
      </div>
    </fieldset>
    <?php
    return (string) ob_get_clean();
}
