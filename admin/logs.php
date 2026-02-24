<?php
require_once __DIR__ . '/../app/bootstrap.php';
require_once __DIR__ . '/../app/settings.php';
require_once __DIR__ . '/_layout.php';

$u = require_admin();
$lang = $u['lang'] ?? 'fa';

$err = '';
$ok = '';

// Actions: purge / clear
if (is_post()) {
    csrf_validate();
    $action = str_param('action');

    if ($action === 'purge') {
        $days = setting_int('logs_retention_days', 0);
        if ($days <= 0) {
            $err = ($lang==='fa') ? 'نگهداری لاگ نامحدود است. برای پاکسازی، مقدار "نگهداری لاگ" را > 0 تنظیم کنید.' : 'Retention is unlimited. Set retention days > 0 to purge.';
        } else {
            $stmt = db()->prepare('DELETE FROM audit_logs WHERE created_at < (UTC_TIMESTAMP() - INTERVAL ? DAY)');
            $stmt->execute([$days]);
            $ok = ($lang==='fa') ? 'لاگ‌های قدیمی پاکسازی شد.' : 'Old logs purged.';
            audit_log((int)$u['id'], 'admin.logs.purge', 'audit_logs', null, ['days' => $days]);
        }
    } elseif ($action === 'clear') {
        // Dangerous: clear all logs
        $stmt = db()->prepare('TRUNCATE TABLE audit_logs');
        $stmt->execute();
        $ok = ($lang==='fa') ? 'تمام لاگ‌ها پاک شد.' : 'All logs cleared.';
        audit_log((int)$u['id'], 'admin.logs.clear', 'audit_logs', null);
    }
}

// Pagination + filters
$page = max(1, int_param('page', 1));
$per = max(10, min(200, int_param('per', 50)));
$q = trim(str_param('q'));
$actor = trim(str_param('actor'));
$actionF = trim(str_param('act'));

$where = [];
$args = [];

if ($q !== '') {
    $where[] = '(l.action LIKE ? OR l.target_type LIKE ? OR l.meta_json LIKE ? OR l.ip LIKE ?)';
    $args[] = '%' . $q . '%';
    $args[] = '%' . $q . '%';
    $args[] = '%' . $q . '%';
    $args[] = '%' . $q . '%';
}
if ($actor !== '') {
    $where[] = '(u.username LIKE ? OR u.display_name LIKE ?)';
    $args[] = '%' . $actor . '%';
    $args[] = '%' . $actor . '%';
}
if ($actionF !== '') {
    $where[] = 'l.action = ?';
    $args[] = $actionF;
}

$wsql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

// Count
$stCount = db()->prepare("SELECT COUNT(*) c FROM audit_logs l LEFT JOIN users u ON u.id=l.actor_id $wsql");
$stCount->execute($args);
$total = (int)($stCount->fetch()['c'] ?? 0);
$totalPages = max(1, (int)ceil($total / $per));
if ($page > $totalPages) $page = $totalPages;
$offset = ($page - 1) * $per;

$sql = "SELECT l.id, l.created_at, l.action, l.target_type, l.target_id, l.meta_json, l.ip,
               u.username, u.display_name
        FROM audit_logs l
        LEFT JOIN users u ON u.id=l.actor_id
        $wsql
        ORDER BY l.id DESC
        LIMIT $per OFFSET $offset";
$st = db()->prepare($sql);
$st->execute($args);
$rows = $st->fetchAll();

$retention = setting_int('logs_retention_days', 0);
$enabled = setting_bool('logs_enabled', true);

admin_page_start(($lang==='fa'?'لاگ‌ها':'Logs'), $u, 'logs');
?>

<div class="admin-grid">
  <div class="admin-col-8">
    <div class="admin-card">
      <div style="display:flex; align-items:center; justify-content:space-between; gap:12px; flex-wrap:wrap;">
        <div>
          <h2 style="margin:0; font-size:16px;"><?= ($lang==='fa'?'لاگ‌ها و رخدادها':'Audit logs') ?></h2>
          <div class="hint">
            <?= $enabled ? ($lang==='fa'?'لاگ‌گیری فعال است.':'Logging is enabled.') : ($lang==='fa'?'لاگ‌گیری غیرفعال است.':'Logging is disabled.') ?>
            <?php if ($retention === 0): ?>
              • <?= ($lang==='fa'?'نگهداری: نامحدود':'Retention: unlimited') ?>
            <?php else: ?>
              • <?= ($lang==='fa'?'نگهداری: ':'Retention: ') . (int)$retention . ($lang==='fa'?' روز':' days') ?>
            <?php endif; ?>
          </div>
        </div>

        <div style="display:flex; gap:10px; flex-wrap:wrap;">
          <form method="post" onsubmit="return confirm('<?= ($lang==='fa'?'پاکسازی لاگ‌های قدیمی انجام شود؟':'Purge old logs?') ?>');">
            <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="purge">
            <button class="btn" type="submit"><?= ($lang==='fa'?'پاکسازی قدیمی‌ها':'Purge old') ?></button>
          </form>

          <form method="post" onsubmit="return confirm('<?= ($lang==='fa'?'تمام لاگ‌ها حذف شود؟ این کار برگشت ندارد!':'Delete ALL logs? This cannot be undone!') ?>');">
            <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="clear">
            <button class="btn danger" type="submit"><?= ($lang==='fa'?'حذف همه':'Clear all') ?></button>
          </form>
        </div>
      </div>

      <?php if ($err): ?><div class="alert error" style="margin-top:12px;"><?= e($err) ?></div><?php endif; ?>
      <?php if ($ok): ?><div class="alert ok" style="margin-top:12px;"><?= e($ok) ?></div><?php endif; ?>

      <div class="spacer"></div>

      <form method="get" class="form" style="display:flex; gap:10px; flex-wrap:wrap; align-items:end;">
        <div style="flex:1; min-width:180px;">
          <label><?= ($lang==='fa'?'جستجو':'Search') ?></label>
          <input name="q" value="<?= e($q) ?>" placeholder="<?= ($lang==='fa'?'اکشن/آی‌پی/متا...':'action/ip/meta...') ?>">
        </div>
        <div style="flex:1; min-width:180px;">
          <label><?= ($lang==='fa'?'کاربر':'Actor') ?></label>
          <input name="actor" value="<?= e($actor) ?>" placeholder="<?= ($lang==='fa'?'نام کاربری یا نام نمایشی':'username or name') ?>">
        </div>
        <div style="min-width:160px;">
          <label><?= ($lang==='fa'?'اکشن دقیق':'Exact action') ?></label>
          <input name="act" value="<?= e($actionF) ?>" placeholder="user.login">
        </div>
        <div style="min-width:120px;">
          <label><?= ($lang==='fa'?'در هر صفحه':'Per page') ?></label>
          <input type="number" name="per" value="<?= (int)$per ?>" min="10" max="200">
        </div>
        <button class="btn primary" type="submit"><?= ($lang==='fa'?'اعمال':'Apply') ?></button>
      </form>

      <div class="spacer"></div>

      <table class="admin-table">
        <thead>
          <tr>
            <th>ID</th>
            <th><?= ($lang==='fa'?'زمان':'Time') ?></th>
            <th><?= ($lang==='fa'?'کاربر':'Actor') ?></th>
            <th><?= ($lang==='fa'?'اکشن':'Action') ?></th>
            <th><?= ($lang==='fa'?'هدف':'Target') ?></th>
            <th><?= ($lang==='fa'?'آی‌پی':'IP') ?></th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($rows as $r):
          $actorName = trim((string)($r['display_name'] ?? ''));
          if ($actorName === '') $actorName = (string)($r['username'] ?? '-');
          $target = ($r['target_type'] ? ($r['target_type'] . ':' . (string)($r['target_id'] ?? '')) : '-');
        ?>
          <tr>
            <td><?= (int)$r['id'] ?></td>
            <td><?= e($r['created_at']) ?></td>
            <td><?= e($actorName) ?></td>
            <td>
              <div style="font-weight:700;"><?= e($r['action']) ?></div>
              <?php if (!empty($r['meta_json'])): ?>
                <div class="hint" style="max-width:420px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;" title="<?= e($r['meta_json']) ?>"><?= e($r['meta_json']) ?></div>
              <?php endif; ?>
            </td>
            <td><?= e($target) ?></td>
            <td><?= e($r['ip'] ?? '') ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>

      <div class="spacer"></div>

      <div style="display:flex; align-items:center; justify-content:space-between; gap:12px; flex-wrap:wrap;">
        <div class="hint"><?= ($lang==='fa'?'تعداد رکورد: ':'Total: ') . (int)$total ?></div>
        <div style="display:flex; gap:8px; flex-wrap:wrap;">
          <?php
            $baseParams = ['q'=>$q,'actor'=>$actor,'act'=>$actionF,'per'=>$per];
            $mk = function($p) use ($baseParams) {
              $arr = $baseParams;
              $arr['page'] = $p;
              return '?' . http_build_query($arr);
            };
          ?>
          <a class="btn" href="<?= e($mk(1)) ?>" <?= ($page<=1?'style="opacity:.5; pointer-events:none;"':'') ?>><?= ($lang==='fa'?'اول':'First') ?></a>
          <a class="btn" href="<?= e($mk(max(1,$page-1))) ?>" <?= ($page<=1?'style="opacity:.5; pointer-events:none;"':'') ?>><?= ($lang==='fa'?'قبلی':'Prev') ?></a>
          <div class="btn ghost" style="cursor:default;">
            <?= ($lang==='fa'?'صفحه':'Page') ?> <?= (int)$page ?> / <?= (int)$totalPages ?>
          </div>
          <a class="btn" href="<?= e($mk(min($totalPages,$page+1))) ?>" <?= ($page>=$totalPages?'style="opacity:.5; pointer-events:none;"':'') ?>><?= ($lang==='fa'?'بعدی':'Next') ?></a>
          <a class="btn" href="<?= e($mk($totalPages)) ?>" <?= ($page>=$totalPages?'style="opacity:.5; pointer-events:none;"':'') ?>><?= ($lang==='fa'?'آخر':'Last') ?></a>
        </div>
      </div>

    </div>
  </div>

  <div class="admin-col-4">
    <div class="admin-card">
      <h2 style="margin:0 0 10px; font-size:16px;"><?= ($lang==='fa'?'راهنما':'Tips') ?></h2>
      <div style="font-size:12px; opacity:.78; line-height:1.9;">
        <?php if ($lang==='fa'): ?>
          <ul style="margin:0; padding-inline-start:18px;">
            <li>لاگ‌ها برای بررسی ورود، تغییرات ادمین و رخدادها استفاده می‌شود.</li>
            <li>برای نگهداری نامحدود، مقدار «نگهداری لاگ» را ۰ بگذارید.</li>
            <li>برای پاکسازی خودکار، مقدار روزها را تنظیم کنید و سپس «پاکسازی قدیمی‌ها» را بزنید.</li>
          </ul>
        <?php else: ?>
          <ul style="margin:0; padding-inline-start:18px;">
            <li>Logs help you audit logins, admin changes and system events.</li>
            <li>Set retention to 0 to keep forever.</li>
            <li>Set retention days and use purge to remove old entries.</li>
          </ul>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>

<?php admin_page_end(); ?>
