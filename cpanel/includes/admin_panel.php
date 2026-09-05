<?php
declare(strict_types=1);

function admin_nav(): array {
    return [
        ['href' => '/admin', 'label' => 'خلاصه'],
        ['href' => '/admin/users', 'label' => 'کاربران'],
        ['href' => '/admin/appointments', 'label' => 'نوبت‌ها'],
        ['href' => '/admin/messages', 'label' => 'پیام‌ها'],
        ['href' => '/admin/doctors', 'label' => 'درمانگرها'],
        ['href' => '/admin/articles', 'label' => 'مقالات'],
        ['href' => '/secretary/messages', 'label' => 'پنل منشی'],
        ['href' => '/doctor', 'label' => 'پنل دکتر'],
        ['href' => '/change-password', 'label' => 'تغییر رمز عبور'],
    ];
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
            <?php
              $href = (string) $item['href'];
              $active = $href === '/admin'
                ? ($currentPath === $href || str_ends_with($currentPath, '/admin'))
                : str_contains($currentPath, $href);
            ?>
            <a class="<?= $active ? 'is-active' : '' ?>" href="<?= e(url($href)) ?>"><?= e($item['label']) ?></a>
          <?php endforeach; ?>
        </nav>
      </aside>
      <div class="panel-main"><?= $innerHtml ?></div>
    </div>
    <?php
    $content = ob_get_clean();
    require __DIR__ . '/layout.php';
}
