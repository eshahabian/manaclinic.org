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

function secretary_daily_tasks_html(
    PDO $pdo,
    array $secretary,
    string $ymd,
    bool $editable,
    string $postUrl
): string {
    $states = secretary_daily_task_states($pdo, (string) ($secretary['id'] ?? ''), $ymd);
    $progress = secretary_daily_task_progress($states);
    $name = trim((string) ($secretary['name'] ?? ''));
    if ($name === '') {
        $name = (string) ($secretary['username'] ?? 'منشی');
    }
    $doneLabel = to_fa_digits((string) $progress['done']) . ' از ' . to_fa_digits((string) $progress['total']);
    $dateLabel = secretary_daily_task_date_label($ymd);
    ob_start();
    ?>
    <section class="daywork">
      <header class="daywork-head">
        <h2>لیست انجام کارهای روزانه</h2>
        <dl class="daywork-meta">
          <div><dt>نام و نام خانوادگی</dt><dd><?= e($name) ?></dd></div>
          <div><dt>روز و تاریخ</dt><dd><?= e($dateLabel) ?></dd></div>
          <div><dt>انجام‌شده</dt><dd><?= e($doneLabel) ?></dd></div>
        </dl>
      </header>
      <ol class="daywork-list">
        <?php $n = 0; foreach (secretary_daily_task_catalog() as $key => $label): ?>
          <?php
            $n++;
            $checked = !empty($states[$key]['done']);
            $doneAt = (string) ($states[$key]['done_at'] ?? '');
          ?>
          <li class="daywork-item<?= $checked ? ' is-done' : '' ?>">
            <?php if ($editable): ?>
              <form method="post" action="<?= e($postUrl) ?>">
                <?= csrf_field() ?>
                <input type="hidden" name="task_date" value="<?= e($ymd) ?>">
                <input type="hidden" name="task_key" value="<?= e($key) ?>">
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
          </li>
        <?php endforeach; ?>
      </ol>
    </section>
    <?php
    return (string) ob_get_clean();
}
