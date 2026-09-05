<?php
declare(strict_types=1);

function admin_nav(): array {
    return [
        ['href' => '/admin', 'label' => 'خلاصه'],
        ['href' => '/admin/users', 'label' => 'کاربران و رمز عبور'],
        ['href' => '/admin/appointments', 'label' => 'نوبت‌ها و پرداخت‌ها'],
        ['href' => '/admin/messages', 'label' => 'پیام‌ها'],
        ['href' => '/admin/doctors', 'label' => 'مدیریت درمانگرها'],
        ['href' => '/admin/articles', 'label' => 'مقالات'],
        ['href' => '/secretary/messages', 'label' => 'پنل منشی'],
        ['href' => '/doctor', 'label' => 'پنل دکتر'],
        ['href' => '/change-password', 'label' => 'تغییر رمز عبور من'],
    ];
}

function render_admin_page(string $title, string $innerHtml): void {
    $nav = admin_nav();
    $pageTitle = $title;
    $GLOBALS['pageRobots'] = 'noindex,nofollow';
    ob_start();
    ?>
    <div class="container-page panel-layout">
      <aside class="panel side-nav">
        <p class="side-nav-title">پنل ادمین</p>
        <nav><?php foreach ($nav as $item): ?><a href="<?= e(url($item['href'])) ?>"><?= e($item['label']) ?></a><?php endforeach; ?></nav>
      </aside>
      <div><?= $innerHtml ?></div>
    </div>
    <?php
    $content = ob_get_clean();
    require __DIR__ . '/layout.php';
}
