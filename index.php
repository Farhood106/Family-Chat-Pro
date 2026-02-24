<?php
require_once __DIR__ . '/app/bootstrap.php';
require_once __DIR__ . '/app/view.php';

$u = require_login();
$lang = $u['lang'] ?? 'fa';

$meId = (int)$u['id'];
$vr = (string)($u['role'] ?? 'user');

// -------------------------
// Direct chat sidebar users
// -------------------------
$visibleRoles = visible_roles_for($vr);
$ph = implode(',', array_fill(0, count($visibleRoles), '?'));
$sqlUsers = "SELECT id, display_name, username, role, last_seen_at, avatar_path
             FROM users
             WHERE is_active=1 AND id<>? AND role IN ($ph)
             ORDER BY display_name";
$usersStmt = db()->prepare($sqlUsers);
$usersStmt->execute(array_merge([$meId], $visibleRoles));
$users = $usersStmt->fetchAll();

// Pre-compute unread counts for direct chats (sidebar + dashboard)
$unread = [];
try {
    $userIds = array_map(fn($r) => (int)$r['id'], $users);
    $userIds = array_values(array_filter(array_unique($userIds), fn($x) => $x > 0));
    if ($userIds) {
        $ph2 = implode(',', array_fill(0, count($userIds), '?'));
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
              AND IF(dc.user_a = ?, dc.user_b, dc.user_a) IN ($ph2)
            GROUP BY other_id
        ";
        $bind = [$meId, $meId, $meId, $meId, $meId, $meId];
        foreach ($userIds as $x) { $bind[] = $x; }
        $q = db()->prepare($sql);
        $q->execute($bind);
        foreach ($q->fetchAll() as $r) {
            $unread[(int)$r['other_id']] = (int)$r['unread_count'];
        }
    }
} catch (Throwable $e) {
    $unread = [];
}

// -------------------------
// Groups (only some roles)
// -------------------------
$groupsEnabled = in_array(role_effective($vr), ['public','admin','super_admin'], true);
$groups = [];
if ($groupsEnabled) {
    $groupsStmt = db()->prepare(
        'SELECT g.id, g.name, g.description,
            (SELECT left_at IS NULL FROM group_members WHERE group_id=g.id AND user_id=? LIMIT 1) AS is_member
         FROM chat_groups g
         WHERE g.is_active=1 AND g.is_ticket=0
         ORDER BY g.name'
    );
    $groupsStmt->execute([$meId]);
    $groups = $groupsStmt->fetchAll();
}

// Group unread (sidebar)
$gunread = [];
try {
    $gIds = array_map(fn($r) => (int)$r['id'], $groups);
    $gIds = array_values(array_filter(array_unique($gIds), fn($x) => $x > 0));
    if ($gIds) {
        $ph3 = implode(',', array_fill(0, count($gIds), '?'));
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
              AND gm.group_id IN ($ph3)
            GROUP BY gm.group_id
        ";
        $bind = [$meId, $meId];
        foreach ($gIds as $x) { $bind[] = $x; }
        $q = db()->prepare($sql);
        $q->execute($bind);
        foreach ($q->fetchAll() as $r) {
            $gunread[(int)$r['gid']] = (int)$r['unread_count'];
        }
    }
} catch (Throwable $e) {
    $gunread = [];
}

// -------------------------
// Tickets widget (dashboard)
// -------------------------
$ticketWidget = [
    'show' => false,
    'items' => [],
    'unread' => [],
];
try {
    if (tickets_requester_allowed($vr) || tickets_staff_allowed($vr)) {
        $ticketWidget['show'] = true;

        if (tickets_staff_allowed($vr)) {
            // staff: show recent tickets where staff is a member (meaningful settings)
            $ts = db()->prepare(
                "SELECT t.id, t.group_id, t.subject, t.status, t.priority, t.updated_at
                 FROM tickets t
                 JOIN group_members gm ON gm.group_id=t.group_id AND gm.user_id=? AND gm.left_at IS NULL
                 ORDER BY
                   CASE WHEN t.status IN ('open','pending') THEN 0 ELSE 1 END,
                   t.updated_at DESC
                 LIMIT 30"
            );
            $ts->execute([$meId]);
            $ticketWidget['items'] = $ts->fetchAll();
        } else {
            // requester: only their tickets
            $ts = db()->prepare(
                "SELECT id, group_id, subject, status, priority, updated_at
                 FROM tickets
                 WHERE requester_id=?
                 ORDER BY
                   CASE WHEN status IN ('open','pending') THEN 0 ELSE 1 END,
                   updated_at DESC
                 LIMIT 30"
            );
            $ts->execute([$meId]);
            $ticketWidget['items'] = $ts->fetchAll();
        }

        $gids = array_map(fn($r) => (int)$r['group_id'], $ticketWidget['items']);
        $gids = array_values(array_filter(array_unique($gids), fn($x) => $x > 0));
        $ticketWidget['unread'] = $gids ? ticket_unread_counts($meId, $gids) : [];
    }
} catch (Throwable $e) {
    $ticketWidget['show'] = false;
    $ticketWidget['items'] = [];
    $ticketWidget['unread'] = [];
}

// Total unread in tickets (sum of unread messages in ticket groups)
$totalUnreadTickets = 0;
if (!empty($ticketWidget['unread']) && is_array($ticketWidget['unread'])) {
    foreach ($ticketWidget['unread'] as $cnt) {
        $totalUnreadTickets += (int)$cnt;
    }
}

// -------------------------
// Recent direct conversations (dashboard)
// show only a few; scroll inside box
// -------------------------
$recentDirect = [];
try {
    // Fetch last message id per direct conversation for this user
    $stmt = db()->prepare(
        "SELECT
            dc.id AS direct_id,
            IF(dc.user_a = ?, dc.user_b, dc.user_a) AS other_id,
            lm.last_msg_id,
            m.created_at AS last_at
         FROM direct_conversations dc
         JOIN (
            SELECT direct_id, MAX(id) AS last_msg_id
            FROM messages
            WHERE chat_type='direct' AND deleted_at IS NULL
            GROUP BY direct_id
         ) lm ON lm.direct_id = dc.id
         JOIN messages m ON m.id = lm.last_msg_id
         WHERE (dc.user_a = ? OR dc.user_b = ?)
         ORDER BY m.id DESC
         LIMIT 30"
    );
    $stmt->execute([$meId, $meId, $meId]);
    $rows = $stmt->fetchAll();

    // Map other users and filter by visibility + can_direct_chat (paranoid but correct)
    $otherIds = array_values(array_unique(array_map(fn($r) => (int)$r['other_id'], $rows)));
    $otherIds = array_values(array_filter($otherIds, fn($x) => $x > 0));

    $othersById = [];
    if ($otherIds) {
        $ph4 = implode(',', array_fill(0, count($otherIds), '?'));
        $q = db()->prepare("SELECT id, display_name, username, role, last_seen_at, avatar_path FROM users WHERE is_active=1 AND id IN ($ph4)");
        $q->execute($otherIds);
        foreach ($q->fetchAll() as $x) {
            $othersById[(int)$x['id']] = $x;
        }
    }

    foreach ($rows as $r) {
        $oid = (int)$r['other_id'];
        if (!isset($othersById[$oid])) continue;

        $other = $othersById[$oid];
        if (!can_direct_chat((string)($u['role'] ?? 'user'), (string)($other['role'] ?? 'user'), $meId, $oid)) {
            continue;
        }
        $recentDirect[] = [
            'direct_id' => (int)$r['direct_id'],
            'other_id' => $oid,
            'display_name' => (string)($other['display_name'] ?? ''),
            'username' => (string)($other['username'] ?? ''),
            'role' => (string)($other['role'] ?? ''),
            'avatar_path' => (string)($other['avatar_path'] ?? ''),
            'last_seen_at' => $other['last_seen_at'] ?? null,
            'last_at' => (string)($r['last_at'] ?? ''),
            'unread' => (int)($unread[$oid] ?? 0),
        ];
    }

    // Keep it small for dashboard; the rest is accessible via sidebar list
    $recentDirect = array_slice($recentDirect, 0, 12);
} catch (Throwable $e) {
    $recentDirect = [];
}

function tt(string $fa, string $en, string $lang): string {
    return $lang === 'fa' ? $fa : $en;
}

render_header('Family Chat Pro', $u, [
    'extra_head' => "\n<meta name=\"theme-color\" content=\"#0f172a\">\n<style>
/* Dashboard tweaks without touching app.css */
.home-grid{
  display:grid;
  grid-template-columns: 1.3fr .9fr;
  gap:14px;
  padding:14px;
}
.home-card{
  background: rgba(255,255,255,0.04);
  border: 1px solid rgba(255,255,255,0.08);
  border-radius: 16px;
  padding: 12px;
}
.home-card h3{
  margin:0;
  font-size:14px;
  font-weight:800;
}
.home-card .sub{
  font-size:12px;
  opacity:.75;
  margin-top:4px;
}
.home-row{
  display:flex;
  align-items:center;
  justify-content:space-between;
  gap:10px;
}
.home-badge{
  display:inline-flex;
  align-items:center;
  justify-content:center;
  min-width:22px;
  height:22px;
  padding:0 8px;
  border-radius:999px;
  font-size:12px;
  font-weight:800;
  background: rgba(34,197,94,.22);
  border: 1px solid rgba(34,197,94,.35);
}
.box-scroll{
  margin-top:10px;
  max-height: 320px;
  overflow:auto;
  padding-right: 4px;
}
.box-scroll::-webkit-scrollbar{ width:8px; }
.box-scroll::-webkit-scrollbar-thumb{ background: rgba(255,255,255,0.10); border-radius: 10px; }
.mini-item{
  display:flex;
  align-items:center;
  justify-content:space-between;
  gap:10px;
  padding:10px;
  border-radius: 12px;
  border: 1px solid rgba(255,255,255,0.06);
  background: rgba(255,255,255,0.03);
  text-decoration:none;
  color:inherit;
}
.mini-item + .mini-item{ margin-top:8px; }
.mini-left{ display:flex; align-items:center; gap:10px; min-width:0; }
.mini-title{
  font-weight:800;
  font-size:13px;
  white-space:nowrap;
  overflow:hidden;
  text-overflow:ellipsis;
  max-width: 320px;
}
.mini-sub{
  font-size:12px;
  opacity:.75;
  white-space:nowrap;
  overflow:hidden;
  text-overflow:ellipsis;
}
.mini-right{
  display:flex; align-items:center; gap:8px; flex-shrink:0;
}
.pill{
  display:inline-flex;
  padding:2px 8px;
  border-radius:999px;
  font-size:11px;
  border:1px solid rgba(255,255,255,0.10);
  opacity:.9;
}
@media (max-width: 980px){
  .home-grid{ grid-template-columns: 1fr; padding: 10px; }
  .box-scroll{ max-height: 260px; }
  .mini-title{ max-width: 220px; }
}
/* Make main center area actually scroll on mobile */
.main{
  overflow:auto;
}
.empty-state{
  padding: 12px;
}
</style>\n"
]);
?>
<div class="app" data-user-id="<?= (int)$u['id'] ?>" data-lang="<?= e($lang) ?>">
  <aside class="sidebar">
    <div class="sb-top">
      <div class="sb-title">Family Chat</div>
      <button type="button" class="btn ghost mobile-only" data-close-sidebar>✕</button>
      <div class="sb-user">
        <div class="avatar">
          <?php if (!empty($u['avatar_path'])): ?>
            <img src="<?= e(avatar_url($u['avatar_path'])) ?>" alt="" loading="lazy">
          <?php else: ?>
            <?= e(mb_strtoupper(mb_substr((string)$u['display_name'],0,1))) ?>
          <?php endif; ?>
        </div>
        <div>
          <div class="sb-name"><?= e($u['display_name']) ?></div>
          <div class="sb-meta"><?= e($u['username']) ?> • <a class="link" href="<?= e(url('profile.php')) ?>"><?= e(t('profile',$lang)) ?></a></div>
        </div>
      </div>
      <div class="sb-actions">
        <?php if (is_admin($u)): ?>
          <a class="btn ghost" href="<?= e(url('admin/dashboard.php')) ?>"><?= e(t('admin_panel',$lang)) ?></a>
        <?php endif; ?>
        <a class="btn ghost" href="<?= e(url('logout.php')) ?>"><?= e(t('logout',$lang)) ?></a>
      </div>

      <div class="sb-tabs" role="tablist" aria-label="Chats">
        <button class="sb-tab is-active" type="button" data-tab="direct" role="tab" aria-selected="true">
          <?= e(t('direct',$lang)) ?>
        </button>
        <?php if ($groupsEnabled): ?>
          <button class="sb-tab" type="button" data-tab="groups" role="tab" aria-selected="false">
            <?= e(t('groups',$lang)) ?>
          </button>
        <?php endif; ?>
      </div>
      <div class="sb-search">
        <input id="sbSearch" type="search" placeholder="<?= e($lang==='fa'?'جستجو...':'Search...') ?>" autocomplete="off">
      </div>
    </div>

    <div class="sb-section" data-section="direct">
      <div class="sb-section-title"><?= e(t('direct',$lang)) ?></div>
      <div class="sb-list">
        <?php foreach ($users as $x): ?>
          <?php $online = is_online($x['last_seen_at'] ?? null); ?>
          <a class="sb-item" data-user-id="<?= (int)$x['id'] ?>" data-close-sidebar data-name="<?= e(mb_strtolower((string)$x['display_name'].' '.$x['username'])) ?>" href="<?= e(url('chat/direct.php?u='.(int)$x['id'])) ?>">
            <div class="avatar small">
              <?php if (!empty($x['avatar_path'])): ?>
                <img src="<?= e(avatar_url($x['avatar_path'])) ?>" alt="" loading="lazy">
              <?php else: ?>
                <?= e(mb_strtoupper(mb_substr((string)$x['display_name'],0,1))) ?>
              <?php endif; ?>
              <span class="status-dot <?= $online ? 'on' : 'off' ?>" title="<?= $online ? 'Online' : 'Offline' ?>"></span>
            </div>
            <div class="sb-item-main">
              <div class="sb-item-title"><?= e($x['display_name']) ?></div>
              <div class="sb-item-sub">
                <span class="muted">@<?= e($x['username']) ?></span>
                <span class="sep">•</span>
                <span class="presence-text">
                  <?= e(presence_label($x['last_seen_at'] ?? null, $lang)) ?>
                </span>
                <?php $uc = (int)($unread[(int)$x['id']] ?? 0); ?>
                <span class="unread-badge" style="<?= $uc>0?'':'display:none' ?>"><?= (int)$uc ?></span>
              </div>
            </div>
          </a>
        <?php endforeach; ?>
      </div>
    </div>

    <?php if ($groupsEnabled): ?>
    <div class="sb-section" data-section="groups">
      <div class="sb-section-title"><?= e(t('groups',$lang)) ?></div>
      <div class="sb-list">
        <?php foreach ($groups as $g): ?>
          <a class="sb-item" data-close-sidebar data-name="<?= e(mb_strtolower((string)$g['name'].' '.($g['description']??''))) ?>" href="<?= e(url('chat/group.php?g='.(int)$g['id'])) ?>">
            <div class="avatar small">#</div>
            <div class="sb-item-main">
              <div class="sb-item-title"><?= e($g['name']) ?></div>
              <div class="sb-item-sub">
                <?= ($g['is_member'] ? '✅ ' : '➕ ') . e($g['description'] ?? '') ?>
                <?php $gc = (int)($gunread[(int)$g['id']] ?? 0); ?>
                <span class="unread-badge unread-badge-group" data-group-id="<?= (int)$g['id'] ?>" style="<?= $gc>0?'':'display:none' ?>"><?= $gc>99?'99+':$gc ?></span>
              </div>
            </div>
          </a>
        <?php endforeach; ?>
      </div>
    </div>
    <?php endif; ?>
  </aside>

  <div class="drawer-overlay" data-close-sidebar></div>

  <main class="main">
    <div class="chat-header">
      <div class="chat-title">
        <button type="button" class="btn ghost mobile-only" data-open-sidebar><span class="menu-ico">&#9776;</span></button>
        <div>
          <div class="chat-name">Family Chat</div>
          <div class="chat-sub"><?= e(t('direct',$lang)) ?> / <?= e(t('groups',$lang)) ?></div>
        </div>
      </div>
      <div class="chat-actions"></div>
    </div>

    <div class="empty-state">
      <div class="home-grid">
        <div class="home-card">
          <div class="home-row">
            <div>
              <h3>💬 <?= e(tt('آخرین چت‌ها','Recent chats',$lang)) ?></h3>
              <div class="sub"><?= e(tt('۴ تا ۵ مورد را ببینید؛ بقیه داخل همین کادر اسکرول می‌خورند.','See a few; the rest scroll inside this box.',$lang)) ?></div>
            </div>
            <a class="btn ghost" href="<?= e(url('index.php')) ?>"><?= e(tt('لیست کامل در سایدبار','Full list in sidebar',$lang)) ?></a>
          </div>

          <div class="box-scroll">
            <?php if (!$recentDirect): ?>
              <div class="muted" style="padding:10px;"><?= e(tt('هنوز چتی ندارید.','No chats yet.',$lang)) ?></div>
            <?php else: ?>
              <?php foreach ($recentDirect as $rd): ?>
                <?php
                  $online = is_online($rd['last_seen_at'] ?? null);
                  $name = $rd['display_name'] ?: ('@'.$rd['username']);
                  $uc = (int)$rd['unread'];
                ?>
                <a class="mini-item" href="<?= e(url('chat/direct.php?u='.(int)$rd['other_id'])) ?>">
                  <div class="mini-left">
                    <div class="avatar small">
                      <?php if (!empty($rd['avatar_path'])): ?>
                        <img src="<?= e(avatar_url($rd['avatar_path'])) ?>" alt="" loading="lazy">
                      <?php else: ?>
                        <?= e(mb_strtoupper(mb_substr((string)$name,0,1))) ?>
                      <?php endif; ?>
                      <span class="status-dot <?= $online ? 'on' : 'off' ?>"></span>
                    </div>
                    <div style="min-width:0;">
                      <div class="mini-title"><?= e($name) ?></div>
                      <div class="mini-sub">@<?= e($rd['username']) ?> • <?= e(presence_label($rd['last_seen_at'] ?? null, $lang)) ?></div>
                    </div>
                  </div>
                  <div class="mini-right">
                    <?php if ($uc > 0): ?>
                      <span class="home-badge"><?= e($uc>99?'99+':(string)$uc) ?></span>
                    <?php endif; ?>
                  </div>
                </a>
              <?php endforeach; ?>
            <?php endif; ?>
          </div>
        </div>

        <div class="home-card">
          <div class="home-row">
            <div>
              <h3>🎫 <?= e(tt('تیکت‌ها','Tickets',$lang)) ?></h3>
              <div class="sub"><?= e(tt('تیکت‌های باز بالا، بسته‌ها پایین.','Open on top, closed at bottom.',$lang)) ?></div>
            </div>

            <a class="btn primary" href="<?= e(url('tickets/index.php')) ?>">
              <?= e(tt('ورود به تیکت‌ها','Open tickets',$lang)) ?>
              <?php if ($totalUnreadTickets > 0): ?>
                <span class="home-badge" style="margin-inline-start:8px;">
                  <?= e($totalUnreadTickets > 99 ? '99+' : (string)$totalUnreadTickets) ?>
                </span>
              <?php endif; ?>
            </a>
          </div>

          <?php
            // Show only 5 initially; rest scroll within box (still accessible)
            $ticketItems = $ticketWidget['items'] ?? [];
          ?>
          <div class="box-scroll">
            <?php if (!$ticketWidget['show']): ?>
              <div class="muted" style="padding:10px;"><?= e(tt('بخش تیکت فعال نیست.','Tickets are disabled.',$lang)) ?></div>
            <?php elseif (!$ticketItems): ?>
              <div class="muted" style="padding:10px;"><?= e(tt('هنوز تیکتی ندارید.','No tickets yet.',$lang)) ?></div>
            <?php else: ?>
              <?php foreach ($ticketItems as $t): ?>
                <?php
                  $gid = (int)($t['group_id'] ?? 0);
                  $uc = (int)($ticketWidget['unread'][$gid] ?? 0);
                  $st = (string)($t['status'] ?? '');
                  $pr = (string)($t['priority'] ?? '');
                ?>
                <a class="mini-item" href="<?= e(url('tickets/view.php?id='.(int)$t['id'])) ?>">
                  <div class="mini-left" style="min-width:0;">
                    <div style="min-width:0;">
                      <div class="mini-title"><?= e((string)$t['subject']) ?></div>
                      <div class="mini-sub">
                        <span class="pill st-<?= e($st) ?>"><?= e(strtoupper($st)) ?></span>
                        <span class="pill pr-<?= e($pr) ?>"><?= e($pr) ?></span>
                      </div>
                    </div>
                  </div>
                  <div class="mini-right">
                    <?php if ($uc > 0): ?>
                      <span class="home-badge"><?= e($uc>99?'99+':(string)$uc) ?></span>
                    <?php endif; ?>
                  </div>
                </a>
              <?php endforeach; ?>
            <?php endif; ?>
          </div>

          <div class="spacer"></div>
          <div class="home-row" style="gap:8px; flex-wrap:wrap;">
            <a class="btn ghost" href="<?= e(url('profile.php')) ?>">👤 <?= e(tt('پروفایل','Profile',$lang)) ?></a>
            <?php if (tickets_staff_allowed($vr)): ?>
              <a class="btn ghost" href="<?= e(url('tickets/index.php')) ?>">🎫 <?= e(tt('پنل تیکت','Ticket panel',$lang)) ?></a>
            <?php endif; ?>
            <?php if (is_admin($u)): ?>
              <a class="btn" href="<?= e(url('admin/index.php')) ?>">⚙️ <?= e(tt('مدیریت','Admin',$lang)) ?></a>
            <?php endif; ?>
          </div>
        </div>
      </div>
    </div>
  </main>
</div>
<?php render_footer(); ?>
