<?php
require_once __DIR__ . '/../app/bootstrap.php';

$me = require_login();

csrf_validate();
$gid = int_param('group_id');
if ($gid <= 0) json_out(['ok'=>false,'error'=>'BAD_GROUP'], 400);

$g = db()->prepare('SELECT id FROM chat_groups WHERE id=? AND is_active=1 AND is_ticket=0');
$g->execute([$gid]);
if (!$g->fetch()) json_out(['ok'=>false,'error'=>'NOT_FOUND'], 404);

// Join
$stmt = db()->prepare('INSERT INTO group_members (group_id, user_id, role, joined_at, left_at) VALUES (?,?,?,?,NULL)
  ON DUPLICATE KEY UPDATE left_at=NULL, joined_at=VALUES(joined_at)');
$stmt->execute([$gid, (int)$me['id'], 'member', now_utc()]);
json_out(['ok'=>true]);
