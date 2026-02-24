<?php
require_once __DIR__ . '/../app/bootstrap.php';

$me = require_login();
$chat_type = str_param('chat_type');
$direct_id = int_param('direct_id');
$group_id = int_param('group_id');
$since_id = int_param('since_id', 0);
$before_id = int_param('before_id', 0);
$include_typing = int_param('include_typing', 0) === 1;
$limit = setting_int('messages_per_page', 30);
$limit = max(10, min($limit, 100));

function ensure_direct_access(int $direct_id, int $me_id): array
{
    $stmt = db()->prepare('SELECT id, user_a, user_b FROM direct_conversations WHERE id=? LIMIT 1');
    $stmt->execute([$direct_id]);
    $dc = $stmt->fetch();
    if (!$dc || ((int)$dc['user_a'] !== $me_id && (int)$dc['user_b'] !== $me_id)) {
        json_out(['ok'=>false,'error'=>'forbidden'], 403);
    }
    return $dc;
}

function ensure_group_access(int $group_id, int $me_id): void
{
    $m = db()->prepare('SELECT left_at FROM group_members WHERE group_id=? AND user_id=? LIMIT 1');
    $m->execute([$group_id, $me_id]);
    $row = $m->fetch();
    if (!$row || $row['left_at'] !== null) {
        json_out(['ok'=>false,'error'=>'not_member'], 403);
    }
}

if ($chat_type === 'direct') {
    if ($direct_id <= 0) json_out(['ok'=>false,'error'=>'bad_request'], 400);
    $dc = ensure_direct_access($direct_id, (int)$me['id']);

    $where = 'chat_type=\'direct\' AND direct_id=?';
    $params = [$direct_id];
    if ($since_id > 0) {
        $where .= ' AND m.id>?';
        $params[] = $since_id;
        $order = 'ASC';
    } elseif ($before_id > 0) {
        $where .= ' AND m.id<?';
        $params[] = $before_id;
        $order = 'DESC';
    } else {
        $order = 'DESC';
    }

    $sql = "SELECT m.id, m.sender_id, u.display_name, m.body, m.file_path, m.file_name, m.file_mime, m.file_size,
                   m.deleted_at, m.deleted_by, m.created_at
            FROM messages m
            JOIN users u ON u.id=m.sender_id
            WHERE $where
            ORDER BY m.id $order
            LIMIT $limit";
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    if ($order === 'DESC') {
        $rows = array_reverse($rows);
    }

    // Read receipts: other user's last read message id
    $other_id = ((int)$dc['user_a'] === (int)$me['id']) ? (int)$dc['user_b'] : (int)$dc['user_a'];
    $rStmt = db()->prepare('SELECT last_read_message_id FROM direct_reads WHERE direct_id=? AND user_id=? LIMIT 1');
    $rStmt->execute([$direct_id, $other_id]);
    $other_last_read = (int)($rStmt->fetch()['last_read_message_id'] ?? 0);

    $resp = ['ok'=>true,'messages'=>normalize_messages($rows, $me, $other_last_read)];
    $resp['other_last_read_message_id'] = $other_last_read;
    if ($include_typing && setting_bool('typing_enabled', true)) {
        $resp['typing'] = fetch_typing_users('direct', $direct_id, 0, (int)$me['id']);
    }
    json_out($resp);
}

if ($chat_type === 'group') {
    if ($group_id <= 0) json_out(['ok'=>false,'error'=>'bad_request'], 400);
    ensure_group_access($group_id, (int)$me['id']);

    $where = 'chat_type=\'group\' AND group_id=?';
    $params = [$group_id];
    if ($since_id > 0) {
        $where .= ' AND m.id>?';
        $params[] = $since_id;
        $order = 'ASC';
    } elseif ($before_id > 0) {
        $where .= ' AND m.id<?';
        $params[] = $before_id;
        $order = 'DESC';
    } else {
        $order = 'DESC';
    }

    $sql = "SELECT m.id, m.sender_id, u.display_name, m.body, m.file_path, m.file_name, m.file_mime, m.file_size,
                   m.deleted_at, m.deleted_by, m.created_at
            FROM messages m
            JOIN users u ON u.id=m.sender_id
            WHERE $where
            ORDER BY m.id $order
            LIMIT $limit";
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    if ($order === 'DESC') {
        $rows = array_reverse($rows);
    }

    $resp = ['ok'=>true,'messages'=>normalize_messages($rows, $me)];
    if ($include_typing && setting_bool('typing_enabled', true)) {
        $resp['typing'] = fetch_typing_users('group', 0, $group_id, (int)$me['id']);
    }
    json_out($resp);
}

json_out(['ok'=>false,'error'=>'bad_request'], 400);

function normalize_messages(array $rows, array $me, int $other_last_read = 0): array
{
    $me_id = (int)$me['id'];
    $is_admin = is_admin_role((string)($me['role'] ?? 'user'));
    $lang = (string)($me['lang'] ?? 'fa');
    $out = [];
    foreach ($rows as $r) {
        $deleted = ($r['deleted_at'] !== null);
        $can_delete = !$deleted && ($is_admin || ((int)$r['sender_id'] === $me_id));
        $tf = chat_time_fields((string)($r['created_at'] ?? ''), $lang);
        $out[] = [
            'id' => (int)$r['id'],
            'sender_id' => (int)$r['sender_id'],
            'sender_name' => (string)$r['display_name'],
            'mine' => ((int)$r['sender_id'] === $me_id),
            // Only meaningful for direct chats: whether other side has read this message
            'seen' => (((int)$r['sender_id'] === $me_id) && ((int)$r['id'] <= $other_last_read)),
            'body' => $deleted ? '' : (string)($r['body'] ?? ''),
            'deleted' => $deleted,
            'can_delete' => $can_delete,
            // Use server-converted local fields to avoid timezone/JS inconsistencies.
            'time_text' => (string)$tf['time_text'],
            'day_key' => (string)$tf['day_key'],
            'day_label' => (string)$tf['day_label'],
            'datetime_text' => (string)$tf['datetime_text'],
            'created_at' => (string)$r['created_at'],
            'file' => ($deleted || empty($r['file_path'])) ? null : [
                'url' => url('api/file.php?id=' . (int)$r['id']),
                'name' => (string)($r['file_name'] ?? ''),
                'mime' => (string)($r['file_mime'] ?? ''),
                'size' => (int)($r['file_size'] ?? 0),
                'is_image' => is_string($r['file_mime']) && str_starts_with($r['file_mime'], 'image/'),
            ],
        ];
    }
    return $out;
}

function fetch_typing_users(string $chat_type, int $direct_id, int $group_id, int $me_id): array
{
    $cut = gmdate('Y-m-d H:i:s', time() - 5);
    if ($chat_type === 'direct') {
        $stmt = db()->prepare('SELECT u.display_name FROM typing_status t JOIN users u ON u.id=t.user_id WHERE t.chat_type=\'direct\' AND t.direct_id=? AND t.last_ping_at>=? AND t.user_id<>?');
        $stmt->execute([$direct_id, $cut, $me_id]);
        return array_values(array_map(fn($r)=>(string)$r['display_name'], $stmt->fetchAll()));
    }
    $stmt = db()->prepare('SELECT u.display_name FROM typing_status t JOIN users u ON u.id=t.user_id WHERE t.chat_type=\'group\' AND t.group_id=? AND t.last_ping_at>=? AND t.user_id<>?');
    $stmt->execute([$group_id, $cut, $me_id]);
    return array_values(array_map(fn($r)=>(string)$r['display_name'], $stmt->fetchAll()));
}
