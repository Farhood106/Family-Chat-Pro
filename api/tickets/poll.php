<?php
require_once __DIR__ . '/../../app/bootstrap.php';

$me = require_login();
$role = (string)($me['role'] ?? 'user');

if (!tickets_enabled()) {
    json_out(['ok' => false, 'error' => 'tickets_disabled'], 400);
}

csrf_validate();

$ids = $_POST['ticket_ids'] ?? [];
if (!is_array($ids)) $ids = [];

$ticketIds = [];
foreach ($ids as $x) {
    $id = (int)$x;
    if ($id > 0) $ticketIds[] = $id;
}
$ticketIds = array_values(array_unique($ticketIds));

if (!$ticketIds) {
    json_out(['ok' => true, 'items' => []]);
}

$ph = implode(',', array_fill(0, count($ticketIds), '?'));

try {
    // Admin can poll all tickets, but unread is per current user anyway.
    // We also return group_id + updated_at + status + priority.
    $stmt = db()->prepare(
        "SELECT t.id, t.group_id, t.status, t.priority, t.updated_at
         FROM tickets t
         WHERE t.id IN ($ph)"
    );
    $stmt->execute($ticketIds);
    $rows = $stmt->fetchAll();
} catch (Throwable $e) {
    json_out(['ok' => false, 'error' => 'db_error'], 500);
}

$gids = [];
foreach ($rows as $r) {
    $gid = (int)($r['group_id'] ?? 0);
    if ($gid > 0) $gids[] = $gid;
}
$gids = array_values(array_unique($gids));

$unread = [];
try {
    if ($gids) {
        $unread = ticket_unread_counts((int)$me['id'], $gids);
    }
} catch (Throwable $e) {
    $unread = [];
}

$out = [];
foreach ($rows as $r) {
    $gid = (int)$r['group_id'];
    $out[] = [
        'id' => (int)$r['id'],
        'group_id' => $gid,
        'updated_at' => (string)$r['updated_at'],
        'status' => (string)$r['status'],
        'priority' => (string)$r['priority'],
        'unread' => (int)($unread[$gid] ?? 0),
    ];
}

json_out(['ok' => true, 'items' => $out]);
