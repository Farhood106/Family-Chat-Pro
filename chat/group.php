<?php
require_once __DIR__ . '/../app/bootstrap.php';
require_once __DIR__ . '/../app/view.php';

$me = require_login();
$lang = $me['lang'] ?? 'fa';
$gid = int_param('g');
if ($gid <= 0) redirect(url('index.php'));

$gStmt = db()->prepare('SELECT id, name, description FROM chat_groups WHERE id=? AND is_active=1');
$gStmt->execute([$gid]);
$group = $gStmt->fetch();
if (!$group) {
    redirect(url('index.php'));
}

// Membership check
$mStmt = db()->prepare('SELECT left_at FROM group_members WHERE group_id=? AND user_id=? LIMIT 1');
$mStmt->execute([$gid, $me['id']]);
$m = $mStmt->fetch();
$is_member = ($m && $m['left_at'] === null);

$features = [
    'files' => setting_bool('files_enabled', true),
    'typing' => setting_bool('typing_enabled', true),
    'search' => setting_bool('search_enabled', true),
    'delete' => setting_bool('delete_enabled', true),
    'allow_leave' => setting_bool('allow_leave_group', true),
    'per_page' => setting_int('messages_per_page', 30),
];

render_header('# ' . (string)$group['name'], $me);
?>
<div class="app chat"
  data-chat-type="group"
  data-chat-id="<?= (int)$gid ?>"
  data-me="<?= (int)$me['id'] ?>"
  data-lang="<?= e($lang) ?>"
  data-files="<?= $features['files'] ? '1':'0' ?>"
  data-typing="<?= $features['typing'] ? '1':'0' ?>"
  data-search="<?= $features['search'] ? '1':'0' ?>"
  data-delete="<?= $features['delete'] ? '1':'0' ?>"
  data-per-page="<?= (int)$features['per_page'] ?>"
  data-can-send="<?= $is_member ? '1':'0' ?>"
>
  <aside class="sidebar">
    <div class="sb-top">
      <a class="btn ghost" href="<?= e(url('index.php')) ?>">←</a>
      <button type="button" class="btn ghost mobile-only" data-close-sidebar>✕</button>
      <div class="sb-title"><?= e(t('groups',$lang)) ?></div>
    </div>
    <div class="sb-section">
      <div class="sb-section-title"># <?= e($group['name']) ?></div>
      <div class="muted"><?= e($group['description'] ?? '') ?></div>
      <div class="spacer"></div>

      <?php if (!$is_member): ?>
        <button class="btn primary" id="joinBtn" data-gid="<?= (int)$gid ?>"><?= e(t('join',$lang)) ?></button>
        <div class="muted" style="margin-top:10px">برای دیدن پیام‌ها باید Join کنید.</div>
      <?php else: ?>
        <?php if ($features['allow_leave']): ?>
          <button class="btn ghost" id="leaveBtn" data-gid="<?= (int)$gid ?>"><?= e(t('leave',$lang)) ?></button>
        <?php endif; ?>
      <?php endif; ?>
    </div>
  </aside>

  <div class="drawer-overlay" data-close-sidebar></div>

  <main class="main">
    <div class="chat-header">
      <div class="chat-title">
        <!-- Big Back button (mobile-first) -->
        <a class="btn ghost back-big" href="<?= e(url('index.php')) ?>" aria-label="Back">←</a>
        <div>
          <div class="chat-name"># <?= e($group['name']) ?></div>
          <div class="chat-sub" id="presenceLine"></div>
          <div class="chat-sub" id="typingLine"></div>
        </div>
      </div>
      <div class="chat-actions">
        <?php if ($features['search']): ?>
          <input class="chat-search" id="searchInput" placeholder="<?= e(t('search',$lang)) ?>" <?= $is_member ? '' : 'disabled' ?>>
        <?php endif; ?>

        <!-- Three-dots menu (replaces hamburger) -->
        <div class="chat-more">
          <button type="button" class="btn ghost chat-more-btn" id="chatMoreBtn" aria-label="Menu">⋮</button>
          <div class="chat-more-menu" id="chatMoreMenu" role="menu">
            <button type="button" class="chat-more-item" data-open-sidebar role="menuitem">ℹ️ <?= e($lang==='fa'?'اطلاعات':'Info') ?></button>
            <a class="chat-more-item" href="<?= e(url('index.php')) ?>" role="menuitem">💬 <?= e($lang==='fa'?'لیست چت‌ها':'Chats') ?></a>
            <a class="chat-more-item" href="<?= e(url('profile.php')) ?>" role="menuitem">👤 <?= e(t('profile',$lang)) ?></a>
            <a class="chat-more-item" href="<?= e(url('logout.php')) ?>" role="menuitem">🚪 <?= e(t('logout',$lang)) ?></a>
          </div>
        </div>
      </div>
    </div>

    <?php if (!$is_member): ?>
      <div class="notice">
        <div class="notice-title">برای دیدن پیام‌ها باید عضو گروه شوید</div>
        <div class="notice-sub">بعد از Join می‌توانید پیام‌ها را ببینید و ارسال کنید.</div>
      </div>
    <?php endif; ?>

    <div class="messages" id="messages"></div>
    <button type="button" class="new-msgs" id="newMsgsBtn" style="display:none"></button>
    <div class="load-more" id="loadMoreWrap"><button class="btn ghost" id="loadMoreBtn" <?= $is_member ? '' : 'disabled' ?>>Load older</button></div>

    <div class="composer" style="<?= $is_member ? '' : 'display:none;' ?>">
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
