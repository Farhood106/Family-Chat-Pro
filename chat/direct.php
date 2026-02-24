<?php
require_once __DIR__ . '/../app/bootstrap.php';
require_once __DIR__ . '/../app/view.php';

$me = require_login();
$lang = $me['lang'] ?? 'fa';
$other_id = int_param('u');
if ($other_id <= 0 || $other_id === (int)$me['id']) {
    redirect(url('index.php'));
}

// Validate other user exists
$uStmt = db()->prepare('SELECT id, display_name, username, role FROM users WHERE id=? AND is_active=1');
$uStmt->execute([$other_id]);
$other = $uStmt->fetch();
if (!$other) {
    http_response_code(404);
    echo 'User not found';
    exit;
}

// Enforce role visibility (prevents manual URL access).
if (!can_direct_chat((string)($me['role'] ?? 'user'), (string)($other['role'] ?? 'user'), (int)$me['id'], (int)$other_id)) {
    http_response_code(403);
    echo 'Forbidden';
    exit;
}

// Create or fetch direct conversation (store pair as user_a < user_b)
$user_a = min((int)$me['id'], (int)$other_id);
$user_b = max((int)$me['id'], (int)$other_id);

$dcStmt = db()->prepare('SELECT id FROM direct_conversations WHERE user_a=? AND user_b=? LIMIT 1');
$dcStmt->execute([$user_a, $user_b]);
$dc = $dcStmt->fetch();
if (!$dc) {
    $ins = db()->prepare('INSERT INTO direct_conversations (user_a, user_b) VALUES (?,?)');
    $ins->execute([$user_a, $user_b]);
    $direct_id = (int)db()->lastInsertId();
} else {
    $direct_id = (int)$dc['id'];
}

$features = [
    'files' => setting_bool('files_enabled', true),
    'typing' => setting_bool('typing_enabled', true),
    'search' => setting_bool('search_enabled', true),
    'delete' => setting_bool('delete_enabled', true),
    'per_page' => setting_int('messages_per_page', 30),
];

render_header($other['display_name'] . ' - Direct', $me);
?>
<div class="app chat" 
  data-chat-type="direct"
  data-chat-id="<?= (int)$direct_id ?>"
  data-me="<?= (int)$me['id'] ?>"
  data-other-id="<?= (int)$other_id ?>"
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
      <a class="btn ghost" href="<?= e(url('index.php')) ?>">←</a>
      <button type="button" class="btn ghost mobile-only" data-close-sidebar>✕</button>
      <div class="sb-title"><?= e(t('direct',$lang)) ?></div>
    </div>
    <div class="sb-section">
      <div class="sb-section-title"><?= e($other['display_name']) ?></div>
      <div class="muted">@<?= e($other['username']) ?></div>
    </div>
  </aside>

  <div class="drawer-overlay" data-close-sidebar></div>

  <main class="main">
    <div class="chat-header">
      <div class="chat-title">
        <!-- Big Back button (mobile-first) -->
        <a class="btn ghost back-big" href="<?= e(url('index.php')) ?>" aria-label="Back">←</a>
        <div>
          <div class="chat-name"><?= e($other['display_name']) ?></div>
          <div class="chat-sub" id="presenceLine"></div>
          <div class="chat-sub" id="typingLine"></div>
        </div>
      </div>
      <div class="chat-actions">
        <?php if ($features['search']): ?>
          <input class="chat-search" id="searchInput" placeholder="<?= e(t('search',$lang)) ?>">
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

    <div class="messages" id="messages"></div>
    <button type="button" class="new-msgs" id="newMsgsBtn" style="display:none"></button>
    <div class="load-more" id="loadMoreWrap"><button class="btn ghost" id="loadMoreBtn">Load older</button></div>

    <div class="composer">
      <div class="preview" id="filePreview" style="display:none"></div>
      <form id="sendForm" class="composer-form" method="post" enctype="multipart/form-data">
        <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="chat_type" value="direct">
        <input type="hidden" name="direct_id" value="<?= (int)$direct_id ?>">
        <input type="file" id="fileInput" name="file" <?= $features['files'] ? '' : 'disabled' ?> hidden>
        <button type="button" class="btn icon" id="attachBtn" <?= $features['files'] ? '' : 'disabled' ?>>📎</button>
        <input class="composer-input" id="messageInput" name="body" placeholder="<?= e(t('type_message',$lang)) ?>" autocomplete="off">
        <button class="btn primary" type="submit"><?= e(t('send',$lang)) ?></button>
      </form>
    </div>
  </main>
</div>
<?php render_footer(); ?>
