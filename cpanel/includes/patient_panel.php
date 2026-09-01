<?php
declare(strict_types=1);

function patient_nav(): array
{
    return [
        ['href' => '/dashboard', 'label' => 'خلاصه'],
        ['href' => '/dashboard/appointments', 'label' => 'نوبت‌های من'],
        ['href' => '/dashboard/profile', 'label' => 'پروفایل'],
        ['href' => '/doctors', 'label' => 'رزرو نوبت جدید'],
    ];
}

function render_patient_page(string $title, string $innerHtml): void
{
    $nav = patient_nav();
    if ($title !== '') {
        $GLOBALS['pageTitle'] = $title;
    }
    ob_start();
    ?>
    <div class="container-page panel-layout">
      <aside class="panel side-nav">
        <p class="side-nav-title">پنل مراجع</p>
        <nav><?php foreach ($nav as $item): ?><a href="<?= e(url($item['href'])) ?>"><?= e($item['label']) ?></a><?php endforeach; ?></nav>
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
