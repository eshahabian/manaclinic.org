<?php
declare(strict_types=1);

require_once __DIR__ . '/workshops.php';

function patient_nav(): array
{
    return [
        ['href' => '/dashboard', 'label' => 'خلاصه'],
        ['href' => '/dashboard/appointments', 'label' => 'نوبت‌های من'],
        ['href' => '/doctors', 'label' => 'رزرو نوبت جدید'],
        ['href' => '/dashboard/courses', 'label' => 'دوره‌های من'],
        ['href' => '/dashboard/wallet', 'label' => 'کیف پول'],
        ['href' => '/dashboard/profile', 'label' => 'پروفایل'],
    ];
}

function render_patient_page(string $title, string $innerHtml): void
{
    global $pdo;

    $nav = patient_nav();
    $coursesBadge = 0;
    $user = current_user();
    if ($pdo && $user && ($user['role'] ?? '') === 'PATIENT') {
        $coursesBadge = patient_courses_new_count($pdo, (string) $user['id']);
    }

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
            <a href="<?= e(url($item['href'])) ?>">
              <?= e($item['label']) ?>
              <?php if ($item['href'] === '/dashboard/courses' && $coursesBadge > 0): ?>
                <span class="side-nav-badge"><?= (int) $coursesBadge ?></span>
              <?php endif; ?>
            </a>
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
