<?php
declare(strict_types=1);

function doctor_nav(): array {
    return [
        ['href' => '/doctor', 'label' => 'خلاصه'],
        ['href' => '/doctor/patients', 'label' => 'پرونده بیماران'],
        ['href' => '/doctor/profile', 'label' => 'پروفایل حرفه‌ای'],
        ['href' => '/doctor/availability', 'label' => 'روزهای خالی'],
        ['href' => '/doctor/appointments', 'label' => 'نوبت‌ها'],
        ['href' => '/doctor/articles', 'label' => 'مقالات'],
    ];
}

function require_doctor_profile(PDO $pdo): array {
    $user = require_login(['DOCTOR']);
    $stmt = $pdo->prepare('SELECT dp.*, u.name, u.email FROM doctor_profiles dp JOIN users u ON u.id=dp.user_id WHERE dp.user_id=?');
    $stmt->execute([$user['id']]);
    $profile = $stmt->fetch();
    if (!$profile) {
        flash_set('error', 'پروفایل دکتر یافت نشد.');
        redirect('/');
    }
    return ['user' => $user, 'profile' => $profile];
}

function render_doctor_page(string $title, string $innerHtml): void {
    global $pageScripts, $pageHead;
    $nav = doctor_nav();
    $pageTitle = $title;
    ob_start();
    ?>
    <div class="container-page panel-layout">
      <aside class="panel side-nav">
        <p class="side-nav-title">پنل دکتر</p>
        <nav><?php foreach ($nav as $item): ?><a href="<?= e(url($item['href'])) ?>"><?= e($item['label']) ?></a><?php endforeach; ?></nav>
        <p class="muted" style="font-size:.75rem;margin-top:1rem;line-height:1.6">پرونده بیماران فقط برای شما قابل مشاهده است.</p>
      </aside>
      <div><?= $innerHtml ?></div>
    </div>
    <?php
    $content = ob_get_clean();
    require __DIR__ . '/layout.php';
}
