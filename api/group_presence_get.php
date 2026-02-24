<?php
require_once __DIR__ . '/../app/bootstrap.php';

$me = require_login();

$group_id = int_param('group_id');
if ($group_id <= 0) {
    json_out(['ok' => false, 'error' => 'bad_request'], 400);
}

// Must be a member to see presence.
$m = db()->prepare('SELECT left_at FROM group_members WHERE group_id=? AND user_id=? LIMIT 1');
$m->execute([$group_id, (int)$me['id']]);
$row = $m->fetch();
if (!$row || $row['left_at'] !== null) {
    json_out(['ok' => false, 'error' => 'not_member'], 403);
}

$cut = gmdate('Y-m-d H:i:s', time() - 60);
$stmt = db()->prepare(
    'SELECT COUNT(*) AS c
     FROM group_members gm
     JOIN users u ON u.id=gm.user_id
     WHERE gm.group_id=? AND gm.left_at IS NULL AND u.is_active=1 AND u.last_seen_at IS NOT NULL AND u.last_seen_at>=?'
);
$stmt->execute([$group_id, $cut]);
$count = (int)($stmt->fetch()['c'] ?? 0);

json_out(['ok' => true, 'online_count' => $count]);
