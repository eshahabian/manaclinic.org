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

$stages = mana_path2_week_status($pdo, $patientId, $streak);
$doneStageN = 0;
foreach ($stages as $st) {
    if (!empty($st['done'])) {
        $doneStageN++;
    }
}
$stageTotal = count($stages);
$todayDone = $doneN >= count($missions);
$progressPct = (int) round(100 * $doneStageN / max(1, $stageTotal));
$monthHist = mana_path2_month_history($pdo, $patientId);

$concerns = $profile['concerns'] ?? [];
$allConcerns = mana_path_concerns();
$goalBits = [];
foreach ($concerns as $cid) {
    $cid = (string) $cid;
    if (isset($allConcerns[$cid])) {
        $goalBits[] = $allConcerns[$cid]['label'];
    }
}
$goalLabel = $goalBits !== [] ? ('کار روی ' . implode('، ', $goalBits)) : 'مدیریت اضطراب';
$extraConcerns = [];
foreach ($allConcerns as $cid => $c) {
    if (!in_array($cid, $concerns, true)) {
        $extraConcerns[$cid] = $c;
    }
}
$needIntro = empty($profile['intro_done']) || $concerns === [];

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

$post = url('/dashboard/path');
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

$xpNeed = mana_path_xp_need();
$xpIn = $xp % $xpNeed;
$plantDay = mana_path_plant_day($pdo, $profile);
$plantSrc = mana_path_plant_src($plantDay);
$plantGrew = !empty($_SESSION['mp2_plant_grew']);
unset($_SESSION['mp2_plant_grew']);

$GLOBALS['pageRobots'] = 'noindex,nofollow';
$GLOBALS['pageTitle'] = 'اتاق ذهن';
$GLOBALS['pageHead'] = '<link rel="stylesheet" href="' . e(url('/assets/css/mana-path2.css')) . '?v=20260924h">';
$GLOBALS['pageBodyClass'] = trim((string) ($GLOBALS['pageBodyClass'] ?? '') . ' mp2-page');

ob_start();
?>
<div class="mp2">
  <div class="mp2-top">
    <div>
      <p class="mp2-kicker">◎ ماموریت امروز</p>
      <h1>اتاق ذهن</h1>
      <p class="mp2-lead">کارهای امروز را همین‌جا بزن. هفته از شنبه شروع می‌شود.</p>
    </div>
    <aside class="mp2-plant-tile" id="xp" aria-label="گیاه مسیر، روز <?= e(to_fa_digits((string) $plantDay)) ?> از ۳۰">
      <div class="mp2-plant-sway<?= $plantGrew ? ' is-grew' : '' ?>">
        <img src="<?= e($plantSrc) ?>" alt="" width="160" height="214">
      </div>
    </aside>
  </div>
  <p class="mp2-model">امروز <?= e($todayLabel) ?> — هدف، سفر روزانه، XP و streak برای ادامه دادن است. رقابت با دیگران اینجا نیست.</p>

  <div class="mp2-grid">
    <section class="mp2-card" aria-label="نقشه سفر">
      <p class="mp2-start">شروع ⌂</p>
      <div class="mp2-vwrap">
        <div class="mp2-vline" aria-hidden="true">
          <?php for ($i = 0; $i < 14; $i++): ?><span>▾</span><?php endfor; ?>
        </div>
        <ol class="mp2-vlist">
          <?php foreach ($stages as $st): ?>
            <?php
              $cls = !empty($st['done']) ? 'is-done' : (!empty($st['now']) ? 'is-now' : '');
            ?>
            <li class="<?= e($cls) ?>">
              <strong><?= e((string) $st['label']) ?><small><?= e((string) $st['focus']) ?></small></strong>
              <span class="mp2-dot"><?= !empty($st['done']) ? '✓' : e((string) $st['icon']) ?></span>
            </li>
          <?php endforeach; ?>
        </ol>
      </div>
    </section>

    <section class="mp2-card" id="journey">
      <div class="mp2-hhead">
        <h2>سفر من 🌿</h2>
        <small>این هفته <?= e(to_fa_digits((string) $doneStageN)) ?> از <?= e(to_fa_digits((string) $stageTotal)) ?></small>
      </div>
      <div class="mp2-dots">
        <?php foreach ($stages as $i => $step): ?>
          <?php if ($i > 0): ?><b class="<?= !empty($step['done']) || !empty($step['now']) ? 'is-on' : '' ?>"></b><?php endif; ?>
          <span class="mp2-dow" title="<?= e((string) $step['label']) ?>">
            <i class="<?= !empty($step['done']) ? 'is-done' : (!empty($step['now']) ? 'is-now' : '') ?>"></i>
            <?= e((string) $step['short']) ?>
          </span>
        <?php endforeach; ?>
      </div>
      <p class="mp2-goal">هدف فعلی من:<strong><?= e($goalLabel) ?></strong></p>
      <h3>کارهای امروز</h3>
      <ul class="mp2-today">
        <?php foreach ($missions as $i => $m): ?>
          <?php
            $ok = in_array($m['id'], $doneMissions, true);
            $practice = (string) ($m['practice'] ?? '');
            $needsNote = in_array($practice, ['thought', 'feelings', 'assert', 'kind'], true);
          ?>
          <li class="<?= $ok ? 'is-done' : '' ?>">
            <?php if ($ok): ?>
              <span class="mp2-orb" aria-hidden="true">✓</span>
              <p class="mp2-today-cap">کار امروز من: <?= e((string) $m['title']) ?></p>
            <?php else: ?>
              <details>
                <summary>
                  <span class="mp2-orb"><?= e(to_fa_digits((string) ($i + 1))) ?></span>
                  <span class="mp2-today-cap">کار امروز من: <?= e((string) $m['title']) ?></span>
                </summary>
                <p class="mp2-today-body"><?= e((string) ($m['body'] ?? '')) ?></p>
                <form method="post" action="<?= e($post) ?>">
                  <?= csrf_field() ?>
                  <input type="hidden" name="do" value="mission">
                  <input type="hidden" name="mission_id" value="<?= e((string) $m['id']) ?>">
                  <input type="hidden" name="back" value="/dashboard/path">
                  <?php if ($needsNote): ?>
                    <textarea name="note" required rows="2" placeholder="اول این کار را انجام بده، بعد اینجا بنویس."></textarea>
                  <?php endif; ?>
                  <button type="submit">ثبت انجام این کار</button>
                </form>
              </details>
            <?php endif; ?>
          </li>
        <?php endforeach; ?>
      </ul>
      <?php if ($todayDone || $doneN >= $actNeed): ?>
        <p class="mp2-note">امروز انجام شد؛ نقطه این روز سبز است.</p>
      <?php else: ?>
        <p class="mp2-note">نقطه سیاه، امروز است. با تمام شدن کارها سبز می‌شود.</p>
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
        <input type="hidden" name="back" value="/dashboard/path">
        <?php foreach ($moods as $n => $m): ?>
          <button name="mood" value="<?= (int) $n ?>" type="submit" class="<?= $moodNow === (int) $n ? 'is-on' : '' ?>">
            <?= e($m['emoji']) ?> <?= e($m['label']) ?>
          </button>
        <?php endforeach; ?>
      </form>
      <div class="mp2-tools">
        <a href="#journey"><strong>🧠 کارهای امروز</strong>تمرین روزانه CBT</a>
        <a href="<?= e($journalUrl) ?>"><strong>📓 Journal</strong>یادداشت روزانه</a>
        <a class="is-lg" href="<?= e(url('/dashboard/path/report')) ?>"><strong>📅 گزارش ماهانه</strong>نتیجه مسیر این ماه</a>
        <a class="is-lg" href="#xp"><strong>⭐ XP / Achievement</strong>سطح <?= e(to_fa_digits((string) $level)) ?></a>
      </div>
    </section>
  </div>

  <section class="mp2-card mp2-concerns" id="concerns">
    <?php if ($needIntro): ?>
      <h2>این روزها بیشتر درگیر چی هستی؟</h2>
      <p class="mp2-note">می‌توانی چند مورد را انتخاب کنی. این تشخیص نیست؛ فقط نقطهٔ شروع مسیر است.</p>
      <form method="post" action="<?= e($post) ?>">
        <?= csrf_field() ?>
        <input type="hidden" name="do" value="intro">
        <input type="hidden" name="back" value="/dashboard/path">
        <div class="mp2-chips">
          <?php foreach ($allConcerns as $id => $c): ?>
            <label>
              <input type="checkbox" name="concerns[]" value="<?= e($id) ?>">
              <span><?= e($c['emoji'] . ' ' . $c['label']) ?></span>
              <small><?= e($c['hint']) ?></small>
            </label>
          <?php endforeach; ?>
        </div>
        <button class="mp2-concern-btn" type="submit">ساخت مسیر من</button>
      </form>
    <?php else: ?>
      <h2>مسیر و مشکل‌های من</h2>
      <p class="mp2-note">الان روی این موضوع‌ها کار می‌کنی:</p>
      <p class="mp2-now"><?php foreach ($concerns as $cid): ?><?php if (isset($allConcerns[$cid])): ?><b><?= e($allConcerns[$cid]['emoji'] . ' ' . $allConcerns[$cid]['label']) ?></b><?php endif; ?><?php endforeach; ?></p>
      <?php if ($extraConcerns !== []): ?>
        <p class="mp2-note">اگر مشکل تازه‌ای آمده، همان سوال‌های اول را بزن و به مسیر اضافه کن.</p>
        <form method="post" action="<?= e($post) ?>">
          <?= csrf_field() ?>
          <input type="hidden" name="do" value="add_concerns">
          <input type="hidden" name="back" value="/dashboard/path">
          <div class="mp2-chips">
            <?php foreach ($extraConcerns as $id => $c): ?>
              <label>
                <input type="checkbox" name="concerns[]" value="<?= e($id) ?>">
                <span><?= e($c['emoji'] . ' ' . $c['label']) ?></span>
                <small><?= e($c['hint']) ?></small>
              </label>
            <?php endforeach; ?>
          </div>
          <button class="mp2-concern-btn" type="submit">افزودن به مسیر</button>
        </form>
      <?php else: ?>
        <p class="mp2-note">همهٔ موضوع‌های فعلی مسیر در لیستت هستند.</p>
      <?php endif; ?>
    <?php endif; ?>
  </section>

  <p class="mp2-disclaimer">اتاق ذهن غربالگری و تمرین همراه است، نه تشخیص و نه جایگزین درمان. نسخه آزمایشی فقط برای حساب تو.</p>
</div>
<?php
$inner = (string) ob_get_clean();
render_patient_page('اتاق ذهن', $inner);
