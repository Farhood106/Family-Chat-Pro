<?php
// app/tickets.php

declare(strict_types=1);

/**
 * Ticket module feature flag.
 */
function tickets_enabled(): bool
{
    try {
        return setting_bool('tickets_enabled', false);
    } catch (Throwable $t) {
        return false;
    }
}

/**
 * Whether the given role is allowed to use tickets as a requester.
 *
 * Default: only hidden1 (support customers).
 */
function tickets_requester_allowed(string $role): bool
{
    if (!tickets_enabled()) return false;
    $r = role_effective($role);
    if ($r === 'hidden1') {
        return setting_bool('tickets_for_hidden1', true);
    }
    return false;
}

/**
 * Whether a role can manage/support tickets.
 */
function tickets_staff_allowed(string $role): bool
{
    if (!tickets_enabled()) return false;
    $r = role_effective($role);
    return in_array($r, ['support','admin','super_admin'], true);
}

/**
 * User can view ticket if they are requester or staff AND member of the backing group.
 */
function can_view_ticket(int $user_id, string $user_role, array $ticket_row): bool
{
    $r = role_effective($user_role);
    if ((int)$ticket_row['requester_id'] === $user_id) return true;
    return tickets_staff_allowed($r);
}

/**
 * Get unread counts for ticket groups for a user (member-only).
 */
function ticket_unread_counts(int $user_id, array $group_ids): array
{
    $out = [];
    $group_ids = array_values(array_filter(array_unique(array_map('intval', $group_ids)), fn($x) => $x > 0));
    if (!$group_ids) return $out;

    $ph = implode(',', array_fill(0, count($group_ids), '?'));
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
          AND gm.group_id IN ($ph)
        GROUP BY gm.group_id
    ";
    $bind = [$user_id, $user_id];
    foreach ($group_ids as $g) { $bind[] = $g; }
    $q = db()->prepare($sql);
    $q->execute($bind);
    foreach ($q->fetchAll() as $r) {
        $out[(int)$r['gid']] = (int)$r['unread_count'];
    }
    return $out;
}
