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
$firstName = trim(explode(' ', (string) $user['name'])[0] ?: (string) $user['name']);

$tab = trim((string) ($_GET['tab'] ?? 'home'));
if (!in_array($tab, ['home', 'path', 'practice', 'progress'], true)) {
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

$GLOBALS['pageRobots'] = 'noindex,nofollow';
$GLOBALS['pageTitle'] = 'مسیر مانا';
$GLOBALS['pageHead'] = '<link rel="stylesheet" href="' . e(mana_path_css_href()) . '">';

$post = url('/dashboard/path');
$base = url('/dashboard/path');

function mpath_step_url(string $tree, string $step, string $tab = 'path'): string
{
    return url('/dashboard/path?tab=' . rawurlencode($tab) . '&tree=' . rawurlencode($tree) . '&step=' . rawurlencode($step));
}

ob_start();
?>
<div class="mpath">
  <header class="mpath-hero">
    <div>
      <p class="mpath-kicker">مسیر مانا</p>
      <h1><?= e($firstName) ?> — Level <?= e(to_fa_digits((string) $level)) ?> 🌱</h1>
      <p class="mpath-stats">
        حال امروز: <span><?= e($moodEmoji) ?></span>
        · انرژی ذهن: <strong><?= e(to_fa_digits((string) $xp)) ?></strong>
        <?php if ((int) ($profile['rest_days'] ?? 0) > 0): ?>
          · <span class="mpath-rest">استراحت <?= e(to_fa_digits((string) $profile['rest_days'])) ?> روزه</span>
        <?php elseif ((int) ($profile['gentle_streak'] ?? 0) > 0): ?>
          · همراهی: <?= e(to_fa_digits((string) $profile['gentle_streak'])) ?> روز
        <?php endif; ?>
      </p>
    </div>
  </header>

  <?php if (!empty($profile['crisis_flag'])): ?>
    <?= mana_path_crisis_html() ?>
  <?php endif; ?>

  <nav class="mpath-tabs" aria-label="بخش‌های مسیر مانا">
    <a class="<?= $tab === 'home' ? 'is-on' : '' ?>" href="<?= e($base) ?>">خانه</a>
    <a class="<?= $tab === 'path' ? 'is-on' : '' ?>" href="<?= e($base . '?tab=path&tree=' . rawurlencode($activeTree)) ?>">مسیر من</a>
    <a class="<?= $tab === 'practice' ? 'is-on' : '' ?>" href="<?= e($base . '?tab=practice') ?>">تمرین‌ها</a>
    <a href="<?= e(url('/dashboard/workshops/mine')) ?>">کارگاه‌ها</a>
    <a href="<?= e(url('/dashboard/appointments')) ?>">جلسات من</a>
    <a class="<?= $tab === 'progress' ? 'is-on' : '' ?>" href="<?= e($base . '?tab=progress') ?>">پیشرفت</a>
  </nav>

  <?php if (empty($profile['intro_done'])): ?>
    <section class="mpath-card">
      <h2>سلام؛ از کجا شروع کنیم؟</h2>
      <p class="muted">این روزها بیشتر درگیر چی هستی؟ می‌توانی چند مورد را انتخاب کنی. این تشخیص نیست؛ فقط نقطهٔ شروع مسیر است.</p>
      <form method="post" action="<?= e($post) ?>" class="mpath-concerns">
        <?= csrf_field() ?>
        <input type="hidden" name="do" value="intro">
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

    <?php if ($tab === 'home'): ?>
      <div class="mpath-grid">
        <?= mana_path_room_html($profile, $state) ?>
        <section class="mpath-card">
          <h2>حال امروز</h2>
          <form method="post" action="<?= e($post) ?>" class="mpath-moods">
            <?= csrf_field() ?>
            <input type="hidden" name="do" value="mood">
            <?php foreach ($moods as $n => $m): ?>
              <button class="mpath-mood<?= $moodNow === $n ? ' is-on' : '' ?>" name="mood" value="<?= (int) $n ?>" type="submit">
                <span><?= e($m['emoji']) ?></span>
                <?= e($m['label']) ?>
              </button>
            <?php endforeach; ?>
          </form>
          <h3>ماموریت‌های کوچک امروز</h3>
          <ul class="mpath-missions">
            <?php foreach ($missions as $m): ?>
              <?php $ok = in_array($m['id'], $doneMissions, true); ?>
              <li>
                <a href="<?= e($base . '?tab=practice&mission=' . rawurlencode($m['id'])) ?>">
                  <?= $ok ? '✓' : '○' ?> <?= e($m['title']) ?>
                  <span class="muted">+<?= e(to_fa_digits((string) $m['xp'])) ?></span>
                </a>
              </li>
            <?php endforeach; ?>
          </ul>
          <p class="mpath-links">
            <a class="btn btn-primary btn-sm" href="<?= e($base . '?tab=path&tree=' . rawurlencode($activeTree)) ?>">ادامه مسیر</a>
            <a class="btn btn-outline btn-sm" href="<?= e(url('/dashboard/journal')) ?>">دفتر یادداشت</a>
          </p>
        </section>
      </div>
    <?php endif; ?>

    <?php if ($tab === 'path'): ?>
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
          <a class="<?= $tid === $activeTree ? 'is-on' : '' ?>" href="<?= e($base . '?tab=path&tree=' . rawurlencode($tid)) ?>">
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
    <?php endif; ?>

    <?php if ($tab === 'practice'): ?>
      <section class="mpath-card">
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
      <section class="mpath-card">
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
            <a href="<?= e($base . '?tab=path&tree=' . rawurlencode($tid)) ?>">
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

  <?php endif; ?>

  <p class="mpath-disclaimer muted">مسیر مانا همراه تمرین و غربالگری است، نه تشخیص بیماری و نه جایگزین درمان. نسخهٔ آزمایشی فعلاً فقط برای حساب تو فعال است.</p>
</div>
<script src="<?= e(url('/assets/js/mana-path.js')) ?>?v=20260922a"></script>
<?php
$inner = ob_get_clean();
render_patient_page('مسیر مانا', $inner);
