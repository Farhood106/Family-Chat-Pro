<?php
require_once __DIR__ . '/../app/bootstrap.php';

$me = require_login();

csrf_validate();

if (!setting_bool('delete_enabled', true)) {
    json_out(['ok'=>false,'error'=>'DISABLED'], 403);
}

$id = int_param('id');
if ($id <= 0) json_out(['ok'=>false,'error'=>'BAD_ID'], 400);

$stmt = db()->prepare('SELECT id, chat_type, direct_id, group_id, sender_id, deleted_at FROM messages WHERE id=? LIMIT 1');
$stmt->execute([$id]);
$msg = $stmt->fetch();
if (!$msg) json_out(['ok'=>false,'error'=>'NOT_FOUND'], 404);
if ($msg['deleted_at'] !== null) json_out(['ok'=>true]);

$is_admin = is_admin_role((string)($me['role'] ?? 'user'));
$can = $is_admin || ((int)$msg['sender_id'] === (int)$me['id']);
if (!$can) json_out(['ok'=>false,'error'=>'FORBIDDEN'], 403);

// Access checks
if ($msg['chat_type'] === 'direct') {
    $d = db()->prepare('SELECT id FROM direct_conversations WHERE id=? AND (user_a=? OR user_b=?) LIMIT 1');
    $d->execute([(int)$msg['direct_id'], (int)$me['id'], (int)$me['id']]);
    if (!$d->fetch()) json_out(['ok'=>false,'error'=>'FORBIDDEN'], 403);
} else {
    $m = db()->prepare('SELECT 1 FROM group_members WHERE group_id=? AND user_id=? AND left_at IS NULL LIMIT 1');
    $m->execute([(int)$msg['group_id'], (int)$me['id']]);
    if (!$m->fetch()) json_out(['ok'=>false,'error'=>'FORBIDDEN'], 403);
}

$upd = db()->prepare('UPDATE messages SET deleted_at=?, deleted_by=? WHERE id=?');
$upd->execute([now_utc(), (int)$me['id'], $id]);
json_out(['ok'=>true]);
