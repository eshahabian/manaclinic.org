<?php
declare(strict_types=1);

function current_user(): ?array
{
    return $_SESSION['user'] ?? null;
}

/** ستون رمز قابل‌مشاهده فقط برای ادمین (رمزهای قدیمی قابل بازیابی نیستند) */
function ensure_users_password_plain_schema(PDO $pdo): void
{
    static $ready = false;
    if ($ready) {
        return;
    }
    try {
        $has = $pdo->query("SHOW COLUMNS FROM users LIKE 'password_plain'")->fetch();
        if (!$has) {
            $pdo->exec("ALTER TABLE users ADD COLUMN password_plain VARCHAR(255) NULL AFTER password_hash");
        }
    } catch (Throwable $ignored) {
    }
    $ready = true;
}

function ensure_users_disabled_schema(PDO $pdo): void
{
    static $ready = false;
    if ($ready) {
        return;
    }
    try {
        $has = $pdo->query("SHOW COLUMNS FROM users LIKE 'is_disabled'")->fetch();
        if (!$has) {
            $pdo->exec('ALTER TABLE users ADD COLUMN is_disabled TINYINT(1) NOT NULL DEFAULT 0 AFTER must_change_password');
        }
    } catch (Throwable $ignored) {
    }
    $ready = true;
}

function user_account_is_disabled(PDO $pdo, string $userId): bool
{
    if ($userId === '') {
        return false;
    }
    ensure_users_disabled_schema($pdo);
    try {
        $stmt = $pdo->prepare('SELECT is_disabled FROM users WHERE id=? LIMIT 1');
        $stmt->execute([$userId]);

        return (int) $stmt->fetchColumn() === 1;
    } catch (Throwable $ignored) {
        return false;
    }
}

/** غیرفعال‌کردن حساب فقط برای مدیر سایت و eshahabian */
function user_disable_actor_allowed(?array $user): bool
{
    if (!$user) {
        return false;
    }
    $username = strtolower(trim((string) ($user['username'] ?? '')));
    if ($username === 'eshahabian') {
        return true;
    }

    return strtoupper((string) ($user['role'] ?? '')) === 'ADMIN';
}

function ensure_users_gender_schema(PDO $pdo): void
{
    static $ready = false;
    if ($ready) {
        return;
    }
    try {
        $has = $pdo->query("SHOW COLUMNS FROM users LIKE 'gender'")->fetch();
        if (!$has) {
            $pdo->exec("ALTER TABLE users ADD COLUMN gender VARCHAR(8) NULL AFTER role");
        }
    } catch (Throwable $ignored) {
    }
    $ready = true;
}

/** ذخیرهٔ نسخهٔ قابل‌مشاهدهٔ رمز فقط برای پنل ادمین */
function user_remember_password_plain(PDO $pdo, string $userId, string $plain): void
{
    if ($userId === '' || $plain === '') {
        return;
    }
    ensure_users_password_plain_schema($pdo);
    try {
        if (function_exists('mb_substr')) {
            $plain = mb_substr($plain, 0, 255);
        } else {
            $plain = substr($plain, 0, 255);
        }
        $pdo->prepare('UPDATE users SET password_plain=? WHERE id=?')->execute([$plain, $userId]);
    } catch (Throwable $ignored) {
    }
}

function auth_idle_seconds(): int
{
    if (function_exists('staff_idle_seconds')) {
        return staff_idle_seconds();
    }

    return 600;
}

function ensure_auth_session_schema(PDO $pdo): void
{
    static $ready = false;
    if ($ready) {
        return;
    }
    try {
        $has = $pdo->query("SHOW COLUMNS FROM users LIKE 'auth_session_token'")->fetch();
        if (!$has) {
            $pdo->exec('ALTER TABLE users ADD COLUMN auth_session_token VARCHAR(64) NULL');
        }
    } catch (Throwable $ignored) {
    }
    $ready = true;
}

/** یک نشست برای هر حساب؛ ورود تازه، نشست‌های قبلی را بی‌اعتبار می‌کند */
function auth_bind_session(PDO $pdo, string $userId): void
{
    if ($userId === '') {
        return;
    }
    ensure_auth_session_schema($pdo);
    try {
        $token = bin2hex(random_bytes(16));
        $pdo->prepare('UPDATE users SET auth_session_token=? WHERE id=?')->execute([$token, $userId]);
        $_SESSION['auth_session_token'] = $token;
        $_SESSION['last_activity'] = time();
    } catch (Throwable $ignored) {
    }
}

/** فقط اگر همین نشست هنوز صاحب حساب است، توکن را عوض می‌کند تا بعد از خروج دوباره زنده نشود */
function auth_release_session(PDO $pdo, array $user): void
{
    $userId = (string) ($user['id'] ?? '');
    $sess = (string) ($_SESSION['auth_session_token'] ?? '');
    if ($userId === '' || $sess === '') {
        return;
    }
    ensure_auth_session_schema($pdo);
    try {
        $fresh = bin2hex(random_bytes(16));
        $pdo->prepare('UPDATE users SET auth_session_token=? WHERE id=? AND auth_session_token=?')
            ->execute([$fresh, $userId, $sess]);
    } catch (Throwable $ignored) {
    }
}

function auth_drop_local_session(): void
{
    unset(
        $_SESSION['user'],
        $_SESSION['last_activity'],
        $_SESSION['auth_session_token'],
        $_SESSION['staff_shift_id'],
        $_SESSION['staff_shift_mobile']
    );
}

function auth_idle_message(?array $user): string
{
    if ($user && function_exists('staff_tracks_presence') && staff_tracks_presence($user)) {
        return 'به‌خاطر ۱۰ دقیقه بی‌فعالیتی از حساب خارج شدید و ساعت کاری متوقف شد.';
    }

    return 'به‌خاطر ۱۰ دقیقه بی‌فعالیتی از حساب خارج شدید.';
}

/** @return 'ok'|'idle'|'replaced' */
function auth_session_state(PDO $pdo, array $user): string
{
    $userId = (string) ($user['id'] ?? '');
    if ($userId === '') {
        return 'ok';
    }
    ensure_auth_session_schema($pdo);
    try {
        $stmt = $pdo->prepare('SELECT auth_session_token FROM users WHERE id=? LIMIT 1');
        $stmt->execute([$userId]);
        $dbToken = (string) ($stmt->fetchColumn() ?: '');
        $sessToken = (string) ($_SESSION['auth_session_token'] ?? '');
        if ($dbToken === '') {
            auth_bind_session($pdo, $userId);
            return 'ok';
        }
        if ($sessToken === '' || !hash_equals($dbToken, $sessToken)) {
            return 'replaced';
        }
        $last = (int) ($_SESSION['last_activity'] ?? 0);
        if ($last <= 0) {
            $_SESSION['last_activity'] = time();
            return 'ok';
        }
        if ((time() - $last) >= auth_idle_seconds()) {
            return 'idle';
        }
    } catch (Throwable $ignored) {
        return 'ok';
    }

    return 'ok';
}

function auth_reject_session(string $reason): never
{
    $user = current_user();
    $idle = $reason === 'idle';
    $message = $idle
        ? auth_idle_message($user)
        : 'این حساب از مرورگر یا دستگاه دیگری وارد شد و این نشست بسته شد.';
    flash_set('info', $message);
    if ($idle) {
        logout_user('idle');
    } else {
        auth_drop_local_session();
    }
    $path = (string) ($GLOBALS['path'] ?? '');
    $asJson = function_exists('request_expects_json') && request_expects_json();
    if ($asJson || $path === '/session/ping' || $path === '/secretary/heartbeat') {
        http_response_code(401);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'ok' => false,
            'expired' => $idle,
            'replaced' => !$idle,
            'error' => $message,
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
    flash_set('info', $message);
    redirect('/login');
}

function auth_guard_request(PDO $pdo, array $user, bool $touch): void
{
    $state = auth_session_state($pdo, $user);
    if ($state === 'idle' || $state === 'replaced') {
        auth_reject_session($state);
    }
    if ($touch) {
        $_SESSION['last_activity'] = time();
    }
}

/** حساب‌های مشخص را به نقش مدیر ارتقا می‌دهد */
function auth_grant_named_roles(PDO $pdo, array $user): array
{
    $username = strtolower(trim((string) ($user['username'] ?? '')));
    $id = (string) ($user['id'] ?? '');
    if ($username !== 'eshahabian' || $id === '' || (string) ($user['role'] ?? '') === 'ADMIN') {
        return $user;
    }
    try {
        $pdo->prepare("UPDATE users SET role='ADMIN' WHERE id=? AND role<>'ADMIN'")->execute([$id]);
    } catch (Throwable $ignored) {
    }
    $user['role'] = 'ADMIN';
    if (isset($_SESSION['user']) && is_array($_SESSION['user']) && (string) ($_SESSION['user']['id'] ?? '') === $id) {
        $_SESSION['user']['role'] = 'ADMIN';
    }

    return $user;
}

function login_user(array $user): void
{
    global $pdo;
    if ($pdo instanceof PDO) {
        $user = auth_grant_named_roles($pdo, $user);
    }
    $_SESSION['user'] = [
        'id' => $user['id'],
        'name' => $user['name'],
        'email' => $user['email'] ?? null,
        'username' => $user['username'] ?? null,
        'role' => $user['role'],
        'must_change_password' => (int) ($user['must_change_password'] ?? 0),
        'gender' => ((string) ($user['gender'] ?? '') === 'female') ? 'female' : (((string) ($user['gender'] ?? '') === 'male') ? 'male' : ''),
    ];
    if ($pdo instanceof PDO) {
        auth_bind_session($pdo, (string) $user['id']);
        if (function_exists('staff_tracks_presence') && staff_tracks_presence($user) && function_exists('staff_shift_start')) {
            staff_shift_start($pdo, (string) $user['id']);
        }
    }
}

function logout_user(string $reason = 'logout'): void
{
    $user = current_user();
    global $pdo;
    if ($reason !== 'replaced' && $user && $pdo instanceof PDO) {
        auth_release_session($pdo, $user);
    }
    if ($reason !== 'replaced' && $user && function_exists('staff_tracks_presence') && staff_tracks_presence($user) && function_exists('staff_shift_end')) {
        if ($pdo instanceof PDO) {
            staff_shift_end($pdo, (string) $user['id'], $reason === 'idle' ? 'idle' : 'logout');
        }
    }
    auth_drop_local_session();
}

function require_login(?array $roles = null): array
{
    $user = current_user();
    if (!$user) {
        redirect('/login');
    }
    global $path, $pdo;
    if ($pdo instanceof PDO) {
        $user = auth_grant_named_roles($pdo, $user);
    }
    $isSessionPoll = in_array($path ?? '', ['/secretary/heartbeat', '/session/ping'], true);
    if ($pdo instanceof PDO) {
        auth_guard_request($pdo, $user, !$isSessionPoll);
        $user = current_user() ?? $user;
        if ($user && user_account_is_disabled($pdo, (string) ($user['id'] ?? ''))) {
            logout_user('logout');
            flash_set('error', 'این حساب غیرفعال شده است.');
            redirect('/login');
        }
    }
    if (function_exists('staff_tracks_presence') && staff_tracks_presence($user) && function_exists('staff_guard_session') && $pdo instanceof PDO) {
        staff_guard_session($pdo, $user, !$isSessionPoll);
        $user = current_user() ?? $user;
    }
    if (!empty($user['must_change_password'])) {
        $allowed = ['/change-password', '/logout', '/secretary/heartbeat', '/session/ping', '/secretary/handover/ack', '/secretary/admin-message/ack'];
        if (!in_array($path ?? '', $allowed, true)) {
            redirect('/change-password');
        }
    }
    if (($user['role'] ?? '') === 'DOCTOR' && $pdo instanceof PDO && function_exists('doctor_must_complete_profile') && doctor_must_complete_profile($pdo, $user)) {
        $profileAllowed = ['/doctor/profile', '/logout', '/change-password', '/session/ping', '/secretary/heartbeat'];
        if (!in_array($path ?? '', $profileAllowed, true)) {
            redirect('/doctor/profile');
        }
    }
    if (($user['role'] ?? '') === 'SECRETARY' && $pdo instanceof PDO) {
        $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
        if (function_exists('admin_staff_msg_pending_for')) {
            $adminPending = admin_staff_msg_pending_for($pdo, (string) $user['id']);
            if ($adminPending) {
                $GLOBALS['adminStaffMsgBlock'] = $adminPending;
                $adminAllowed = ['/secretary/admin-message/ack', '/logout', '/secretary/heartbeat', '/session/ping', '/staff/admin-message-image'];
                if ($method === 'POST' && !in_array($path ?? '', $adminAllowed, true)) {
                    flash_set('error', 'ابتدا پیام مدیر را بخوانید، تیک بزنید و «خواندم» را بزنید.');
                    redirect('/secretary/profile#admin-site-messages');
                }
            }
        }
        if (function_exists('handover_pending_for') && empty($GLOBALS['adminStaffMsgBlock'])) {
            $pending = handover_pending_for($pdo, (string) $user['id']);
            if ($pending) {
                $GLOBALS['handoverBlock'] = $pending;
                $handoverAllowed = ['/secretary/handover/ack', '/logout', '/secretary/heartbeat', '/session/ping', '/secretary/admin-message/ack'];
                if ($method === 'POST' && !in_array($path ?? '', $handoverAllowed, true)) {
                    flash_set('error', 'ابتدا پیام تحویل شیفت را بخوانید و «خواندم» را بزنید.');
                    redirect('/secretary/messages');
                }
            }
        }
    }
    if ($roles && !in_array($user['role'], $roles, true) && ($user['role'] ?? '') !== 'ADMIN') {
        redirect('/');
    }
    return $user;
}

function is_admin_user(?array $user = null): bool
{
    $user = $user ?? current_user();
    return $user && ($user['role'] ?? '') === 'ADMIN';
}

function client_ip(): string
{
    return (string) ($_SERVER['REMOTE_ADDR'] ?? '0');
}

function throttle_file(string $bucket): string
{
    $safe = preg_replace('/[^a-z0-9_]/i', '', $bucket) ?: 'req';
    return sys_get_temp_dir() . '/mana_' . $safe . '_' . hash('sha256', client_ip());
}

function throttle_hits(string $bucket, int $windowSeconds = 600): array
{
    $file = throttle_file($bucket);
    if (!is_file($file)) {
        return [];
    }
    $hits = json_decode((string) @file_get_contents($file), true);
    if (!is_array($hits)) {
        return [];
    }
    $since = time() - max(1, $windowSeconds);
    return array_values(array_filter($hits, static fn($t): bool => is_int($t) && $t > $since));
}

function throttle_too_many(string $bucket, int $max, int $windowSeconds = 600): bool
{
    return count(throttle_hits($bucket, $windowSeconds)) >= $max;
}

function throttle_hit(string $bucket, int $windowSeconds = 600): void
{
    $hits = throttle_hits($bucket, $windowSeconds);
    $hits[] = time();
    @file_put_contents(throttle_file($bucket), json_encode($hits), LOCK_EX);
}

function throttle_clear(string $bucket): void
{
    $file = throttle_file($bucket);
    if (is_file($file)) {
        @unlink($file);
    }
}

function throttle_guard_page(string $bucket, int $max, int $windowSeconds, string $redirectTo, string $message): void
{
    if (throttle_too_many($bucket, $max, $windowSeconds)) {
        flash_set('error', $message);
        redirect($redirectTo);
    }
}

function throttle_guard_json(string $bucket, int $max, int $windowSeconds, string $message): void
{
    if (throttle_too_many($bucket, $max, $windowSeconds)) {
        http_response_code(429);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['error' => $message], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

function login_throttle_file(): string
{
    return throttle_file('login');
}

function login_throttle_hits(): array
{
    return throttle_hits('login', 600);
}

function login_throttle_guard(): void
{
    throttle_guard_page('login', 8, 600, '/login', 'تلاش ورود زیاد بود. چند دقیقه بعد دوباره تلاش کنید.');
}

function login_throttle_fail(): void
{
    throttle_hit('login', 600);
}

function login_throttle_clear(): void
{
    throttle_clear('login');
}

function panel_href_for(?array $user): ?string
{
    if (!$user) {
        return null;
    }
    return match ($user['role']) {
        'ADMIN' => '/admin',
        'DOCTOR' => '/doctor/notifications',
        'SECRETARY' => '/secretary/messages',
        'PATIENT' => '/dashboard',
        default => null,
    };
}
