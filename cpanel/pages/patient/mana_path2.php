<?php
declare(strict_types=1);

$user = require_login(['PATIENT']);
require_once __DIR__ . '/../../includes/patient_panel.php';
require_once __DIR__ . '/../../includes/mana_path.php';
require_once __DIR__ . '/../../includes/mana_path_ui.php';

mana_path_require_user($user);
ensure_mana_path_schema($pdo);

$patientId = (string) $user['id'];
$profile = mana_path_load_profile($pdo, $patientId);
$moods = mana_path_moods();
$xp = (int) ($profile['energy_xp'] ?? 0);
$level = mana_path_level($xp);
$streak = (int) ($profile['gentle_streak'] ?? 0);
$moodNow = ((string) ($profile['mood_date'] ?? '') === date('Y-m-d')) ? (int) ($profile['mood_today'] ?? 0) : 0;
$missions = mana_path_daily_missions();
$doneMissions = mana_path_today_mission_ids($pdo, $patientId);
$doneN = 0;
foreach ($missions as $m) {
    if (in_array($m['id'], $doneMissions, true)) {
        $doneN++;
    }
}

$stages = mana_path2_journey();
$doneStages = mana_path2_completed_stage_ids($pdo, $patientId);
$stageTotal = count($stages);
$doneStageN = count($doneStages);
$advancedToday = mana_path2_advanced_today($pdo, $patientId);
$stageNow = min($stageTotal, $doneStageN + ($advancedToday || $doneStageN >= $stageTotal ? 0 : 1));
if ($stageNow < 1) {
    $stageNow = 1;
}
$progressPct = (int) round(100 * $doneStageN / max(1, $stageTotal));

$concerns = $profile['concerns'] ?? [];
$goalLabel = 'مدیریت اضطراب';
$allConcerns = mana_path_concerns();
if (isset($concerns[0], $allConcerns[$concerns[0]])) {
    $goalLabel = 'کاهش ' . $allConcerns[$concerns[0]]['label'];
}

$nextAp = null;
try {
    $apStmt = $pdo->prepare("
      SELECT a.starts_at, u.name AS doctor_name
      FROM appointments a
      JOIN doctor_profiles dp ON dp.id = a.doctor_id
      JOIN users u ON u.id = dp.user_id
      WHERE a.patient_id = ? AND a.starts_at >= NOW() AND a.status IN ('confirmed','pending','paid')
      ORDER BY a.starts_at ASC
      LIMIT 1
    ");
    $apStmt->execute([$patientId]);
    $nextAp = $apStmt->fetch() ?: null;
} catch (Throwable $ignored) {
}

$post = url('/dashboard/path2');
$journalUrl = url('/dashboard/journal');
$doctorsUrl = url('/doctors');
$actNeed = count($missions);
$actHave = min($actNeed, $doneN);
$nextWhen = '';
if ($nextAp && function_exists('format_fa_datetime')) {
    $nextWhen = format_fa_datetime((string) $nextAp['starts_at']);
} elseif ($nextAp) {
    $nextWhen = (string) $nextAp['starts_at'];
}
$todayLabel = to_fa_digits(date('j'));
if (function_exists('gregorian_to_jalali') && function_exists('jalali_month_names')) {
    $tj = gregorian_to_jalali((int) date('Y'), (int) date('n'), (int) date('j'));
    $todayLabel = to_fa_digits((string) $tj[2]) . ' ' . (jalali_month_names()[$tj[1]] ?? '') . ' ' . to_fa_digits((string) $tj[0]);
}

$GLOBALS['pageRobots'] = 'noindex,nofollow';
$GLOBALS['pageTitle'] = 'اتاق ذهن ۲';
$GLOBALS['pageHead'] = '<link rel="stylesheet" href="' . e(url('/assets/css/mana-path2.css')) . '?v=20260924a">';
$GLOBALS['pageBodyClass'] = trim((string) ($GLOBALS['pageBodyClass'] ?? '') . ' mp2-page');

ob_start();
?>
<div class="mp2">
  <p class="mp2-kicker">◎ ماموریت امروز</p>
  <h1>اتاق ذهن ۲</h1>
  <p class="mp2-lead">سفر ۷ مرحله‌ای است و هر مرحله برای <strong>یک روز</strong> است. کارهای امروز را همین‌جا بزن؛ صفحه عوض نمی‌شود.</p>
  <p class="mp2-model">امروز <?= e($todayLabel) ?> — هدف، سفر روزانه، XP و streak برای ادامه دادن است. رقابت با دیگران اینجا نیست.</p>

  <div class="mp2-grid">
    <section class="mp2-card" aria-label="نقشه سفر">
      <p class="mp2-start">شروع ⌂</p>
      <div class="mp2-vwrap">
        <div class="mp2-vline" aria-hidden="true">
          <?php for ($i = 0; $i < 14; $i++): ?><span>▾</span><?php endfor; ?>
        </div>
        <ol class="mp2-vlist">
          <?php foreach ($stages as $i => $st): ?>
            <?php
              $n = $i + 1;
              $cls = $n <= $doneStageN ? 'is-done' : ($n === $stageNow ? 'is-now' : '');
            ?>
            <li class="<?= e($cls) ?>">
              <strong>روز <?= e(to_fa_digits((string) $n)) ?> — <?= e($st['label']) ?></strong>
              <span class="mp2-dot"><?= $n <= $doneStageN ? '✓' : e($st['icon']) ?></span>
            </li>
          <?php endforeach; ?>
        </ol>
      </div>
    </section>

    <section class="mp2-card" id="journey">
      <div class="mp2-hhead">
        <h2>سفر من 🌿</h2>
        <small>مرحله <?= e(to_fa_digits((string) min($stageTotal, max(1, $doneStageN + ($advancedToday ? 0 : 1))))) ?> از <?= e(to_fa_digits((string) $stageTotal)) ?> · روزانه</small>
      </div>
      <div class="mp2-dots" aria-hidden="true">
        <?php foreach ($stages as $i => $step): ?>
          <?php
            $done = $i < $doneStageN;
            $now = $i === $doneStageN && !$advancedToday;
          ?>
          <?php if ($i > 0): ?><b class="<?= $done || $now ? 'is-on' : '' ?>"></b><?php endif; ?>
          <i class="<?= $done ? 'is-done' : ($now ? 'is-now' : '') ?>" title="<?= e((string) ($step['label'] ?? '')) ?>"></i>
        <?php endforeach; ?>
      </div>
      <p class="mp2-goal">هدف فعلی من:<strong><?= e($goalLabel) ?></strong></p>
      <h3>کارهای امروز</h3>
      <ul class="mp2-checks">
        <?php foreach ($missions as $m): ?>
          <?php $ok = in_array($m['id'], $doneMissions, true); ?>
          <li>
            <?php if ($ok): ?>
              <span class="is-done"><span class="mp2-box"></span><?= e((string) $m['title']) ?></span>
            <?php else: ?>
              <form method="post" action="<?= e($post) ?>">
                <?= csrf_field() ?>
                <input type="hidden" name="do" value="mission">
                <input type="hidden" name="mission_id" value="<?= e((string) $m['id']) ?>">
                <input type="hidden" name="back" value="/dashboard/path2">
                <button type="submit">
                  <span class="mp2-box"></span>
                  <?= e((string) $m['title']) ?>
                  <em>انجام شد</em>
                </button>
              </form>
            <?php endif; ?>
          </li>
        <?php endforeach; ?>
      </ul>
      <?php if ($advancedToday): ?>
        <p class="mp2-note">مرحله امروز در سفر ثبت شد. فردا مرحله بعد باز می‌شود.</p>
      <?php elseif ($doneStageN >= $stageTotal): ?>
        <p class="mp2-note">هر ۷ مرحله را تمام کرده‌ای. کارهای روزانه را می‌توانی برای XP ادامه بدهی.</p>
      <?php else: ?>
        <p class="mp2-note">با زدن هر کار، همان‌جا ثبت می‌شود. وقتی هر سه تمام شود، یک مرحله از سفر جلو می‌روی.</p>
      <?php endif; ?>
      <div class="mp2-foot">
        <span>⭐ <?= e(to_fa_digits((string) $xp)) ?> XP</span>
        <span>🔥 <?= e(to_fa_digits((string) $streak)) ?> روز متوالی</span>
      </div>
    </section>
  </div>

  <div class="mp2-grid" style="margin-top:.85rem">
    <section class="mp2-card" id="status">
      <h2>نگاه مسیر</h2>
      <dl class="mp2-status">
        <div>
          <dt>🎯 هدف درمانی</dt>
          <dd><?= e($goalLabel) ?></dd>
        </div>
        <div>
          <dt>📈 پیشرفت سفر</dt>
          <dd>
            <?= e(to_fa_digits((string) $progressPct)) ?>٪
            <p class="mp2-bar"><i style="width:<?= (int) $progressPct ?>%"></i></p>
          </dd>
        </div>
        <div>
          <dt>😊 Mood امروز</dt>
          <dd><?= e($moodNow && isset($moods[$moodNow]) ? $moods[$moodNow]['label'] : 'هنوز ثبت نشده') ?></dd>
        </div>
        <div>
          <dt>✅ کارهای امروز</dt>
          <dd><?= e(to_fa_digits((string) $actHave)) ?> / <?= e(to_fa_digits((string) $actNeed)) ?></dd>
        </div>
        <div>
          <dt>🔥 Streak</dt>
          <dd><?= e(to_fa_digits((string) $streak)) ?> روز</dd>
        </div>
        <div>
          <dt>📅 جلسه بعد</dt>
          <dd>
            <?php if ($nextAp): ?>
              <?= e((string) ($nextAp['doctor_name'] ?? 'درمانگر')) ?><br>
              <small><?= e($nextWhen) ?></small>
            <?php else: ?>
              هنوز رزرو نشده
            <?php endif; ?>
          </dd>
        </div>
        <?php if ($moodNow > 0 && $moodNow <= 2): ?>
          <p class="mp2-alert">⚠️ افت حال امروز ثبت شد — اگر خواستی با درمانگر در میان بگذار؛ مسیر همین‌جا می‌ماند.</p>
        <?php endif; ?>
      </dl>
    </section>

    <section class="mp2-card">
      <h2>ابزارهای مسیر</h2>
      <p class="mp2-note">چک‌این خلق و کارهای روزانه همین صفحه است. ژورنال و درمانگر جدا می‌مانند.</p>
      <form method="post" action="<?= e($post) ?>" class="mp2-faces">
        <?= csrf_field() ?>
        <input type="hidden" name="do" value="mood">
        <input type="hidden" name="back" value="/dashboard/path2">
        <?php foreach ($moods as $n => $m): ?>
          <button name="mood" value="<?= (int) $n ?>" type="submit" class="<?= $moodNow === (int) $n ? 'is-on' : '' ?>">
            <?= e($m['emoji'] . ' ' . $m['label']) ?>
          </button>
        <?php endforeach; ?>
      </form>
      <div class="mp2-tools">
        <a href="#journey"><strong>🧠 کارهای امروز</strong>تمرین روزانه CBT</a>
        <a href="<?= e($journalUrl) ?>"><strong>📓 Journal</strong>یادداشت روزانه</a>
        <a href="#journey"><strong>🗺️ Journey</strong>۷ مرحله روزانه</a>
        <a href="#status"><strong>⭐ XP / Achievement</strong>سطح <?= e(to_fa_digits((string) $level)) ?></a>
        <a href="<?= e($doctorsUrl) ?>"><strong>👩‍⚕️ درمانگر</strong>رزرو یا پیام جلسه</a>
      </div>
    </section>
  </div>
  <p class="mp2-disclaimer">اتاق ذهن ۲ غربالگری و تمرین همراه است، نه تشخیص و نه جایگزین درمان. نسخه آزمایشی فقط برای حساب تو.</p>
</div>
<?php
$inner = (string) ob_get_clean();
render_patient_page('اتاق ذهن ۲', $inner);
