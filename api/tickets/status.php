<?php
require_once __DIR__ . '/../../app/bootstrap.php';

$me = require_login();
$lang = $me['lang'] ?? 'fa';
$role = (string)($me['role'] ?? 'user');

csrf_validate();

$tid = (int)($_POST['ticket_id'] ?? 0);
$status = trim((string)($_POST['status'] ?? ''));
$allowed = ['open','pending','solved','closed'];
if ($tid <= 0 || !in_array($status, $allowed, true)) {
    redirect(url('tickets/index.php'));
}

$stmt = db()->prepare('SELECT id, group_id, requester_id, status FROM tickets WHERE id=? LIMIT 1');
$stmt->execute([$tid]);
$t = $stmt->fetch();
if (!$t) redirect(url('tickets/index.php'));

$isStaff = tickets_staff_allowed($role);
$isRequester = ((int)$t['requester_id'] === (int)$me['id']);
$canRequesterClose = setting_bool('tickets_allow_requester_close', true);

if (!$isStaff) {
    if (!($isRequester && $canRequesterClose && $status === 'closed')) {
        http_response_code(403);
        echo 'Forbidden';
        exit;
    }
}

try {
    $now = now_utc();
    $upd = db()->prepare('UPDATE tickets SET status=?, updated_at=? WHERE id=?');
    $upd->execute([$status, $now, $tid]);

    $ev = db()->prepare('INSERT INTO ticket_events (ticket_id, actor_id, event_type, meta_json, created_at) VALUES (?,?,?,?,?)');
    $ev->execute([$tid, (int)$me['id'], 'status', json_encode(['from'=>$t['status'],'to'=>$status], JSON_UNESCAPED_UNICODE), $now]);

    audit_log((int)$me['id'], 'ticket_status', 'ticket', $tid, ['from'=>$t['status'], 'to'=>$status]);
} catch (Throwable $e) {
    audit_log((int)$me['id'], 'ticket_status_failed', 'ticket', $tid, ['err'=>$e->getMessage()]);
}

redirect(url('tickets/view.php?id=' . $tid));
