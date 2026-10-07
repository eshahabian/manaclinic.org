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
            <a href="#" data-daytasks-open="1">
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
    <?php if ($user && $pdo instanceof PDO && (string) ($user['role'] ?? '') === 'SECRETARY'): ?>
    <?php
        require_once __DIR__ . '/secretary_daily_tasks.php';
        $taskDate = date('Y-m-d');
        $taskUserId = (string) ($user['id'] ?? '');
    ?>
    <div id="daytasks-live" style="display:none !important">
      <div id="daytasks-card" style="position:relative;width:min(42rem,100%);max-height:calc(100vh - 32px);overflow:auto;background:#fff;color:#1a2e28;border-radius:16px;padding:18px 18px 20px;direction:rtl;text-align:right;box-shadow:0 18px 50px rgba(26,46,40,.28)">
        <?= secretary_daily_tasks_fragment($pdo, $user, $taskDate, secretary_daily_task_can_edit($pdo, $taskUserId, $taskDate, true), url('/secretary/daily-tasks')) ?>
      </div>
    </div>
    <script>
    (function(){
      if (window.__daytasksNav) return;
      window.__daytasksNav = true;
      var box = document.getElementById("daytasks-live");
      var card = document.getElementById("daytasks-card");
      if (card) card.addEventListener("click", function(ev){
        var shut = ev.target.closest ? ev.target.closest("[data-daytasks-close]") : null;
        if (shut) { hideBox(); return; }
        ev.stopPropagation();
      });
      function hideBox(){
        if (!box) return;
        box.style.setProperty("display", "none", "important");
      }
      function showBox(){
        if (!box) return;
        if (box.parentNode !== document.body) document.body.appendChild(box);
        box.style.setProperty("display", "flex", "important");
        box.style.setProperty("position", "fixed", "important");
        box.style.setProperty("top", "0", "important");
        box.style.setProperty("right", "0", "important");
        box.style.setProperty("bottom", "0", "important");
        box.style.setProperty("left", "0", "important");
        box.style.setProperty("z-index", "5000", "important");
        box.style.setProperty("align-items", "center", "important");
        box.style.setProperty("justify-content", "center", "important");
        box.style.setProperty("padding", "16px", "important");
        box.style.setProperty("background", "rgba(26,46,40,.55)", "important");
        box.style.setProperty("direction", "rtl", "important");
      }
      document.addEventListener("click", function(e){
        var open = e.target.closest ? e.target.closest("[data-daytasks-open]") : null;
        if (open) {
          if (e.preventDefault) e.preventDefault();
          if (box && box.style.display === "flex") hideBox();
          else showBox();
          return;
        }
        if (e.target === box) hideBox();
        var shut = e.target.closest ? e.target.closest("[data-daytasks-close]") : null;
        if (shut) hideBox();
      });
      document.addEventListener("change", function(e){
        var input = e.target;
        if (!input || !input.form || !input.form.classList || !input.form.classList.contains("daytask-form")) return;
        var form = input.form;
        var data = new FormData(form);
        data.set("done", input.checked ? "1" : "0");
        var row = form.closest(".dayshadow-row");
        fetch(form.action, {method:"POST", body:data, credentials:"same-origin", headers:{"X-Requested-With":"XMLHttpRequest","Accept":"application/json"}})
          .then(function(r){ if (!r.ok) throw new Error(); return r.json(); })
          .then(function(){ if (row) row.classList.toggle("is-done", !!input.checked); })
          .catch(function(){ input.checked = !input.checked; });
      });
    })();
    </script>
    <?php endif; ?>
    <?php
    $content = ob_get_clean();
    require __DIR__ . '/layout.php';
}
