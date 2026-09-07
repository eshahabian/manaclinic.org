<?php
declare(strict_types=1);

function doctor_nav(): array
{
    return [
        ['type' => 'link', 'href' => '/doctor', 'label' => 'خلاصه'],
        ['type' => 'group', 'label' => 'گفتگو و پیام'],
        ['type' => 'link', 'href' => '/doctor/intakes', 'label' => 'گفتگوهای دستیار'],
        ['type' => 'link', 'href' => '/doctor/notifications', 'label' => 'اعلان‌ها'],
        ['type' => 'group', 'label' => 'نوبت و پرونده'],
        ['type' => 'link', 'href' => '/doctor/appointments', 'label' => 'نوبت‌ها'],
        ['type' => 'link', 'href' => '/doctor/availability', 'label' => 'روزهای خالی'],
        ['type' => 'link', 'href' => '/doctor/patients', 'label' => 'پرونده مراجعه‌کنندگان'],
        ['type' => 'link', 'href' => '/doctor/workshops', 'label' => 'کارگاه‌ها'],
        ['type' => 'group', 'label' => 'پروفایل من'],
        ['type' => 'link', 'href' => '/doctor/articles', 'label' => 'مقالات'],
        ['type' => 'link', 'href' => '/doctor/profile', 'label' => 'پروفایل حرفه‌ای'],
        ['type' => 'group', 'label' => 'حساب'],
        ['type' => 'link', 'href' => '/doctor/staff-hours', 'label' => 'ساعت کاری'],
        ['type' => 'link', 'href' => '/doctor/staff-messages', 'label' => 'پیام‌ها'],
        ['type' => 'link', 'href' => '/change-password', 'label' => 'تغییر رمز عبور'],
    ];
}

/** پروفایل کاری درمانگر را می‌سازد یا فیلدهای خالی را پر می‌کند */
function doctor_ensure_profile(PDO $pdo, string $userId, array $defaults = []): ?array
{
    if ($userId === '') {
        return null;
    }
    $stmt = $pdo->prepare('SELECT dp.*, u.name, u.email FROM doctor_profiles dp JOIN users u ON u.id=dp.user_id WHERE dp.user_id=? LIMIT 1');
    $stmt->execute([$userId]);
    $row = $stmt->fetch();
    $specialtyDefault = trim((string) ($defaults['specialty'] ?? 'روان‌شناسی'));
    if ($specialtyDefault === '') {
        $specialtyDefault = 'روان‌شناسی';
    }
    $bioDefault = (string) ($defaults['bio'] ?? '');
    $priceDefault = (int) ($defaults['session_price'] ?? 3000000);
    if ($priceDefault <= 0) {
        $priceDefault = 3000000;
    }

    if ($row) {
        $specialty = trim((string) ($row['specialty'] ?? ''));
        $price = (int) ($row['session_price'] ?? 0);
        $sets = [];
        $params = [];
        if ($specialty === '') {
            $sets[] = 'specialty=?';
            $params[] = $specialtyDefault;
            $row['specialty'] = $specialtyDefault;
        }
        if ($price <= 0) {
            $sets[] = 'session_price=?';
            $params[] = $priceDefault;
            $row['session_price'] = $priceDefault;
        }
        if (!empty($defaults['is_approved'])) {
            $sets[] = 'is_approved=1';
            $row['is_approved'] = 1;
        }
        if (!empty($defaults['is_active'])) {
            $sets[] = 'is_active=1';
            $row['is_active'] = 1;
        }
        if ($sets !== []) {
            $params[] = $row['id'];
            $pdo->prepare('UPDATE doctor_profiles SET ' . implode(', ', $sets) . ' WHERE id=?')->execute($params);
        }
        return $row;
    }

    $pdo->prepare('INSERT INTO doctor_profiles (id,user_id,specialty,bio,session_price,is_approved,is_active) VALUES (?,?,?,?,?,?,?)')
        ->execute([
            cuid(),
            $userId,
            $specialtyDefault,
            $bioDefault,
            $priceDefault,
            !empty($defaults['is_approved']) ? 1 : 0,
            !empty($defaults['is_active']) ? 1 : 0,
        ]);
    $stmt->execute([$userId]);
    $created = $stmt->fetch();
    return $created ?: null;
}

function require_doctor_profile(PDO $pdo): array
{
    $user = require_login(['DOCTOR']);
    if (($user['role'] ?? '') === 'ADMIN') {
        $profile = $pdo->query("
          SELECT dp.*, u.name, u.email
          FROM doctor_profiles dp
          JOIN users u ON u.id = dp.user_id
          WHERE dp.is_active = 1 AND dp.is_approved = 1
          ORDER BY u.name ASC
          LIMIT 1
        ")->fetch();
        if (!$profile) {
            flash_set('error', 'هنوز درمانگر فعالی نیست.');
            redirect('/admin');
        }
        return ['user' => $user, 'profile' => $profile, 'admin_mode' => true];
    }
    $profile = doctor_ensure_profile($pdo, (string) $user['id']);
    if (!$profile || !(int) ($profile['is_approved'] ?? 0)) {
        flash_set('error', 'حساب درمانگر شما هنوز توسط مدیر سایت تأیید نشده است.');
        logout_user();
        redirect('/login');
    }
    if (!(int) ($profile['is_active'] ?? 0)) {
        flash_set('error', 'حساب درمانگر شما فعلاً غیرفعال است.');
        logout_user();
        redirect('/login');
    }
    return ['user' => $user, 'profile' => $profile];
}

function render_doctor_page(string $title, string $innerHtml): void
{
    global $pageScripts, $pageHead;
    $nav = doctor_nav();
    $pageTitle = $title;
    $GLOBALS['pageRobots'] = 'noindex,nofollow';
    $currentPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
    ob_start();
    ?>
    <div class="container-page panel-layout">
      <aside class="panel side-nav doctor-side-nav">
        <p class="side-nav-title">پنل درمانگر</p>
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
        <p class="muted" style="font-size:.75rem;margin-top:1rem;line-height:1.6">پرونده مراجعه‌کنندگان فقط برای شما قابل مشاهده است.</p>
      </aside>
      <div class="panel-main"><?= $innerHtml ?></div>
    </div>
    <?php
    $content = ob_get_clean();
    require __DIR__ . '/layout.php';
}
