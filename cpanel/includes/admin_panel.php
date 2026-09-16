<?php
declare(strict_types=1);

function admin_pending_doctor_count(): int
{
    global $pdo;
    if (!$pdo instanceof PDO) {
        return 0;
    }
    try {
        return (int) $pdo->query('SELECT COUNT(*) FROM doctor_profiles WHERE is_approved=0')->fetchColumn();
    } catch (Throwable $ignored) {
        return 0;
    }
}

function admin_nav(): array {
    $unread = 0;
    global $pdo;
    $user = current_user();
    if ($pdo instanceof PDO && $user && function_exists('count_unread_notifications')) {
        $unread = count_unread_notifications($pdo, (string) ($user['id'] ?? ''));
    }
    $nav = [
        ['type' => 'group', 'label' => 'اصلی'],
        ['href' => '/admin', 'label' => 'خلاصه'],
        ['href' => '/admin/users', 'label' => 'کاربران'],
        ['href' => '/admin/doctors', 'label' => 'درمانگرها', 'badge' => admin_pending_doctor_count()],
        ['href' => '/admin/appointments', 'label' => 'نوبت‌ها'],
        ['href' => '/admin/staff-messages', 'label' => 'پیام‌ها'],
        ['type' => 'group', 'label' => 'منشی‌ها'],
        ['href' => '/secretary/messages', 'label' => 'پنل منشی'],
        ['href' => '/admin/secretary-messages', 'label' => 'پیام منشی‌ها', 'badge' => function_exists('secretary_to_admin_unread_count') && $pdo instanceof PDO ? secretary_to_admin_unread_count($pdo) : 0],
        ['href' => '/admin/staff-board', 'label' => 'یادداشت مشترک منشی‌ها'],
        ['href' => '/admin/staff-hours', 'label' => 'ساعت کاری منشی‌ها'],
        ['type' => 'group', 'label' => 'درمانگرها'],
        ['href' => '/doctor/notifications', 'label' => 'اعلان‌ها', 'badge' => $unread, 'badge_tone' => 'new'],
        ['href' => '/doctor/appointments', 'label' => 'نوبت‌های درمانگر'],
        ['href' => '/doctor/availability', 'label' => 'روزهای خالی'],
        ['href' => '/doctor/patients', 'label' => 'پرونده مراجعه‌کنندگان'],
        ['href' => '/doctor/staff-messages', 'label' => 'پیام‌های درمانگر'],
        ['href' => '/doctor/profile', 'label' => 'پروفایل حرفه‌ای'],
        ['type' => 'group', 'label' => 'محتوا'],
        ['href' => '/admin/articles', 'label' => 'مقالات'],
        ['href' => '/doctor/articles', 'label' => 'مقالات درمانگر'],
        ['href' => '/doctor/workshops', 'label' => 'کارگاه‌ها'],
        ['type' => 'group', 'label' => 'تنظیمات'],
        ['href' => '/admin/mail', 'label' => 'ایمیل و SMTP'],
        ['href' => '/change-password', 'label' => 'تغییر رمز عبور'],
    ];
    $videoLink = function_exists('video_call_nav_link') ? video_call_nav_link() : null;
    if ($videoLink) {
        // بعد از «خلاصه» داخل گروه اصلی
        array_splice($nav, 2, 0, [$videoLink]);
    }

    return $nav;
}

function render_admin_page(string $title, string $innerHtml): void {
    global $pageScripts, $pageHead;
    $nav = admin_nav();
    $pageTitle = $title;
    $GLOBALS['pageRobots'] = 'noindex,nofollow';
    $currentPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
    ob_start();
    ?>
    <div class="container-page panel-layout">
      <aside class="panel side-nav">
        <p class="side-nav-title">پنل ادمین</p>
        <nav>
          <?php foreach ($nav as $item): ?>
            <?php if (($item['type'] ?? 'link') === 'group'): ?>
              <p class="side-nav-group"><?= e($item['label']) ?></p>
            <?php else: ?>
              <?php
                $href = (string) $item['href'];
                if ($href === '/admin') {
                    $active = $currentPath === $href || str_ends_with($currentPath, '/admin');
                } elseif ($href === '/doctor') {
                    $active = $currentPath === $href || str_ends_with($currentPath, '/doctor');
                } else {
                    $active = str_contains($currentPath, $href);
                }
              ?>
              <a class="<?= $active ? 'is-active' : '' ?>" href="<?= e(url($href)) ?>">
                <span class="side-nav-link-main">
                  <?php if (!empty($item['icon'])): ?>
                    <img class="side-nav-call-logo" src="<?= e((string) $item['icon']) ?>" alt="" width="22" height="22">
                  <?php endif; ?>
                  <?= e($item['label']) ?>
                </span>
                <?php if ((int) ($item['badge'] ?? 0) > 0): ?>
                  <span class="side-nav-badge<?= (($item['badge_tone'] ?? '') === 'new') ? ' side-nav-badge-new' : '' ?>"><?= e(to_fa_digits((string) (int) $item['badge'])) ?></span>
                <?php endif; ?>
              </a>
            <?php endif; ?>
          <?php endforeach; ?>
        </nav>
      </aside>
      <div class="panel-main"><?= $innerHtml ?></div>
    </div>
    <?php
    $content = ob_get_clean();
    require __DIR__ . '/layout.php';
}
