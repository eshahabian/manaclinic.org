<?php
declare(strict_types=1);

function ensure_accountant_account(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;

    try {
        $pdo->exec("ALTER TABLE users MODIFY role ENUM('ADMIN','DOCTOR','PATIENT','SECRETARY','ACCOUNTANT') NOT NULL DEFAULT 'PATIENT'");
    } catch (Throwable $ignored) {
    }

    try {
        $exists = $pdo->query("SELECT id FROM users WHERE username='accountant' LIMIT 1")->fetch();
        if ($exists) {
            return;
        }
        $id = 'accountant001mana';
        $plain = '123';
        $pdo->prepare('INSERT INTO users (id,username,name,email,phone,password_hash,role,must_change_password) VALUES (?,?,?,?,?,?,?,1)')
            ->execute([
                $id,
                'accountant',
                'حسابدار',
                'accountant@manaclinic.local',
                '09125555555',
                password_hash($plain, PASSWORD_DEFAULT),
                'ACCOUNTANT',
            ]);
        if (function_exists('user_remember_password_plain')) {
            user_remember_password_plain($pdo, $id, $plain);
        }
    } catch (Throwable $ignored) {
    }
}

function accountant_nav(): array
{
    return [
        ['href' => '/accountant', 'label' => 'خلاصه مالی'],
        ['href' => '/accountant/payments', 'label' => 'پرداخت‌ها'],
        ['href' => '/accountant/payouts', 'label' => 'پرداخت درمانگرها'],
        ['href' => '/accountant/petty-cash', 'label' => 'تنخواه'],
        ['href' => '/change-password', 'label' => 'تغییر رمز عبور'],
    ];
}

function render_accountant_page(string $title, string $innerHtml): void
{
    global $pageScripts, $pageHead;
    $nav = accountant_nav();
    $pageTitle = $title;
    $GLOBALS['pageTitle'] = $title;
    $GLOBALS['pageRobots'] = 'noindex,nofollow';
    $currentPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
    ob_start();
    ?>
    <div class="container-page panel-layout">
      <aside class="panel side-nav">
        <p class="side-nav-title">پنل حسابدار</p>
        <nav>
          <?php foreach ($nav as $item): ?>
            <?php
              $href = (string) ($item['href'] ?? '');
              $active = $href === '/accountant'
                  ? $currentPath === '/accountant'
                  : ($href !== '' && ($currentPath === $href || str_starts_with($currentPath, $href . '/')));
            ?>
            <a class="<?= $active ? 'is-active' : '' ?>" href="<?= e(url($href)) ?>"><?= e((string) ($item['label'] ?? '')) ?></a>
          <?php endforeach; ?>
        </nav>
      </aside>
      <div class="panel-main"><?= $innerHtml ?></div>
    </div>
    <?php
    $content = ob_get_clean();
    require __DIR__ . '/layout.php';
}

/** @return array{start:string,end:string,year:int,month:int,label:string} */
function accountant_selected_range(): array
{
    if (!function_exists('petty_cash_month_range')) {
        require_once __DIR__ . '/petty_cash.php';
    }
    $meta = jalali_current_month_meta();
    [$todayY, $todayM] = gregorian_to_jalali((int) date('Y'), (int) date('n'), (int) date('j'));
    $jy = (int) ($_GET['jy'] ?? ($meta['year'] ?? $todayY));
    $jm = (int) ($_GET['jm'] ?? ($meta['month'] ?? $todayM));

    return petty_cash_month_range($jy, $jm);
}

function accountant_month_form(string $action, array $range): string
{
    if (!function_exists('petty_cash_month_name')) {
        require_once __DIR__ . '/petty_cash.php';
    }
    [$todayY] = gregorian_to_jalali((int) date('Y'), (int) date('n'), (int) date('j'));
    $years = [];
    for ($y = (int) $todayY - 1; $y <= (int) $todayY + 1; $y++) {
        $years[] = $y;
    }
    if (!in_array((int) $range['year'], $years, true)) {
        $years[] = (int) $range['year'];
        sort($years);
    }
    ob_start();
    ?>
    <form class="panel form-stack" method="get" action="<?= e(url($action)) ?>" style="margin-top:1rem">
      <p style="margin:0;font-weight:600">ماه گزارش</p>
      <div>
        <label class="label" for="acct-year">سال</label>
        <select class="input" id="acct-year" name="jy">
          <?php foreach ($years as $y): ?>
            <option value="<?= (int) $y ?>"<?= (int) $y === (int) $range['year'] ? ' selected' : '' ?>><?= e(to_fa_digits((string) $y)) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div>
        <label class="label" for="acct-month">ماه</label>
        <select class="input" id="acct-month" name="jm">
          <?php for ($m = 1; $m <= 12; $m++): ?>
            <option value="<?= $m ?>"<?= $m === (int) $range['month'] ? ' selected' : '' ?>><?= e(petty_cash_month_name($m)) ?></option>
          <?php endfor; ?>
        </select>
      </div>
      <button class="btn btn-outline" type="submit" style="justify-self:start">نمایش این ماه</button>
    </form>
    <?php
    return (string) ob_get_clean();
}

/** @return array{count:int,total:int,clinic:int,therapist:int,unknown:int} */
function accountant_session_totals(PDO $pdo, string $start, string $end): array
{
    $empty = ['count' => 0, 'total' => 0, 'clinic' => 0, 'therapist' => 0, 'unknown' => 0];
    try {
        if (function_exists('ensure_user_referral_schema')) {
            ensure_user_referral_schema($pdo);
        }
        $stmt = $pdo->prepare("
          SELECT COUNT(*) AS c,
                 COALESCE(SUM(p.amount),0) AS total,
                 COALESCE(SUM(p.clinic_share_amount),0) AS clinic,
                 COALESCE(SUM(CASE WHEN p.clinic_share_amount IS NULL THEN 0 ELSE p.amount - p.clinic_share_amount END),0) AS therapist,
                 SUM(CASE WHEN p.clinic_share_amount IS NULL THEN 1 ELSE 0 END) AS unknown_share
          FROM payments p
          JOIN appointments a ON a.id = p.appointment_id
          WHERE p.status = 'PAID'
            AND DATE(a.starts_at) BETWEEN ? AND ?
        ");
        $stmt->execute([$start, $end]);
        $row = $stmt->fetch() ?: [];

        return [
            'count' => (int) ($row['c'] ?? 0),
            'total' => (int) ($row['total'] ?? 0),
            'clinic' => (int) ($row['clinic'] ?? 0),
            'therapist' => (int) ($row['therapist'] ?? 0),
            'unknown' => (int) ($row['unknown_share'] ?? 0),
        ];
    } catch (Throwable $ignored) {
        return $empty;
    }
}

/** @return list<array<string,mixed>> */
function accountant_session_by_doctor(PDO $pdo, string $start, string $end): array
{
    try {
        $stmt = $pdo->prepare("
          SELECT du.name AS doctor_name,
                 COUNT(*) AS c,
                 COALESCE(SUM(p.amount),0) AS total,
                 COALESCE(SUM(p.clinic_share_amount),0) AS clinic
          FROM payments p
          JOIN appointments a ON a.id = p.appointment_id
          JOIN doctor_profiles dp ON dp.id = a.doctor_id
          JOIN users du ON du.id = dp.user_id
          WHERE p.status = 'PAID'
            AND DATE(a.starts_at) BETWEEN ? AND ?
          GROUP BY dp.id, du.name
          ORDER BY total DESC, du.name ASC
        ");
        $stmt->execute([$start, $end]);

        return $stmt->fetchAll() ?: [];
    } catch (Throwable $ignored) {
        return [];
    }
}

/** @return list<array<string,mixed>> */
function accountant_session_rows(PDO $pdo, string $start, string $end): array
{
    try {
        $stmt = $pdo->prepare("
          SELECT p.amount, p.clinic_share_amount, a.starts_at,
                 pu.name AS patient_name, pu.referral_source,
                 du.name AS doctor_name
          FROM payments p
          JOIN appointments a ON a.id = p.appointment_id
          JOIN users pu ON pu.id = a.patient_id
          JOIN doctor_profiles dp ON dp.id = a.doctor_id
          JOIN users du ON du.id = dp.user_id
          WHERE p.status = 'PAID'
            AND DATE(a.starts_at) BETWEEN ? AND ?
          ORDER BY a.starts_at DESC, pu.name ASC
          LIMIT 400
        ");
        $stmt->execute([$start, $end]);

        return $stmt->fetchAll() ?: [];
    } catch (Throwable $ignored) {
        return [];
    }
}

/** @return array{count:int,total:int} */
function accountant_workshop_totals(PDO $pdo, string $start, string $end): array
{
    try {
        $exists = $pdo->query("SHOW TABLES LIKE 'workshop_payments'")->fetch();
        if (!$exists) {
            return ['count' => 0, 'total' => 0];
        }
        $stmt = $pdo->prepare("
          SELECT COUNT(*) AS c, COALESCE(SUM(amount),0) AS total
          FROM workshop_payments
          WHERE status = 'PAID'
            AND DATE(created_at) BETWEEN ? AND ?
        ");
        $stmt->execute([$start, $end]);
        $row = $stmt->fetch() ?: [];

        return ['count' => (int) ($row['c'] ?? 0), 'total' => (int) ($row['total'] ?? 0)];
    } catch (Throwable $ignored) {
        return ['count' => 0, 'total' => 0];
    }
}

/** @return list<array<string,mixed>> */
function accountant_workshop_rows(PDO $pdo, string $start, string $end): array
{
    try {
        $exists = $pdo->query("SHOW TABLES LIKE 'workshop_payments'")->fetch();
        if (!$exists) {
            return [];
        }
        $stmt = $pdo->prepare("
          SELECT wp.amount, wp.created_at, u.name AS patient_name, w.title
          FROM workshop_payments wp
          JOIN workshop_enrollments e ON e.id = wp.enrollment_id
          JOIN users u ON u.id = e.patient_id
          JOIN workshops w ON w.id = e.workshop_id
          WHERE wp.status = 'PAID'
            AND DATE(wp.created_at) BETWEEN ? AND ?
          ORDER BY wp.created_at DESC
          LIMIT 400
        ");
        $stmt->execute([$start, $end]);

        return $stmt->fetchAll() ?: [];
    } catch (Throwable $ignored) {
        return [];
    }
}

/** @return array{count:int,total:int} */
function accountant_payout_totals(PDO $pdo, string $start, string $end): array
{
    try {
        if (function_exists('ensure_therapist_payouts_schema')) {
            ensure_therapist_payouts_schema($pdo);
        }
        $stmt = $pdo->prepare("
          SELECT COUNT(*) AS c, COALESCE(SUM(total_amount),0) AS total
          FROM therapist_payouts
          WHERE DATE(settled_at) BETWEEN ? AND ?
        ");
        $stmt->execute([$start, $end]);
        $row = $stmt->fetch() ?: [];

        return ['count' => (int) ($row['c'] ?? 0), 'total' => (int) ($row['total'] ?? 0)];
    } catch (Throwable $ignored) {
        return ['count' => 0, 'total' => 0];
    }
}

function accountant_send_csv(array $rows, string $filename): void
{
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    echo "\xEF\xBB\xBF";
    $out = fopen('php://output', 'w');
    if ($out === false) {
        exit;
    }
    foreach ($rows as $row) {
        fputcsv($out, $row);
    }
    fclose($out);
    exit;
}
