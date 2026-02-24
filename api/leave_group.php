<?php
require_once __DIR__ . '/../app/bootstrap.php';

$me = require_login();

csrf_validate();

if (!setting_bool('allow_leave_group', true)) {
    json_out(['ok'=>false,'error'=>'DISABLED'], 403);
}

$gid = int_param('group_id');
if ($gid <= 0) json_out(['ok'=>false,'error'=>'BAD_GROUP'], 400);

$stmt = db()->prepare('UPDATE group_members SET left_at=? WHERE group_id=? AND user_id=?');
$stmt->execute([now_utc(), $gid, $me['id']]);
json_out(['ok'=>true]);
