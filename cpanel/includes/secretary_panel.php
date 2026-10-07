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
        ['shadow' => 'sec-day-tasks', 'label' => 'کارهای روزانه'],
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
              $shadow = (string) ($item['shadow'] ?? '');
              $active = $shadow === '' && $href !== '' && str_contains($currentPath, $href);
          ?>
            <?php if ($shadow !== ''): ?>
            <a href="#" data-dayshadow-open="<?= e($shadow) ?>">
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
    if ($user && $pdo instanceof PDO && (string) ($user['role'] ?? '') === 'SECRETARY') {
        require_once __DIR__ . '/secretary_daily_tasks.php';
        $taskDate = date('Y-m-d');
        $taskEditable = secretary_daily_task_can_edit($pdo, (string) $user['id'], $taskDate, true);
        echo secretary_daily_tasks_html($pdo, $user, $taskDate, $taskEditable, url('/secretary/daily-tasks'), false, false, 'sec-day-tasks');
    }
    $content = ob_get_clean();
    require __DIR__ . '/layout.php';
}
