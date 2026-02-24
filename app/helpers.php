<?php
// app/helpers.php

declare(strict_types=1);

function e(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function redirect(string $url): never
{
    header('Location: ' . $url);
    exit;
}

function base_url(): string
{
    $cfg = require __DIR__ . '/config.php';
    if (!empty($cfg['base_url'])) {
        // Can be a full URL (https://example.com/chat) or a path (/chat)
        return rtrim((string)$cfg['base_url'], '/');
    }

    // Auto-detect the project root URL.
    // We must support installs inside any subfolder (e.g. /chat) and still work
    // correctly when browsing nested folders like /chat/admin or /chat/chat.
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';

    $scriptName = (string)($_SERVER['SCRIPT_NAME'] ?? '/');
    $dir = rtrim(str_replace('\\', '/', dirname($scriptName)), '/');

    // Split into path parts: /chat/admin -> [chat, admin]
    $parts = array_values(array_filter(explode('/', trim($dir, '/'))));

    // Known leaf folders that are inside the project root.
    $leaf = ['admin', 'api', 'chat', 'assets', 'storage'];

    // Only strip leaf folders when we are definitely INSIDE a project folder.
    // Example:
    //   /chat/admin  -> strip 'admin' -> /chat
    //   /chat        -> DO NOT strip 'chat' (that's the project folder itself)
    if (count($parts) >= 2 && in_array(end($parts), $leaf, true)) {
        array_pop($parts);
    }

    $rootPath = '/' . implode('/', $parts);
    if ($rootPath === '/') $rootPath = '';

    return $scheme . '://' . $host . $rootPath;
}

function json_out(array $data, int $code = 200): never
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function now_utc(): string
{
    return gmdate('Y-m-d H:i:s');
}

function is_post(): bool
{
    return ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
}

function int_param(string $key, int $default = 0): int
{
    // Prefer POST values on POST requests so that GET query strings (e.g. ?action=edit)
    // do not override form submissions (e.g. POST action=update).
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
        $v = $_POST[$key] ?? $_GET[$key] ?? null;
    } else {
        $v = $_GET[$key] ?? $_POST[$key] ?? null;
    }
    if ($v === null) return $default;
    return (int)$v;
}

function str_param(string $key, string $default = ''): string
{
    // Prefer POST values on POST requests so that GET query strings (e.g. ?action=edit)
    // do not override form submissions (e.g. POST action=update).
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
        $v = $_POST[$key] ?? $_GET[$key] ?? null;
    } else {
        $v = $_GET[$key] ?? $_POST[$key] ?? null;
    }
    if ($v === null) return $default;
    return trim((string)$v);
}

function url(string $path): string
{
    $base = rtrim(base_url(), '/');
    $p = ltrim($path, '/');
    return $p === '' ? $base : ($base . '/' . $p);
}

/**
 * Returns true if the user should be considered online.
 * last_seen_at is stored in UTC (Y-m-d H:i:s).
 */
function is_online(?string $last_seen_at, int $threshold_seconds = 75): bool
{
    if (!$last_seen_at) return false;
    $ts = strtotime($last_seen_at . ' UTC');
    if ($ts === false) return false;
    return (time() - $ts) <= $threshold_seconds;
}

/**
 * Formats last seen timestamp for UI.
 */
function format_last_seen(?string $last_seen_at, string $lang): string
{
    if (!$last_seen_at) {
        return $lang === 'fa' ? 'نامشخص' : 'Unknown';
    }

    // last_seen_at is stored in UTC. Convert to the configured local offset for display.
    $ts = strtotime($last_seen_at . ' UTC');
    if ($ts === false) {
        return $last_seen_at;
    }

    $off = tz_offset_minutes();
    $local = $ts + ($off * 60);

    $d = gmdate('Y-m-d', $local);
    $t = gmdate('H:i', $local);
    $today = gmdate('Y-m-d', time() + ($off * 60));

    if ($d === $today) {
        return $t;
    }
    return $d . ' ' . $t;
}

/**
 * Telegram-like presence text.
 * - Online: within threshold seconds
 * - Last seen recently: within 3 days
 * - Last seen within a week: within 7 days
 * - Last seen within a month: within 30 days
 * - Last seen long time ago: older
 */
function presence_label(?string $last_seen_at, string $lang, int $online_threshold_seconds = 75): string
{
    if (!$last_seen_at) {
        return $lang === 'fa' ? 'آخرین بازدید نامشخص' : 'Last seen unknown';
    }
    $ts = strtotime($last_seen_at . ' UTC');
    if ($ts === false) {
        return $lang === 'fa' ? 'آخرین بازدید نامشخص' : 'Last seen unknown';
    }

    $delta = time() - $ts;
    if ($delta <= $online_threshold_seconds) {
        return $lang === 'fa' ? 'آنلاین' : 'Online';
    }

    $day = 86400;
    if ($delta <= 3 * $day) {
        return $lang === 'fa' ? 'آخرین بازدید اخیراً' : 'Last seen recently';
    }
    if ($delta <= 7 * $day) {
        return $lang === 'fa' ? 'آخرین بازدید این هفته' : 'Last seen within a week';
    }
    if ($delta <= 30 * $day) {
        return $lang === 'fa' ? 'آخرین بازدید این ماه' : 'Last seen within a month';
    }

    // As a fallback for very old records, show date+time.
    $when = format_last_seen($last_seen_at, $lang);
    return $lang === 'fa' ? ('آخرین بازدید: ' . $when) : ('Last seen: ' . $when);
}

/**
 * Returns the configured timezone offset in minutes.
 * We store timestamps in UTC in DB, and convert for display using this offset.
 * Example for Tehran: +210 minutes (+03:30).
 */
function tz_offset_minutes(): int
{
    // settings.php is loaded by app/bootstrap.php, but keep this safe.
    if (!function_exists('setting_int')) {
        require_once __DIR__ . '/settings.php';
    }
    $off = (int)setting_int('tz_offset_minutes', 210);
    // Clamp to sane limits (-12:00 .. +14:00)
    if ($off < -720) $off = -720;
    if ($off > 840) $off = 840;
    return $off;
}

/**
 * Convert a UTC datetime string (Y-m-d H:i:s) to local display fields.
 */
function chat_time_fields(?string $created_at_utc, string $lang): array
{
    if (!$created_at_utc) {
        return ['time_text'=>'', 'day_key'=>'', 'day_label'=>'', 'datetime_text'=>''];
    }

    $ts = strtotime($created_at_utc . ' UTC');
    if ($ts === false) {
        return ['time_text'=>'', 'day_key'=>'', 'day_label'=>'', 'datetime_text'=>$created_at_utc];
    }

    $off = tz_offset_minutes();
    $local = $ts + ($off * 60);

    $day_key = gmdate('Y-m-d', $local);
    $time_text = gmdate('H:i', $local);
    $datetime_text = gmdate('Y-m-d H:i', $local);

    $today_key = gmdate('Y-m-d', time() + ($off * 60));
    $yesterday_key = gmdate('Y-m-d', time() + ($off * 60) - 86400);

    if ($day_key === $today_key) {
        $day_label = ($lang === 'fa') ? 'امروز' : 'Today';
    } elseif ($day_key === $yesterday_key) {
        $day_label = ($lang === 'fa') ? 'دیروز' : 'Yesterday';
    } else {
        // Keep it simple and robust for all installs; no Jalali conversion here.
        $day_label = $day_key;
    }

    return [
        'time_text' => $time_text,
        'day_key' => $day_key,
        'day_label' => $day_label,
        'datetime_text' => $datetime_text,
    ];
}

/**
 * Builds a public URL for an avatar path stored in DB.
 */
function avatar_url(?string $avatar_path): string
{
    if (!$avatar_path) return '';
    // Stored paths are relative like: storage/avatars/xxx.webp
    return url(ltrim($avatar_path, '/'));
}

/**
 * Best-effort client IP for auditing.
 * Works on typical shared hosting + common reverse-proxy headers.
 */
function client_ip(): string
{
    $ip = '';
    $xff = (string)($_SERVER['HTTP_X_FORWARDED_FOR'] ?? '');
    if ($xff !== '') {
        // First IP in the list
        $ip = trim(explode(',', $xff)[0] ?? '');
    }
    if ($ip === '') {
        $ip = (string)($_SERVER['REMOTE_ADDR'] ?? '');
    }
    // Basic normalization
    return substr($ip, 0, 64);
}

/**
 * Append an audit log entry.
 * This is safe to call from anywhere; failures should not break the app.
 */
function audit_log(?int $actor_id, string $action, ?string $target_type = null, ?int $target_id = null, array $meta = []): void
{
    try {
        require_once __DIR__ . '/settings.php';
        if (!setting_bool('logs_enabled', true)) {
            return;
        }
        $stmt = db()->prepare('INSERT INTO audit_logs (actor_id, action, target_type, target_id, meta_json, ip, created_at) VALUES (?,?,?,?,?,?,?)');
        $stmt->execute([
            $actor_id,
            $action,
            $target_type,
            $target_id,
            json_encode($meta, JSON_UNESCAPED_UNICODE),
            client_ip(),
            now_utc(),
        ]);
    } catch (Throwable $t) {
        // Never hard-fail the app because of audit logging.
    }
}

// -----------------------------------------------------------------------------
// Roles (advanced, support-ready)
// -----------------------------------------------------------------------------

/**
 * Returns the effective role for permission checks.
 *
 * When advanced roles are disabled, the app falls back to a simple model:
 * - 'admin' stays 'admin'
 * - everything else becomes 'public'
 */
function role_effective(string $role): string
{
    try {
        require_once __DIR__ . '/settings.php';
        $adv = setting_bool('advanced_roles_enabled', false);
    } catch (Throwable $t) {
        $adv = false;
    }

    $role = strtolower(trim($role));
    if ($role === 'user') $role = 'public';

    if (!$adv) {
        return ($role === 'admin' || $role === 'super_admin') ? 'admin' : 'public';
    }

    $allowed = ['super_admin','admin','support','public','hidden1','hidden2'];
    return in_array($role, $allowed, true) ? $role : 'public';
}

function is_super_admin_role(string $role): bool
{
    return role_effective($role) === 'super_admin';
}

function is_admin_role(string $role): bool
{
    $r = role_effective($role);
    return ($r === 'admin' || $r === 'super_admin');
}

function is_support_role(string $role): bool
{
    return role_effective($role) === 'support';
}

/**
 * Returns a list of roles visible in the sidebar for a given viewer role.
 */
function visible_roles_for(string $viewer_role): array
{
    $vr = role_effective($viewer_role);

    // Feature flag: mutual visibility between admin and hidden1
    $adminSeeHidden1 = false;
    try {
        require_once __DIR__ . '/settings.php';
        $adminSeeHidden1 = setting_bool('admin_can_see_hidden1', false);
    } catch (Throwable $t) {
        $adminSeeHidden1 = false;
    }

    if ($vr === 'super_admin') {
        return ['super_admin','admin','support','public','hidden1','hidden2'];
    }

    if ($vr === 'admin') {
        $roles = ['super_admin','admin','support','public'];
        if ($adminSeeHidden1) {
            $roles[] = 'hidden1';
        }
        return $roles;
    }

    if ($vr === 'support') {
        return ['super_admin','admin','support','hidden1'];
    }

    if ($vr === 'hidden1') {
        $roles = ['super_admin','support'];
        if ($adminSeeHidden1) {
            $roles[] = 'admin';
        }
        return $roles;
    }

    if ($vr === 'hidden2') {
        return ['super_admin'];
    }

    return ['super_admin','admin','public'];
}

/**
 * Whether the viewer is allowed to chat with the target (direct chat).
 *
 * NOTE: This enforces role visibility rules and is used to block manual URL access.
 */
function can_direct_chat(string $viewer_role, string $target_role, int $viewer_id, int $target_id): bool
{
    if ($viewer_id === $target_id) return false;
    $vr = role_effective($viewer_role);
    $tr = role_effective($target_role);

    // Hidden2 is only visible to super_admin, and can only see super_admin.
    if ($tr === 'hidden2' && $vr !== 'super_admin') return false;
    if ($vr === 'hidden2') {
        return ($tr === 'super_admin');
    }

    // Otherwise, allowed if target role is in viewer's visible role list.
    return in_array($tr, visible_roles_for($vr), true);
}

/**
 * Determines which target roles can be created/assigned by an actor.
 */
function assignable_roles_for(string $actor_role): array
{
    $ar = role_effective($actor_role);
    if ($ar === 'super_admin') {
        return ['super_admin','admin','support','public','hidden1','hidden2'];
    }
    if ($ar === 'admin') {
        return ['admin','support','public','hidden1'];
    }
    // Others cannot assign roles.
    return [];
}


