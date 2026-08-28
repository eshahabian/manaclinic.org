<?php
declare(strict_types=1);

function render(string $viewFile, array $vars = []): void
{
    extract($vars, EXTR_SKIP);
    ob_start();
    require $viewFile;
    $content = ob_get_clean();
    require __DIR__ . '/layout.php';
}

function render_panel(string $title, array $nav, string $viewFile, array $vars = []): void
{
    $vars['panelTitle'] = $title;
    $vars['panelNav'] = $nav;
    extract($vars, EXTR_SKIP);
    ob_start();
    ?>
    <div class="container-page panel-layout">
      <aside class="panel side-nav">
        <p class="side-nav-title"><?= e($title) ?></p>
        <nav>
          <?php foreach ($nav as $item): ?>
            <a href="<?= e(url($item['href'])) ?>"><?= e($item['label']) ?></a>
          <?php endforeach; ?>
        </nav>
      </aside>
      <div class="panel-main">
        <?php require $viewFile; ?>
      </div>
    </div>
    <?php
    $content = ob_get_clean();
    require __DIR__ . '/layout.php';
}
