<?php
declare(strict_types=1);

/** فهرست ثابت کارهای روزانه منشی — نام، روز و عنوان جدا هستند و چک‌باکس نیستند. */
function secretary_daily_task_catalog(): array
{
    return [
        '01' => 'اطمینان از تمیزی میز پذیرش، میزهای آشپزخانه، اتاق‌های درمان و سرویس بهداشتی.',
        '02' => 'اطمینان از جمع شدن پرونده‌های روز قبل.',
        '03' => 'اطمینان از وجود پرونده‌های روز بعدی بر روی میز.',
        '04' => 'پاسخ به تمامی پیام‌ها در واتس‌اپ، تلگرام، آی‌مسیج و بقیه پیام‌رسان‌ها.',
        '05' => 'گذاشتن آب خنک و لیوان یکبارمصرف بر روی میز پذیرایی.',
        '06' => 'پخش موسیقی بی‌کلام در زمان مشغول بودن اتاق‌ها.',
        '07' => 'چک کردن هفتگی برنامه‌های تریاژ، اطلاع به مراجعان، تعیین زمان و هماهنگی با دکتر گرانمایه برای ارجاع نوبت یک هفته بعد از تریاژ.',
        '08' => 'ارسال برنامه روزانه اتاق‌ها به خانم دکتر گرانمایه.',
        '09' => 'ارسال عملکرد هر روز دکتر گرانمایه‌پور برای راحله در پایان ساعت کار.',
        '10' => 'سبز کردن پرداختی‌ها و قرمز کردن در صورت عدم پرداخت (اطمینان از پرداخت مراجعان روز قبل).',
        '11' => 'فرستادن برنامه فردا در گروه مانا.',
        '12' => 'اطمینان از به ترتیب قرار دادن پرونده‌ها که توالی شماره به‌هم نخورد.',
        '13' => 'نظافت همه سطل‌های زباله و انتقال آن‌ها به سطل زباله شهری.',
        '14' => 'اطمینان از خاموش بودن کولر و چراغ اتاق‌ها و سرویس بعد از اتمام کار درمانگران.',
        '15' => 'فرستادن روزانه عکس یا فیلم برای نشرین پالین‌پرست.',
        '16' => 'استفاده از خوشبوکننده یا عود و شمع در روزهای شلوغ.',
        '17' => 'در روزهای تعطیل، هماهنگی و اطلاع به خانم دکتر گرانمایه.',
        '18' => 'در پایان شب سطل‌ها تمیز باشد، کف کلینیک بدون آشغال و پلاستیک باشد و ظرف‌ها شسته شده باشد.',
        '19' => 'تهیه لیست مایحتاج مطب و در صورت نبود کالا، سفارش و پیگیری سریع آن.',
        '20' => 'برنامه ارسالی به خانم دکتر گرانمایه همه زمان‌ها را پر داشته باشد. در صورت کنسلی سریع جایگزین تعیین شود.',
        '21' => 'اطمینان از وجود دستمال در سرویس و اتاق‌ها و همچنین مواد شوینده و الکل.',
        '22' => 'ارسال برنامه بقیه درمانگران به چت شخصی و گروه مانا.',
        '23' => 'اطمینان از تداخل نداشتن اتاق‌ها.',
        '24' => 'دادن فرم مراجعان جدید قبل از جلسه.',
    ];
}

function secretary_daily_tasks_can_review(?array $user = null): bool
{
    $user = $user ?? (function_exists('current_user') ? current_user() : null);
    if (!$user) {
        return false;
    }
    $username = strtolower(trim((string) ($user['username'] ?? '')));
    if ($username === 'eshahabian') {
        return true;
    }

    return function_exists('doctor_has_shiva_access') && doctor_has_shiva_access($user);
}

function ensure_secretary_daily_tasks_schema(PDO $pdo): void
{
    static $ready = false;
    if ($ready) {
        return;
    }
    $pdo->exec("
      CREATE TABLE IF NOT EXISTS secretary_daily_tasks (
        id VARCHAR(32) PRIMARY KEY,
        user_id VARCHAR(32) NOT NULL,
        task_date DATE NOT NULL,
        task_key VARCHAR(8) NOT NULL,
        done TINYINT(1) NOT NULL DEFAULT 0,
        done_at DATETIME NULL,
        UNIQUE KEY uniq_sec_daily_task (user_id, task_date, task_key),
        INDEX idx_sec_daily_date (task_date, user_id)
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    $ready = true;
}

function secretary_daily_task_weekday(string $ymd): string
{
    $ts = strtotime($ymd . ' 12:00:00');
    if (!$ts) {
        return '';
    }
    $names = ['یکشنبه', 'دوشنبه', 'سه‌شنبه', 'چهارشنبه', 'پنجشنبه', 'جمعه', 'شنبه'];

    return $names[(int) date('w', $ts)] ?? '';
}

function secretary_daily_task_date_label(string $ymd): string
{
    $day = secretary_daily_task_weekday($ymd);
    $jalali = function_exists('to_jalali_label') ? to_jalali_label($ymd) : $ymd;

    return trim($day . ' · ' . $jalali);
}

function secretary_was_present(PDO $pdo, string $userId, string $ymd): bool
{
    if ($userId === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $ymd)) {
        return false;
    }
    if (function_exists('ensure_staff_desk_schema')) {
        ensure_staff_desk_schema($pdo);
    }
    try {
        $stmt = $pdo->prepare('SELECT 1 FROM staff_shifts WHERE user_id=? AND DATE(started_at)=? LIMIT 1');
        $stmt->execute([$userId, $ymd]);

        return (bool) $stmt->fetchColumn();
    } catch (Throwable $e) {
        return false;
    }
}

/** @return list<string> Y-m-d newest first */
function secretary_presence_days(PDO $pdo, string $userId, int $limit = 45): array
{
    if ($userId === '') {
        return [];
    }
    if (function_exists('ensure_staff_desk_schema')) {
        ensure_staff_desk_schema($pdo);
    }
    try {
        $stmt = $pdo->prepare("
          SELECT DISTINCT DATE(started_at) AS day
          FROM staff_shifts
          WHERE user_id=?
          ORDER BY day DESC
          LIMIT {$limit}
        ");
        $stmt->execute([$userId]);
        $days = [];
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $day) {
            $day = (string) $day;
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $day)) {
                $days[] = $day;
            }
        }

        return $days;
    } catch (Throwable $e) {
        return [];
    }
}

function secretary_daily_task_can_edit(PDO $pdo, string $userId, string $ymd, bool $isSelf): bool
{
    if (!$isSelf || $ymd > date('Y-m-d')) {
        return false;
    }
    if ($ymd === date('Y-m-d')) {
        return true;
    }

    return secretary_was_present($pdo, $userId, $ymd);
}

/** @return array<string, array{done:int, done_at:?string}> */
function secretary_daily_task_states(PDO $pdo, string $userId, string $ymd): array
{
    ensure_secretary_daily_tasks_schema($pdo);
    $stmt = $pdo->prepare('SELECT task_key, done, done_at FROM secretary_daily_tasks WHERE user_id=? AND task_date=?');
    $stmt->execute([$userId, $ymd]);
    $out = [];
    foreach ($stmt->fetchAll() as $row) {
        $out[(string) $row['task_key']] = [
            'done' => (int) ($row['done'] ?? 0) === 1 ? 1 : 0,
            'done_at' => $row['done_at'] !== null ? (string) $row['done_at'] : null,
        ];
    }

    return $out;
}

function secretary_daily_task_set(PDO $pdo, string $userId, string $ymd, string $taskKey, bool $done): void
{
    ensure_secretary_daily_tasks_schema($pdo);
    if (!isset(secretary_daily_task_catalog()[$taskKey])) {
        throw new RuntimeException('این مورد در فهرست نیست.');
    }
    $existing = $pdo->prepare('SELECT id FROM secretary_daily_tasks WHERE user_id=? AND task_date=? AND task_key=? LIMIT 1');
    $existing->execute([$userId, $ymd, $taskKey]);
    $id = (string) ($existing->fetchColumn() ?: '');
    if ($id === '') {
        $pdo->prepare('INSERT INTO secretary_daily_tasks (id, user_id, task_date, task_key, done, done_at) VALUES (?,?,?,?,?,?)')
            ->execute([cuid(), $userId, $ymd, $taskKey, $done ? 1 : 0, $done ? date('Y-m-d H:i:s') : null]);

        return;
    }
    $pdo->prepare('UPDATE secretary_daily_tasks SET done=?, done_at=? WHERE id=?')
        ->execute([$done ? 1 : 0, $done ? date('Y-m-d H:i:s') : null, $id]);
}

function secretary_daily_task_progress(array $states): array
{
    $total = count(secretary_daily_task_catalog());
    $done = 0;
    foreach (secretary_daily_task_catalog() as $key => $_label) {
        if (!empty($states[$key]['done'])) {
            $done++;
        }
    }

    return ['done' => $done, 'total' => $total];
}

/** @return list<array{id:string,name:string,username:string}> */
function secretary_daily_task_secretaries(PDO $pdo): array
{
    try {
        return $pdo->query("
          SELECT id, name, username
          FROM users
          WHERE role='SECRETARY' AND COALESCE(is_disabled,0)=0
          ORDER BY name ASC
        ")->fetchAll() ?: [];
    } catch (Throwable $e) {
        return [];
    }
}

function secretary_daily_task_requested_date(PDO $pdo, string $userId): string
{
    $today = date('Y-m-d');
    $requested = trim((string) ($_GET['task_date'] ?? $_GET['date'] ?? $today));
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $requested) || $requested > $today) {
        return $today;
    }
    $presence = secretary_presence_days($pdo, $userId);
    if (!in_array($requested, $presence, true) && $requested !== $today) {
        return $today;
    }

    return $requested;
}

function secretary_daily_tasks_html(
    PDO $pdo,
    array $secretary,
    string $ymd,
    bool $editable,
    string $postUrl,
    bool $startOpen = false,
    bool $showCard = true,
    string $shadowIdOverride = ''
): string {
    $states = secretary_daily_task_states($pdo, (string) ($secretary['id'] ?? ''), $ymd);
    $progress = secretary_daily_task_progress($states);
    $name = trim((string) ($secretary['name'] ?? ''));
    if ($name === '') {
        $name = (string) ($secretary['username'] ?? 'منشی');
    }
    $total = (int) $progress['total'];
    $doneCount = to_fa_digits((string) $progress['done']);
    $totalCount = to_fa_digits((string) $total);
    $dateLabel = secretary_daily_task_date_label($ymd);
    $shadowId = $shadowIdOverride !== ''
        ? $shadowIdOverride
        : 'dayshadow-' . substr(md5((string) ($secretary['id'] ?? '') . '|' . $ymd), 0, 12);
    $returnPath = parse_url($_SERVER['REQUEST_URI'] ?? '/secretary/messages', PHP_URL_PATH);
    $returnPath = is_string($returnPath) ? $returnPath : '/secretary/messages';
    if (str_contains($returnPath, 'daily-tasks')) {
        $returnPath = '/secretary/messages';
    }
    $presence = $showCard ? [] : secretary_presence_days($pdo, (string) ($secretary['id'] ?? ''));
    if (!$showCard && !in_array(date('Y-m-d'), $presence, true)) {
        array_unshift($presence, date('Y-m-d'));
    }
    static $booted = false;
    ob_start();
    if (!$booted) {
        $booted = true;
        ?>
    <style>
      [data-dayshadow]{position:fixed;top:0;right:0;bottom:0;left:0;z-index:5000;display:flex;align-items:center;justify-content:center;padding:16px;background:rgba(26,46,40,.55);direction:rtl;text-align:right;font-family:Vazirmatn,Tahoma,sans-serif;box-sizing:border-box}
      [data-dayshadow][hidden]{display:none !important}
      [data-dayshadow] *{box-sizing:border-box}
      .dayshadow-card{width:min(42rem,100%);max-height:calc(100vh - 32px);display:flex;flex-direction:column;overflow:hidden;background:#fff;color:#1a2e28;border:1px solid #d5e0da;border-radius:16px;direction:rtl;text-align:right;position:relative;box-shadow:0 18px 50px rgba(26,46,40,.28)}
      .dayshadow-x{position:absolute;top:8px;left:8px;width:36px;height:36px;border:0;border-radius:999px;background:transparent;font-size:24px;line-height:1;cursor:pointer;color:#5a6f66}
      .dayshadow-head{padding:16px 18px 10px 48px;border-bottom:1px solid #d5e0da;direction:rtl;text-align:right}
      .dayshadow-head h2{margin:0 0 12px;font-size:1.15rem;font-family:Vazirmatn,Tahoma,sans-serif}
      .dayshadow-meta{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:10px;margin:0;direction:rtl;text-align:right}
      .dayshadow-meta div{min-width:0}
      .dayshadow-meta dt{margin:0;font-size:.78rem;color:#5a6f66;font-weight:500}
      .dayshadow-meta dd{margin:4px 0 0;font-weight:700;line-height:1.55}
      .dayshadow-scroll{overflow-x:hidden;overflow-y:auto;max-height:calc(100vh - 220px);padding:12px 14px 20px;direction:rtl;text-align:right}
      .dayshadow-row{display:block;margin:0 0 8px;padding:10px 12px;border:1px solid #d5e0da;border-radius:12px;background:#f7f5f0;direction:rtl;text-align:right;line-height:1.75}
      .dayshadow-row.is-done{background:#e8f6ee;border-color:#b7e0c6}
      .dayshadow-row form,.dayshadow-row label{display:flex;direction:rtl;text-align:right;gap:8px;align-items:flex-start;margin:0;width:100%;cursor:pointer;font-family:Vazirmatn,Tahoma,sans-serif}
      .dayshadow-row input{margin-top:6px;width:18px;height:18px;flex:none}
      .dayshadow-row b{flex:none;min-width:1.7rem}
      .dayshadow-row span{flex:1;text-align:right}
      .dayshadow-row small{display:block;margin-top:4px;color:#5a6f66;font-size:.78rem}
      @media (max-width:640px){.dayshadow-meta{grid-template-columns:1fr}}
    </style>
    <script>
    (function(){
      if (window.__dayshadow) return;
      window.__dayshadow = true;
      function placeAll(){
        document.querySelectorAll("[data-dayshadow]").forEach(function(el){
          if (el.parentNode !== document.body) document.body.appendChild(el);
        });
      }
      document.addEventListener("click", function(e){
        var open = e.target.closest ? e.target.closest("[data-dayshadow-open]") : null;
        if (open) {
          if (e.preventDefault) e.preventDefault();
          var el = document.getElementById(open.getAttribute("data-dayshadow-open"));
          if (el) { placeAll(); el.hidden = false; el.scrollTop = 0; }
          return;
        }
        var close = e.target.closest ? e.target.closest("[data-dayshadow-close]") : null;
        if (close) {
          var root = close.closest("[data-dayshadow]");
          if (root) root.hidden = true;
          return;
        }
        if (e.target && e.target.hasAttribute && e.target.hasAttribute("data-dayshadow")) {
          e.target.hidden = true;
        }
      });
      if (document.readyState === "loading") document.addEventListener("DOMContentLoaded", placeAll);
      else placeAll();
    })();
    </script>
        <?php
    }
    ?>
    <?php if ($showCard): ?>
    <article class="panel" style="margin-top:1rem;direction:rtl;text-align:right">
      <div style="display:flex;flex-wrap:wrap;gap:12px;align-items:center;justify-content:space-between;direction:rtl">
        <div>
          <strong><?= e($name) ?></strong>
          <span class="muted" style="display:block"><?= e($dateLabel) ?></span>
          <span style="display:block">انجام‌شده: <?= e($doneCount) ?> · همهٔ کارها: <?= e($totalCount) ?></span>
        </div>
        <button type="button" class="btn btn-primary btn-sm" data-dayshadow-open="<?= e($shadowId) ?>">باز کردن فهرست</button>
      </div>
    </article>
    <?php endif; ?>
    <div data-dayshadow id="<?= e($shadowId) ?>" dir="rtl"<?= $startOpen ? '' : ' hidden' ?>>
      <div class="dayshadow-card" role="dialog" aria-modal="true" aria-label="لیست انجام کارهای روزانه" dir="rtl">
        <button type="button" class="dayshadow-x" data-dayshadow-close aria-label="بستن">×</button>
        <div class="dayshadow-head">
          <h2>لیست انجام کارهای روزانه</h2>
          <?php if (!$showCard && count($presence) > 1): ?>
            <form method="get" action="" style="margin:0 0 10px">
              <input type="hidden" name="open_tasks" value="1">
              <select class="input" name="task_date" onchange="this.form.submit()" style="max-width:16rem">
                <?php foreach ($presence as $day): ?>
                  <option value="<?= e($day) ?>"<?= $day === $ymd ? ' selected' : '' ?>><?= e(secretary_daily_task_date_label($day)) ?></option>
                <?php endforeach; ?>
              </select>
            </form>
          <?php endif; ?>
          <dl class="dayshadow-meta">
            <div><dt>نام و نام خانوادگی</dt><dd><?= e($name) ?></dd></div>
            <div><dt>روز و تاریخ</dt><dd><?= e($dateLabel) ?></dd></div>
            <div><dt>انجام‌شده</dt><dd><?= e($doneCount) ?></dd></div>
            <div><dt>تعداد کارها</dt><dd><?= e($totalCount) ?></dd></div>
          </dl>
        </div>
        <div class="dayshadow-scroll" dir="rtl">
          <?php $n = 0; foreach (secretary_daily_task_catalog() as $key => $label): ?>
            <?php
              $n++;
              $checked = !empty($states[$key]['done']);
              $doneAt = (string) ($states[$key]['done_at'] ?? '');
            ?>
            <div class="dayshadow-row<?= $checked ? ' is-done' : '' ?>">
              <?php if ($editable): ?>
                <form method="post" action="<?= e($postUrl) ?>">
                  <?= csrf_field() ?>
                  <input type="hidden" name="task_date" value="<?= e($ymd) ?>">
                  <input type="hidden" name="task_key" value="<?= e($key) ?>">
                  <input type="hidden" name="return_to" value="<?= e($returnPath) ?>">
                  <input type="hidden" name="done" value="0">
                  <label>
                    <input type="checkbox" name="done" value="1"<?= $checked ? ' checked' : '' ?> onchange="this.form.submit()">
                    <b><?= e(to_fa_digits((string) $n)) ?></b>
                    <span><?= e($label) ?></span>
                  </label>
                </form>
              <?php else: ?>
                <label>
                  <input type="checkbox" disabled<?= $checked ? ' checked' : '' ?>>
                  <b><?= e(to_fa_digits((string) $n)) ?></b>
                  <span><?= e($label) ?></span>
                </label>
              <?php endif; ?>
              <?php if ($checked && $doneAt !== ''): ?>
                <small>انجام شد · <?= e(format_fa_time($doneAt)) ?></small>
              <?php endif; ?>
            </div>
          <?php endforeach; ?>
          <p style="margin:8px 0 0;text-align:center;color:#5a6f66;font-size:.85rem">پایان فهرست · <?= e($totalCount) ?> کار</p>
        </div>
      </div>
    </div>
    <script>
    (function(){
      var el = document.getElementById(<?= json_encode($shadowId) ?>);
      if (el && el.parentNode !== document.body) document.body.appendChild(el);
    })();
    </script>
    <?php
    return (string) ob_get_clean();
}
