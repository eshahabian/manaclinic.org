<?php
declare(strict_types=1);

function secretary_nav(): array
{
    return [
        ['href' => '/secretary', 'label' => 'خلاصه'],
        ['href' => '/secretary/book', 'label' => 'رزرو برای بیمار'],
        ['href' => '/secretary/appointments', 'label' => 'نوبت‌ها'],
    ];
}

function render_secretary_page(string $title, string $innerHtml): void
{
    global $pageScripts, $pageHead;
    $nav = secretary_nav();
    $pageTitle = $title;
    ob_start();
    ?>
    <div class="container-page panel-layout">
      <aside class="panel side-nav">
        <p class="side-nav-title">پنل منشی</p>
        <nav>
          <?php foreach ($nav as $item): ?>
            <a href="<?= e(url($item['href'])) ?>"><?= e($item['label']) ?></a>
          <?php endforeach; ?>
        </nav>
      </aside>
      <div><?= $innerHtml ?></div>
    </div>
    <?php
    $content = ob_get_clean();
    require __DIR__ . '/layout.php';
}
