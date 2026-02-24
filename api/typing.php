<?php
require_once __DIR__ . '/../app/bootstrap.php';

$me = require_login();

csrf_validate();

if (!setting_bool('typing_enabled', true)) {
    json_out(['ok'=>true,'disabled'=>true]);
}

$chat_type = str_param('chat_type');
$direct_id = int_param('direct_id');
$group_id = int_param('group_id');

$now = now_utc();

if ($chat_type === 'direct') {
    $stmt = db()->prepare('SELECT user_a, user_b FROM direct_conversations WHERE id=? LIMIT 1');
    $stmt->execute([$direct_id]);
    $c = $stmt->fetch();
    if (!$c || ((int)$c['user_a'] !== (int)$me['id'] && (int)$c['user_b'] !== (int)$me['id'])) {
        json_out(['ok'=>false,'error'=>'FORBIDDEN'], 403);
    }
} elseif ($chat_type === 'group') {
    $m = db()->prepare('SELECT left_at FROM group_members WHERE group_id=? AND user_id=? LIMIT 1');
    $m->execute([$group_id, $me['id']]);
    $row = $m->fetch();
    if (!$row || $row['left_at'] !== null) {
        json_out(['ok'=>false,'error'=>'NOT_MEMBER'], 403);
    }
} else {
    json_out(['ok'=>false,'error'=>'BAD_TYPE'], 400);
}

$up = db()->prepare('INSERT INTO typing_status (chat_type, direct_id, group_id, user_id, last_ping_at) VALUES (?,?,?,?,?)
    ON DUPLICATE KEY UPDATE last_ping_at=VALUES(last_ping_at)');
$up->execute([$chat_type, $chat_type==='direct'?$direct_id:null, $chat_type==='group'?$group_id:null, $me['id'], $now]);

json_out(['ok'=>true]);
