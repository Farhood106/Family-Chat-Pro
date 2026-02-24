<?php
require_once __DIR__ . '/../app/bootstrap.php';
require_once __DIR__ . '/../app/view.php';

$me   = require_login();
$lang = $me['lang'] ?? 'fa';
$role = (string)($me['role'] ?? 'user');
$meId = (int)$me['id'];

if (!tickets_enabled()) {
    redirect(url('index.php'));
}

if (!is_admin_role($role)) {
    http_response_code(403);
    echo 'Forbidden';
    exit;
}

function tt(string $fa, string $en, string $lang): string { return $lang==='fa' ? $fa : $en; }

// ---------------------------------------------------------
// Filters (server-side)
// ---------------------------------------------------------
$q        = trim((string)($_GET['q'] ?? ''));
$status   = trim((string)($_GET['status'] ?? ''));
$priority = trim((string)($_GET['priority'] ?? ''));

$allowedStatus   = ['open','pending','solved','closed'];
$allowedPriority = ['low','normal','high','urgent'];

$where = [];
$bind  = [];

if ($q !== '') {
    $where[] = "(t.subject LIKE ? OR u.username LIKE ? OR u.display_name LIKE ? OR au.username LIKE ? OR au.display_name LIKE ?)";
    $like = '%' . $q . '%';
    $bind[] = $like; $bind[] = $like; $bind[] = $like; $bind[] = $like; $bind[] = $like;
}

if ($status !== '' && in_array($status, $allowedStatus, true)) {
    $where[] = "t.status = ?";
    $bind[]  = $status;
} else {
    $status = '';
}

if ($priority !== '' && in_array($priority, $allowedPriority, true)) {
    $where[] = "t.priority = ?";
    $bind[]  = $priority;
} else {
    $priority = '';
}

$sqlWhere = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

// ---------------------------------------------------------
// Fetch tickets (admin sees all)
// ---------------------------------------------------------
$tickets = [];
try {
    $stmt = db()->prepare(
        "SELECT t.id, t.group_id, t.subject, t.status, t.priority, t.updated_at, t.created_at,
                t.requester_id, t.assigned_to,
                u.display_name AS requester_name, u.username AS requester_username,
                au.display_name AS assigned_name, au.username AS assigned_username
         FROM tickets t
         JOIN users u ON u.id=t.requester_id
         LEFT JOIN users au ON au.id=t.assigned_to
         $sqlWhere
         ORDER BY
           CASE WHEN t.status IN ('open','pending') THEN 0 ELSE 1 END,
           CASE t.status
             WHEN 'open' THEN 1
             WHEN 'pending' THEN 2
             WHEN 'solved' THEN 3
             WHEN 'closed' THEN 4
             ELSE 9
           END ASC,
           t.updated_at DESC
         LIMIT 3000"
    );
    $stmt->execute($bind);
    $tickets = $stmt->fetchAll();
} catch (Throwable $e) {
    $tickets = [];
}

// ---------------------------------------------------------
// Unread counts per ticket group (for admin viewer)
// ---------------------------------------------------------
$unread = [];
$totalUnread = 0;
try {
    $gids = array_map(fn($r) => (int)$r['group_id'], $tickets);
    $gids = array_values(array_filter(array_unique($gids), fn($x) => $x > 0));
    if ($gids) {
        $unread = ticket_unread_counts($meId, $gids);
        foreach ($gids as $gid) {
            $totalUnread += (int)($unread[$gid] ?? 0);
        }
    }
} catch (Throwable $e) {
    $unread = [];
    $totalUnread = 0;
}

// ---------------------------------------------------------
// Bucket tickets for tabs
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
    if (isset($bucket[$st])) $bucket[$st][] = $t;
}
$bucket['all'] = $tickets;

$counts = [
    'open' => count($bucket['open']),
    'pending' => count($bucket['pending']),
    'solved' => count($bucket['solved']),
    'closed' => count($bucket['closed']),
    'all' => count($bucket['all']),
];

$extraHead = <<<HTML
<style>
/*
  Global layout sets .app { height:100vh; overflow:hidden } (great for chats).
  But this admin page can be taller than the viewport, especially on mobile.
  So we allow normal page scrolling on small screens.
*/
html, body { height:auto; overflow:auto; }

@media (max-width: 980px){
  /* Allow the page to scroll instead of forcing nested scroll areas. */
  .app.tickets{ height:auto !important; min-height:100dvh; overflow:visible !important; }
  .app.tickets .main{ overflow:visible !important; }
}

.tickets .tickets-shell{padding:12px}
.tickets .panel{
  border:1px solid rgba(255,255,255,.08);
  background: rgba(255,255,255,.03);
  border-radius: 16px;
  padding: 12px;
}
.tickets .panel-head{
  display:flex; align-items:center; justify-content:space-between; gap:10px; flex-wrap:wrap;
}
.tickets .panel-title{font-weight:900; font-size:14px;}
.tickets .panel-sub{font-size:12px; opacity:.75; line-height:1.7}
.tickets .toolbar{
  margin-top:12px;
  display:flex; align-items:center; justify-content:space-between; gap:10px; flex-wrap:wrap;
}
.tickets .toolbar .filters{
  display:flex; gap:8px; align-items:center; flex-wrap:wrap;
}
.tickets .toolbar .filters .input{min-width: 170px}
.tickets .toolbar .filters .input.q{min-width: 260px; width:min(420px, 100%)}

.tickets .tabs{
  display:flex; gap:8px; flex-wrap:wrap;
  margin-top:12px;
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

.tickets .list{
  margin-top:12px;
  border:1px solid rgba(255,255,255,.08);
  background: rgba(255,255,255,.02);
  border-radius: 16px;
  overflow:hidden;
}
.tickets .list-head{
  display:flex; align-items:center; justify-content:space-between; gap:10px;
  padding:10px 12px;
  border-bottom:1px solid rgba(255,255,255,.08);
}
.tickets .list-head .muted{font-size:12px}

.tickets .list-body{
  max-height: calc(100dvh - 290px);
  overflow:auto;
  padding: 8px;
  display:flex;
  flex-direction:column;
  gap:8px;
  -webkit-overflow-scrolling: touch;
}
@supports not (height: 100dvh) {
  .tickets .list-body{ max-height: calc(100vh - 290px); }
}

.tickets .row{
  display:flex;
  align-items:stretch;
  gap:8px;
}

.tickets .ticket-card{
  flex:1;
  display:flex;
  align-items:flex-start;
  justify-content:space-between;
  gap:10px;
  padding:10px 12px;
  border-radius: 14px;
  border:1px solid rgba(255,255,255,.06);
  background: rgba(255,255,255,.03);
  text-decoration:none;
  color:inherit;
  min-width:0;
}
.tickets .ticket-card:hover{
  border-color: rgba(255,255,255,.12);
  background: rgba(255,255,255,.04);
}
.tickets .ticket-card .title{
  font-weight:900;
  font-size:14px;
  white-space:nowrap;
  overflow:hidden;
  text-overflow:ellipsis;
  max-width: 640px;
}
.tickets .ticket-card .meta{
  margin-top:6px;
  display:flex;
  flex-wrap:wrap;
  gap:8px;
  align-items:center;
  font-size:12px;
  opacity:.88;
}

.tickets .row-actions{
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

.tickets .danger-btn{
  border: 1px solid rgba(239,68,68,.35);
  background: rgba(239,68,68,.12);
}
.tickets .danger-btn:hover{
  background: rgba(239,68,68,.18);
}

/* Responsive: Mobile should scroll the PAGE, not nested lists */
@media (max-width: 980px){
  .tickets .tickets-shell{padding:10px}
  .tickets .toolbar .filters .input{min-width: 140px}
  .tickets .toolbar .filters .input.q{min-width: 220px}
  .tickets .ticket-card .title{max-width: 260px;}

  .tickets .list-body{
    max-height: none !important;
    overflow: visible !important;
  }

  .tickets .toolbar .filters{width:100%}
  .tickets .toolbar .filters .input.q{width:100%}

  .tickets .row{flex-direction:column}
  .tickets .row-actions{justify-content:flex-end}
}
</style>
HTML;

render_header($lang==='fa'?'همه تیکت‌ها':'All tickets', $me, ['extra_head'=>$extraHead]);
?>
<div class="app tickets" data-lang="<?= e($lang) ?>">
  <aside class="sidebar">
    <div class="sb-top">
      <a class="btn ghost" href="<?= e(url('tickets/index.php')) ?>">←</a>
      <button type="button" class="btn ghost mobile-only" data-close-sidebar>✕</button>
      <div class="sb-title">
        <?= e(tt('مدیریت تیکت‌ها','Ticket admin',$lang)) ?>
        <?php if ($totalUnread > 0): ?>
          <span class="mini-badge" style="margin-inline-start:8px;"><?= e($totalUnread>99?'99+':(string)$totalUnread) ?></span>
        <?php endif; ?>
      </div>
    </div>

    <div class="sb-section">
      <div class="sb-section-title"><?= e(tt('راهنما','Tips',$lang)) ?></div>
      <div class="muted">
        <?= e(tt('این صفحه مخصوص ادمین/سوپرادمین است و برای مدیریت همه تیکت‌ها استفاده می‌شود.','Admin-only view for managing all tickets.',$lang)) ?>
      </div>
      <div class="spacer"></div>
      <a class="btn primary" href="<?= e(url('tickets/index.php')) ?>"><?= e(tt('لیست من','My tickets',$lang)) ?></a>
      <a class="btn ghost" href="<?= e(url('index.php')) ?>" style="margin-top:8px; width:100%; text-align:center;">
        <?= e(tt('بازگشت به چت‌ها','Back to chats',$lang)) ?>
      </a>
    </div>
  </aside>

  <div class="drawer-overlay" data-close-sidebar></div>

  <main class="main">
    <div class="chat-header">
      <div class="chat-title">
        <button type="button" class="btn ghost mobile-only" data-open-sidebar><span class="menu-ico">&#9776;</span></button>
        <div>
          <div class="chat-name"><?= e(tt('همه تیکت‌ها','All tickets',$lang)) ?></div>
          <div class="chat-sub"><?= e(tt('نتایج: ','Results: ',$lang) . count($tickets)) ?></div>
        </div>
      </div>
      <div class="chat-actions">
        <a class="btn" href="<?= e(url('tickets/all.php')) ?>"><?= e(tt('ریفرش','Refresh',$lang)) ?></a>
      </div>
    </div>

    <div class="tickets-shell">
      <div class="panel">
        <div class="panel-head">
          <div>
            <div class="panel-title"><?= e(tt('پنل مدیریت تیکت‌ها','Tickets admin panel',$lang)) ?></div>
            <div class="panel-sub">
              <?= e(tt('تیکت‌های Open/Pending همیشه اول هستند. با تب‌ها و سرچ، سریع مدیریت کن.','Open/Pending always come first. Use tabs + search to manage quickly.',$lang)) ?>
            </div>
          </div>
          <div style="display:flex; gap:8px; flex-wrap:wrap;">
            <span class="pill st-open"><?= e(tt('OPEN بالا','OPEN first',$lang)) ?></span>
            <span class="pill st-closed"><?= e(tt('CLOSED پایین','CLOSED last',$lang)) ?></span>
          </div>
        </div>

        <div class="toolbar">
          <form class="filters" method="get" action="<?= e(url('tickets/all.php')) ?>">
            <input class="input q" name="q" value="<?= e($q) ?>" placeholder="<?= e(tt('جستجو موضوع/کاربر/مسئول...','Search subject/user/assignee...',$lang)) ?>">
            <select class="input" name="status">
              <option value=""><?= e(tt('همه وضعیت‌ها','All statuses',$lang)) ?></option>
              <?php foreach (['open','pending','solved','closed'] as $s): ?>
                <option value="<?= e($s) ?>" <?= $status===$s?'selected':'' ?>><?= e(strtoupper($s)) ?></option>
              <?php endforeach; ?>
            </select>
            <select class="input" name="priority">
              <option value=""><?= e(tt('همه اولویت‌ها','All priorities',$lang)) ?></option>
              <?php foreach (['low','normal','high','urgent'] as $p): ?>
                <option value="<?= e($p) ?>" <?= $priority===$p?'selected':'' ?>><?= e($p) ?></option>
              <?php endforeach; ?>
            </select>
            <button class="btn primary" type="submit"><?= e(tt('اعمال','Apply',$lang)) ?></button>
            <a class="btn ghost" href="<?= e(url('tickets/all.php')) ?>"><?= e(tt('پاک کردن','Reset',$lang)) ?></a>
          </form>

          <div style="display:flex; gap:8px; flex-wrap:wrap;">
            <input class="input" id="clientSearch" type="search" placeholder="<?= e(tt('فیلتر سریع (بدون ریفرش)...','Quick filter (no refresh)...',$lang)) ?>" style="min-width:240px;">
          </div>
        </div>

        <div class="tabs" id="tabs" role="tablist" aria-label="Ticket tabs">
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

        <?php
          $renderList = function(array $items) use ($lang, $unread): void {
              if (!$items) {
                  echo '<div class="muted" style="padding:14px;">' . e($lang==='fa'?'موردی وجود ندارد':'No items') . '</div>';
                  return;
              }
              foreach ($items as $t) {
                  $tid  = (int)$t['id'];
                  $gid  = (int)($t['group_id'] ?? 0);
                  $uc   = (int)($unread[$gid] ?? 0);

                  $subj = (string)($t['subject'] ?? '');
                  $st   = (string)($t['status'] ?? '');
                  $pr   = (string)($t['priority'] ?? '');
                  $req  = (string)($t['requester_name'] ?? $t['requester_username'] ?? ('#'.($t['requester_id'] ?? '')));
                  $ass  = (string)($t['assigned_name'] ?? $t['assigned_username'] ?? '');
                  $assId = (int)($t['assigned_to'] ?? 0);
                  $updated = (string)($t['updated_at'] ?? '');

                  $assText = ($ass !== '') ? $ass : ($assId > 0 ? ('#'.$assId) : ($lang==='fa'?'بدون مسئول':'Unassigned'));

                  $search = mb_strtolower($subj.' '.$req.' '.$assText.' '.$st.' '.$pr);

                  echo '<div class="row">';
                  echo '  <a class="ticket-card" data-search="' . e($search) . '" href="' . e(url('tickets/view.php?id='.$tid)) . '">';
                  echo '    <div style="min-width:0;">';
                  echo '      <div class="title">' . e($subj) . '</div>';
                  echo '      <div class="meta">';
                  echo '        <span class="pill st-' . e($st) . '">' . e(strtoupper($st)) . '</span>';
                  echo '        <span class="pill pr-' . e($pr) . '">' . e($pr) . '</span>';
                  echo '        <span class="muted">• ' . e(($lang==='fa'?'درخواست‌کننده: ':'Requester: ')) . e($req) . '</span>';
                  echo '        <span class="muted">• ' . e(($lang==='fa'?'مسئول: ':'Assigned: ')) . e($assText) . '</span>';
                  echo '        <span class="muted">• ' . e(($lang==='fa'?'آپدیت: ':'Updated: ')) . e($updated) . '</span>';
                  echo '      </div>';
                  echo '    </div>';
                  echo '  </a>';

                  echo '  <div class="row-actions">';
                  if ($uc > 0) echo '<span class="mini-badge" title="Unread">' . e($uc>99?'99+':(string)$uc) . '</span>';

                  // Delete form
                  echo '    <form method="post" action="' . e(url('api/tickets/delete.php')) . '" class="delForm" style="margin:0;">';
                  echo '      <input type="hidden" name="_csrf" value="' . e(csrf_token()) . '">';
                  echo '      <input type="hidden" name="ticket_id" value="' . (int)$tid . '">';
                  echo '      <button type="submit" class="btn danger-btn" data-confirm="1" title="Delete">🗑️</button>';
                  echo '    </form>';

                  echo '  </div>';
                  echo '</div>';
              }
          };
        ?>

        <div class="list" id="panel_open" data-panel="open">
          <div class="list-head">
            <div style="font-weight:900;"><?= e(tt('باز','Open',$lang)) ?></div>
            <div class="muted"><?= e(tt('نمایش فقط Open','Showing open tickets',$lang)) ?></div>
          </div>
          <div class="list-body"><?php $renderList($bucket['open']); ?></div>
        </div>

        <div class="list" id="panel_pending" data-panel="pending" style="display:none">
          <div class="list-head">
            <div style="font-weight:900;"><?= e(tt('در انتظار','Pending',$lang)) ?></div>
            <div class="muted"><?= e(tt('نمایش فقط Pending','Showing pending tickets',$lang)) ?></div>
          </div>
          <div class="list-body"><?php $renderList($bucket['pending']); ?></div>
        </div>

        <div class="list" id="panel_solved" data-panel="solved" style="display:none">
          <div class="list-head">
            <div style="font-weight:900;"><?= e(tt('حل‌شده','Solved',$lang)) ?></div>
            <div class="muted"><?= e(tt('نمایش فقط Solved','Showing solved tickets',$lang)) ?></div>
          </div>
          <div class="list-body"><?php $renderList($bucket['solved']); ?></div>
        </div>

        <div class="list" id="panel_closed" data-panel="closed" style="display:none">
          <div class="list-head">
            <div style="font-weight:900;"><?= e(tt('بسته','Closed',$lang)) ?></div>
            <div class="muted"><?= e(tt('نمایش فقط Closed','Showing closed tickets',$lang)) ?></div>
          </div>
          <div class="list-body"><?php $renderList($bucket['closed']); ?></div>
        </div>

        <div class="list" id="panel_all" data-panel="all" style="display:none">
          <div class="list-head">
            <div style="font-weight:900;"><?= e(tt('همه','All',$lang)) ?></div>
            <div class="muted"><?= e(tt('بازها بالا، بسته‌ها پایین','Open first, closed last',$lang)) ?></div>
          </div>
          <div class="list-body"><?php $renderList($bucket['all']); ?></div>
        </div>

      </div>
    </div>

    <script>
      (function(){
        // Tabs
        const tabs = document.querySelectorAll('#tabs .tab');
        const panels = {
          open: document.getElementById('panel_open'),
          pending: document.getElementById('panel_pending'),
          solved: document.getElementById('panel_solved'),
          closed: document.getElementById('panel_closed'),
          all: document.getElementById('panel_all'),
        };
        let active = 'open';

        function setActive(k){
          active = k;
          tabs.forEach(t => {
            const on = (t.dataset.tab === k);
            t.classList.toggle('is-active', on);
            t.setAttribute('aria-selected', on ? 'true' : 'false');
          });
          Object.keys(panels).forEach(x => {
            if (!panels[x]) return;
            panels[x].style.display = (x === k) ? '' : 'none';
          });
          applyClientSearch();
        }

        // Client search
        const input = document.getElementById('clientSearch');

        function applyClientSearch(){
          const q = (input.value || '').trim().toLowerCase();
          const panel = panels[active];
          if (!panel) return;

          const items = panel.querySelectorAll('.ticket-card');
          let any = false;

          items.forEach(a => {
            const s = (a.getAttribute('data-search') || '');
            const ok = (q === '' || s.includes(q));
            a.parentElement.style.display = ok ? '' : 'none'; // hide row
            if (ok) any = true;
          });

          const body = panel.querySelector('.list-body');
          if (!body) return;

          let hint = body.querySelector('[data-search-hint="1"]');
          if (!hint) {
            hint = document.createElement('div');
            hint.className = 'muted';
            hint.style.padding = '12px';
            hint.setAttribute('data-search-hint','1');
            hint.textContent = '<?= e(tt('نتیجه‌ای پیدا نشد.','No results found.',$lang)) ?>';
            body.appendChild(hint);
          }
          hint.style.display = (q !== '' && !any) ? '' : 'none';
        }

        tabs.forEach(t => t.addEventListener('click', () => setActive(t.dataset.tab)));
        input && input.addEventListener('input', applyClientSearch);

        // Delete confirm
        document.querySelectorAll('form.delForm [data-confirm="1"]').forEach(btn => {
          btn.addEventListener('click', (ev) => {
            ev.stopPropagation();
            ev.preventDefault();
            const ok = confirm('<?= e(tt('این تیکت حذف (غیرفعال) شود؟','Delete (deactivate) this ticket?',$lang)) ?>');
            if (!ok) return;
            btn.closest('form').submit();
          });
        });

        setActive('open');
      })();
    </script>

  </main>
</div>
<?php render_footer(); ?>
