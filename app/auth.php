<?php
// app/auth.php

declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';

function auth_user(): ?array
{
    if (empty($_SESSION['uid'])) return null;
    $uid = (int)$_SESSION['uid'];

    // Include avatar_path for UI rendering.
    $stmt = db()->prepare('SELECT id, username, display_name, role, lang, is_active, avatar_path FROM users WHERE id=? LIMIT 1');
    $stmt->execute([$uid]);
    $u = $stmt->fetch();
    if (!$u || (int)$u['is_active'] !== 1) {
        unset($_SESSION['uid']);
        return null;
    }
    return $u;
}

function require_login(): array
{
    $u = auth_user();
    if (!$u) {
        redirect(url('login.php'));
    }
    // Update last_seen (lightweight)
    $stmt = db()->prepare('UPDATE users SET last_seen_at=? WHERE id=?');
    $stmt->execute([now_utc(), $u['id']]);
    return $u;
}

function require_admin(): array
{
    // Backward-compatible name: this now means "admin panel access".
    $u = require_login();
    $role = (string)($u['role'] ?? 'user');
    if (!is_admin_role($role)) {
        http_response_code(403);
        echo 'Forbidden';
        exit;
    }
    return $u;
}

/**
 * Check if the current user (or a provided user array) is an admin.
 *
 * Some pages (e.g. index.php) call this without parameters.
 */
function is_admin(?array $u = null): bool
{
    if ($u === null) {
        $u = auth_user();
    }
    return (is_array($u) && is_admin_role((string)($u['role'] ?? 'user')));
}

function is_super_admin(?array $u = null): bool
{
    if ($u === null) {
        $u = auth_user();
    }
    return (is_array($u) && is_super_admin_role((string)($u['role'] ?? 'user')));
}

function login_attempt(string $username, string $password): bool
{
    $stmt = db()->prepare('SELECT id, password_hash, is_active FROM users WHERE username=? LIMIT 1');
    $stmt->execute([$username]);
    $row = $stmt->fetch();
    if (!$row || (int)$row['is_active'] !== 1) {
        return false;
    }
    if (!password_verify($password, $row['password_hash'])) {
        return false;
    }
    $_SESSION['uid'] = (int)$row['id'];
    return true;
}

function logout(): void
{
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000,
            $params['path'], $params['domain'], $params['secure'], $params['httponly']
        );
    }
    session_destroy();
}
