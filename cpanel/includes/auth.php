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
}

function logout_user(): void
{
    unset($_SESSION['user']);
}

function require_login(?array $roles = null): array
{
    $user = current_user();
    if (!$user) {
        redirect('/login');
    }
    if (!empty($user['must_change_password'])) {
        global $path;
        $allowed = ['/change-password', '/logout'];
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
        'SECRETARY' => '/secretary',
        'PATIENT' => '/dashboard',
        default => null,
    };
}
