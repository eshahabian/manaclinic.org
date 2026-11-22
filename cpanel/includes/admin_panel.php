<?php
declare(strict_types=1);

require_once __DIR__ . '/view.php';

function admin_disabled_user_count(): int
{
    global $pdo;
    if (!$pdo instanceof PDO) {
        return 0;
    }
    try {
        return (int) $pdo->query('SELECT COUNT(*) FROM users WHERE is_disabled=1')->fetchColumn();
    } catch (Throwable $ignored) {
        return 0;
    }
}

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
    if (!function_exists('clinic_rooms_user_allowed') && is_file(__DIR__ . '/clinic_rooms.php')) {
        require_once __DIR__ . '/clinic_rooms.php';
    }
    if ($pdo instanceof PDO && $user && function_exists('count_unread_notifications')) {
        $unread = count_unread_notifications($pdo, (string) ($user['id'] ?? ''));
    }
    $nav = [
        ['href' => '/admin', 'label' => 'خلاصه'],
    ];
    if ($user && strcasecmp(trim((string) ($user['username'] ?? '')), 'eshahabian') === 0) {
        $nav[] = ['href' => '/staff/excel-reports', 'label' => 'خروجی اکسل'];
        $nav[] = ['href' => '/dashboard/appointments', 'label' => 'نوبت‌های من'];
        $nav[] = ['href' => '/dashboard/path', 'label' => 'اتاق ذهن'];
        if (!function_exists('patient_wallet_paused') || !patient_wallet_paused()) {
            $nav[] = ['href' => '/dashboard/wallet', 'label' => 'کیف پول'];
        }
    }
    $nav[] = ['href' => '/admin/feedback', 'label' => 'انتقادات و پیشنهادات'];
    $nav[] = [
        'href' => '/admin/consult-requests',
        'label' => 'درخواست مشاوره',
        'badge' => function_exists('consult_request_new_count') ? consult_request_new_count() : 0,
        'badge_tone' => 'new',
    ];
    if (!function_exists('sms_operator_allowed') && is_file(__DIR__ . '/outreach.php')) {
        require_once __DIR__ . '/outreach.php';
    }
    if ($user && function_exists('outreach_user_allowed') && outreach_user_allowed($user)) {
        $nav[] = ['href' => '/admin/outreach', 'label' => 'مراجعه‌کنندگان قدیمی'];
    }
    if (!function_exists('clinic_can_add_therapist') && is_file(__DIR__ . '/doctor_profile_fields.php')) {
        require_once __DIR__ . '/doctor_profile_fields.php';
    }
    if ($user && (string) ($user['role'] ?? '') === 'ADMIN' && function_exists('clinic_can_add_therapist') && clinic_can_add_therapist($user)) {
        $nav[] = ['href' => '/secretary/therapists', 'label' => 'درمانگرها و روزها'];
    }
    $nav = array_merge($nav, [
        ['href' => '/admin/doctors', 'label' => 'درمانگرها', 'badge' => admin_pending_doctor_count()],
        ['href' => '/admin/appointments', 'label' => 'نوبت‌ها'],
    ]);
    if ($user && function_exists('clinic_rooms_user_allowed') && clinic_rooms_user_allowed($user)) {
        $nav[] = ['href' => '/admin/rooms', 'label' => 'اتاق‌ها'];
    }
    $nav = array_merge($nav, [
        [
            'href' => '/admin/staff-messages',
            'label' => 'پیام‌ها',
            'badge' => (function_exists('mentions_unread_count') && $pdo instanceof PDO && $user)
                ? mentions_unread_count($pdo, (string) ($user['id'] ?? ''))
                : 0,
            'badge_tone' => 'new',
        ],
    ]);
    $nav = array_merge($nav, [
        ['href' => '/secretary/messages', 'label' => 'پنل منشی'],
        ['href' => '/admin/secretary-messages', 'label' => 'پیام منشی‌ها', 'badge' => function_exists('secretary_to_admin_unread_count') && $pdo instanceof PDO ? secretary_to_admin_unread_count($pdo) : 0],
        ['href' => '/admin/staff-board', 'label' => 'یادداشت مشترک منشی‌ها'],
        ['href' => '/admin/staff-hours', 'label' => 'ساعت کاری منشی‌ها'],
        ['href' => '/admin/workshop-file-log', 'label' => 'حذف و دانلود فایل کارگاه'],
        ['href' => '/doctor/notifications', 'label' => 'اعلان‌ها', 'badge' => $unread, 'badge_tone' => 'new'],
        ['href' => '/doctor/appointments', 'label' => 'نوبت‌های درمانگر'],
        ['href' => '/doctor/availability', 'label' => 'روزهای خالی'],
        ['href' => '/doctor/patients', 'label' => 'پرونده مراجعه‌کنندگان'],
        ['href' => '/doctor/staff-messages', 'label' => 'پیام‌های درمانگر'],
        ['href' => '/doctor/profile', 'label' => 'پروفایل حرفه‌ای'],
        ['href' => '/admin/articles', 'label' => 'مقالات'],
        ['href' => '/doctor/articles', 'label' => 'مقالات درمانگر'],
        ['href' => '/doctor/workshops', 'label' => 'کارگاه‌ها'],
    ]);
    $siteAdmin = $user && in_array(strtolower(trim((string) ($user['username'] ?? ''))), ['admin','eshahabian'], true);
    if ($siteAdmin) {
        $nav[] = ['href' => '/admin/messengers', 'label' => 'دسترسی پیام رسان'];
        $nav[] = ['href' => '/admin/users', 'label' => 'مدیریت کاربران'];
        $nav[] = ['href' => '/admin/users-disabled', 'label' => 'کاربران غیر فعال شده', 'badge' => admin_disabled_user_count()];
        $nav[] = ['href' => '/admin/mail', 'label' => 'ایمیل و SMTP'];
        $nav[] = ['href' => '/admin/gateway', 'label' => 'درگاه پرداخت'];
    }
    $nav[] = ['href' => '/change-password', 'label' => 'تغییر رمز عبور'];
    if (sms_operator_allowed($user)) {
        $nav[]=['href'=>'/admin/sms-test','label'=>'تست پیامک'];
        $nav[]=['href'=>'/admin/sms-broadcast','label'=>'پیامک همگانی'];
        if (sms_settings_allowed($user)) {
            $nav[]=['href'=>'/admin/sms-notify','label'=>'مدیریت پیامک‌ها'];
            $nav[]=['href'=>'/admin/sms-doctor-digest','label'=>'برنامه روزانه درمانگرها'];
            $nav[]=['href'=>'/admin/sms-settings','label'=>'تنظیمات پیامک'];
        }
    }
    if (!function_exists('secretary_daily_tasks_can_review') && is_file(__DIR__ . '/secretary_daily_tasks.php')) {
        require_once __DIR__ . '/secretary_daily_tasks.php';
    }
    if ($user && function_exists('secretary_daily_tasks_can_review') && secretary_daily_tasks_can_review($user)) {
        $nav[] = ['href' => '/admin/secretary-tasks', 'label' => 'وظایف منشی‌ها'];
    }
    $videoLink = function_exists('video_call_nav_link') ? video_call_nav_link() : null;
    if ($videoLink) {
        $nav[] = $videoLink;
    }

    return group_panel_nav($nav, [
        'اصلی' => ['/admin', '/admin/consult-requests', '/admin/feedback', '/dashboard/appointments', '/dashboard/path', '/dashboard/wallet'],
        'نوبت‌ها و مراجعه‌کنندگان' => ['/admin/appointments', '/doctor/appointments', '/doctor/availability', '/doctor/patients', '/admin/outreach'],
        'پیام‌ها و اعلان‌ها' => ['/admin/staff-messages', '/doctor/staff-messages', '/doctor/notifications', '/video-call'],
        'منشی‌ها' => ['/secretary/messages', '/admin/secretary-messages', '/admin/staff-board', '/admin/staff-hours', '/admin/secretary-tasks'],
        'محتوا' => ['/admin/articles', '/doctor/articles', '/doctor/workshops', '/admin/workshop-file-log', '/staff/excel-reports'],
        'مدیریت' => ['/admin/doctors', '/secretary/therapists', '/admin/rooms', '/admin/users', '/admin/users-disabled', '/admin/messengers', '/admin/mail', '/admin/gateway'],
        'پیامک' => ['/admin/sms-test', '/admin/sms-broadcast', '/admin/sms-notify', '/admin/sms-doctor-digest', '/admin/sms-settings'],
        'تنظیمات' => ['/doctor/profile', '/change-password'],
    ]);
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
                } elseif (in_array($href, ['/admin/users', '/admin/users-disabled'], true)) {
                    $active = $currentPath === $href || str_ends_with($currentPath, $href);
                } else {
                    $active = str_contains($currentPath, $href);
                }
              ?>
              <a class="<?= $active ? 'is-active' : '' ?>" href="<?= e(url($href)) ?>" <?= $href === '/staff/excel-reports' ? 'data-excel-report="menu" aria-haspopup="dialog"' : '' ?>>
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
