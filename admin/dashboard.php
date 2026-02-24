<?php
require_once __DIR__ . '/../app/bootstrap.php';
require_once __DIR__ . '/_layout.php';

$u = require_admin();
$lang = $u['lang'] ?? 'fa';
$meId = (int)$u['id'];

function tt(string $fa, string $en, string $lang): string { return $lang==='fa' ? $fa : $en; }

// ---------------------------------------------------------
// KPI queries
// ---------------------------------------------------------
$totalUsers  = (int)db()->query("SELECT COUNT(*) c FROM users")->fetch()['c'];
$totalGroups = (int)db()->query("SELECT COUNT(*) c FROM chat_groups WHERE is_ticket=0")->fetch()['c'];

// Online = last_seen within 60s (same logic as presence)
$onlineUsers = (int)db()->query("SELECT COUNT(*) c FROM users WHERE last_seen_at IS NOT NULL AND last_seen_at >= (UTC_TIMESTAMP() - INTERVAL 60 SECOND)")->fetch()['c'];

// Messages today (UTC)
$msgsToday = (int)db()->query("SELECT COUNT(*) c FROM messages WHERE created_at >= (UTC_DATE())")->fetch()['c'];

// Tickets KPIs
$ticketOpen    = 0;
$ticketPending = 0;
$ticketAll     = 0;

try {
    $ticketAll     = (int)db()->query("SELECT COUNT(*) c FROM tickets")->fetch()['c'];
    $ticketOpen    = (int)db()->query("SELECT COUNT(*) c FROM tickets WHERE status='open'")->fetch()['c'];
    $ticketPending = (int)db()->query("SELECT COUNT(*) c FROM tickets WHERE status='pending'")->fetch()['c'];
} catch (Throwable $e) {
    $ticketAll = $ticketOpen = $ticketPending = 0;
}

// Recent chat activity
$recent = db()->query("SELECT m.id, m.chat_type, m.created_at, u.display_name, u.username
                        FROM messages m
                        JOIN users u ON u.id = m.sender_id
                        ORDER BY m.id DESC
                        LIMIT 8")->fetchAll();

// Recent tickets
$recentTickets = [];
$ticketUnread = [];
$ticketUnreadTotal = 0;

try {
    $stmt = db()->prepare(
        "SELECT t.id, t.group_id, t.subject, t.status, t.priority, t.updated_at,
                u.display_name AS requester_name, u.username AS requester_username,
                au.display_name AS assigned_name, au.username AS assigned_username
         FROM tickets t
         JOIN users u ON u.id=t.requester_id
         LEFT JOIN users au ON au.id=t.assigned_to
         ORDER BY
           CASE WHEN t.status IN ('open','pending') THEN 0 ELSE 1 END,
           t.updated_at DESC
         LIMIT 6"
    );
    $stmt->execute();
    $recentTickets = $stmt->fetchAll();

    $gids = array_map(fn($r) => (int)$r['group_id'], $recentTickets);
    $gids = array_values(array_filter(array_unique($gids), fn($x) => $x > 0));
    if ($gids) {
        $ticketUnread = ticket_unread_counts($meId, $gids);
        foreach ($gids as $gid) {
            $ticketUnreadTotal += (int)($ticketUnread[$gid] ?? 0);
        }
    }
} catch (Throwable $e) {
    $recentTickets = [];
    $ticketUnread = [];
    $ticketUnreadTotal = 0;
}

admin_page_start(($lang==='fa'?'داشبورد مدیریت':'Admin Dashboard'), $u, 'dashboard');
?>

<div class="admin-grid">
  <div class="admin-col-3">
    <div class="admin-card">
      <div class="admin-kpi">
        <div class="admin-kpi-ico">👤</div>
        <div>
          <div class="admin-kpi-num"><?= (int)$totalUsers ?></div>
          <div class="admin-kpi-label"><?= tt('کل کاربران','Total users',$lang) ?></div>
        </div>
      </div>
    </div>
  </div>

  <div class="admin-col-3">
    <div class="admin-card">
      <div class="admin-kpi">
        <div class="admin-kpi-ico">🟢</div>
        <div>
          <div class="admin-kpi-num"><?= (int)$onlineUsers ?></div>
          <div class="admin-kpi-label"><?= tt('آنلاین الآن','Online now',$lang) ?></div>
        </div>
      </div>
    </div>
  </div>

  <div class="admin-col-3">
    <div class="admin-card">
      <div class="admin-kpi">
        <div class="admin-kpi-ico">👥</div>
        <div>
          <div class="admin-kpi-num"><?= (int)$totalGroups ?></div>
          <div class="admin-kpi-label"><?= tt('کل گروه‌ها','Total groups',$lang) ?></div>
        </div>
      </div>
    </div>
  </div>

  <div class="admin-col-3">
    <div class="admin-card">
      <div class="admin-kpi">
        <div class="admin-kpi-ico">💬</div>
        <div>
          <div class="admin-kpi-num"><?= (int)$msgsToday ?></div>
          <div class="admin-kpi-label"><?= tt('پیام‌های امروز','Messages today',$lang) ?></div>
        </div>
      </div>
    </div>
  </div>

  <!-- Tickets KPI row -->
  <div class="admin-col-3">
    <div class="admin-card">
      <div class="admin-kpi">
        <div class="admin-kpi-ico">🎫</div>
        <div>
          <div class="admin-kpi-num"><?= (int)$ticketOpen ?></div>
          <div class="admin-kpi-label"><?= tt('تیکت باز (Open)','Open tickets',$lang) ?></div>
        </div>
      </div>
    </div>
  </div>

  <div class="admin-col-3">
    <div class="admin-card">
      <div class="admin-kpi">
        <div class="admin-kpi-ico">⏳</div>
        <div>
          <div class="admin-kpi-num"><?= (int)$ticketPending ?></div>
          <div class="admin-kpi-label"><?= tt('در انتظار (Pending)','Pending tickets',$lang) ?></div>
        </div>
      </div>
    </div>
  </div>

  <div class="admin-col-3">
    <div class="admin-card">
      <div class="admin-kpi">
        <div class="admin-kpi-ico">📦</div>
        <div>
          <div class="admin-kpi-num"><?= (int)$ticketAll ?></div>
          <div class="admin-kpi-label"><?= tt('کل تیکت‌ها','All tickets',$lang) ?></div>
        </div>
      </div>
    </div>
  </div>

  <div class="admin-col-3">
    <div class="admin-card">
      <div class="admin-kpi">
        <div class="admin-kpi-ico">🔔</div>
        <div>
          <div class="admin-kpi-num"><?= (int)$ticketUnreadTotal ?></div>
          <div class="admin-kpi-label"><?= tt('خوانده‌نشده (۶ تای اخیر)','Unread (recent)',$lang) ?></div>
        </div>
      </div>
    </div>
  </div>

  <!-- Recent activity -->
  <div class="admin-col-8">
    <div class="admin-card">
      <h2 style="margin:0 0 10px; font-size:16px;">
        <?= tt('فعالیت اخیر','Recent activity',$lang) ?>
      </h2>
      <table class="admin-table">
        <thead>
          <tr>
            <th><?= tt('ID','ID',$lang) ?></th>
            <th><?= tt('فرستنده','Sender',$lang) ?></th>
            <th><?= tt('نوع','Type',$lang) ?></th>
            <th><?= tt('زمان','Time',$lang) ?></th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($recent as $r): ?>
          <tr>
            <td><?= (int)$r['id'] ?></td>
            <td><?= e($r['display_name'] ?: $r['username']) ?></td>
            <td><?= e($r['chat_type']) ?></td>
            <td><?= e($r['created_at']) ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <!-- Recent tickets -->
    <div class="admin-card" style="margin-top:12px;">
      <div style="display:flex; align-items:center; justify-content:space-between; gap:10px; flex-wrap:wrap;">
        <h2 style="margin:0; font-size:16px;"><?= tt('آخرین تیکت‌ها','Recent tickets',$lang) ?></h2>
        <a class="btn" href="<?= e(url('tickets/all.php')) ?>"><?= tt('مشاهده همه','View all',$lang) ?></a>
      </div>

      <div style="margin-top:10px; display:flex; flex-direction:column; gap:8px;">
        <?php if (!$recentTickets): ?>
          <div class="muted"><?= tt('تیکتی وجود ندارد.','No tickets.',$lang) ?></div>
        <?php else: ?>
          <?php foreach ($recentTickets as $t):
              $gid = (int)($t['group_id'] ?? 0);
              $uc  = (int)($ticketUnread[$gid] ?? 0);
              $req = (string)($t['requester_name'] ?? $t['requester_username'] ?? '');
              $ass = (string)($t['assigned_name'] ?? $t['assigned_username'] ?? '');
              $assText = ($ass !== '') ? $ass : tt('بدون مسئول','Unassigned',$lang);
          ?>
            <a class="admin-card" href="<?= e(url('tickets/view.php?id='.(int)$t['id'])) ?>" style="text-decoration:none; color:inherit; padding:10px;">
              <div style="display:flex; align-items:flex-start; justify-content:space-between; gap:10px;">
                <div style="min-width:0;">
                  <div style="font-weight:900; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;">
                    🎫 <?= e((string)$t['subject']) ?>
                  </div>
                  <div class="muted" style="margin-top:6px; display:flex; flex-wrap:wrap; gap:8px; align-items:center;">
                    <span class="pill st-<?= e((string)$t['status']) ?>"><?= e(strtoupper((string)$t['status'])) ?></span>
                    <span class="pill pr-<?= e((string)$t['priority']) ?>"><?= e((string)$t['priority']) ?></span>
                    <span>• <?= e(tt('درخواست‌کننده: ','Requester: ',$lang)) ?><?= e($req) ?></span>
                    <span>• <?= e(tt('مسئول: ','Assigned: ',$lang)) ?><?= e($assText) ?></span>
                    <span>• <?= e(tt('آپدیت: ','Updated: ',$lang)) ?><?= e((string)$t['updated_at']) ?></span>
                  </div>
                </div>
                <?php if ($uc > 0): ?>
                  <span class="btn" style="pointer-events:none;"><?= e($uc>99?'99+':(string)$uc) ?></span>
                <?php endif; ?>
              </div>
            </a>
          <?php endforeach; ?>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <!-- Quick actions -->
  <div class="admin-col-4">
    <div class="admin-card">
      <h2 style="margin:0 0 10px; font-size:16px;">
        <?= tt('میانبرها','Quick actions',$lang) ?>
      </h2>
      <div class="admin-actions">
        <a class="btn primary" href="<?= e(url('admin/users.php')) ?>" data-close-admin-nav="1">
          <?= tt('مدیریت کاربران','Manage users',$lang) ?>
        </a>
        <a class="btn" href="<?= e(url('admin/groups.php')) ?>" data-close-admin-nav="1">
          <?= tt('مدیریت گروه‌ها','Manage groups',$lang) ?>
        </a>
        <a class="btn" href="<?= e(url('tickets/all.php')) ?>" data-close-admin-nav="1">
          <?= tt('مدیریت تیکت‌ها','Manage tickets',$lang) ?>
        </a>
        <a class="btn ghost" href="<?= e(url('admin/settings.php')) ?>" data-close-admin-nav="1">
          <?= tt('تنظیمات','Settings',$lang) ?>
        </a>
      </div>

      <div class="spacer"></div>
      <div style="font-size:12px; opacity:.7; line-height:1.8;">
        <?= tt(
          'نکته: روی هاست اشتراکی، وضعیت آنلاین با آخرین بازدید در ۶۰ ثانیه محاسبه می‌شود.',
          'Note: On shared hosting, online status is derived from last seen within 60 seconds.',
          $lang
        ) ?>
      </div>
    </div>
  </div>
</div>

<?php admin_page_end(); ?>
