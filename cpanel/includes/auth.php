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
        $allowed = ['/change-password', '/logout', '/secretary/heartbeat'];
        if (!in_array($path ?? '', $allowed, true)) {
            redirect('/change-password');
        }
    }
    if ($roles && !in_array($user['role'], $roles, true)) {
        redirect('/');
    }
    return $user;
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
