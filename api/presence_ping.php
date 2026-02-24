<?php
require_once __DIR__ . '/../app/bootstrap.php';

$me = require_login();
csrf_validate();

// Throttle DB writes to keep shared hosting fast.
$now = time();
$last = (int)($_SESSION['presence_last_ping'] ?? 0);
if ($last === 0 || ($now - $last) >= 20) {
    $_SESSION['presence_last_ping'] = $now;
    $stmt = db()->prepare('UPDATE users SET last_seen_at=UTC_TIMESTAMP() WHERE id=?');
    $stmt->execute([(int)$me['id']]);
}

json_out(['ok' => true]);
