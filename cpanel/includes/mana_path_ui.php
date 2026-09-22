<?php
declare(strict_types=1);

function mana_path_css_href(): string
{
    return url('/assets/css/mana-path.css') . '?v=20260923c';
}

function mana_path_future_film_html(): string
{
    ob_start();
    ?>
    <section class="mpf" aria-label="فیلم کوتاه آیندهٔ اتاق ذهن">
      <div class="mpf-screen" data-mpf-film>
        <svg class="mpf-svg" viewBox="0 0 360 260" role="img" aria-hidden="true">
          <defs>
            <linearGradient id="mpfSky" x1="0" y1="0" x2="0" y2="1">
              <stop class="mpf-sky-a" offset="0%" stop-color="#7a9aa0"/>
              <stop class="mpf-sky-b" offset="100%" stop-color="#e8d7c4"/>
            </linearGradient>
            <filter id="mpfGlow" x="-40%" y="-40%" width="180%" height="180%">
              <feGaussianBlur stdDeviation="6" result="b"/>
              <feMerge><feMergeNode in="b"/><feMergeNode in="SourceGraphic"/></feMerge>
            </filter>
          </defs>
          <rect class="mpf-sky" width="360" height="260" fill="url(#mpfSky)"/>
          <circle class="mpf-sun" cx="92" cy="74" r="14" fill="#fff3b0" filter="url(#mpfGlow)"/>
          <rect x="18" y="28" width="324" height="208" rx="22" fill="#fffdf8" opacity=".94"/>
          <rect x="38" y="48" width="92" height="72" rx="10" fill="#d7e8df"/>
          <rect class="mpf-window" x="46" y="56" width="76" height="56" rx="6" fill="#8ec8e6"/>
          <g class="mpf-bed">
            <rect x="210" y="168" width="110" height="36" rx="10" fill="#e8d5c4"/>
            <rect class="mpf-pillow" x="218" y="176" width="94" height="16" rx="6" fill="#f6eee6"/>
            <rect class="mpf-blanket" x="216" y="158" width="98" height="12" rx="6" fill="#c9e4d6"/>
          </g>
          <g class="mpf-desk">
            <rect x="40" y="168" width="78" height="36" rx="6" fill="#cbb79a"/>
            <rect class="mpf-book" x="52" y="148" width="22" height="20" rx="3" fill="#f3f0ea"/>
            <circle class="mpf-cup" cx="92" cy="156" r="7" fill="#d98b6a"/>
          </g>
          <g class="mpf-plant">
            <ellipse cx="168" cy="202" rx="16" ry="8" fill="#b08968"/>
            <line class="mpf-stem" x1="168" y1="196" x2="168" y2="168" stroke="#6a9a7c" stroke-width="4"/>
            <ellipse class="mpf-leaf-l" cx="152" cy="176" rx="16" ry="8" fill="#5f9a62"/>
            <ellipse class="mpf-leaf-r" cx="184" cy="178" rx="14" ry="7" fill="#7bb56e"/>
            <circle class="mpf-flower" cx="168" cy="158" r="8" fill="#e08a6a"/>
          </g>
          <g class="mpf-buddy">
            <circle cx="258" cy="126" r="16" fill="#f3d7c4"/>
            <rect class="mpf-body" x="244" y="142" width="28" height="34" rx="10" fill="#c4ddd3"/>
            <path class="mpf-eyes" d="M250 124 h4 M262 124 h4" stroke="#3a4a44" stroke-width="2" stroke-linecap="round"/>
            <path class="mpf-mouth" d="M254 132 q4 5 8 0" fill="none" stroke="#3a4a44" stroke-width="1.8" stroke-linecap="round"/>
            <g class="mpf-spark">
              <circle cx="278" cy="112" r="2.2" fill="#e0a05c"/>
              <circle cx="286" cy="124" r="1.6" fill="#7eb39c"/>
              <circle cx="240" cy="108" r="1.8" fill="#e08a6a"/>
            </g>
          </g>
          <rect x="18" y="228" width="324" height="8" rx="4" fill="#d5c4b0"/>
          <text class="mpf-title" x="180" y="22" text-anchor="middle" font-size="11" fill="#1b5e4b">آیندهٔ اتاق ذهن</text>
        </svg>
        <p class="mpf-line" data-mpf-line>اول همه چیز ساده است.</p>
      </div>
      <div class="mpf-bar">
        <button type="button" class="btn btn-outline btn-sm" data-mpf-replay>پخش دوباره</button>
        <span class="muted">با قدم‌های کوچک، نور، گیاه و حال آدمک عوض می‌شود — بدون تنبیه.</span>
      </div>
    </section>
    <?php
    return (string) ob_get_clean();
}

function mana_path_room_html(array $profile, string $state): string
{
    $w = $profile['world'] ?? mana_path_world_defaults();
    $light = (int) ($w['light'] ?? 18);
    $plant = (int) ($w['plant'] ?? 8);
    $bed = (int) ($w['bed'] ?? 12);
    $window = (int) ($w['window'] ?? 14);
    $desk = (int) ($w['desk'] ?? 10);
    $outfit = (int) ($w['outfit'] ?? 0);
    $rest = (int) ($profile['rest_days'] ?? 0);
    $lines = mana_path_companion_lines($state, $rest);
    $line = $lines[array_rand($lines)];

    $sky = 40 + (int) round($light * 0.45);
    $glow = max(0.15, min(0.85, 0.2 + $light / 140));
    $plantH = 28 + (int) round($plant * 0.55);
    $bedFluff = 8 + (int) round($bed * 0.12);
    $winA = max(0.25, min(1, 0.3 + $window / 120));
    $deskStuff = $desk >= 40;
    $leaf = $plant >= 35;
    $flower = $plant >= 60;
    $face = match ($state) {
        'crisis' => '·  ·',
        'waiting' => '-  -',
        'tired' => '·  ·',
        'bright' => '^  ^',
        default => '·  ·',
    };
    $mouth = match ($state) {
        'bright' => '‿',
        'waiting' => '·',
        'tired' => '‿',
        'crisis' => '.',
        default => '‿',
    };
    $bodyColor = ['#c4ddd3', '#9ec9b8', '#7eb39c', '#5f9a86'][min(3, $outfit)];

    ob_start();
    ?>
    <section class="mpath-room" data-state="<?= e($state) ?>" aria-label="اتاق ذهن">
      <svg class="mpath-room-svg" viewBox="0 0 360 260" role="img" aria-hidden="true">
        <defs>
          <linearGradient id="mpSky" x1="0" y1="0" x2="0" y2="1">
            <stop offset="0%" stop-color="hsl(160 28% <?= (int) $sky ?>%)"/>
            <stop offset="100%" stop-color="hsl(40 30% <?= (int) (72 + $light * 0.12) ?>%)"/>
          </linearGradient>
        </defs>
        <rect width="360" height="260" fill="url(#mpSky)"/>
        <rect x="18" y="28" width="324" height="208" rx="22" fill="var(--card, #fff)" opacity=".92"/>
        <rect x="38" y="48" width="92" height="72" rx="10" fill="#d7e8df"/>
        <rect x="46" y="56" width="76" height="56" rx="6" fill="#8ec8e6" opacity="<?= e((string) $winA) ?>"/>
        <circle cx="92" cy="74" r="<?= 8 + (int) round($window * 0.08) ?>" fill="#fff6c8" opacity="<?= e((string) $glow) ?>"/>
        <rect x="210" y="<?= 168 - $bedFluff ?>" width="110" height="<?= 36 + $bedFluff ?>" rx="10" fill="#e8d5c4"/>
        <rect x="218" y="<?= 176 - $bedFluff ?>" width="94" height="16" rx="6" fill="#f6eee6"/>
        <rect x="40" y="168" width="<?= 70 + (int) round($desk * 0.25) ?>" height="36" rx="6" fill="#cbb79a"/>
        <?php if ($deskStuff): ?>
          <rect x="52" y="150" width="22" height="18" rx="3" fill="#f3f0ea"/>
          <circle cx="92" cy="158" r="7" fill="#d98b6a"/>
        <?php endif; ?>
        <line x1="168" y1="196" x2="168" y2="<?= 196 - $plantH ?>" stroke="#6a9a7c" stroke-width="4"/>
        <ellipse cx="168" cy="202" rx="16" ry="8" fill="#b08968"/>
        <?php if ($leaf): ?>
          <ellipse cx="152" cy="<?= 196 - $plantH + 16 ?>" rx="16" ry="8" fill="#5f9a62"/>
          <ellipse cx="184" cy="<?= 196 - $plantH + 18 ?>" rx="14" ry="7" fill="#7bb56e"/>
        <?php endif; ?>
        <?php if ($flower): ?>
          <circle cx="168" cy="<?= 196 - $plantH ?>" r="7" fill="#e08a6a"/>
        <?php endif; ?>
        <g transform="translate(230 118)">
          <circle cx="28" cy="8" r="16" fill="#f3d7c4"/>
          <rect x="14" y="24" width="28" height="34" rx="10" fill="<?= e($bodyColor) ?>"/>
          <text x="20" y="12" font-size="9" fill="#3a4a44"><?= e($face) ?></text>
          <text x="24" y="18" font-size="10" fill="#3a4a44"><?= e($mouth) ?></text>
        </g>
        <rect x="18" y="228" width="324" height="8" rx="4" fill="#d5c4b0"/>
      </svg>
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
