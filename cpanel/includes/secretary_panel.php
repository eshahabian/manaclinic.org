<?php
declare(strict_types=1);

function secretary_nav(): array
{
    $nav = [
        [
            'href' => '/secretary/messages',
            'label' => 'پیام‌ها',
            'badge' => (function_exists('mentions_unread_count') && ($GLOBALS['pdo'] ?? null) instanceof PDO && current_user())
                ? mentions_unread_count($GLOBALS['pdo'], (string) (current_user()['id'] ?? ''))
                : 0,
            'badge_tone' => 'new',
        ],
        [
            'href' => '/secretary/consult-requests',
            'label' => 'درخواست مشاوره',
            'badge' => function_exists('consult_request_new_count') ? consult_request_new_count() : 0,
            'badge_tone' => 'new',
        ],
        ['href' => '/secretary/appointments', 'label' => 'نوبت‌ها'],
        ['href' => '/secretary/therapist-days', 'label' => 'روزهای درمانگر'],
        ['href' => '/secretary/payouts', 'label' => 'پرداخت درمانگرها'],
        ['href' => '/secretary/petty-cash', 'label' => 'تنخواه'],
        ['href' => '/secretary/complaints', 'label' => 'شکایت از درمانگر'],
        ['href' => '/admin/rooms', 'label' => 'اتاق‌ها'],
        ['href' => '/admin/outreach', 'label' => 'مراجعه‌کنندگان قدیمی'],
        ['href' => '/secretary/patients', 'label' => 'مراجعه‌کنندگان'],
        ['href' => '/secretary/users', 'label' => 'کاربران'],
        ['href' => '/secretary/profile', 'label' => 'پیام مدیر'],
        ['href' => '/secretary/board', 'label' => 'یادداشت مشترک'],
        ['href' => '/secretary/hours', 'label' => 'ساعت کاری'],
        ['href' => '/doctor/staff-hours', 'label' => 'ساعت کاری منشی‌ها'],
        ['href' => '/doctor/secretary-messages', 'label' => 'پیام به منشی‌ها'],
        ['href' => '/secretary/daily-tasks', 'label' => 'وظایف'],
        ['href' => '/secretary/colleague-messages', 'label' => 'پیام همکار'],
        ['href' => '/secretary/articles', 'label' => 'مقالات'],
        ['href' => '/secretary/workshops', 'label' => 'کارگاه‌ها'],
        ['href' => '/change-password', 'label' => 'تغییر رمز عبور'],
    ];
    $videoLink = function_exists('video_call_nav_link') ? video_call_nav_link() : null;
    if ($videoLink) {
        array_splice($nav, 1, 0, [$videoLink]);
    }
    if (!function_exists('secretary_daily_tasks_can_review') && is_file(__DIR__ . '/secretary_daily_tasks.php')) {
        require_once __DIR__ . '/secretary_daily_tasks.php';
    }
    if (function_exists('secretary_daily_tasks_can_review') && secretary_daily_tasks_can_review()) {
        $taskLink = ['href' => '/secretary/secretary-tasks', 'label' => 'وظایف منشی‌ها'];
        $inserted = false;
        foreach ($nav as $i => $item) {
            if (($item['href'] ?? '') === '/secretary/daily-tasks') {
                array_splice($nav, $i + 1, 0, [$taskLink]);
                $inserted = true;
                break;
            }
        }
        if (!$inserted) {
            $nav[] = $taskLink;
        }
    }

    return $nav;
}

function secretary_panel_daytasks_box($pdo, ?array $user): string
{
    if (!$user || !$pdo instanceof PDO || (string) ($user['role'] ?? '') !== 'SECRETARY') {
        return '';
    }
    if (!function_exists('secretary_daily_task_catalog')) {
        require_once __DIR__ . '/secretary_daily_tasks.php';
    }
    if (!function_exists('secretary_daily_task_catalog')) {
        return '';
    }

    $ymd = date('Y-m-d');
    $userId = (string) ($user['id'] ?? '');
    $states = [];
    try {
        if (function_exists('secretary_daily_task_states')) {
            $states = secretary_daily_task_states($pdo, $userId, $ymd);
        }
    } catch (Throwable $e) {
        $states = [];
    }
    $editable = true;
    try {
        if (function_exists('secretary_daily_task_can_edit')) {
            $editable = secretary_daily_task_can_edit($pdo, $userId, $ymd, true);
        }
    } catch (Throwable $e) {
        $editable = true;
    }

    $name = trim((string) ($user['name'] ?? ''));
    if ($name === '') {
        $name = (string) ($user['username'] ?? 'منشی');
    }
    $done = 0;
    $catalog = secretary_daily_task_catalog();
    foreach ($catalog as $key => $_label) {
        if (!empty($states[$key]['done'])) {
            $done++;
        }
    }
    $dateLabel = function_exists('secretary_daily_task_date_label') ? secretary_daily_task_date_label($ymd) : $ymd;
    $postUrl = url('/secretary/daily-tasks');
    $forceOpen = false;

    ob_start();
    ?>
    <style>
      #daytasks-live[hidden]{display:none !important}
      #daytasks-live{position:fixed;top:0;right:0;bottom:0;left:0;z-index:4000;display:flex;align-items:center;justify-content:center;padding:16px;background:rgba(26,46,40,.55);direction:rtl;text-align:right}
      #daytasks-live .daytasks-card{position:relative;width:min(42rem,100%);max-height:calc(100vh - 32px);overflow:auto;border:0;border-radius:16px;padding:18px 18px 20px;background:#fff;color:#1a2e28;box-shadow:0 18px 50px rgba(26,46,40,.28)}
      #daytasks-live .daytask-time{display:block;margin-top:4px;color:#1f6b45;font-size:.82rem;font-weight:700}
      #daytasks-live .daytask-time[hidden]{display:none}
    </style>
    <div id="daytasks-live"<?= $forceOpen ? '' : ' hidden' ?> onclick="if(event.target===this&&window.closeDayTasks)closeDayTasks()">
      <div class="daytasks-card" role="dialog" aria-modal="true" aria-label="لیست انجام کارهای روزانه">
        <button type="button" aria-label="بستن" onclick="if(window.closeDayTasks)closeDayTasks()" style="position:absolute;top:8px;left:8px;width:36px;height:36px;border:0;background:transparent;font-size:24px;line-height:1;cursor:pointer">×</button>
        <h2 style="margin:0 28px 12px 0;font-size:1.15rem">لیست انجام کارهای روزانه</h2>
        <p style="margin:0 0 12px;line-height:1.7"><b><?= e($name) ?></b><br><?= e($dateLabel) ?><br>انجام‌شده <span id="daytasks-done-count"><?= e(to_fa_digits((string) $done)) ?></span> از <?= e(to_fa_digits((string) count($catalog))) ?></p>
        <?php $n = 0; foreach ($catalog as $key => $label): ?>
          <?php
            $n++;
            $checked = !empty($states[$key]['done']);
            $doneAt = (string) ($states[$key]['done_at'] ?? '');
            $timeLabel = ($checked && $doneAt !== '') ? format_fa_time($doneAt) : '';
          ?>
          <div class="dayshadow-row" data-daytask-row style="margin:0 0 8px;padding:10px 12px;border:1px solid <?= $checked ? '#b7e0c6' : '#d5e0da' ?>;border-radius:12px;background:<?= $checked ? '#e8f6ee' : '#f7f5f0' ?>;line-height:1.75">
            <?php if ($editable): ?>
              <form class="daytask-form" method="post" action="<?= e($postUrl) ?>" onsubmit="return false" style="margin:0">
                <?= csrf_field() ?>
                <input type="hidden" name="task_date" value="<?= e($ymd) ?>">
                <input type="hidden" name="task_key" value="<?= e($key) ?>">
                <input type="hidden" name="done" value="0">
                <label style="display:flex;flex-direction:row;gap:10px;align-items:flex-start;cursor:pointer">
                  <input type="checkbox" name="done" value="1" style="margin-top:6px;width:18px;height:18px;flex:none"<?= $checked ? ' checked' : '' ?>>
                  <span style="flex:1;min-width:0">
                    <span style="display:block"><b><?= e(to_fa_digits((string) $n)) ?></b> <?= e($label) ?></span>
                    <small class="daytask-time"<?= $timeLabel === '' ? ' hidden' : '' ?>><?= $timeLabel !== '' ? 'ساعت تیک: ' . e($timeLabel) : '' ?></small>
                  </span>
                </label>
              </form>
            <?php else: ?>
              <label style="display:flex;flex-direction:row;gap:10px;align-items:flex-start">
                <input type="checkbox" disabled style="margin-top:6px;width:18px;height:18px;flex:none"<?= $checked ? ' checked' : '' ?>>
                <span style="flex:1;min-width:0">
                  <span style="display:block"><b><?= e(to_fa_digits((string) $n)) ?></b> <?= e($label) ?></span>
                  <small class="daytask-time"<?= $timeLabel === '' ? ' hidden' : '' ?>><?= $timeLabel !== '' ? 'ساعت تیک: ' . e($timeLabel) : '' ?></small>
                </span>
              </label>
            <?php endif; ?>
          </div>
        <?php endforeach; ?>
      </div>
    </div>
    <script>
    (function(){
      function faDigits(n){
        return String(n).replace(/\d/g, function(d){ return "۰۱۲۳۴۵۶۷۸۹"[d]; });
      }
      window.openDayTasks = function(){
        var box = document.getElementById("daytasks-live");
        if (!box) return;
        if (box.parentNode !== document.body) document.body.appendChild(box);
        box.hidden = false;
      };
      window.closeDayTasks = function(){
        var box = document.getElementById("daytasks-live");
        if (box) box.hidden = true;
      };
      var box = document.getElementById("daytasks-live");
      if (box && !box.hidden) window.openDayTasks();
      document.addEventListener("keydown", function(e){
        if (e.key === "Escape") window.closeDayTasks();
      });
      document.addEventListener("change", function(e){
        var input = e.target;
        if (!input || !input.form || !input.form.classList || !input.form.classList.contains("daytask-form")) return;
        if (box && !box.contains(input)) return;
        var form = input.form;
        var data = new FormData(form);
        data.set("done", input.checked ? "1" : "0");
        var row = form.closest("[data-daytask-row]");
        var time = form.querySelector(".daytask-time");
        fetch(form.action, {method:"POST", body:data, credentials:"same-origin", headers:{"X-Requested-With":"XMLHttpRequest","Accept":"application/json"}})
          .then(function(r){ if (!r.ok) throw new Error(); return r.json(); })
          .then(function(res){
            var on = !!input.checked && !!(res && res.time);
            if (row) {
              row.style.background = on ? "#e8f6ee" : "#f7f5f0";
              row.style.borderColor = on ? "#b7e0c6" : "#d5e0da";
            }
            if (time) {
              if (on) {
                time.hidden = false;
                time.textContent = "ساعت تیک: " + res.time;
              } else {
                time.hidden = true;
                time.textContent = "";
              }
            }
            var count = document.getElementById("daytasks-done-count");
            if (count && box) {
              count.textContent = faDigits(box.querySelectorAll('.daytask-form input[type="checkbox"]:checked').length);
            }
          })
          .catch(function(){ input.checked = !input.checked; });
      });
    })();
    </script>
    <?php
    return (string) ob_get_clean();
}

function render_secretary_page(string $title, string $innerHtml): void
{
    global $pageScripts, $pageHead, $pdo;
    $nav = secretary_nav();
    $pageTitle = $title;
    $GLOBALS['pageRobots'] = 'noindex,nofollow';
    $user = current_user();
    $shift = ($user && $pdo instanceof PDO) ? staff_current_shift($pdo, (string) $user['id']) : null;
    $clockStart = $shift ? (string) ($shift['started_at'] ?? '') : '';
    if ($shift && $user && $pdo instanceof PDO) {
        try {
            $firstStmt = $pdo->prepare('SELECT MIN(started_at) FROM staff_shifts WHERE user_id=? AND DATE(started_at)=CURDATE()');
            $firstStmt->execute([(string) $user['id']]);
            $firstIn = $firstStmt->fetchColumn();
            if (is_string($firstIn) && $firstIn !== '') {
                $clockStart = $firstIn;
            }
        } catch (Throwable $ignored) {
        }
    }
    ob_start();
    ?>
    <div class="container-page panel-layout">
      <aside class="panel side-nav">
        <p class="side-nav-title">پنل منشی</p>
        <nav>
          <?php
            $currentPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
            foreach ($nav as $item):
              $href = (string) ($item['href'] ?? '');
              $active = $href !== '' && str_contains($currentPath, $href);
          ?>
            <a class="<?= $active ? 'is-active' : '' ?>" href="<?= e(url($href)) ?>">
              <span class="side-nav-link-main">
                <?php if (!empty($item['icon'])): ?>
                  <img class="side-nav-call-logo" src="<?= e((string) $item['icon']) ?>" alt="" width="22" height="22">
                <?php endif; ?>
                <?= e((string) ($item['label'] ?? '')) ?>
              </span>
              <?php if ((int) ($item['badge'] ?? 0) > 0): ?>
                <span class="side-nav-badge<?= (($item['badge_tone'] ?? '') === 'new') ? ' side-nav-badge-new' : '' ?>"><?= e(to_fa_digits((string) (int) $item['badge'])) ?></span>
              <?php endif; ?>
            </a>
          <?php endforeach; ?>
        </nav>
        <?php if ($shift): ?>
          <div class="staff-clock" id="staff-clock" data-started="<?= e($clockStart) ?>" data-regular-start="<?= e(STAFF_REGULAR_START) ?>" data-regular-end="<?= e(STAFF_REGULAR_END) ?>">
            <div class="staff-clock-label">اولین ورود امروز</div>
            <div class="staff-clock-time"><?= e(format_fa_datetime($clockStart)) ?></div>
            <div class="staff-clock-label">مدت حضور</div>
            <div class="staff-clock-elapsed" id="staff-clock-elapsed"><?= e(staff_format_duration(staff_shift_seconds($shift))) ?></div>
            <div class="staff-clock-split" id="staff-clock-split"><?= e(staff_format_split_line(staff_shift_seconds_split($shift), true)) ?></div>
          </div>
        <?php endif; ?>
      </aside>
      <div class="panel-main"><?= $innerHtml ?></div>
    </div>
    <?php
    $content = ob_get_clean();
    require __DIR__ . '/layout.php';
}
