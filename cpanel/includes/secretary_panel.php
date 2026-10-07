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
        ['href' => '/admin/rooms', 'label' => 'اتاق‌ها'],
        ['href' => '/admin/outreach', 'label' => 'مراجعه‌کنندگان قدیمی'],
        ['href' => '/secretary/patients', 'label' => 'مراجعه‌کنندگان'],
        ['href' => '/secretary/profile', 'label' => 'پیام مدیر'],
        ['href' => '/secretary/board', 'label' => 'یادداشت مشترک'],
        ['href' => '/secretary/hours', 'label' => 'ساعت کاری'],
        ['tasks' => true, 'label' => 'کارهای روزانه'],
        ['href' => '/secretary/colleague-messages', 'label' => 'پیام همکار'],
        ['href' => '/secretary/articles', 'label' => 'مقالات'],
        ['href' => '/secretary/workshops', 'label' => 'کارگاه‌ها'],
        ['href' => '/change-password', 'label' => 'تغییر رمز عبور'],
    ];
    $videoLink = function_exists('video_call_nav_link') ? video_call_nav_link() : null;
    if ($videoLink) {
        array_splice($nav, 1, 0, [$videoLink]);
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

    ob_start();
    ?>
    <style>#daytasks-live::backdrop{background:rgba(26,46,40,.55)}</style>
    <dialog id="daytasks-live" style="width:min(42rem,calc(100vw - 32px));max-height:calc(100vh - 32px);overflow:auto;margin:auto;border:0;border-radius:16px;padding:18px 18px 20px;background:#fff;color:#1a2e28;direction:rtl;text-align:right;box-shadow:0 18px 50px rgba(26,46,40,.28)">
        <button type="button" aria-label="بستن" onclick="document.getElementById('daytasks-live').close()" style="position:absolute;top:8px;left:8px;width:36px;height:36px;border:0;background:transparent;font-size:24px;line-height:1;cursor:pointer">×</button>
        <h2 style="margin:0 28px 12px 0;font-size:1.15rem">لیست انجام کارهای روزانه</h2>
        <p style="margin:0 0 12px;line-height:1.7"><b><?= e($name) ?></b><br><?= e($dateLabel) ?><br>انجام‌شده <?= e(to_fa_digits((string) $done)) ?> از <?= e(to_fa_digits((string) count($catalog))) ?></p>
        <?php $n = 0; foreach ($catalog as $key => $label): ?>
          <?php $n++; $checked = !empty($states[$key]['done']); ?>
          <div class="dayshadow-row" style="margin:0 0 8px;padding:10px 12px;border:1px solid #d5e0da;border-radius:12px;background:<?= $checked ? '#e8f6ee' : '#f7f5f0' ?>;line-height:1.75">
            <?php if ($editable): ?>
              <form class="daytask-form" method="post" action="<?= e($postUrl) ?>" onsubmit="return false" style="margin:0">
                <?= csrf_field() ?>
                <input type="hidden" name="task_date" value="<?= e($ymd) ?>">
                <input type="hidden" name="task_key" value="<?= e($key) ?>">
                <input type="hidden" name="done" value="0">
                <label style="display:flex;flex-direction:row;gap:8px;align-items:flex-start;cursor:pointer">
                  <input type="checkbox" name="done" value="1"<?= $checked ? ' checked' : '' ?>>
                  <b><?= e(to_fa_digits((string) $n)) ?></b>
                  <span><?= e($label) ?></span>
                </label>
              </form>
            <?php else: ?>
              <label style="display:flex;flex-direction:row;gap:8px;align-items:flex-start">
                <input type="checkbox" disabled<?= $checked ? ' checked' : '' ?>>
                <b><?= e(to_fa_digits((string) $n)) ?></b>
                <span><?= e($label) ?></span>
              </label>
            <?php endif; ?>
          </div>
        <?php endforeach; ?>
    </dialog>
    <script>
    (function(){
      var box = document.getElementById("daytasks-live");
      if (box && !box.dataset.bound) {
        box.dataset.bound = "1";
        box.addEventListener("click", function(e){
          var r = box.getBoundingClientRect();
          if (e.clientX < r.left || e.clientX > r.right || e.clientY < r.top || e.clientY > r.bottom) box.close();
        });
      }
      document.addEventListener("change", function(e){
        var input = e.target;
        if (!input || !input.form || !input.form.classList || !input.form.classList.contains("daytask-form")) return;
        var form = input.form;
        var data = new FormData(form);
        data.set("done", input.checked ? "1" : "0");
        fetch(form.action, {method:"POST", body:data, credentials:"same-origin", headers:{"X-Requested-With":"XMLHttpRequest","Accept":"application/json"}})
          .then(function(r){ if (!r.ok) throw new Error(); return r.json(); })
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
              $tasks = !empty($item['tasks']);
              $active = !$tasks && $href !== '' && str_contains($currentPath, $href);
          ?>
            <?php if ($tasks): ?>
            <a href="#" onclick="event.preventDefault();var d=document.getElementById('daytasks-live');if(!d)return false;if(d.parentNode!==document.body)document.body.appendChild(d);if(d.open)d.close();else d.showModal();return false;">
            <?php else: ?>
            <a class="<?= $active ? 'is-active' : '' ?>" href="<?= e(url($href)) ?>">
            <?php endif; ?>
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
    $bufferLevel = ob_get_level();
    try {
        echo secretary_panel_daytasks_box($pdo, is_array($user) ? $user : null);
    } catch (Throwable $e) {
        while (ob_get_level() > $bufferLevel) {
            ob_end_clean();
        }
    }
    $content = ob_get_clean();
    require __DIR__ . '/layout.php';
}
