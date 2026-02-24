<?php
require_once __DIR__ . '/../app/bootstrap.php';

$me = require_login();

$idsRaw = str_param('ids');
if ($idsRaw === '') {
    json_out(['ok' => true, 'groups' => []]);
}

$ids = array_values(array_unique(array_filter(
    array_map('intval', explode(',', $idsRaw)) ,
    fn($x) => $x > 0
)));

if (!$ids) {
    json_out(['ok' => true, 'groups' => []]);
}

// Limit to avoid abuse.
$ids = array_slice($ids, 0, 200);
$placeholders = implode(',', array_fill(0, count($ids), '?'));

$sql = "
    SELECT
        gm.group_id AS gid,
        COUNT(m.id) AS unread_count
    FROM group_members gm
    LEFT JOIN group_reads gr
        ON gr.group_id = gm.group_id AND gr.user_id = gm.user_id
    LEFT JOIN messages m
        ON m.chat_type='group'
        AND m.group_id = gm.group_id
        AND m.deleted_at IS NULL
        AND m.sender_id <> ?
        AND m.id > COALESCE(gr.last_read_message_id, 0)
    WHERE gm.user_id = ?
      AND gm.left_at IS NULL
      AND gm.group_id IN ($placeholders)
    GROUP BY gm.group_id
";

$bind = [(int)$me['id'], (int)$me['id']];
foreach ($ids as $x) { $bind[] = $x; }

$out = [];
try {
    $stmt = db()->prepare($sql);
    $stmt->execute($bind);
    foreach ($stmt->fetchAll() as $r) {
        $out[(int)$r['gid']] = (int)$r['unread_count'];
    }
} catch (Throwable $e) {
    $out = [];
}

json_out(['ok' => true, 'groups' => $out]);
