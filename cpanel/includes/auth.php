<?php
declare(strict_types=1);

function current_user(): ?array
{
    return $_SESSION['user'] ?? null;
}

function login_user(array $user): void
{
    $_SESSION['user'] = [
        'id' => $user['id'],
        'name' => $user['name'],
        'email' => $user['email'] ?? null,
        'username' => $user['username'] ?? null,
        'role' => $user['role'],
        'must_change_password' => (int) ($user['must_change_password'] ?? 0),
    ];
    if (($user['role'] ?? '') === 'SECRETARY' && function_exists('staff_shift_start')) {
        global $pdo;
        if ($pdo instanceof PDO) {
            staff_shift_start($pdo, (string) $user['id']);
        }
    }
}

function logout_user(string $reason = 'logout'): void
{
    $user = current_user();
    if ($user && ($user['role'] ?? '') === 'SECRETARY' && function_exists('staff_shift_end')) {
        global $pdo;
        if ($pdo instanceof PDO) {
            staff_shift_end($pdo, (string) $user['id'], $reason);
        }
    }
    unset($_SESSION['user'], $_SESSION['last_activity'], $_SESSION['staff_shift_id']);
}

function require_login(?array $roles = null): array
{
    $user = current_user();
    if (!$user) {
        redirect('/login');
    }
    global $path, $pdo;
    $isHeartbeat = ($path ?? '') === '/secretary/heartbeat';
    if (($user['role'] ?? '') === 'SECRETARY' && function_exists('staff_guard_session') && $pdo instanceof PDO) {
        staff_guard_session($pdo, $user, !$isHeartbeat);
        $user = current_user() ?? $user;
    }
    if (!empty($user['must_change_password'])) {
        $allowed = ['/change-password', '/logout', '/secretary/heartbeat', '/secretary/handover/ack'];
        if (!in_array($path ?? '', $allowed, true)) {
            redirect('/change-password');
        }
    }
    if (($user['role'] ?? '') === 'SECRETARY' && $pdo instanceof PDO && function_exists('handover_pending_for')) {
        $pending = handover_pending_for($pdo, (string) $user['id']);
        if ($pending) {
            $GLOBALS['handoverBlock'] = $pending;
            $handoverAllowed = ['/secretary/handover/ack', '/logout', '/secretary/heartbeat'];
            $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
            if ($method === 'POST' && !in_array($path ?? '', $handoverAllowed, true)) {
                flash_set('error', 'ابتدا پیام تحویل شیفت را بخوانید و «خواندم» را بزنید.');
                redirect('/secretary/messages');
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

function login_throttle_file(): string
{
    $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '0');
    return sys_get_temp_dir() . '/mana_login_' . hash('sha256', $ip);
}

function login_throttle_hits(): array
{
    $file = login_throttle_file();
    if (!is_file($file)) {
        return [];
    }
    $hits = json_decode((string) @file_get_contents($file), true);
    if (!is_array($hits)) {
        return [];
    }
    $since = time() - 600;
    return array_values(array_filter($hits, static fn($t): bool => is_int($t) && $t > $since));
}

function login_throttle_guard(): void
{
    if (count(login_throttle_hits()) >= 8) {
        flash_set('error', 'تلاش ورود زیاد بود. چند دقیقه بعد دوباره تلاش کنید.');
        redirect('/login');
    }
}

function login_throttle_fail(): void
{
    $hits = login_throttle_hits();
    $hits[] = time();
    @file_put_contents(login_throttle_file(), json_encode($hits), LOCK_EX);
}

function login_throttle_clear(): void
{
    $file = login_throttle_file();
    if (is_file($file)) {
        @unlink($file);
    }
}

function panel_href_for(?array $user): ?string
{
    if (!$user) {
        return null;
    }
    return match ($user['role']) {
        'ADMIN' => '/admin',
        'DOCTOR' => '/doctor',
        'SECRETARY' => '/secretary/messages',
        'PATIENT' => '/dashboard',
        default => null,
    };
}
