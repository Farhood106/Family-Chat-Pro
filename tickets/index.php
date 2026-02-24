<?php
require_once __DIR__ . '/../app/bootstrap.php';
require_once __DIR__ . '/../app/view.php';

$me = require_login();
$lang = $me['lang'] ?? 'fa';
$role = (string)($me['role'] ?? 'user');
$meId = (int)$me['id'];

if (!tickets_enabled()) {
    redirect(url('index.php'));
}

$isRequester = tickets_requester_allowed($role);
$isStaff = tickets_staff_allowed($role);

if (!$isRequester && !$isStaff) {
    http_response_code(403);
    echo 'Forbidden';
    exit;
}

// ---------------------------------------------------------
// Fetch tickets
// - Staff: only tickets where staff is a member of the ticket group
// - Requester: only own tickets
// - Order: open/pending first, then solved/closed (then updated_at desc)
// ---------------------------------------------------------
$tickets = [];
try {
    if ($isStaff) {
        $stmt = db()->prepare(
            "SELECT t.id, t.group_id, t.subject, t.status, t.priority, t.updated_at, t.created_at, t.requester_id,
                    u.display_name AS requester_name, u.username AS requester_username, u.role AS requester_role,
                    t.assigned_to,
                    au.display_name AS assigned_name
             FROM tickets t
             JOIN users u ON u.id=t.requester_id
             LEFT JOIN users au ON au.id=t.assigned_to
             JOIN group_members gm ON gm.group_id=t.group_id AND gm.user_id=? AND gm.left_at IS NULL
             ORDER BY
               CASE WHEN t.status IN ('open','pending') THEN 0 ELSE 1 END,
               t.updated_at DESC
             LIMIT 500"
        );
        $stmt->execute([$meId]);
        $tickets = $stmt->fetchAll();
    } else {
        $stmt = db()->prepare(
            "SELECT t.id, t.group_id, t.subject, t.status, t.priority, t.updated_at, t.created_at, t.requester_id, t.assigned_to,
                    au.display_name AS assigned_name
             FROM tickets t
             LEFT JOIN users au ON au.id=t.assigned_to
             WHERE t.requester_id=?
             ORDER BY
               CASE WHEN t.status IN ('open','pending') THEN 0 ELSE 1 END,
               t.updated_at DESC
             LIMIT 500"
        );
        $stmt->execute([$meId]);
        $tickets = $stmt->fetchAll();
    }
} catch (Throwable $e) {
    $tickets = [];
}

// Unread badge per ticket group
$ticketGroups = [];
foreach ($tickets as $t) {
    $ticketGroups[(int)$t['id']] = (int)($t['group_id'] ?? 0);
}

$unread = [];
try {
    $gids = array_values(array_filter(array_unique(array_values($ticketGroups)), fn($x) => $x > 0));
    $unread = $gids ? ticket_unread_counts($meId, $gids) : [];
} catch (Throwable $e) {
    $unread = [];
}

// ---------------------------------------------------------
// Prepare buckets for tabs
// ---------------------------------------------------------
$bucket = [
    'open' => [],
    'pending' => [],
    'solved' => [],
    'closed' => [],
    'all' => [],
];

foreach ($tickets as $t) {
    $st = (string)($t['status'] ?? '');
    if (!isset($bucket[$st])) {
        // unknown status goes to "all"
        $bucket['all'][] = $t;
        continue;
    }
    $bucket[$st][] = $t;
}
$bucket['all'] = $tickets;

// Tab counts
$counts = [
    'open' => count($bucket['open']),
    'pending' => count($bucket['pending']),
    'solved' => count($bucket['solved']),
    'closed' => count($bucket['closed']),
    'all' => count($bucket['all']),
];

// Total unread (sum per ticket group)
$totalUnread = 0;
foreach ($ticketGroups as $tid => $gid) {
    $totalUnread += (int)($unread[$gid] ?? 0);
}

function tt(string $fa, string $en, string $lang): string {
    return $lang === 'fa' ? $fa : $en;
}

render_header($lang==='fa'?'تیکت‌های پشتیبانی':'Support Tickets', $me, [
    'extra_head' => "\n<style>
/* Tickets index: professional layout upgrades */
.tickets .main{overflow:auto}
.tickets .tickets-shell{padding:12px}
.tickets .tabs{
  display:flex; gap:8px; flex-wrap:wrap;
  margin-top:10px;
}
.tickets .tab{
  border:1px solid rgba(255,255,255,.10);
  background: rgba(255,255,255,.03);
  padding:8px 10px;
  border-radius: 12px;
  cursor:pointer;
  display:flex;
  align-items:center;
  gap:8px;
  user-select:none;
}
.tickets .tab.is-active{
  border-color: rgba(34,197,94,.35);
  background: rgba(34,197,94,.12);
}
.tickets .tab-count{
  display:inline-flex;
  align-items:center;
  justify-content:center;
  min-width:22px;
  height:22px;
  padding:0 8px;
  border-radius:999px;
  font-size:12px;
  font-weight:800;
  background: rgba(255,255,255,.06);
  border: 1px solid rgba(255,255,255,.10);
}
.tickets .tab.is-active .tab-count{
  background: rgba(34,197,94,.18);
  border-color: rgba(34,197,94,.35);
}
.tickets .tickets-toolbar{
  display:flex;
  align-items:center;
  justify-content:space-between;
  gap:10px;
  flex-wrap:wrap;
  margin-top:12px;
}
.tickets .tickets-toolbar .search{
  width:min(420px, 100%);
}
.tickets .tickets-toolbar input{
  width:100%;
}
.tickets .panel{
  margin-top:12px;
  border:1px solid rgba(255,255,255,.08);
  background: rgba(255,255,255,.03);
  border-radius: 16px;
  padding: 12px;
}
.tickets .panel-head{
  display:flex;
  align-items:center;
  justify-content:space-between;
  gap:10px;
  flex-wrap:wrap;
}
.tickets .panel-title{
  font-weight:900;
  font-size:14px;
}
.tickets .panel-sub{
  font-size:12px;
  opacity:.75;
}
.tickets .ticket-grid{
  margin-top:10px;
  max-height: 520px;
  overflow:auto;
  padding-right: 4px;
  display:flex;
  flex-direction:column;
  gap:8px;
}
.tickets .ticket-grid::-webkit-scrollbar{ width:8px; }
.tickets .ticket-grid::-webkit-scrollbar-thumb{ background: rgba(255,255,255,0.10); border-radius: 10px; }

.tickets .ticket-card{
  display:flex;
  align-items:center;
  justify-content:space-between;
  gap:10px;
  padding:10px;
  border-radius: 14px;
  border:1px solid rgba(255,255,255,.06);
  background: rgba(255,255,255,.03);
  text-decoration:none;
  color:inherit;
}
.tickets .ticket-card:hover{
  border-color: rgba(255,255,255,.12);
  background: rgba(255,255,255,.04);
}
.tickets .ticket-card .left{ min-width:0; }
.tickets .ticket-card .title{
  font-weight:900;
  font-size:13px;
  white-space:nowrap;
  overflow:hidden;
  text-overflow:ellipsis;
  max-width: 520px;
}
.tickets .ticket-card .meta{
  margin-top:4px;
  display:flex;
  align-items:center;
  gap:8px;
  flex-wrap:wrap;
  font-size:12px;
  opacity:.85;
}
.tickets .badge{
  margin-left:auto;
}
.tickets .right{
  flex-shrink:0;
  display:flex;
  align-items:center;
  gap:8px;
}
.tickets .mini-badge{
  display:inline-flex;
  align-items:center;
  justify-content:center;
  min-width:22px;
  height:22px;
  padding:0 8px;
  border-radius:999px;
  font-size:12px;
  font-weight:900;
  background: rgba(34,197,94,.18);
  border: 1px solid rgba(34,197,94,.35);
}

.tickets .sidebar .ticket-list{
  max-height: calc(100vh - 340px);
  overflow:auto;
  padding-right: 4px;
}
.tickets .sidebar .ticket-list::-webkit-scrollbar{ width:8px; }
.tickets .sidebar .ticket-list::-webkit-scrollbar-thumb{ background: rgba(255,255,255,0.10); border-radius: 10px; }

/* Responsive polish */
@media (max-width: 980px){
  .tickets .tickets-shell{padding:10px}
  .tickets .ticket-card .title{max-width: 240px;}
  .tickets .ticket-grid{max-height: 60vh;}
  .tickets .sidebar .ticket-list{max-height: 55vh;}
}
</style>\n"
]);
?>

<div class="app tickets" data-lang="<?= e($lang) ?>">
  <aside class="sidebar">
    <div class="sb-top">
      <a class="btn ghost" href="<?= e(url('index.php')) ?>">←</a>
      <button type="button" class="btn ghost mobile-only" data-close-sidebar>✕</button>
      <div class="sb-title">
        <?= e($lang==='fa'?'تیکت‌ها':'Tickets') ?>
        <?php if ($totalUnread > 0): ?>
          <span class="mini-badge" style="margin-inline-start:8px;"><?= e($totalUnread>99?'99+':(string)$totalUnread) ?></span>
        <?php endif; ?>
      </div>
    </div>

    <?php if ($isRequester): ?>
      <div class="sb-section">
        <div class="sb-section-title"><?= e($lang==='fa'?'تیکت جدید':'New ticket') ?></div>
        <form class="ticket-new" method="post" action="<?= e(url('api/tickets/create.php')) ?>">
          <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
          <input class="input" name="subject" maxlength="140" placeholder="<?= e($lang==='fa'?'موضوع را بنویسید...':'Subject...') ?>" required>
          <select class="input" name="priority">
            <option value="normal"><?= e($lang==='fa'?'عادی':'Normal') ?></option>
            <option value="low"><?= e($lang==='fa'?'کم':'Low') ?></option>
            <option value="high"><?= e($lang==='fa'?'بالا':'High') ?></option>
            <option value="urgent"><?= e($lang==='fa'?'فوری':'Urgent') ?></option>
          </select>
          <button class="btn primary" type="submit"><?= e($lang==='fa'?'ایجاد تیکت':'Create') ?></button>
        </form>
        <div class="muted" style="margin-top:8px">
          <?= e($lang==='fa'?'بعد از ایجاد، گفتگوی تیکت مثل چت باز می‌شود.':'After creating, the ticket opens as a chat thread.') ?>
        </div>
      </div>
    <?php endif; ?>

    <div class="sb-section">
      <div style="display:flex; align-items:center; justify-content:space-between; gap:10px;">
        <div class="sb-section-title"><?= e($lang==='fa'?'لیست تیکت‌ها':'Ticket list') ?></div>
        <?php if ($isStaff): ?>
          <a class="btn ghost" href="<?= e(url('tickets/all.php')) ?>" style="padding:6px 10px;">
            <?= e($lang==='fa'?'همه':'All') ?>
          </a>
        <?php endif; ?>
      </div>

      <div class="ticket-list" id="sidebarTicketList">
        <?php if (!$tickets): ?>
          <div class="muted" style="padding:10px"><?= e($lang==='fa'?'تیکتی وجود ندارد':'No tickets') ?></div>
        <?php else: ?>
          <?php foreach ($tickets as $t):
            $tid = (int)$t['id'];
            $gid = (int)($ticketGroups[$tid] ?? 0);
            $uc = (int)($unread[$gid] ?? 0);
          ?>
            <a class="ticket-item" href="<?= e(url('tickets/view.php?id=' . $tid)) ?>">
              <div class="ticket-title"><?= e((string)$t['subject']) ?></div>
              <div class="ticket-meta">
                <span class="pill st-<?= e((string)$t['status']) ?>"><?= e(strtoupper((string)$t['status'])) ?></span>
                <span class="pill pr-<?= e((string)$t['priority']) ?>"><?= e((string)$t['priority']) ?></span>

                <?php if ($isStaff): ?>
                  <span class="muted">• <?= e($t['requester_name'] ?? $t['requester_username'] ?? ('#'.$t['requester_id'])) ?></span>
                <?php endif; ?>

                <?php if (!empty($t['assigned_name'])): ?>
                  <span class="muted">• <?= e($lang==='fa'?'مسئول: ':'Assigned: ') ?><?= e((string)$t['assigned_name']) ?></span>
                <?php endif; ?>
              </div>

              <?php if ($uc > 0): ?>
                <span class="badge"><?= e($uc > 99 ? '99+' : (string)$uc) ?></span>
              <?php endif; ?>
            </a>
          <?php endforeach; ?>
        <?php endif; ?>
      </div>
    </div>
  </aside>

  <div class="drawer-overlay" data-close-sidebar></div>

  <main class="main">
    <div class="chat-header">
      <div class="chat-title">
        <button type="button" class="btn ghost mobile-only" data-open-sidebar><span class="menu-ico">&#9776;</span></button>
        <div>
          <div class="chat-name"><?= e($lang==='fa'?'تیکت‌های پشتیبانی':'Support Tickets') ?></div>
          <div class="chat-sub"><?= e($isStaff ? ($lang==='fa'?'پنل پشتیبان':'Staff view') : ($lang==='fa'?'پنل کاربر':'User view')) ?></div>
        </div>
      </div>
      <div class="chat-actions">
        <?php if ($isStaff): ?>
          <a class="btn ghost" href="<?= e(url('tickets/all.php')) ?>"><?= e($lang==='fa'?'مشاهده همه':'View all') ?></a>
        <?php endif; ?>
      </div>
    </div>

    <div class="tickets-shell">
      <div class="panel">
        <div class="panel-head">
          <div>
            <div class="panel-title"><?= e(tt('مدیریت تیکت‌ها','Ticket management',$lang)) ?></div>
            <div class="panel-sub">
              <?= e(tt('تیکت‌های باز همیشه بالاتر می‌مانند، بسته‌ها می‌روند پایین. با تب‌ها سریع فیلتر کن.','Open tickets stay on top; use tabs to filter quickly.',$lang)) ?>
            </div>
          </div>
          <div style="display:flex; gap:8px; flex-wrap:wrap;">
            <span class="pill st-open"><?= e(tt('OPEN بالا','OPEN first',$lang)) ?></span>
            <span class="pill st-closed"><?= e(tt('CLOSED پایین','CLOSED last',$lang)) ?></span>
          </div>
        </div>

        <div class="tabs" id="ticketTabs" role="tablist" aria-label="Ticket tabs">
          <div class="tab is-active" data-tab="open" role="tab" aria-selected="true">
            <?= e(tt('باز','Open',$lang)) ?> <span class="tab-count"><?= (int)$counts['open'] ?></span>
          </div>
          <div class="tab" data-tab="pending" role="tab" aria-selected="false">
            <?= e(tt('در انتظار','Pending',$lang)) ?> <span class="tab-count"><?= (int)$counts['pending'] ?></span>
          </div>
          <div class="tab" data-tab="solved" role="tab" aria-selected="false">
            <?= e(tt('حل‌شده','Solved',$lang)) ?> <span class="tab-count"><?= (int)$counts['solved'] ?></span>
          </div>
          <div class="tab" data-tab="closed" role="tab" aria-selected="false">
            <?= e(tt('بسته','Closed',$lang)) ?> <span class="tab-count"><?= (int)$counts['closed'] ?></span>
          </div>
          <div class="tab" data-tab="all" role="tab" aria-selected="false">
            <?= e(tt('همه','All',$lang)) ?> <span class="tab-count"><?= (int)$counts['all'] ?></span>
          </div>
        </div>

        <div class="tickets-toolbar">
          <div class="search">
            <input class="input" id="ticketSearch" type="search" placeholder="<?= e(tt('جستجو در عنوان تیکت‌ها...','Search ticket subjects...',$lang)) ?>" autocomplete="off">
          </div>
          <div style="display:flex; gap:8px; flex-wrap:wrap;">
            <a class="btn ghost" href="<?= e(url('index.php')) ?>"><?= e(tt('بازگشت به چت‌ها','Back to chats',$lang)) ?></a>
            <a class="btn" href="<?= e(url('tickets/index.php')) ?>"><?= e(tt('ریفرش','Refresh',$lang)) ?></a>
          </div>
        </div>

        <?php
          // renderer for ticket cards
          $renderList = function(array $items, string $tabKey) use ($lang, $isStaff, $ticketGroups, $unread): void {
              if (!$items) {
                  echo '<div class="muted" style="padding:10px;">' . e($lang==='fa'?'موردی وجود ندارد':'No items') . '</div>';
                  return;
              }
              foreach ($items as $t) {
                  $tid = (int)$t['id'];
                  $gid = (int)($ticketGroups[$tid] ?? 0);
                  $uc = (int)($unread[$gid] ?? 0);
                  $st = (string)($t['status'] ?? '');
                  $pr = (string)($t['priority'] ?? '');
                  $subj = (string)($t['subject'] ?? '');
                  $reqName = (string)($t['requester_name'] ?? $t['requester_username'] ?? ('#'.($t['requester_id'] ?? '')));
                  $assName = (string)($t['assigned_name'] ?? '');

                  echo '<a class="ticket-card" data-subject="' . e(mb_strtolower($subj)) . '" href="' . e(url('tickets/view.php?id=' . $tid)) . '">';
                  echo '  <div class="left">';
                  echo '    <div class="title">' . e($subj) . '</div>';
                  echo '    <div class="meta">';
                  echo '      <span class="pill st-' . e($st) . '">' . e(strtoupper($st)) . '</span>';
                  echo '      <span class="pill pr-' . e($pr) . '">' . e($pr) . '</span>';
                  if ($isStaff) {
                      echo '  <span class="muted">• ' . e($lang==='fa'?'کاربر: ':'User: ') . e($reqName) . '</span>';
                  }
                  if ($assName !== '') {
                      echo '  <span class="muted">• ' . e($lang==='fa'?'مسئول: ':'Assigned: ') . e($assName) . '</span>';
                  }
                  echo '    </div>';
                  echo '  </div>';
                  echo '  <div class="right">';
                  if ($uc > 0) {
                      echo '  <span class="mini-badge">' . e($uc>99?'99+':(string)$uc) . '</span>';
                  }
                  echo '  </div>';
                  echo '</a>';
              }
          };
        ?>

        <div class="ticket-grid" id="ticketPanel_open" data-panel="open">
          <?php $renderList($bucket['open'], 'open'); ?>
        </div>

        <div class="ticket-grid" id="ticketPanel_pending" data-panel="pending" style="display:none">
          <?php $renderList($bucket['pending'], 'pending'); ?>
        </div>

        <div class="ticket-grid" id="ticketPanel_solved" data-panel="solved" style="display:none">
          <?php $renderList($bucket['solved'], 'solved'); ?>
        </div>

        <div class="ticket-grid" id="ticketPanel_closed" data-panel="closed" style="display:none">
          <?php $renderList($bucket['closed'], 'closed'); ?>
        </div>

        <div class="ticket-grid" id="ticketPanel_all" data-panel="all" style="display:none">
          <?php $renderList($bucket['all'], 'all'); ?>
        </div>
      </div>
    </div>

    <script>
      (function(){
        const tabs = document.querySelectorAll('#ticketTabs .tab');
        const panels = {
          open: document.getElementById('ticketPanel_open'),
          pending: document.getElementById('ticketPanel_pending'),
          solved: document.getElementById('ticketPanel_solved'),
          closed: document.getElementById('ticketPanel_closed'),
          all: document.getElementById('ticketPanel_all'),
        };
        let active = 'open';

        function setActive(tabKey){
          active = tabKey;
          tabs.forEach(t => {
            const on = (t.dataset.tab === tabKey);
            t.classList.toggle('is-active', on);
            t.setAttribute('aria-selected', on ? 'true' : 'false');
          });
          Object.keys(panels).forEach(k => {
            if (!panels[k]) return;
            panels[k].style.display = (k === tabKey) ? '' : 'none';
          });
          // reset search on tab switch? no. keep it applied:
          applySearch();
        }

        tabs.forEach(t => {
          t.addEventListener('click', () => setActive(t.dataset.tab));
        });

        const search = document.getElementById('ticketSearch');
        function applySearch(){
          const q = (search.value || '').trim().toLowerCase();
          const panel = panels[active];
          if (!panel) return;
          const items = panel.querySelectorAll('.ticket-card');
          let any = false;
          items.forEach(a => {
            const s = (a.getAttribute('data-subject') || '');
            const ok = (q === '' || s.includes(q));
            a.style.display = ok ? '' : 'none';
            if (ok) any = true;
          });

          // if none matched, show a muted hint
          let hint = panel.querySelector('[data-search-hint="1"]');
          if (!hint) {
            hint = document.createElement('div');
            hint.className = 'muted';
            hint.style.padding = '10px';
            hint.setAttribute('data-search-hint','1');
            hint.textContent = '<?= e(tt('نتیجه‌ای پیدا نشد.','No results found.',$lang)) ?>';
            panel.appendChild(hint);
          }
          hint.style.display = (q !== '' && !any) ? '' : 'none';
        }
        search && search.addEventListener('input', applySearch);

        // default active
        setActive('open');
      })();
    </script>

  </main>
</div>

<?php render_footer(); ?>
