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
    if (function_exists('staff_tracks_presence') && staff_tracks_presence($user) && function_exists('staff_shift_start')) {
        global $pdo;
        if ($pdo instanceof PDO) {
            staff_shift_start($pdo, (string) $user['id']);
        }
    }
}

function logout_user(string $reason = 'logout'): void
{
    $user = current_user();
    if ($user && function_exists('staff_tracks_presence') && staff_tracks_presence($user) && function_exists('staff_shift_end')) {
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
    if (function_exists('staff_tracks_presence') && staff_tracks_presence($user) && function_exists('staff_guard_session') && $pdo instanceof PDO) {
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
        'DOCTOR' => '/doctor',
        'SECRETARY' => '/secretary/messages',
        'PATIENT' => '/dashboard',
        default => null,
    };
}
