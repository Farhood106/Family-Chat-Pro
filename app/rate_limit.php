<?php
// app/rate_limit.php

declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';


function rate_limit_check(?int $user_id, string $action, int $limit_per_min): bool
{
    $ip = client_ip();
    $window_start = gmdate('Y-m-d H:i:00');

    $stmt = db()->prepare('SELECT id, counter FROM rate_limits WHERE user_id <=> ? AND ip=? AND action=? AND window_start=? LIMIT 1');
    $stmt->execute([$user_id, $ip, $action, $window_start]);
    $row = $stmt->fetch();

    if (!$row) {
        $ins = db()->prepare('INSERT INTO rate_limits (user_id, ip, action, window_start, counter) VALUES (?,?,?,?,1)');
        $ins->execute([$user_id, $ip, $action, $window_start]);
        return true;
    }

    $counter = (int)$row['counter'];
    if ($counter >= $limit_per_min) {
        return false;
    }

    $upd = db()->prepare('UPDATE rate_limits SET counter=counter+1 WHERE id=?');
    $upd->execute([$row['id']]);
    return true;
}

/**
 * Enforce rate limit and hard-fail with HTTP 429 when exceeded.
 *
 * @param string $action          Action key (e.g. 'ticket_create')
 * @param int    $limit_per_min   Max hits per minute
 */
function rate_limit(string $action, int $limit_per_min = 30): void
{
    $uid = null;

    // Best-effort: if user session exists, bucket by user id too.
    if (isset($_SESSION) && isset($_SESSION['user']) && is_array($_SESSION['user']) && isset($_SESSION['user']['id'])) {
        $uid = (int)$_SESSION['user']['id'];
    }

    // Clamp to sane range to avoid misconfig killing the app
    if ($limit_per_min < 1) $limit_per_min = 1;
    if ($limit_per_min > 600) $limit_per_min = 600;

    try {
        $ok = rate_limit_check($uid, $action, $limit_per_min);
    } catch (Throwable $e) {
        // Fail-open: if rate limit storage breaks, don't break the whole app.
        return;
    }

    if (!$ok) {
        http_response_code(429);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'error' => 'rate_limited'], JSON_UNESCAPED_UNICODE);
        exit;
    }
}
