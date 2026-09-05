<?php
declare(strict_types=1);

function secretary_nav(): array
{
    return [
        ['href' => '/secretary/messages', 'label' => 'پیام‌ها'],
        ['href' => '/secretary/patients', 'label' => 'مراجعه‌کنندگان'],
        ['href' => '/secretary/appointments', 'label' => 'نوبت‌ها'],
        ['href' => '/secretary/workshops', 'label' => 'کارگاه‌ها'],
        ['href' => '/secretary/articles', 'label' => 'مقالات'],
        ['href' => '/secretary/hours', 'label' => 'ساعت کاری'],
    ];
}

function render_secretary_page(string $title, string $innerHtml): void
{
    global $pageScripts, $pageHead, $pdo;
    $nav = secretary_nav();
    $pageTitle = $title;
    $GLOBALS['pageRobots'] = 'noindex,nofollow';
    $user = current_user();
    $shift = ($user && $pdo instanceof PDO) ? staff_current_shift($pdo, (string) $user['id']) : null;
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
        <?php if ($shift): ?>
          <div class="staff-clock" id="staff-clock" data-started="<?= e((string) $shift['started_at']) ?>">
            <div class="staff-clock-label">ورود امروز</div>
            <div class="staff-clock-time"><?= e(format_fa_datetime((string) $shift['started_at'])) ?></div>
            <div class="staff-clock-label">مدت حضور</div>
            <div class="staff-clock-elapsed" id="staff-clock-elapsed"><?= e(staff_format_duration(staff_shift_seconds($shift))) ?></div>
          </div>
        <?php endif; ?>
      </aside>
      <div><?= $innerHtml ?></div>
    </div>
    <?php
    $content = ob_get_clean();
    require __DIR__ . '/layout.php';
}
