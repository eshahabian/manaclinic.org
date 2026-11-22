<?php
declare(strict_types=1);

/** Arrange existing links in sidebar sections while preserving their metadata. */
function group_panel_nav(array $nav, array $sections): array
{
    $links = [];
    foreach ($nav as $item) {
        if (isset($item['href'])) {
            $links[$item['href']] = $item;
        }
    }
    $grouped = [];
    foreach ($sections as $label => $paths) {
        $items = [];
        foreach ($paths as $path) {
            if (isset($links[$path])) {
                $items[] = $links[$path];
                unset($links[$path]);
            }
        }
        if ($items) {
            $grouped[] = ['type' => 'group', 'label' => $label];
            array_push($grouped, ...$items);
        }
    }
    if ($links) {
        $grouped[] = ['type' => 'group', 'label' => 'سایر امکانات'];
        array_push($grouped, ...array_values($links));
    }
    return $grouped;
}

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
    $GLOBALS['hideTestsSidebar'] = true;
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
