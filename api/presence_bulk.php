<?php
// api/presence_bulk.php
// Returns presence (online/last_seen) for a list of user ids.

require_once __DIR__ . '/../app/bootstrap.php';

$me = require_login();

$lang = str_param('lang', (string)($me['lang'] ?? 'fa'));
$lang = ($lang === 'en') ? 'en' : 'fa';

$idsRaw = str_param('ids'); // comma separated (GET)
if ($idsRaw === '') {
    json_out(['ok' => true, 'users' => []]);
}

$ids = array_values(array_unique(array_filter(array_map('intval', explode(',', $idsRaw)), fn($x) => $x > 0)));
if (!$ids) {
    json_out(['ok' => true, 'users' => []]);
}

// Limit to avoid abuse.
$ids = array_slice($ids, 0, 200);

$placeholders = implode(',', array_fill(0, count($ids), '?'));
$stmt = db()->prepare("SELECT id, last_seen_at FROM users WHERE is_active=1 AND id IN ($placeholders)");
$stmt->execute($ids);
$rows = $stmt->fetchAll();


// Compute unread counts for direct chats (messages from each user not yet read by me).
$unreadMap = [];
try {
    // Only count messages in existing direct conversations.
    $ph = implode(',', array_fill(0, count($ids), '?'));

    $sql = "
        SELECT
            IF(dc.user_a = ?, dc.user_b, dc.user_a) AS other_id,
            COUNT(m.id) AS unread_count
        FROM direct_conversations dc
        LEFT JOIN direct_reads dr
            ON dr.direct_id = dc.id AND dr.user_id = ?
        LEFT JOIN messages m
            ON m.chat_type = 'direct'
            AND m.direct_id = dc.id
            AND m.deleted_at IS NULL
            AND m.sender_id = IF(dc.user_a = ?, dc.user_b, dc.user_a)
            AND m.id > COALESCE(dr.last_read_message_id, 0)
        WHERE (dc.user_a = ? OR dc.user_b = ?)
          AND IF(dc.user_a = ?, dc.user_b, dc.user_a) IN ($ph)
        GROUP BY other_id
    ";

    // Bind: me, me, me, me, me, me + ids...
    $bind = [$me['id'], $me['id'], $me['id'], $me['id'], $me['id'], $me['id']];
    foreach ($ids as $x) { $bind[] = $x; }

    $q = db()->prepare($sql);
    $q->execute($bind);
    foreach ($q->fetchAll() as $r) {
        $unreadMap[(int)$r['other_id']] = (int)$r['unread_count'];
    }
} catch (Throwable $e) {
    // Ignore unread computation errors; presence still works.
    $unreadMap = [];
}

$out = [];
foreach ($rows as $r) {
    $last = $r['last_seen_at'] ?? null;
    $out[(int)$r['id']] = [
        'is_online' => is_online($last),
        'last_seen_at' => $last,
        'label' => presence_label($last, $lang),
        'unread_count' => (int)($unreadMap[(int)$r['id']] ?? 0),
    ];
}

json_out(['ok' => true, 'users' => $out]);
