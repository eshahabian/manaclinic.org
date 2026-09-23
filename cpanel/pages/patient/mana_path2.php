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
$catalog = mana_path_trees();
$moods = mana_path_moods();
$xp = (int) ($profile['energy_xp'] ?? 0);
$level = mana_path_level($xp);
$xpNeed = mana_path_xp_need();
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

$activeTree = (string) ($profile['active_tree'] ?? 'anxiety');
if (!isset($catalog[$activeTree])) {
    $activeTree = 'anxiety';
}
$treeRow = mana_path_user_trees($pdo, $patientId)[$activeTree] ?? null;
$completed = is_array($treeRow['completed'] ?? null) ? $treeRow['completed'] : [];
$current = (int) ($treeRow['current_index'] ?? 0);
$steps = array_slice($catalog[$activeTree]['steps'] ?? [], 0, 8);
$stepTotal = max(1, count($steps));
$stepNow = min($stepTotal, max(1, $current + 1));
$progressPct = (int) round(100 * count($completed) / max(1, $stepTotal));

$concerns = $profile['concerns'] ?? [];
$goalLabel = 'مدیریت اضطراب';
$allConcerns = mana_path_concerns();
if (isset($concerns[0], $allConcerns[$concerns[0]])) {
    $goalLabel = 'کاهش ' . $allConcerns[$concerns[0]]['label'];
}

$stages = [
    ['id' => 'thoughts', 'label' => 'شناخت افکار', 'icon' => '🧠'],
    ['id' => 'feelings', 'label' => 'شناخت احساسات', 'icon' => '🌱'],
    ['id' => 'anxiety', 'label' => 'تنظیم اضطراب', 'icon' => '🔔'],
    ['id' => 'skills', 'label' => 'تمرین مهارت‌ها', 'icon' => '◎'],
    ['id' => 'goal', 'label' => 'هدف درمان', 'icon' => '🏆'],
];
$stageNow = min(count($stages), max(1, (int) ceil(($current + 1) * count($stages) / max(1, $stepTotal))));

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
$pathUrl = url('/dashboard/path');
$journalUrl = url('/dashboard/journal');
$doctorsUrl = url('/doctors');
$actNeed = 15;
$actHave = min($actNeed, count($completed) + $doneN);
$nextWhen = '';
if ($nextAp && function_exists('format_fa_datetime')) {
    $nextWhen = format_fa_datetime((string) $nextAp['starts_at']);
} elseif ($nextAp) {
    $nextWhen = (string) $nextAp['starts_at'];
}

$GLOBALS['pageRobots'] = 'noindex,nofollow';
$GLOBALS['pageTitle'] = 'اتاق ذهن ۲';
$GLOBALS['pageHead'] = '<link rel="stylesheet" href="' . e(url('/assets/css/mana-path2.css')) . '?v=20260923a">';

ob_start();
?>
<div class="mp2">
  <p class="mp2-kicker">◎ ماموریت این هفته</p>
  <h1>اتاق ذهن ۲</h1>
  <p class="mp2-lead">این هفته قرار است یک قدم به هدف درمانی‌ات نزدیک‌تر شوی. پیشرفت را روی نقشه می‌بینی — مسیر درمان است با حس بازی، نه رقابت با دیگران.</p>
  <p class="mp2-model"><strong>Therapy Journey + Game-like UX</strong> — هدف، سفر، XP، دستاورد و streak برای ادامه دادن است. لیدربورد و رقابت با بیمار دیگر اینجا نیست.</p>

  <div class="mp2-grid">
    <section class="mp2-card" aria-label="نقشه سفر">
      <p class="mp2-start">شروع ⌂</p>
      <div class="mp2-vwrap">
        <div class="mp2-vline" aria-hidden="true">
          <?php for ($i = 0; $i < 12; $i++): ?><span>▾</span><?php endfor; ?>
        </div>
        <ol class="mp2-vlist">
          <?php foreach ($stages as $i => $st): ?>
            <?php
              $n = $i + 1;
              $cls = $n < $stageNow ? 'is-done' : ($n === $stageNow ? 'is-now' : '');
            ?>
            <li class="<?= e($cls) ?>">
              <strong><?= e($st['label']) ?></strong>
              <span class="mp2-dot"><?= $n < $stageNow ? '✓' : e($st['icon']) ?></span>
            </li>
          <?php endforeach; ?>
        </ol>
      </div>
    </section>

    <section class="mp2-card">
      <div class="mp2-hhead">
        <h2>سفر من 🌿</h2>
        <small>مرحله <?= e(to_fa_digits((string) $stepNow)) ?> از <?= e(to_fa_digits((string) $stepTotal)) ?></small>
      </div>
      <div class="mp2-dots" aria-hidden="true">
        <?php foreach ($steps as $i => $step): ?>
          <?php
            $id = (string) ($step['id'] ?? '');
            $done = in_array($id, $completed, true) || $i < $current;
            $now = $i === $current;
          ?>
          <?php if ($i > 0): ?><b class="<?= $done || $now ? 'is-on' : '' ?>"></b><?php endif; ?>
          <i class="<?= $done ? 'is-done' : ($now ? 'is-now' : '') ?>" title="<?= e((string) ($step['title'] ?? '')) ?>"></i>
        <?php endforeach; ?>
      </div>
      <p class="mp2-goal">هدف فعلی من:<strong><?= e($goalLabel) ?></strong></p>
      <h3 style="margin:.2rem 0 .45rem;font-size:.9rem">ماموریت امروز</h3>
      <ul class="mp2-checks">
        <?php foreach ($missions as $m): ?>
          <?php $ok = in_array($m['id'], $doneMissions, true); ?>
          <li>
            <a class="<?= $ok ? 'is-done' : '' ?>" href="<?= e($pathUrl . '?tab=practice&mission=' . rawurlencode((string) $m['id'])) ?>">
              <span class="mp2-box"></span>
              <?= e((string) $m['title']) ?>
            </a>
          </li>
        <?php endforeach; ?>
      </ul>
      <div class="mp2-foot">
        <span>⭐ <?= e(to_fa_digits((string) $xp)) ?> XP</span>
        <span>🔥 <?= e(to_fa_digits((string) $streak)) ?> روز متوالی</span>
      </div>
    </section>
  </div>

  <div class="mp2-grid" style="margin-top:.85rem">
    <section class="mp2-card">
      <h2>نگاه مسیر — مثل داشبورد درمان</h2>
      <dl class="mp2-status">
        <div>
          <dt>🎯 هدف درمانی</dt>
          <dd><?= e($goalLabel) ?></dd>
        </div>
        <div>
          <dt>📈 پیشرفت</dt>
          <dd>
            <?= e(to_fa_digits((string) $progressPct)) ?>٪
            <p class="mp2-bar"><i style="width:<?= (int) $progressPct ?>%"></i></p>
          </dd>
        </div>
        <div>
          <dt>😊 Mood این هفته</dt>
          <dd><?= e($moodNow && isset($moods[$moodNow]) ? $moods[$moodNow]['label'] : 'هنوز ثبت نشده') ?></dd>
        </div>
        <div>
          <dt>✅ فعالیت‌ها</dt>
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
      <p class="mp2-note">همان قطعات پورتال‌های CBT معتبر: چک‌این خلق، تمرین، ژورنال، مسیر و ارتباط با درمانگر — بدون لیدربورد.</p>
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
        <a href="<?= e($pathUrl . '?tab=practice') ?>"><strong>🧠 تمرین CBT</strong>شناخت فکر و مهارت</a>
        <a href="<?= e($journalUrl) ?>"><strong>📓 Journal</strong>یادداشت روزانه</a>
        <a href="<?= e($pathUrl . '?tab=path&tree=' . rawurlencode($activeTree)) ?>"><strong>🗺️ Journey</strong>مراحل مسیر</a>
        <a href="<?= e($pathUrl . '?tab=progress') ?>"><strong>⭐ XP / Achievement</strong>سطح <?= e(to_fa_digits((string) $level)) ?></a>
        <a href="<?= e($doctorsUrl) ?>"><strong>👩‍⚕️ درمانگر</strong>رزرو یا پیام جلسه</a>
        <a href="<?= e($pathUrl) ?>"><strong>🏠 اتاق ذهن ۱</strong>فضای تصویری اتاق</a>
      </div>
    </section>
  </div>
  <p class="mp2-disclaimer">اتاق ذهن ۲ غربالگری و تمرین همراه است، نه تشخیص و نه جایگزین درمان. نسخه آزمایشی فقط برای حساب تو.</p>
</div>
<?php
$inner = (string) ob_get_clean();
render_patient_page('اتاق ذهن ۲', $inner);
