<?php
require_once __DIR__ . '/../app/bootstrap.php';

$me = require_login();

if (!setting_bool('search_enabled', true)) {
    json_out(['ok'=>false,'error'=>'DISABLED'], 403);
}

$q = str_param('q');
$q = trim($q);
if ($q === '' || mb_strlen($q) < 2) {
    json_out(['ok'=>false,'error'=>'QUERY_TOO_SHORT'], 400);
}

$chat_type = str_param('chat_type');
$direct_id = int_param('direct_id');
$group_id = int_param('group_id');
$limit = 50;

// Access checks
if ($chat_type === 'direct') {
    $stmt = db()->prepare('SELECT user_a, user_b FROM direct_conversations WHERE id=?');
    $stmt->execute([$direct_id]);
    $c = $stmt->fetch();
    if (!$c || ((int)$c['user_a'] !== (int)$me['id'] && (int)$c['user_b'] !== (int)$me['id'])) {
        json_out(['ok'=>false,'error'=>'FORBIDDEN'], 403);
    }
    $where = 'chat_type=\'direct\' AND direct_id=?';
    $params = [$direct_id];
} elseif ($chat_type === 'group') {
    $stmt = db()->prepare('SELECT left_at FROM group_members WHERE group_id=? AND user_id=? LIMIT 1');
    $stmt->execute([$group_id, $me['id']]);
    $m = $stmt->fetch();
    if (!$m || $m['left_at'] !== null) {
        json_out(['ok'=>false,'error'=>'FORBIDDEN'], 403);
    }
    $where = 'chat_type=\'group\' AND group_id=?';
    $params = [$group_id];
} else {
    json_out(['ok'=>false,'error'=>'BAD_CHAT'], 400);
}

$sql = "SELECT m.id, m.body, m.created_at, u.display_name AS sender_name
        FROM messages m
        JOIN users u ON u.id=m.sender_id
        WHERE {$where} AND m.deleted_at IS NULL AND m.body LIKE ?
        ORDER BY m.id DESC
        LIMIT {$limit}";

$params[] = '%' . $q . '%';
$stmt = db()->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();

json_out(['ok'=>true,'results'=>$rows]);
