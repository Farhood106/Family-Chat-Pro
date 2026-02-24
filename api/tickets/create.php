<?php
require_once __DIR__ . '/../../app/bootstrap.php';

$me = require_login();
$role = (string)($me['role'] ?? 'user');

if (!tickets_requester_allowed($role)) {
    http_response_code(403);
    echo 'Forbidden';
    exit;
}

csrf_validate();
rate_limit('ticket_create', (int)setting_int('message_limit_per_min', 25));

$subject = trim((string)($_POST['subject'] ?? ''));
$priority = trim((string)($_POST['priority'] ?? 'normal'));
$allowedP = ['low','normal','high','urgent'];
if (!in_array($priority, $allowedP, true)) $priority = 'normal';

if ($subject === '' || mb_strlen($subject) > 140) {
    redirect(url('tickets/index.php'));
}

try {
    db()->beginTransaction();

    // Create backing group (private ticket thread)
    $gname = 'Ticket';
    $stmt = db()->prepare('INSERT INTO chat_groups (name, description, created_by, is_ticket, is_active) VALUES (?,?,?,?,1)');
    $stmt->execute([$gname, $subject, (int)$me['id'], 1]);
    $gid = (int)db()->lastInsertId();

    // Create ticket record
    $now = now_utc();
    $stmt = db()->prepare(
        "INSERT INTO tickets (group_id, requester_id, subject, status, priority, assigned_to, created_at, updated_at)
         VALUES (?,?,?,'open',?,?,?,?)"
    );
    $stmt->execute([$gid, (int)$me['id'], $subject, $priority, null, $now, $now]);
    $tid = (int)db()->lastInsertId();

    // Membership: requester
    $m = db()->prepare(
        'INSERT INTO group_members (group_id, user_id, joined_at, left_at) VALUES (?,?,?,NULL)
         ON DUPLICATE KEY UPDATE left_at=NULL'
    );
    $m->execute([$gid, (int)$me['id'], $now]);

    // ---------------------------------------------------------------------
    // Staff pools (auto-add members vs auto-assign candidates)
    // - New keys (preferred):
    //   tickets_auto_add_admin / tickets_auto_add_super_admin / tickets_auto_add_support
    //   tickets_assign_admin / tickets_assign_super_admin / tickets_assign_support
    // - Backward compatibility (fallback):
    //   tickets_auto_add_admins (admin + super_admin)
    //   tickets_auto_add_support
    // ---------------------------------------------------------------------

    // Detect whether new keys exist (so we don't accidentally override old installs)
    $hasNewAutoAdd =
        setting_get('tickets_auto_add_admin') !== null ||
        setting_get('tickets_auto_add_super_admin') !== null ||
        setting_get('tickets_auto_add_support') !== null;

    $hasNewAssign =
        setting_get('tickets_assign_admin') !== null ||
        setting_get('tickets_assign_super_admin') !== null ||
        setting_get('tickets_assign_support') !== null;

    // Auto-add roles
    $addRoles = [];
    if ($hasNewAutoAdd) {
        if (setting_bool('tickets_auto_add_admin', true)) $addRoles[] = 'admin';
        if (setting_bool('tickets_auto_add_super_admin', true)) $addRoles[] = 'super_admin';
        if (setting_bool('tickets_auto_add_support', true)) $addRoles[] = 'support';
    } else {
        // Old behavior
        if (setting_bool('tickets_auto_add_admins', true)) {
            $addRoles[] = 'admin';
            $addRoles[] = 'super_admin';
        }
        if (setting_bool('tickets_auto_add_support', true)) {
            $addRoles[] = 'support';
        }
    }
    $addRoles = array_values(array_unique($addRoles));

    // Auto-assign roles (eligible candidates)
    // If new assign keys are not present, default to whatever addRoles resolved to.
    $assignRoles = [];
    if ($hasNewAssign) {
        if (setting_bool('tickets_assign_admin', true)) $assignRoles[] = 'admin';
        if (setting_bool('tickets_assign_super_admin', true)) $assignRoles[] = 'super_admin';
        if (setting_bool('tickets_assign_support', true)) $assignRoles[] = 'support';
    } else {
        $assignRoles = $addRoles;
    }
    $assignRoles = array_values(array_unique($assignRoles));

    // Auto-add staff as members
    $staffIds = [];
    if ($addRoles) {
        $ph = implode(',', array_fill(0, count($addRoles), '?'));
        $q = db()->prepare("SELECT id FROM users WHERE is_active=1 AND role IN ($ph)");
        $q->execute($addRoles);
        foreach ($q->fetchAll() as $r) {
            $uid = (int)$r['id'];
            $staffIds[] = $uid;
            $m->execute([$gid, $uid, $now]);
        }
    }
    $staffIds = array_values(array_unique(array_filter($staffIds)));

    // ---------------------------
    // Professional auto-assignment
    // ---------------------------
    $autoAssign = setting_bool('tickets_auto_assign', true);
    $assignMode = (int)setting_int('tickets_assign_mode', 1); // 0 off, 1 least-open, 2 round-robin
    if ($assignMode < 0) $assignMode = 0;
    if ($assignMode > 2) $assignMode = 2;

    $assigneeId = null;

    if ($autoAssign && $assignMode !== 0 && $assignRoles) {
        // Exclude requester from candidates just in case
        $reqId = (int)$me['id'];
        $ph = implode(',', array_fill(0, count($assignRoles), '?'));

        if ($assignMode === 2) {
            // Round-robin using a cursor in app_settings (no new tables)
            $cursor = (int)setting_int('tickets_rr_cursor', 0);

            $q = db()->prepare(
                "SELECT id FROM users
                 WHERE is_active=1 AND role IN ($ph) AND id<>?
                 ORDER BY id ASC"
            );
            $q->execute(array_merge($assignRoles, [$reqId]));
            $cands = array_map(fn($x) => (int)$x['id'], $q->fetchAll());

            if ($cands) {
                $pick = null;
                foreach ($cands as $cid) {
                    if ($cid > $cursor) { $pick = $cid; break; }
                }
                if ($pick === null) $pick = $cands[0];

                $assigneeId = (int)$pick;
                setting_set('tickets_rr_cursor', (string)$assigneeId);
            }
        } else {
            // Least-open tickets (recommended)
            $q = db()->prepare(
                "SELECT u.id, COUNT(t.id) AS open_cnt
                 FROM users u
                 LEFT JOIN tickets t
                   ON t.assigned_to = u.id
                  AND t.status IN ('open','pending')
                 WHERE u.is_active=1
                   AND u.role IN ($ph)
                   AND u.id <> ?
                 GROUP BY u.id
                 ORDER BY open_cnt ASC, u.id ASC
                 LIMIT 1"
            );
            $q->execute(array_merge($assignRoles, [$reqId]));
            $row = $q->fetch();
            if ($row) {
                $assigneeId = (int)$row['id'];
            }
        }
    }

    if ($assigneeId !== null && $assigneeId > 0) {
        // Set assigned_to
        $up = db()->prepare("UPDATE tickets SET assigned_to=?, updated_at=? WHERE id=?");
        $up->execute([$assigneeId, $now, $tid]);

        // Ensure assignee is member even if auto-add for their role is off
        $m->execute([$gid, $assigneeId, $now]);

        // Ticket event: assigned
        $ev = db()->prepare(
            'INSERT INTO ticket_events (ticket_id, actor_id, event_type, meta_json, created_at)
             VALUES (?,?,?,?,?)'
        );
        $ev->execute([
            $tid,
            (int)$me['id'],
            'assigned',
            json_encode(['assigned_to'=>$assigneeId,'mode'=>$assignMode,'roles'=>$assignRoles], JSON_UNESCAPED_UNICODE),
            $now
        ]);

        audit_log((int)$me['id'], 'ticket_auto_assigned', 'ticket', $tid, [
            'assigned_to' => $assigneeId,
            'mode' => $assignMode,
            'roles' => $assignRoles,
        ]);
    }

    // Event log: created
    $ev = db()->prepare(
        'INSERT INTO ticket_events (ticket_id, actor_id, event_type, meta_json, created_at)
         VALUES (?,?,?,?,?)'
    );
    $ev->execute([
        $tid,
        (int)$me['id'],
        'created',
        json_encode(['subject'=>$subject,'priority'=>$priority], JSON_UNESCAPED_UNICODE),
        $now
    ]);

    audit_log((int)$me['id'], 'ticket_create', 'ticket', $tid, [
        'group_id' => $gid,
        'priority' => $priority
    ]);

    db()->commit();

    redirect(url('tickets/view.php?id=' . $tid));
} catch (Throwable $e) {
    try { db()->rollBack(); } catch (Throwable $t) {}
    audit_log((int)$me['id'], 'ticket_create_failed', 'user', (int)$me['id'], ['err'=>$e->getMessage()]);
    redirect(url('tickets/index.php'));
}