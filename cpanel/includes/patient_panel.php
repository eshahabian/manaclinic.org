<?php
declare(strict_types=1);

require_once __DIR__ . '/workshops.php';

function patient_request_path(): string
{
    $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
    global $base;
    if ($base && $base !== '/' && str_starts_with($path, rtrim($base, '/'))) {
        $path = substr($path, strlen(rtrim($base, '/'))) ?: '/';
    }
    if ($path === '') {
        $path = '/';
    }
    return $path;
}

function patient_nav(): array
{
    $nav = [
        ['href' => '/dashboard', 'label' => 'خلاصه'],
        ['href' => '/dashboard/appointments', 'label' => 'نوبت‌های من'],
        ['href' => '/doctors', 'label' => 'رزرو نوبت جدید'],
        [
            'href' => '/dashboard/workshops',
            'label' => 'کارگاه‌ها',
            'children' => [
                ['href' => '/dashboard/workshops/requested', 'label' => 'دوره‌های درخواست داده‌شده', 'badge' => 'requested'],
                ['href' => '/dashboard/workshops/mine', 'label' => 'دوره‌های من', 'badge' => 'mine'],
            ],
        ],
        ['href' => '/dashboard/wallet', 'label' => 'کیف پول'],
        ['href' => '/dashboard/profile', 'label' => 'پروفایل'],
        ['href' => '/change-password', 'label' => 'تغییر رمز عبور'],
    ];
    $videoLink = function_exists('video_call_nav_link') ? video_call_nav_link() : null;
    if ($videoLink) {
        array_splice($nav, -1, 0, [$videoLink]);
    }

    return $nav;
}

function patient_nav_is_active(string $href, string $currentPath, bool $exact = false): bool
{
    if ($href === '/dashboard') {
        return $currentPath === '/dashboard' || $currentPath === '/dashboard/';
    }
    if ($exact) {
        return $currentPath === $href || $currentPath === rtrim($href, '/');
    }
    return $currentPath === $href || str_starts_with($currentPath, rtrim($href, '/') . '/');
}

function render_patient_page(string $title, string $innerHtml): void
{
    global $pdo;

    $nav = patient_nav();
    $counts = ['available' => 0, 'requested' => 0, 'mine' => 0];
    $user = current_user();
    if ($pdo && $user && ($user['role'] ?? '') === 'PATIENT') {
        $counts = patient_workshop_nav_counts($pdo, (string) $user['id']);
    }
    $currentPath = patient_request_path();

    if ($title !== '') {
        $GLOBALS['pageTitle'] = $title;
    }
    ob_start();
    ?>
    <div class="container-page panel-layout">
      <aside class="panel side-nav">
        <p class="side-nav-title">پنل مراجعه‌کننده</p>
        <nav>
          <?php foreach ($nav as $item): ?>
            <?php
              $href = (string) ($item['href'] ?? '');
              $children = $item['children'] ?? [];
              $parentActive = patient_nav_is_active($href, $currentPath, $children === []);
              if ($children) {
                  foreach ($children as $child) {
                      if (patient_nav_is_active((string) $child['href'], $currentPath, true)) {
                          $parentActive = true;
                      }
                  }
              }
            ?>
            <a class="<?= $parentActive ? 'is-active' : '' ?>" href="<?= e(url($href)) ?>">
              <?= e((string) $item['label']) ?>
              <?php if ($href === '/dashboard/workshops' && $counts['available'] > 0): ?>
                <span class="side-nav-badge"><?= (int) $counts['available'] ?></span>
              <?php endif; ?>
            </a>
            <?php if ($children): ?>
              <div class="side-nav-sub">
                <?php foreach ($children as $child): ?>
                  <?php
                    $childHref = (string) $child['href'];
                    $childActive = patient_nav_is_active($childHref, $currentPath, true);
                    if ($childHref === '/dashboard/workshops/mine' && str_starts_with($currentPath, '/dashboard/workshops/path')) {
                        $childActive = true;
                    }
                    $badgeKey = (string) ($child['badge'] ?? '');
                    $badge = $badgeKey !== '' ? (int) ($counts[$badgeKey] ?? 0) : 0;
                  ?>
                  <a class="<?= $childActive ? 'is-active' : '' ?>" href="<?= e(url($childHref)) ?>">
                    <?= e((string) $child['label']) ?>
                    <?php if ($badge > 0): ?>
                      <span class="side-nav-badge"><?= $badge ?></span>
                    <?php endif; ?>
                  </a>
                <?php endforeach; ?>
              </div>
            <?php endif; ?>
          <?php endforeach; ?>
        </nav>
      </aside>
      <div class="panel-main"><?= $innerHtml ?></div>
    </div>
    <?php
    $GLOBALS['content'] = ob_get_clean();
    require __DIR__ . '/layout.php';
}

function finish_patient_or_public_page(string $title, string $innerHtml): void
{
    $user = current_user();
    if ($user && ($user['role'] ?? '') === 'PATIENT') {
        render_patient_page($title, $innerHtml);
        return;
    }
    $GLOBALS['pageTitle'] = $title;
    $GLOBALS['content'] = $innerHtml;
    require __DIR__ . '/layout.php';
}
