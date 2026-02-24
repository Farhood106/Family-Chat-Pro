<?php
require_once __DIR__ . '/../app/bootstrap.php';
require_once __DIR__ . '/../app/view.php';

$me   = require_login();
$lang = $me['lang'] ?? 'fa';
$role = (string)($me['role'] ?? 'user');

if (!tickets_enabled()) {
    redirect(url('index.php'));
}

$tid = int_param('id');
if ($tid <= 0) {
    redirect(url('tickets/index.php'));
}

$stmt = db()->prepare(
    "SELECT t.id, t.group_id, t.requester_id, t.subject, t.status, t.priority, t.assigned_to, t.updated_at, t.created_at,
            u.display_name AS requester_name, u.username AS requester_username, u.role AS requester_role,
            au.display_name AS assigned_name, au.username AS assigned_username, au.role AS assigned_role
     FROM tickets t
     JOIN users u ON u.id=t.requester_id
     LEFT JOIN users au ON au.id=t.assigned_to
     WHERE t.id=?
     LIMIT 1"
);
$stmt->execute([$tid]);
$ticket = $stmt->fetch();
if (!$ticket) {
    redirect(url('tickets/index.php'));
}

$gid     = (int)$ticket['group_id'];
$meId    = (int)$me['id'];
$isStaff = tickets_staff_allowed($role);
$isReq   = ((int)$ticket['requester_id'] === $meId);

if (!can_view_ticket($meId, $role, $ticket)) {
    http_response_code(403);
    echo 'Forbidden';
    exit;
}

// ---------------------------------------------------------
// Membership rule (important for respecting auto-add settings)
// - Requester can always join their own ticket
// - Staff MUST be a member to view/respond
//   Exception: legacy tickets assigned_to this staff => auto-join
// ---------------------------------------------------------
$mStmt = db()->prepare('SELECT left_at FROM group_members WHERE group_id=? AND user_id=? LIMIT 1');
$mStmt->execute([$gid, $meId]);
$m = $mStmt->fetch();
$is_member = ($m && $m['left_at'] === null);

if (!$is_member) {
    if ($isReq) {
        $ins = db()->prepare(
            'INSERT INTO group_members (group_id, user_id, joined_at, left_at)
             VALUES (?,?,?,NULL)
             ON DUPLICATE KEY UPDATE left_at=NULL'
        );
        $ins->execute([$gid, $meId, now_utc()]);
        $is_member = true;
    } elseif ($isStaff) {
        $assignedTo = (int)($ticket['assigned_to'] ?? 0);
        if ($assignedTo === $meId) {
            $ins = db()->prepare(
                'INSERT INTO group_members (group_id, user_id, joined_at, left_at)
                 VALUES (?,?,?,NULL)
                 ON DUPLICATE KEY UPDATE left_at=NULL'
            );
            $ins->execute([$gid, $meId, now_utc()]);
            $is_member = true;
        } else {
            http_response_code(403);
            echo 'Forbidden';
            exit;
        }
    } else {
        http_response_code(403);
        echo 'Forbidden';
        exit;
    }
}

$features = [
    'files'    => setting_bool('files_enabled', true),
    'typing'   => setting_bool('typing_enabled', true),
    'search'   => setting_bool('search_enabled', true),
    'delete'   => setting_bool('delete_enabled', true),
    'per_page' => setting_int('messages_per_page', 30),
];

$canCloseRequester = setting_bool('tickets_allow_requester_close', true) && $isReq;
$canUpdateStatus   = ($isStaff || $canCloseRequester);

$subject   = (string)($ticket['subject'] ?? '');
$status    = (string)($ticket['status'] ?? 'open');
$priority  = (string)($ticket['priority'] ?? 'normal');

$requesterText = (string)($ticket['requester_name'] ?? $ticket['requester_username'] ?? ('#'.$ticket['requester_id']));
$assignedToId  = (int)($ticket['assigned_to'] ?? 0);
$assignedText  = '';
if (!empty($ticket['assigned_name']) || !empty($ticket['assigned_username'])) {
    $assignedText = (string)($ticket['assigned_name'] ?? $ticket['assigned_username']);
} elseif ($assignedToId > 0) {
    $assignedText = '#'.$assignedToId;
} else {
    $assignedText = ($lang==='fa') ? 'بدون مسئول' : 'Unassigned';
}

$isAdminViewer = is_admin_role($role);

// ---------------------------------------------------------
// Extra head CSS for professional + responsive ticket view
// ---------------------------------------------------------
$extraHead = <<<HTML
<style>
/* Ticket view improvements */
.ticket .ticket-tools{
  display:flex;
  align-items:center;
  justify-content:space-between;
  gap:10px;
  flex-wrap:wrap;
  padding:10px 12px;
  border:1px solid rgba(255,255,255,.08);
  background: rgba(255,255,255,.03);
  border-radius: 16px;
  margin: 10px 12px 0;
}
.ticket .ticket-tools .left{
  display:flex;
  align-items:center;
  gap:10px;
  flex-wrap:wrap;
  min-width: 0;
}
.ticket .ticket-tools .right{
  display:flex;
  align-items:center;
  gap:8px;
  flex-wrap:wrap;
}
.ticket .trow{
  display:flex;
  align-items:center;
  gap:8px;
  flex-wrap:wrap;
}
.ticket .tlabel{
  font-size:12px;
  opacity:.75;
}
.ticket .tval{
  font-weight:900;
  font-size:12px;
  opacity:.95;
}
.ticket .ticket-mini{
  display:flex;
  gap:8px;
  flex-wrap:wrap;
  align-items:center;
}
.ticket .ticket-mini .pill{font-size:11px}
.ticket .ticket-mini .muted{font-size:12px}

/* Make sidebar info nicer */
.ticket .ticket-details{
  line-height: 1.85;
}
.ticket .ticket-details .hint-mini{
  margin-top:8px;
  font-size:12px;
  opacity:.75;
}

/* Mobile: keep padding consistent and reduce clutter */
@media (max-width: 900px){
  .ticket .ticket-tools{
    margin: 10px 10px 0;
    padding: 10px;
    border-radius: 14px;
  }
  .ticket .ticket-tools .left{
    width: 100%;
    justify-content: space-between;
  }
  .ticket .ticket-tools .right{
    width: 100%;
    justify-content: flex-start;
  }
  .ticket .ticket-tools .trow{
    width: 100%;
    justify-content: space-between;
  }
}
</style>
HTML;

render_header('🎫 ' . $subject, $me, ['extra_head' => $extraHead]);
?>
<div class="app chat ticket"
  data-chat-type="group"
  data-chat-id="<?= (int)$gid ?>"
  data-me="<?= (int)$meId ?>"
  data-lang="<?= e($lang) ?>"
  data-files="<?= $features['files'] ? '1':'0' ?>"
  data-typing="<?= $features['typing'] ? '1':'0' ?>"
  data-search="<?= $features['search'] ? '1':'0' ?>"
  data-delete="<?= $features['delete'] ? '1':'0' ?>"
  data-per-page="<?= (int)$features['per_page'] ?>"
  data-can-send="1"
>
  <aside class="sidebar">
    <div class="sb-top">
      <a class="btn ghost" href="<?= e(url('tickets/index.php')) ?>">←</a>
      <button type="button" class="btn ghost mobile-only" data-close-sidebar>✕</button>
      <div class="sb-title"><?= e($lang==='fa'?'تیکت':'Ticket') ?></div>
    </div>

    <div class="sb-section">
      <div class="sb-section-title"><?= e($lang==='fa'?'جزئیات':'Details') ?></div>

      <div class="ticket-details">
        <div><span class="muted"><?= e($lang==='fa'?'موضوع: ':'Subject: ') ?></span><?= e($subject) ?></div>

        <div style="margin-top:8px">
          <span class="pill st-<?= e($status) ?>"><?= e(strtoupper($status)) ?></span>
          <span class="pill pr-<?= e($priority) ?>"><?= e($priority) ?></span>
        </div>

        <div class="muted" style="margin-top:10px">
          <?= e($lang==='fa'?'درخواست‌کننده: ':'Requester: ') ?>
          <?= e($requesterText) ?>
        </div>

        <div class="muted" style="margin-top:6px">
          <?= e($lang==='fa'?'مسئول: ':'Assigned: ') ?>
          <?= e($assignedText) ?>
        </div>

        <div class="hint-mini">
          <?= e($lang==='fa'
            ? 'این تیکت یک گفتگوی خصوصی است. فقط اعضای تیکت می‌توانند ببینند/پاسخ دهند.'
            : 'This ticket is a private thread. Only members can view/respond.'
          ) ?>
        </div>

        <?php if ($canUpdateStatus): ?>
          <div class="spacer"></div>

          <form method="post" action="<?= e(url('api/tickets/status.php')) ?>" class="ticket-actions">
            <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="ticket_id" value="<?= (int)$tid ?>">

            <select class="input" name="status">
              <option value="open"   <?= $status==='open'?'selected':'' ?>>OPEN</option>
              <option value="pending"<?= $status==='pending'?'selected':'' ?>>PENDING</option>
              <option value="solved" <?= $status==='solved'?'selected':'' ?>>SOLVED</option>
              <option value="closed" <?= $status==='closed'?'selected':'' ?>>CLOSED</option>
            </select>

            <?php if (!$isStaff && $canCloseRequester): ?>
              <div class="muted" style="margin-top:6px">
                <?= e($lang==='fa'?'شما فقط می‌توانید تیکت را Close کنید.':'You can only close your ticket.') ?>
              </div>
            <?php endif; ?>

            <button class="btn" type="submit"><?= e($lang==='fa'?'ثبت وضعیت':'Update') ?></button>
          </form>
        <?php endif; ?>

        <?php if ($isAdminViewer): ?>
          <div class="spacer"></div>
          <a class="btn" href="<?= e(url('tickets/all.php')) ?>" style="width:100%; text-align:center;">
            🎫 <?= e($lang==='fa'?'همه تیکت‌ها':'All tickets') ?>
          </a>
        <?php endif; ?>
      </div>
    </div>
  </aside>

  <div class="drawer-overlay" data-close-sidebar></div>

  <main class="main">
    <div class="chat-header">
      <div class="chat-title">
        <a class="btn ghost back-big" href="<?= e(url('tickets/index.php')) ?>" aria-label="Back">←</a>
        <div style="min-width:0;">
          <div class="chat-name" style="white-space:nowrap; overflow:hidden; text-overflow:ellipsis;">
            🎫 <?= e($subject) ?>
          </div>
          <div class="chat-sub">
            <?= e($lang==='fa'?'تیکت پشتیبانی':'Support ticket') ?>
            • <span class="pill st-<?= e($status) ?>"><?= e(strtoupper($status)) ?></span>
          </div>
          <div class="chat-sub" id="typingLine"></div>
        </div>
      </div>

      <div class="chat-actions">
        <?php if ($features['search']): ?>
          <input class="chat-search" id="searchInput" placeholder="<?= e(t('search',$lang)) ?>">
        <?php endif; ?>

        <div class="chat-more">
          <button type="button" class="btn ghost chat-more-btn" id="chatMoreBtn" aria-label="Menu">⋮</button>
          <div class="chat-more-menu" id="chatMoreMenu" role="menu">
            <button type="button" class="chat-more-item" data-open-sidebar role="menuitem">ℹ️ <?= e($lang==='fa'?'جزئیات':'Details') ?></button>
            <a class="chat-more-item" href="<?= e(url('tickets/index.php')) ?>" role="menuitem">🎫 <?= e($lang==='fa'?'لیست تیکت‌ها':'Tickets') ?></a>
            <?php if ($isAdminViewer): ?>
              <a class="chat-more-item" href="<?= e(url('tickets/all.php')) ?>" role="menuitem">🧰 <?= e($lang==='fa'?'همه تیکت‌ها':'All tickets') ?></a>
            <?php endif; ?>
            <a class="chat-more-item" href="<?= e(url('index.php')) ?>" role="menuitem">💬 <?= e($lang==='fa'?'چت‌ها':'Chats') ?></a>
            <a class="chat-more-item" href="<?= e(url('profile.php')) ?>" role="menuitem">👤 <?= e(t('profile',$lang)) ?></a>
            <a class="chat-more-item" href="<?= e(url('logout.php')) ?>" role="menuitem">🚪 <?= e(t('logout',$lang)) ?></a>
          </div>
        </div>
      </div>
    </div>

    <!-- Ticket Tools (uses the big main space intelligently) -->
    <div class="ticket-tools">
      <div class="left">
        <div class="ticket-mini">
          <span class="pill st-<?= e($status) ?>"><?= e(strtoupper($status)) ?></span>
          <span class="pill pr-<?= e($priority) ?>"><?= e($priority) ?></span>
          <span class="muted">• <?= e($lang==='fa'?'درخواست‌کننده: ':'Requester: ') ?><?= e($requesterText) ?></span>
          <span class="muted">• <?= e($lang==='fa'?'مسئول: ':'Assigned: ') ?><?= e($assignedText) ?></span>
        </div>
      </div>

      <div class="right">
        <?php if ($canUpdateStatus): ?>
          <form method="post" action="<?= e(url('api/tickets/status.php')) ?>" style="display:flex; gap:8px; align-items:center; flex-wrap:wrap;">
            <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="ticket_id" value="<?= (int)$tid ?>">
            <select class="input" name="status" style="min-width:160px;">
              <option value="open"   <?= $status==='open'?'selected':'' ?>>OPEN</option>
              <option value="pending"<?= $status==='pending'?'selected':'' ?>>PENDING</option>
              <option value="solved" <?= $status==='solved'?'selected':'' ?>>SOLVED</option>
              <option value="closed" <?= $status==='closed'?'selected':'' ?>>CLOSED</option>
            </select>
            <button class="btn" type="submit"><?= e($lang==='fa'?'ثبت':'Update') ?></button>
          </form>
        <?php endif; ?>

        <a class="btn ghost" href="<?= e(url('tickets/index.php')) ?>">
          <?= e($lang==='fa'?'لیست تیکت‌ها':'Tickets') ?>
        </a>

        <?php if ($isAdminViewer): ?>
          <a class="btn" href="<?= e(url('tickets/all.php')) ?>">🧰 <?= e($lang==='fa'?'همه':'All') ?></a>
        <?php endif; ?>
      </div>
    </div>

    <div class="messages" id="messages"></div>
    <button type="button" class="new-msgs" id="newMsgsBtn" style="display:none"></button>
    <div class="load-more" id="loadMoreWrap"><button class="btn ghost" id="loadMoreBtn">Load older</button></div>

    <div class="composer">
      <div class="preview" id="filePreview" style="display:none"></div>
      <form id="sendForm" class="composer-form" method="post" enctype="multipart/form-data">
        <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="chat_type" value="group">
        <input type="hidden" name="group_id" value="<?= (int)$gid ?>">
        <input type="file" id="fileInput" name="file" <?= $features['files'] ? '' : 'disabled' ?> hidden>
        <button type="button" class="btn icon" id="attachBtn" <?= $features['files'] ? '' : 'disabled' ?>>📎</button>
        <input class="composer-input" id="messageInput" name="body" placeholder="<?= e(t('type_message',$lang)) ?>" autocomplete="off">
        <button class="btn primary" type="submit"><?= e(t('send',$lang)) ?></button>
      </form>
    </div>
  </main>
</div>
<?php render_footer(); ?>
