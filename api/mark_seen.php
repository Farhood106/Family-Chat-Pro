<?php
require_once __DIR__ . '/../app/bootstrap.php';

$me = require_login();
csrf_validate();


$chat_type = str_param('chat_type', 'direct');
$chat_type = ($chat_type === 'group') ? 'group' : 'direct';

$direct_id = int_param('direct_id');
$group_id = int_param('group_id');
$last_read = int_param('last_read_message_id');
if ($last_read < 0) {
    json_out(['ok' => false, 'error' => 'bad_request'], 400);
}

if ($chat_type === 'group') {
    if ($group_id <= 0) {
        json_out(['ok' => false, 'error' => 'bad_request'], 400);
    }

    // Validate membership
    $gm = db()->prepare('SELECT left_at FROM group_members WHERE group_id=? AND user_id=? LIMIT 1');
    $gm->execute([$group_id, (int)$me['id']]);
    $row = $gm->fetch();
    if (!$row || $row['left_at'] !== null) {
        json_out(['ok' => false, 'error' => 'forbidden'], 403);
    }

    // Upsert read pointer. Only move forward.
    $up = db()->prepare(
        'INSERT INTO group_reads (group_id, user_id, last_read_message_id)
         VALUES (?,?,?)
         ON DUPLICATE KEY UPDATE last_read_message_id = GREATEST(last_read_message_id, VALUES(last_read_message_id))'
    );
    $up->execute([$group_id, (int)$me['id'], $last_read]);
    json_out(['ok' => true]);
}

// Default: direct
if ($direct_id <= 0) {
    json_out(['ok' => false, 'error' => 'bad_request'], 400);
}

// Validate access to direct conversation
$stmt = db()->prepare('SELECT id, user_a, user_b FROM direct_conversations WHERE id=? LIMIT 1');
$stmt->execute([$direct_id]);
$dc = $stmt->fetch();
if (!$dc || ((int)$dc['user_a'] !== (int)$me['id'] && (int)$dc['user_b'] !== (int)$me['id'])) {
    json_out(['ok' => false, 'error' => 'forbidden'], 403);
}

// Upsert read pointer. Only move forward.
$up = db()->prepare(
    'INSERT INTO direct_reads (direct_id, user_id, last_read_message_id)
     VALUES (?,?,?)
     ON DUPLICATE KEY UPDATE last_read_message_id = GREATEST(last_read_message_id, VALUES(last_read_message_id))'
);
$up->execute([$direct_id, (int)$me['id'], $last_read]);

json_out(['ok' => true]);
