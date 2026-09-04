<?php
declare(strict_types=1);

function doctor_nav(): array
{
    return [
        ['type' => 'link', 'href' => '/doctor', 'label' => 'خلاصه'],
        ['type' => 'group', 'label' => 'گفتگو و پیام'],
        ['type' => 'link', 'href' => '/doctor/intakes', 'label' => 'گفتگوهای دستیار'],
        ['type' => 'group', 'label' => 'نوبت و پرونده'],
        ['type' => 'link', 'href' => '/doctor/appointments', 'label' => 'نوبت‌ها'],
        ['type' => 'link', 'href' => '/doctor/availability', 'label' => 'روزهای خالی'],
        ['type' => 'link', 'href' => '/doctor/patients', 'label' => 'پرونده بیماران'],
        ['type' => 'group', 'label' => 'کارگاه و محتوا'],
        ['type' => 'link', 'href' => '/doctor/workshops', 'label' => 'کارگاه‌ها'],
        ['type' => 'link', 'href' => '/doctor/articles', 'label' => 'مقالات'],
        ['type' => 'group', 'label' => 'حساب'],
        ['type' => 'link', 'href' => '/doctor/profile', 'label' => 'پروفایل حرفه‌ای'],
    ];
}

function require_doctor_profile(PDO $pdo): array
{
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

function render_doctor_page(string $title, string $innerHtml): void
{
    global $pageScripts, $pageHead;
    $nav = doctor_nav();
    $pageTitle = $title;
    $currentPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
    ob_start();
    ?>
    <div class="container-page panel-layout">
      <aside class="panel side-nav doctor-side-nav">
        <p class="side-nav-title">پنل دکتر</p>
        <nav>
          <?php foreach ($nav as $item): ?>
            <?php if (($item['type'] ?? 'link') === 'group'): ?>
              <p class="side-nav-group"><?= e($item['label']) ?></p>
            <?php else: ?>
              <?php
                $href = (string) $item['href'];
                $active = $href === '/doctor'
                  ? ($currentPath === $href || str_ends_with($currentPath, '/doctor'))
                  : (str_contains($currentPath, $href));
              ?>
              <a class="<?= $active ? 'is-active' : '' ?>" href="<?= e(url($href)) ?>"><?= e($item['label']) ?></a>
            <?php endif; ?>
          <?php endforeach; ?>
        </nav>
        <p class="muted" style="font-size:.75rem;margin-top:1rem;line-height:1.6">پرونده بیماران فقط برای شما قابل مشاهده است.</p>
      </aside>
      <div class="panel-main"><?= $innerHtml ?></div>
    </div>
    <?php
    $content = ob_get_clean();
    require __DIR__ . '/layout.php';
}
