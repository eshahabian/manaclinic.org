<?php
declare(strict_types=1);

function admin_nav(): array {
    return [
        ['href' => '/admin', 'label' => 'خلاصه'],
        ['href' => '/admin/doctors', 'label' => 'مدیریت دکترها'],
        ['href' => '/admin/users', 'label' => 'کاربران'],
        ['href' => '/admin/articles', 'label' => 'مقالات'],
        ['href' => '/admin/appointments', 'label' => 'نوبت‌ها و پرداخت‌ها'],
    ];
}

function render_admin_page(string $title, string $innerHtml): void {
    $nav = admin_nav();
    $pageTitle = $title;
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
