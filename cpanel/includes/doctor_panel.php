<?php
declare(strict_types=1);

function doctor_ctx_user_id(array $ctx): string
{
    if (!empty($ctx['admin_mode'])) {
        return (string) ($ctx['profile']['user_id'] ?? '');
    }

    return (string) ($ctx['user']['id'] ?? '');
}

function doctor_ctx_user_name(array $ctx): string
{
    if (!empty($ctx['admin_mode'])) {
        return (string) ($ctx['profile']['name'] ?? '');
    }

    return (string) ($ctx['user']['name'] ?? '');
}

function doctor_can_view_staff_hours(?array $user = null): bool
{
    $user = $user ?? current_user();
    if (!$user) {
        return false;
    }
    if (($user['role'] ?? '') === 'ADMIN') {
        return true;
    }
    if (($user['role'] ?? '') !== 'DOCTOR') {
        return false;
    }

    return function_exists('doctor_is_shiva') && doctor_is_shiva($user);
}

function doctor_nav(): array
{
    $nav = [
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
    ];
    if (doctor_can_view_staff_hours()) {
        $nav[] = ['type' => 'link', 'href' => '/doctor/staff-hours', 'label' => 'ساعت کاری منشی‌ها'];
    }
    $nav[] = ['type' => 'link', 'href' => '/doctor/staff-messages', 'label' => 'پیام‌ها'];
    $videoLink = function_exists('video_call_nav_link') ? video_call_nav_link(true) : null;
    if ($videoLink) {
        $nav[] = $videoLink;
    }
    $nav[] = ['type' => 'link', 'href' => '/change-password', 'label' => 'تغییر رمز عبور'];

    return $nav;
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

function admin_doctor_choices(PDO $pdo): array
{
    return $pdo->query("
      SELECT dp.id, u.name
      FROM doctor_profiles dp
      JOIN users u ON u.id = dp.user_id
      WHERE dp.is_approved = 1
      ORDER BY
        CASE WHEN u.name LIKE '%گرانمایه%' THEN 0 ELSE 1 END,
        u.name ASC
    ")->fetchAll() ?: [];
}

function admin_bind_doctor_profile(PDO $pdo): ?array
{
    $requested = trim((string) ($_GET['as_doctor'] ?? $_POST['as_doctor'] ?? ''));
    if ($requested !== '') {
        $_SESSION['admin_doctor_id'] = $requested;
    }
    $selected = trim((string) ($_SESSION['admin_doctor_id'] ?? ''));
    $sql = "
      SELECT dp.*, u.name, u.email
      FROM doctor_profiles dp
      JOIN users u ON u.id = dp.user_id
      WHERE dp.is_approved = 1
    ";
    if ($selected !== '') {
        $stmt = $pdo->prepare($sql . ' AND dp.id = ? LIMIT 1');
        $stmt->execute([$selected]);
        $row = $stmt->fetch();
        if ($row) {
            $_SESSION['admin_doctor_id'] = (string) $row['id'];

            return $row;
        }
    }
    $row = $pdo->query($sql . " ORDER BY CASE WHEN u.name LIKE '%گرانمایه%' THEN 0 ELSE 1 END, u.name ASC LIMIT 1")->fetch();
    if ($row) {
        $_SESSION['admin_doctor_id'] = (string) $row['id'];
    }

    return $row ?: null;
}

function admin_doctor_switcher_html(PDO $pdo, array $profile): string
{
    $choices = admin_doctor_choices($pdo);
    $current = (string) ($profile['id'] ?? '');
    $action = parse_url($_SERVER['REQUEST_URI'] ?? '/doctor', PHP_URL_PATH) ?: '/doctor';
    ob_start();
    ?>
    <form class="doc-admin-switcher" method="get" action="<?= e(url($action)) ?>">
      <label for="as_doctor">ویرایش پنل درمانگر</label>
      <select class="input" id="as_doctor" name="as_doctor" onchange="this.form.submit()">
        <?php foreach ($choices as $d): ?>
          <option value="<?= e((string) $d['id']) ?>" <?= $current === (string) $d['id'] ? 'selected' : '' ?>><?= e((string) $d['name']) ?></option>
        <?php endforeach; ?>
      </select>
      <?php foreach ($_GET as $key => $value): ?>
        <?php if ($key === 'as_doctor' || !is_string($value)) { continue; } ?>
        <input type="hidden" name="<?= e((string) $key) ?>" value="<?= e($value) ?>">
      <?php endforeach; ?>
    </form>
    <?php
    return ob_get_clean();
}

function require_doctor_profile(PDO $pdo): array
{
    $user = require_login(['DOCTOR']);
    if (($user['role'] ?? '') === 'ADMIN') {
        $profile = admin_bind_doctor_profile($pdo);
        if (!$profile) {
            flash_set('error', 'هنوز درمانگر تأییدشده‌ای نیست.');
            redirect('/admin');
        }
        $ctx = ['user' => $user, 'profile' => $profile, 'admin_mode' => true];
        $GLOBALS['doctor_ctx'] = $ctx;

        return $ctx;
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
    $ctx = ['user' => $user, 'profile' => $profile];
    $GLOBALS['doctor_ctx'] = $ctx;

    return $ctx;
}

function render_doctor_page(string $title, string $innerHtml): void
{
    global $pageScripts, $pageHead, $pdo;
    $ctx = is_array($GLOBALS['doctor_ctx'] ?? null) ? $GLOBALS['doctor_ctx'] : null;
    $user = current_user();
    $currentPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
    $prefix = '';
    if (!empty($ctx['admin_mode']) && $pdo instanceof PDO && is_array($ctx['profile'] ?? null)) {
        $prefix .= admin_doctor_switcher_html($pdo, $ctx['profile']);
    }
    if (
        $currentPath !== '/doctor/profile'
        && is_array($ctx['user'] ?? null)
        && function_exists('doctor_is_shiva')
        && doctor_is_shiva($ctx['user'])
        && is_array($ctx['profile'] ?? null)
        && function_exists('doctor_profile_is_complete')
        && !doctor_profile_is_complete($ctx['profile'])
    ) {
        $prefix .= '<div class="doc-onboard-banner">پروفایل را تکمیل کنید. <a href="' . e(url('/doctor/profile')) . '">رفتن به پروفایل</a></div>';
    }
    $innerHtml = $prefix . $innerHtml;
    if (function_exists('is_admin_user') && is_admin_user($user)) {
        require_once __DIR__ . '/admin_panel.php';
        render_admin_page($title, $innerHtml);
        return;
    }
    $nav = doctor_nav();
    $pageTitle = $title;
    $GLOBALS['pageRobots'] = 'noindex,nofollow';
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
                if ($href === '/doctor') {
                    $active = $currentPath === $href || str_ends_with($currentPath, '/doctor');
                } else {
                    $active = str_contains($currentPath, $href);
                }
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
