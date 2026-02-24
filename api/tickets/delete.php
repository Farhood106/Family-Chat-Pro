<?php
// api/tickets/delete.php
require_once __DIR__ . '/../../app/bootstrap.php';

$me = require_login();
$role = (string)($me['role'] ?? 'user');

if (!tickets_enabled()) {
    http_response_code(403);
    echo 'Forbidden';
    exit;
}

if (!is_admin_role($role)) {
    http_response_code(403);
    echo 'Forbidden';
    exit;
}

csrf_validate();
rate_limit('ticket_delete', 10); // خیلی هم مهربونیم.

$tid = int_param('ticket_id');
if ($tid <= 0) {
    redirect(url('tickets/all.php'));
}

try {
    db()->beginTransaction();

    $stmt = db()->prepare("SELECT id, group_id, subject, status FROM tickets WHERE id=? LIMIT 1");
    $stmt->execute([$tid]);
    $t = $stmt->fetch();

    if (!$t) {
        db()->rollBack();
        redirect(url('tickets/all.php'));
    }

    $gid = (int)($t['group_id'] ?? 0);
    if ($gid <= 0) {
        db()->rollBack();
        redirect(url('tickets/all.php'));
    }

    // Make sure it's really a ticket group
    $g = db()->prepare("SELECT id, is_ticket, is_active FROM chat_groups WHERE id=? LIMIT 1");
    $g->execute([$gid]);
    $grp = $g->fetch();
    if (!$grp || (int)($grp['is_ticket'] ?? 0) !== 1) {
        db()->rollBack();
        http_response_code(400);
        echo 'Bad request';
        exit;
    }

    $now = now_utc();

    // Soft delete: deactivate group so it disappears from chat flows
    $upg = db()->prepare("UPDATE chat_groups SET is_active=0 WHERE id=?");
    $upg->execute([$gid]);

    // Close ticket (doesn't hurt)
    $upt = db()->prepare("UPDATE tickets SET status='closed', updated_at=? WHERE id=?");
    $upt->execute([$now, $tid]);

    // Ticket event: deleted
    $ev = db()->prepare(
        "INSERT INTO ticket_events (ticket_id, actor_id, event_type, meta_json, created_at)
         VALUES (?,?,?,?,?)"
    );
    $ev->execute([
        $tid,
        (int)$me['id'],
        'deleted',
        json_encode(['group_id' => $gid], JSON_UNESCAPED_UNICODE),
        $now
    ]);

    audit_log((int)$me['id'], 'ticket_deleted', 'ticket', $tid, [
        'group_id' => $gid,
        'subject' => (string)($t['subject'] ?? ''),
    ]);

    db()->commit();

    redirect(url('tickets/all.php'));
} catch (Throwable $e) {
    try { db()->rollBack(); } catch (Throwable $t) {}
    audit_log((int)$me['id'], 'ticket_delete_failed', 'ticket', $tid, ['err' => $e->getMessage()]);
    redirect(url('tickets/all.php'));
}
