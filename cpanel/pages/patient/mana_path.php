<?php
declare(strict_types=1);

$user = require_login(['PATIENT']);
require_once __DIR__ . '/../../includes/patient_panel.php';

$manaInc = __DIR__ . '/../../includes/mana_path.php';
$manaUi = __DIR__ . '/../../includes/mana_path_ui.php';
if (!is_file($manaInc) || !is_file($manaUi)) {
    flash_set('error', 'مسیر مانا هنوز روی سرور کامل نصب نشده.');
    redirect('/dashboard');
}
require_once $manaInc;
require_once $manaUi;

if (!function_exists('mana_path_trees') || !function_exists('mana_path_require_user')) {
    flash_set('error', 'فایل محتوای مسیر مانا ناقص است.');
    redirect('/dashboard');
}

mana_path_require_user($user);
ensure_mana_path_schema($pdo);

$patientId = (string) $user['id'];
$profile = mana_path_load_profile($pdo, $patientId);
if ((string) ($profile['mood_date'] ?? '') !== date('Y-m-d')) {
    $profile['mood_today'] = null;
}
$treeRows = mana_path_user_trees($pdo, $patientId);
$catalog = mana_path_trees();
$concerns = mana_path_concerns();
$moods = mana_path_moods();
$state = mana_path_companion_state($profile);
$xp = (int) ($profile['energy_xp'] ?? 0);
$level = mana_path_level($xp);
$rawName = trim((string) ($user['name'] ?? ''));
$nameParts = preg_split('/\s+/u', $rawName) ?: [];
$firstName = trim((string) ($nameParts[0] ?? $rawName));

$tab = trim((string) ($_GET['tab'] ?? 'home'));
if (!in_array($tab, ['home', 'path', 'practice', 'progress', 'settings'], true)) {
    $tab = 'home';
}
$activeTree = trim((string) ($_GET['tree'] ?? ($profile['active_tree'] ?? '')));
if ($activeTree === '' || !isset($catalog[$activeTree])) {
    $picked = $profile['concerns'] ?? [];
    $activeTree = (string) ($picked[0] ?? 'anxiety');
}
$openStep = trim((string) ($_GET['step'] ?? ''));
$openMission = trim((string) ($_GET['mission'] ?? ''));

$missions = mana_path_daily_missions();
$doneMissions = mana_path_today_mission_ids($pdo, $patientId);

$moodNow = isset($profile['mood_today']) ? (int) $profile['mood_today'] : 0;
$moodEmoji = $moodNow && isset($moods[$moodNow]) ? $moods[$moodNow]['emoji'] : '—';

$mpathAppointments = [];
$mpathWorkshops = [];
try {
    $apStmt = $pdo->prepare("
      SELECT a.id, a.starts_at, a.status, a.cancel_reason, u.name AS doctor_name
      FROM appointments a
      JOIN doctor_profiles dp ON dp.id = a.doctor_id
      JOIN users u ON u.id = dp.user_id
      WHERE a.patient_id = ?
      ORDER BY a.starts_at DESC
      LIMIT 8
    ");
    $apStmt->execute([$patientId]);
    $mpathAppointments = $apStmt->fetchAll() ?: [];
} catch (Throwable $ignored) {
}
try {
    if (function_exists('patient_workshop_tab_data')) {
        $wsPeek = patient_workshop_tab_data($pdo, $patientId);
        foreach (['in-person', 'online', 'offline'] as $wsTab) {
            foreach ($wsPeek['enrollmentsByTab'][$wsTab] ?? [] as $en) {
                $mpathWorkshops[] = $en;
            }
        }
        $mpathWorkshops = array_slice($mpathWorkshops, 0, 8);
    }
} catch (Throwable $ignored) {
}

$GLOBALS['pageRobots'] = 'noindex,nofollow';
$GLOBALS['pageTitle'] = 'اتاق ذهن';
$GLOBALS['pageHead'] = '<link rel="stylesheet" href="' . e(mana_path_css_href()) . '">';
$GLOBALS['mpathApp'] = true;

$post = url('/dashboard/path');
$mpathUrl = url('/dashboard/path');
$dashUrl = url('/dashboard');
$journalUrl = url('/dashboard/journal');
$doctorsUrl = url('/doctors');

function mpath_step_url(string $tree, string $step, string $tab = 'path'): string
{
    return url('/dashboard/path?tab=' . rawurlencode($tab) . '&tree=' . rawurlencode($tree) . '&step=' . rawurlencode($step));
}

function mpath_task_meta(string $id): array
{
    return match ($id) {
        'breathe' => ['cls' => 'teal', 'hint' => '۴ دقیقه'],
        'thoughts' => ['cls' => 'violet', 'hint' => '۲ دقیقه'],
        'walk' => ['cls' => 'mint', 'hint' => '۱۰ دقیقه'],
        'feelings' => ['cls' => 'violet', 'hint' => '۲ دقیقه'],
        'assert' => ['cls' => 'amber', 'hint' => '۵ دقیقه'],
        'sleep' => ['cls' => 'teal', 'hint' => 'امشب'],
        'kind' => ['cls' => 'rose', 'hint' => '۱ دقیقه'],
        default => ['cls' => 'teal', 'hint' => ''],
    };
}

$medals = 0;
foreach ($treeRows as $tr) {
    $medals += count($tr['completed'] ?? []);
}
$xpNeed = mana_path_xp_need();
$xpIn = $xp % $xpNeed;
$streak = (int) ($profile['gentle_streak'] ?? 0);
$restDays = (int) ($profile['rest_days'] ?? 0);
$world = $profile['world'] ?? mana_path_world_defaults();
$unlocks = mana_path_unlock_items($world);
$unlockOn = 0;
foreach ($unlocks as $u) {
    if (!empty($u['on'])) {
        $unlockOn++;
    }
}
$companionLine = mana_path_pick_line($profile, $state);
$gender = mana_path_normalize_gender((string) ($profile['companion_gender'] ?? ''));
$needGender = !empty($profile['intro_done']) && $gender === '';
$assets = mana_path_gender_assets($gender !== '' ? $gender : 'male');
$moodUi = [];
foreach ($moods as $n => $m) {
    $moodUi[(int) $n] = (string) $m['label'];
}

ob_start();
?>
<div class="mpath-shell" data-gender="<?= e($assets['gender']) ?>" data-mood="<?= (int) $moodNow ?>" data-state="<?= e($state) ?>">
  <header class="mpath-hud">
    <button type="button" class="mpath-menu-btn" data-mpath-menu aria-controls="mpath-rail" aria-expanded="false">منو</button>
    <a class="mpath-brand" href="<?= e($mpathUrl) ?>">
      <span class="mpath-brand-mark" aria-hidden="true">🌿</span>
      <span>
        <strong>اتاق ذهن</strong>
        <small>مسیر مانا</small>
      </span>
    </a>
    <div class="mpath-hud-stats">
      <div class="mpath-stat mpath-stat--xp">
        <span>سطح <?= e(to_fa_digits((string) $level)) ?></span>
        <div class="mpath-xp" role="progressbar" aria-valuemin="0" aria-valuemax="<?= (int) $xpNeed ?>" aria-valuenow="<?= (int) $xpIn ?>">
          <i style="width:<?= (int) round(100 * $xpIn / max(1, $xpNeed)) ?>%"></i>
        </div>
        <small><?= e(to_fa_digits((string) $xpIn)) ?> / <?= e(to_fa_digits((string) $xpNeed)) ?> XP</small>
      </div>
      <div class="mpath-stat mpath-stat--chip">
        <span aria-hidden="true">🔥</span>
        <?php if ($restDays > 0): ?>
          <b><?= e(to_fa_digits((string) $restDays)) ?></b>
          <small>روز استراحت</small>
        <?php else: ?>
          <b><?= e(to_fa_digits((string) $streak)) ?></b>
          <small>روز متوالی</small>
        <?php endif; ?>
      </div>
      <div class="mpath-stat mpath-stat--chip">
        <span aria-hidden="true">⭐</span>
        <b><?= e(to_fa_digits((string) $medals)) ?></b>
        <small>مدال‌ها</small>
      </div>
    </div>
    <div class="mpath-user">
      <span>
        <strong><?= e($firstName !== '' ? $firstName : 'مهمان') ?></strong>
        <small>حال امروز: <?= e($moodNow && isset($moods[$moodNow]) ? $moods[$moodNow]['label'] : '—') ?></small>
      </span>
      <a class="mpath-avatar" href="<?= e($mpathUrl . '?tab=settings') ?>" aria-label="تنظیمات همراه">
        <img src="<?= e($assets['avatar']) ?>" alt="">
      </a>
    </div>
  </header>

  <div class="mpath-mid">
    <aside class="mpath-rail" id="mpath-rail">
      <nav aria-label="اتاق ذهن">
        <a class="<?= $tab === 'home' ? 'is-on' : '' ?>" href="<?= e($mpathUrl) ?>"><i aria-hidden="true">⌂</i> خانه</a>
        <a class="<?= $tab === 'path' ? 'is-on' : '' ?>" href="<?= e($mpathUrl . '?tab=path&tree=' . rawurlencode($activeTree)) ?>"><i aria-hidden="true">◎</i> مسیر من</a>
        <a class="<?= $tab === 'practice' ? 'is-on' : '' ?>" href="<?= e($mpathUrl . '?tab=practice') ?>"><i aria-hidden="true">✦</i> تمرین‌ها</a>
        <button type="button" data-mpath-sheet="workshops"><i aria-hidden="true">▦</i> کارگاه‌ها</button>
        <button type="button" data-mpath-sheet="sessions"><i aria-hidden="true">◷</i> جلسات من</button>
        <a href="<?= e($mpathUrl . '?tab=path&tree=' . rawurlencode($activeTree)) ?>"><i aria-hidden="true">◉</i> آزمون‌ها</a>
        <a class="<?= $tab === 'progress' ? 'is-on' : '' ?>" href="<?= e($mpathUrl . '?tab=progress') ?>"><i aria-hidden="true">▤</i> پیشرفت</a>
        <a class="<?= $tab === 'settings' ? 'is-on' : '' ?>" href="<?= e($mpathUrl . '?tab=settings') ?>"><i aria-hidden="true">⚙</i> تنظیمات</a>
      </nav>
      <p class="mpath-rail-note">قدم‌های کوچک تغییرات بزرگ می‌سازند.</p>
    </aside>
    <button type="button" class="mpath-rail-mask" data-mpath-menu hidden aria-label="بستن منو"></button>

    <div class="mpath-stage">
      <?php if (!empty($profile['crisis_flag'])): ?>
        <?= mana_path_crisis_html() ?>
      <?php endif; ?>

  <?php if (empty($profile['intro_done'])): ?>
    <div class="mr-stage" aria-hidden="true" data-gender="male" data-mood="4">
      <img class="mr-bg" src="<?= e(mana_path_asset('male-room.png')) ?>" alt="">
      <div class="mr-atmosphere"></div>
    </div>
    <section class="mpath-glass mpath-intro">
      <h1>سلام؛ اتاق ذهن را بسازیم</h1>
      <p class="muted">این روزها بیشتر درگیر چی هستی؟ می‌توانی چند مورد را انتخاب کنی. این تشخیص نیست؛ فقط نقطهٔ شروع مسیر است.</p>
      <form method="post" action="<?= e($post) ?>" class="mpath-concerns">
        <?= csrf_field() ?>
        <input type="hidden" name="do" value="intro">
        <?= mana_path_gender_picker_html($post) ?>
        <?php foreach ($concerns as $id => $c): ?>
          <label class="mpath-chip">
            <input type="checkbox" name="concerns[]" value="<?= e($id) ?>">
            <span><?= e($c['emoji'] . ' ' . $c['label']) ?></span>
            <small><?= e($c['hint']) ?></small>
          </label>
        <?php endforeach; ?>
        <button class="btn btn-primary" type="submit">ساخت مسیر من</button>
      </form>
    </section>
  <?php else: ?>

    <?php if ($needGender): ?>
      <section class="mpath-glass mpath-intro">
        <h1>اول همراهت را انتخاب کن</h1>
        <form method="post" action="<?= e($post) ?>">
          <?= csrf_field() ?>
          <input type="hidden" name="do" value="gender">
          <?= mana_path_gender_picker_html($post) ?>
          <button class="btn btn-primary" type="submit">ورود به اتاق ذهن</button>
        </form>
      </section>
    <?php else: ?>
    <div class="mr-stage" data-state="<?= e($state) ?>" data-gender="<?= e($assets['gender']) ?>" data-mood="<?= (int) $moodNow ?>">
      <img class="mr-bg" src="<?= e($assets['room']) ?>" alt="اتاق ذهن">
      <div class="mr-atmosphere" aria-hidden="true"></div>
    </div>
    <p class="mr-caption"><?= e($companionLine) ?></p>
    <?php endif; ?>

    <?php if ($tab === 'path'): ?>
      <div class="mpath-glass mpath-panel">
      <?php
        $picked = $profile['concerns'] ?? [];
        if (!in_array($activeTree, $picked, true) && isset($catalog[$activeTree])) {
            $picked[] = $activeTree;
        }
      ?>
      <div class="mpath-tree-switch">
        <?php
          $canAdd = false;
          foreach ($catalog as $tid => $t) {
              if (!in_array($tid, $picked, true)) {
                  $canAdd = true;
                  break;
              }
          }
        ?>
        <?php foreach ($picked as $tid): ?>
          <?php if (!isset($catalog[$tid])) { continue; } ?>
          <a class="<?= $tid === $activeTree ? 'is-on' : '' ?>" href="<?= e($mpathUrl . '?tab=path&tree=' . rawurlencode($tid)) ?>">
            <?= e($catalog[$tid]['emoji'] . ' ' . $catalog[$tid]['title']) ?>
          </a>
        <?php endforeach; ?>
        <?php if ($canAdd): ?>
        <details class="mpath-add-tree">
          <summary>مسیر تازه</summary>
          <form method="post" action="<?= e($post) ?>">
            <?= csrf_field() ?>
            <input type="hidden" name="do" value="add_tree">
            <select name="tree_id" class="input">
              <?php foreach ($catalog as $tid => $t): ?>
                <?php if (in_array($tid, $picked, true)) { continue; } ?>
                <option value="<?= e($tid) ?>"><?= e($t['title']) ?></option>
              <?php endforeach; ?>
            </select>
            <button class="btn btn-sm btn-outline" type="submit">افزودن</button>
          </form>
        </details>
        <?php endif; ?>
      </div>

      <div class="mpath-grid">
        <?= mana_path_room_html($profile, $state) ?>
        <ol class="mpath-lane">
          <?php
            $row = $treeRows[$activeTree] ?? null;
            $completed = $row['completed'] ?? [];
            $current = (int) ($row['current_index'] ?? 0);
            $steps = mana_path_step_status($catalog[$activeTree]['steps'], $completed, $current);
            $showClinicNudge = $row && (int) ($row['high_checkins'] ?? 0) >= 2;
          ?>
          <?php if (!empty($row['last_band'])): ?>
            <li class="mpath-band muted">آخرین غربالگری: <?= e((string) $row['last_band']) ?> — نه تشخیص پزشکی.</li>
          <?php endif; ?>
          <?php if ($showClinicNudge): ?>
            <li class="mpath-nudge">
              به نظر می‌رسد این موضوع هنوز اذیتت می‌کند؛ اگر بخواهی می‌توانی با یک درمانگر صحبت کنی.
              <span class="mpath-links">
                <a class="btn btn-primary btn-sm" href="<?= e(url('/doctors')) ?>">انتخاب درمانگر</a>
                <a class="btn btn-outline btn-sm" href="<?= e(url('/doctors')) ?>">رزرو جلسه</a>
              </span>
            </li>
          <?php endif; ?>
          <?php foreach ($steps as $step): ?>
            <?php
              $st = $step['status'];
              $href = $st === 'locked' ? '' : mpath_step_url($activeTree, (string) $step['id']);
            ?>
            <li class="mpath-node mpath-node--<?= e($st) ?>">
              <?php if ($href): ?>
                <a href="<?= e($href) ?>">
                  <span class="mpath-ico"><?= $st === 'done' ? '✓' : e(mana_path_kind_icon((string) $step['kind'])) ?></span>
                  <?= e((string) $step['title']) ?>
                </a>
              <?php else: ?>
                <span>
                  <span class="mpath-ico">🔒</span>
                  <?= e((string) $step['title']) ?>
                </span>
              <?php endif; ?>
            </li>
          <?php endforeach; ?>
        </ol>
      </div>

      <?php
        $stepDef = null;
        foreach ($catalog[$activeTree]['steps'] as $s) {
            if ((string) $s['id'] === $openStep) {
                $stepDef = $s;
                break;
            }
        }
        $stepMeta = null;
        foreach ($steps as $s) {
            if ((string) $s['id'] === $openStep) {
                $stepMeta = $s;
                break;
            }
        }
      ?>
      <?php if ($stepDef && $stepMeta && $stepMeta['status'] !== 'locked'): ?>
        <section class="mpath-card mpath-step" id="step">
          <h2><?= e((string) $stepDef['title']) ?></h2>
          <?php if (!empty($stepDef['body'])): ?>
            <p><?= e((string) $stepDef['body']) ?></p>
          <?php endif; ?>
          <?php $kind = (string) ($stepDef['kind'] ?? ''); ?>

          <?php if ($kind === 'screen'): ?>
            <?php $tool = mana_path_screenings()[$catalog[$activeTree]['screening']]; ?>
            <p class="muted"><?= e((string) $tool['intro']) ?></p>
            <form method="post" action="<?= e($post) ?>" class="mpath-screen">
              <?= csrf_field() ?>
              <input type="hidden" name="do" value="step">
              <input type="hidden" name="tree_id" value="<?= e($activeTree) ?>">
              <input type="hidden" name="step_id" value="<?= e((string) $stepDef['id']) ?>">
              <?php foreach ($tool['items'] as $i => $q): ?>
                <fieldset>
                  <legend><?= e(to_fa_digits((string) ($i + 1)) . '. ' . $q) ?></legend>
                  <?php foreach ($tool['scale'] as $val => $lab): ?>
                    <label>
                      <input type="radio" name="answers[<?= (int) $i ?>]" value="<?= (int) $val ?>" required>
                      <?= e($lab) ?>
                    </label>
                  <?php endforeach; ?>
                </fieldset>
              <?php endforeach; ?>
              <button class="btn btn-primary" type="submit">دیدن سطح علائم</button>
            </form>
          <?php elseif ($kind === 'clinic'): ?>
            <p>اینجا نسخه درمانی داده نمی‌شود. اگر بخواهی، مسیر به جلسهٔ واقعی وصل می‌شود.</p>
            <p class="mpath-links">
              <a class="btn btn-primary" href="<?= e(url('/doctors')) ?>">انتخاب درمانگر</a>
              <a class="btn btn-outline" href="<?= e(url('/services/workshops')) ?>">کارگاه‌ها</a>
            </p>
            <?php if ($stepMeta['status'] !== 'done'): ?>
              <form method="post" action="<?= e($post) ?>">
                <?= csrf_field() ?>
                <input type="hidden" name="do" value="step">
                <input type="hidden" name="tree_id" value="<?= e($activeTree) ?>">
                <input type="hidden" name="step_id" value="<?= e((string) $stepDef['id']) ?>">
                <button class="btn btn-outline" type="submit">این پیشنهاد را دیدم</button>
              </form>
            <?php endif; ?>
          <?php elseif ($kind === 'chest'): ?>
            <p>صندوق همراهی: لباس تازه و نور بیشتر برای اتاق ذهن.</p>
            <?php if ($stepMeta['status'] !== 'done'): ?>
              <form method="post" action="<?= e($post) ?>">
                <?= csrf_field() ?>
                <input type="hidden" name="do" value="step">
                <input type="hidden" name="tree_id" value="<?= e($activeTree) ?>">
                <input type="hidden" name="step_id" value="<?= e((string) $stepDef['id']) ?>">
                <button class="btn btn-primary" type="submit">باز کردن صندوق</button>
              </form>
            <?php else: ?>
              <p class="muted">باز شده. اتاق کمی زنده‌تر است.</p>
            <?php endif; ?>
          <?php elseif ($kind === 'checkin'): ?>
            <form method="post" action="<?= e($post) ?>" class="mpath-checkin">
              <?= csrf_field() ?>
              <input type="hidden" name="do" value="step">
              <input type="hidden" name="tree_id" value="<?= e($activeTree) ?>">
              <input type="hidden" name="step_id" value="<?= e((string) $stepDef['id']) ?>">
              <label>از ۰ تا ۱۰
                <input class="input" type="number" name="checkin" min="0" max="10" required>
              </label>
              <button class="btn btn-primary" type="submit">ثبت</button>
            </form>
          <?php else: ?>
            <?php $practice = (string) ($stepDef['practice'] ?? ($kind === 'real' ? 'real' : 'note')); ?>
            <?php if ($practice === 'breathe'): ?>
              <div class="mpath-breathe" data-mpath-breathe>
                <div class="mpath-orb" aria-hidden="true"></div>
                <p class="mpath-breathe-label">آماده</p>
                <p class="muted">چهار دقیقه کنار نفس. اگر وسطش رها کردی، اشکالی ندارد.</p>
                <button type="button" class="btn btn-outline" data-breathe-start>شروع</button>
              </div>
            <?php endif; ?>
            <form method="post" action="<?= e($post) ?>" class="form-stack">
              <?= csrf_field() ?>
              <input type="hidden" name="do" value="step">
              <input type="hidden" name="tree_id" value="<?= e($activeTree) ?>">
              <input type="hidden" name="step_id" value="<?= e((string) $stepDef['id']) ?>">
              <?php if ($practice === 'thought'): ?>
                <label>موقعیت<input class="input" name="note_sit" maxlength="400"></label>
                <label>فکر خودکار<input class="input" name="note_thought" maxlength="400"></label>
                <label>پاسخ مهربان‌تر<textarea class="input" name="note" rows="3" maxlength="800"></textarea></label>
              <?php elseif ($practice === 'feelings'): ?>
                <label>سه احساس امروز<input class="input" name="note" maxlength="200" placeholder="مثلاً خسته، امیدوار، گیج"></label>
              <?php elseif ($practice === 'assert' || $practice === 'kind' || $practice === 'two'): ?>
                <label>یادداشت کوتاه<textarea class="input" name="note" rows="3" maxlength="800"></textarea></label>
              <?php else: ?>
                <label>اگر خواستی بنویس (اختیاری)<textarea class="input" name="note" rows="3" maxlength="800"></textarea></label>
              <?php endif; ?>
              <?php if ($stepMeta['status'] !== 'done'): ?>
                <button class="btn btn-primary" type="submit">انجام شد — بدون فشار</button>
              <?php else: ?>
                <p class="muted">این مرحله را قبلاً انجام داده‌ای.</p>
              <?php endif; ?>
            </form>
          <?php endif; ?>
        </section>
      <?php endif; ?>
      </div>
    <?php endif; ?>

    <?php if ($tab === 'practice'): ?>
      <section class="mpath-glass mpath-panel mpath-card">
        <h2>تمرین‌های امروز</h2>
        <p class="muted">اگر امروز حالش نبود، فردا همین‌جا منتظرت می‌مانیم. استریک نمی‌سوزد.</p>
        <?php foreach ($missions as $m): ?>
          <?php $ok = in_array($m['id'], $doneMissions, true); ?>
          <article class="mpath-mission-card<?= $openMission === $m['id'] ? ' is-open' : '' ?>" id="m-<?= e($m['id']) ?>">
            <h3><?= $ok ? '✓ ' : '' ?><?= e($m['title']) ?></h3>
            <p><?= e($m['body']) ?></p>
            <?php if (!$ok): ?>
              <?php if (($m['practice'] ?? '') === 'breathe'): ?>
                <div class="mpath-breathe" data-mpath-breathe>
                  <div class="mpath-orb" aria-hidden="true"></div>
                  <p class="mpath-breathe-label">آماده</p>
                  <button type="button" class="btn btn-outline btn-sm" data-breathe-start>شروع تنفس</button>
                </div>
              <?php endif; ?>
              <form method="post" action="<?= e($post) ?>">
                <?= csrf_field() ?>
                <input type="hidden" name="do" value="mission">
                <input type="hidden" name="mission_id" value="<?= e($m['id']) ?>">
                <label>یادداشت اختیاری<textarea class="input" name="note" rows="2" maxlength="500"></textarea></label>
                <button class="btn btn-primary btn-sm" type="submit">تمام کردم</button>
              </form>
            <?php else: ?>
              <p class="muted">امروز انجام شد. انرژی ذهن اضافه شد.</p>
            <?php endif; ?>
          </article>
        <?php endforeach; ?>
        <p class="mpath-links">
          <a class="btn btn-outline btn-sm" href="<?= e(url('/dashboard/journal')) ?>">ثبت افکار در دفتر</a>
          <a class="btn btn-outline btn-sm" href="<?= e(url('/services/workshops')) ?>">مشاهده کارگاه‌ها</a>
        </p>
      </section>
    <?php endif; ?>

    <?php if ($tab === 'progress'): ?>
      <section class="mpath-glass mpath-panel mpath-card">
        <h2>پیشرفت قابل مشاهده</h2>
        <p class="muted">انرژی ذهن با تمرین می‌آید. اگر روزی نبودی، همراهی‌ات قطع نمی‌شود — فقط حالت استراحت است.</p>
        <ul class="mpath-progress">
          <li>سطح <?= e(to_fa_digits((string) $level)) ?></li>
          <li>انرژی ذهن <?= e(to_fa_digits((string) $xp)) ?></li>
          <li>روزهای همراهی <?= e(to_fa_digits((string) ($profile['gentle_streak'] ?? 0))) ?></li>
          <li>گیاه اتاق <?= e(to_fa_digits((string) ($profile['world']['plant'] ?? 0))) ?></li>
          <li>تخت / خواب <?= e(to_fa_digits((string) ($profile['world']['bed'] ?? 0))) ?></li>
          <li>نور <?= e(to_fa_digits((string) ($profile['world']['light'] ?? 0))) ?></li>
        </ul>
        <?= mana_path_room_html($profile, $state) ?>
        <div class="mpath-trees-mini">
          <?php foreach (($profile['concerns'] ?? []) as $tid): ?>
            <?php if (!isset($catalog[$tid])) { continue; } ?>
            <?php
              $row = $treeRows[$tid] ?? null;
              $doneN = count($row['completed'] ?? []);
              $allN = count($catalog[$tid]['steps']);
            ?>
            <a href="<?= e($mpathUrl . '?tab=path&tree=' . rawurlencode($tid)) ?>">
              <?= e($catalog[$tid]['title']) ?>
              — <?= e(to_fa_digits((string) $doneN)) ?> / <?= e(to_fa_digits((string) $allN)) ?>
              <?php if (!empty($row['last_band'])): ?>
                <small class="muted"><?= e((string) $row['last_band']) ?></small>
              <?php endif; ?>
            </a>
          <?php endforeach; ?>
        </div>
      </section>
    <?php endif; ?>

    <?php if ($tab === 'settings'): ?>
      <section class="mpath-glass mpath-panel mpath-card">
        <h2>تنظیمات</h2>
        <p class="muted">همراه و حال اتاق را اینجا عوض می‌کنی. بقیهٔ پیشرفت و مسیرت سر جایش می‌ماند.</p>
        <form method="post" action="<?= e($post) ?>">
          <?= csrf_field() ?>
          <input type="hidden" name="do" value="gender">
          <?= mana_path_gender_picker_html($post, $gender) ?>
          <button class="btn btn-primary" type="submit">ذخیره همراه</button>
        </form>
        <p class="mpath-links">
          <a class="btn btn-outline btn-sm" href="<?= e($dashUrl) ?>">بازگشت به پنل</a>
        </p>
      </section>
    <?php endif; ?>

  <?php endif; ?>
    </div>

    <?php if (!empty($profile['intro_done'])): ?>
    <aside class="mpath-today" aria-label="ماموریت‌های امروز">
      <h2>ماموریت‌های امروز</h2>
      <ul>
        <?php foreach ($missions as $m): ?>
          <?php
            $ok = in_array($m['id'], $doneMissions, true);
            $meta = mpath_task_meta((string) $m['id']);
          ?>
          <li>
            <a class="mpath-task <?= e($meta['cls']) ?><?= $ok ? ' is-done' : '' ?>" href="<?= e($mpathUrl . '?tab=practice&mission=' . rawurlencode((string) $m['id'])) ?>">
              <span class="mpath-task-ico"></span>
              <span>
                <strong><?= e((string) $m['title']) ?></strong>
                <small><?= $ok ? 'انجام شد' : e($meta['hint']) ?> · +<?= e(to_fa_digits((string) ($m['xp'] ?? 0))) ?> XP</small>
              </span>
            </a>
          </li>
        <?php endforeach; ?>
        <li>
          <button type="button" class="mpath-task amber" data-mpath-sheet="workshops">
            <span class="mpath-task-ico mpath-task-ico--play"></span>
            <span>
              <strong>ویدیوی آموزشی</strong>
              <small>۵ دقیقه</small>
            </span>
          </button>
        </li>
        <li>
          <button type="button" class="mpath-task rose" data-mpath-sheet="sessions">
            <span class="mpath-task-ico mpath-task-ico--user"></span>
            <span>
              <strong>جلسه با درمانگر</strong>
              <small><?= $mpathAppointments === [] ? 'در صورت نیاز' : 'جلسه‌های ثبت‌شده' ?></small>
            </span>
          </button>
        </li>
      </ul>
    </aside>
    <?php endif; ?>
  </div>

  <?php if (!empty($profile['intro_done'])): ?>
  <section class="mpath-status">
    <div class="mpath-mood-box">
      <h2>حال و هوای امروز</h2>
      <form method="post" action="<?= e($post) ?>" class="mpath-faces" data-mpath-mood>
        <?= csrf_field() ?>
        <input type="hidden" name="do" value="mood">
        <?php foreach ($moodUi as $n => $lab): ?>
          <button class="mpath-face mpath-face--<?= (int) $n ?><?= $moodNow === $n ? ' is-on' : '' ?>" name="mood" value="<?= (int) $n ?>" type="submit" data-mood="<?= (int) $n ?>">
            <img class="mpath-face-art" src="<?= e($assets['avatar']) ?>" alt="">
            <?= e($lab) ?>
          </button>
        <?php endforeach; ?>
      </form>
    </div>
    <div class="mpath-world-box">
      <h2>محیط اتاق من</h2>
      <ul class="mpath-unlocks">
        <?php foreach ($unlocks as $u): ?>
          <li class="<?= !empty($u['on']) ? 'is-on' : 'is-off' ?>">
            <span class="mpath-lock" aria-hidden="true"><?= !empty($u['on']) ? e((string) ($u['icon'] ?? '')) : '🔒' ?></span>
            <?= e($u['label']) ?>
          </li>
        <?php endforeach; ?>
      </ul>
      <p class="mpath-unlock-bar"><i style="width:<?= (int) round(100 * $unlockOn / max(1, count($unlocks))) ?>%"></i></p>
      <small><?= e(to_fa_digits((string) $unlockOn)) ?> / <?= e(to_fa_digits((string) count($unlocks))) ?> آیتم باز شده</small>
    </div>
    <blockquote class="mpath-quote">
      ذهن سالم محیط سالم می‌سازد.
    </blockquote>
  </section>
  <?php endif; ?>

  <nav class="mpath-dock" aria-label="میانبرها">
    <a href="<?= e($mpathUrl . '?tab=practice') ?>">ماموریت امروز</a>
    <a href="<?= e($mpathUrl . '?tab=practice') ?>">تمرین سریع</a>
    <a href="<?= e($journalUrl) ?>">یادداشت روزانه</a>
    <button type="button" data-mpath-sheet="workshops">کارگاه‌ها</button>
    <a href="<?= e($doctorsUrl) ?>">رزرو جلسه</a>
    <a class="mpath-dock-go" href="<?= e($mpathUrl . '?tab=path&tree=' . rawurlencode($activeTree)) ?>">ادامه مسیر</a>
  </nav>

  <p class="mpath-disclaimer">مسیر مانا همراه تمرین و غربالگری است، نه تشخیص بیماری و نه جایگزین درمان. نسخهٔ آزمایشی فعلاً فقط برای حساب تو فعال است.</p>
</div>

<div class="mpath-sheet" data-mpath-sheet-panel="workshops" hidden>
  <button type="button" class="mpath-sheet-backdrop" data-mpath-sheet-close aria-label="بستن"></button>
  <div class="mpath-sheet-card" role="dialog" aria-modal="true" aria-labelledby="mpath-ws-title">
    <header class="mpath-sheet-head">
      <h2 id="mpath-ws-title">کارگاه‌های من</h2>
      <button type="button" class="mpath-sheet-x" data-mpath-sheet-close aria-label="بستن">×</button>
    </header>
    <div class="mpath-sheet-body">
      <?php if ($mpathWorkshops === []): ?>
        <p class="muted">هنوز کارگاهی ثبت نشده. اگر بخواهی می‌توانی دوره‌ها را ببینی — مسیر همین‌جا می‌ماند.</p>
      <?php else: ?>
        <ul class="mpath-sheet-list">
          <?php foreach ($mpathWorkshops as $en): ?>
            <li>
              <strong><?= e((string) ($en['title'] ?? 'کارگاه')) ?></strong>
              <span class="muted"><?= e((string) ($en['doctor_name'] ?? '')) ?></span>
              <?php if (!empty($en['starts_at'])): ?>
                <span class="muted"><?= e(format_fa_datetime((string) $en['starts_at'])) ?></span>
              <?php endif; ?>
            </li>
          <?php endforeach; ?>
        </ul>
      <?php endif; ?>
      <p class="mpath-links">
        <a class="btn btn-primary btn-sm" href="<?= e(url('/services/workshops')) ?>">دوره‌ها و کارگاه‌ها</a>
      </p>
    </div>
  </div>
</div>

<div class="mpath-sheet" data-mpath-sheet-panel="sessions" hidden>
  <button type="button" class="mpath-sheet-backdrop" data-mpath-sheet-close aria-label="بستن"></button>
  <div class="mpath-sheet-card" role="dialog" aria-modal="true" aria-labelledby="mpath-ss-title">
    <header class="mpath-sheet-head">
      <h2 id="mpath-ss-title">جلسات من</h2>
      <button type="button" class="mpath-sheet-x" data-mpath-sheet-close aria-label="بستن">×</button>
    </header>
    <div class="mpath-sheet-body">
      <?php if ($mpathAppointments === []): ?>
        <p class="muted">جلسه‌ای ثبت نشده. اگر آماده بودی می‌توانی درمانگر انتخاب کنی.</p>
      <?php else: ?>
        <ul class="mpath-sheet-list">
          <?php foreach ($mpathAppointments as $ap): ?>
            <li>
              <strong><?= e((string) ($ap['doctor_name'] ?? 'درمانگر')) ?></strong>
              <span><?= e(format_fa_datetime((string) ($ap['starts_at'] ?? ''))) ?></span>
              <span class="muted"><?= e(appointment_row_status_label($ap)) ?></span>
            </li>
          <?php endforeach; ?>
        </ul>
      <?php endif; ?>
      <p class="mpath-links">
        <a class="btn btn-primary btn-sm" href="<?= e(url('/doctors')) ?>">رزرو جلسه</a>
      </p>
    </div>
  </div>
</div>
<script src="<?= e(url('/assets/js/mana-path.js')) ?>?v=20260923e"></script>
<?php
$inner = ob_get_clean();
render_patient_page('اتاق ذهن', $inner);
