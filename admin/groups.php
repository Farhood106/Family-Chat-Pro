<?php
require_once __DIR__ . '/../app/bootstrap.php';
require_once __DIR__ . '/_layout.php';

$u = require_admin();
$lang = $u['lang'] ?? 'fa';

$err = '';
$ok = '';

$action = str_param('action');
$gid = int_param('id');

// Mutations
if (is_post()) {
    csrf_validate();
    $action = str_param('action');

    if ($action === 'create') {
        $name = trim(str_param('name'));
        $desc = trim(str_param('description'));
        if ($name === '') {
            $err = ($lang==='fa') ? 'نام گروه الزامی است.' : 'Group name is required.';
        } else {
            $st = db()->prepare('INSERT INTO chat_groups (name, description, created_by, is_active) VALUES (?,?,?,1)');
            $st->execute([$name, $desc ?: null, (int)$u['id']]);
            $newId = (int)db()->lastInsertId();
            audit_log((int)$u['id'], 'admin.group.create', 'group', $newId, ['name' => $name]);
            $ok = ($lang==='fa') ? 'گروه ساخته شد.' : 'Group created.';
        }
    }

    if ($action === 'update') {
        $id = int_param('id');
        $name = trim(str_param('name'));
        $desc = trim(str_param('description'));
        $active = str_param('is_active','1') === '1' ? 1 : 0;
        if ($id<=0 || $name==='') {
            $err = ($lang==='fa') ? 'ورودی نامعتبر.' : 'Invalid input.';
        } else {
            $st = db()->prepare('UPDATE chat_groups SET name=?, description=?, is_active=? WHERE id=?');
            $st->execute([$name, $desc ?: null, $active, $id]);
            audit_log((int)$u['id'], 'admin.group.update', 'group', $id, ['name' => $name, 'is_active' => $active]);
            $ok = ($lang==='fa') ? 'گروه ویرایش شد.' : 'Group updated.';
        }
    }

    if ($action === 'delete') {
        $id = int_param('id');
        if ($id>0) {
            db()->prepare('DELETE FROM chat_groups WHERE id=?')->execute([$id]);
            audit_log((int)$u['id'], 'admin.group.delete', 'group', $id);
            $ok = ($lang==='fa') ? 'گروه حذف شد.' : 'Group deleted.';
        }
    }

    if ($action === 'add_member') {
        $id = int_param('id');
        $username = trim(str_param('username'));
        if ($id<=0 || $username==='') {
            $err = ($lang==='fa') ? 'ورودی نامعتبر.' : 'Invalid input.';
        } else {
            $us = db()->prepare('SELECT id FROM users WHERE username=? LIMIT 1');
            $us->execute([$username]);
            $row = $us->fetch();
            if (!$row) {
                $err = ($lang==='fa') ? 'کاربر پیدا نشد.' : 'User not found.';
            } else {
                // Upsert membership
                $gm = db()->prepare('INSERT INTO group_members (group_id, user_id, role, joined_at, left_at)
                                     VALUES (?,?,"member", UTC_TIMESTAMP(), NULL)
                                     ON DUPLICATE KEY UPDATE left_at=NULL');
                $gm->execute([$id, (int)$row['id']]);
                audit_log((int)$u['id'], 'admin.group.add_member', 'group', $id, ['user_id' => (int)$row['id'], 'username' => $username]);
                $ok = ($lang==='fa') ? 'عضو اضافه شد.' : 'Member added.';
            }
        }
    }

    if ($action === 'remove_member') {
        $id = int_param('id');
        $uid = int_param('user_id');
        if ($id>0 && $uid>0) {
            // Soft leave
            $st = db()->prepare('UPDATE group_members SET left_at=UTC_TIMESTAMP() WHERE group_id=? AND user_id=?');
            $st->execute([$id, $uid]);
            audit_log((int)$u['id'], 'admin.group.remove_member', 'group', $id, ['user_id' => $uid]);
            $ok = ($lang==='fa') ? 'عضو حذف شد.' : 'Member removed.';
        }
    }
}

// List groups with counts
$groups = db()->query('SELECT g.id, g.name, g.description, g.is_active, g.created_at,
    (SELECT COUNT(*) FROM group_members m WHERE m.group_id=g.id AND m.left_at IS NULL) AS members
  FROM chat_groups g
  WHERE g.is_ticket=0
  ORDER BY g.created_at DESC')->fetchAll();

$editGroup = null;
if ($action === 'edit' && $gid>0) {
    $st = db()->prepare('SELECT id, name, description, is_active FROM chat_groups WHERE id=?');
    $st->execute([$gid]);
    $editGroup = $st->fetch();
}

$members = [];
if ($action === 'manage' && $gid>0) {
    $st = db()->prepare('SELECT u.id, u.username, u.display_name, u.last_seen_at, m.role
                         FROM group_members m
                         JOIN users u ON u.id=m.user_id
                         WHERE m.group_id=? AND m.left_at IS NULL
                         ORDER BY u.display_name');
    $st->execute([$gid]);
    $members = $st->fetchAll();
}

admin_page_start(($lang==='fa'?'مدیریت گروه‌ها':'Groups'), $u, 'groups');
?>

<div class="admin-grid">
  <div class="admin-col-4">
    <div class="admin-card">
      <h2 style="margin:0 0 12px;font-size:16px;">
        <?= ($lang==='fa'?'ساخت گروه':'Create group') ?>
      </h2>
      <?php if ($err): ?><div class="alert error"><?= e($err) ?></div><?php endif; ?>
      <?php if ($ok): ?><div class="alert ok"><?= e($ok) ?></div><?php endif; ?>

      <form method="post" class="form">
        <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="action" value="create">

        <label><?= ($lang==='fa'?'نام گروه':'Group name') ?></label>
        <input name="name" required>

        <label><?= ($lang==='fa'?'توضیح':'Description') ?></label>
        <input name="description">

        <button class="btn primary" type="submit"><?= ($lang==='fa'?'ایجاد':'Create') ?></button>
      </form>

      <?php if ($editGroup): ?>
        <div class="spacer"></div>
        <h2 style="margin:0 0 12px;font-size:16px;">
          <?= ($lang==='fa'?'ویرایش گروه':'Edit group') ?>
        </h2>
        <form method="post" class="form">
          <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
          <input type="hidden" name="action" value="update">
          <input type="hidden" name="id" value="<?= (int)$editGroup['id'] ?>">

          <label><?= ($lang==='fa'?'نام گروه':'Group name') ?></label>
          <input name="name" value="<?= e($editGroup['name']) ?>" required>

          <label><?= ($lang==='fa'?'توضیح':'Description') ?></label>
          <input name="description" value="<?= e($editGroup['description'] ?? '') ?>">

          <label><?= ($lang==='fa'?'وضعیت':'Status') ?></label>
          <select name="is_active">
            <option value="1" <?= ((int)$editGroup['is_active']===1?'selected':'') ?>><?= ($lang==='fa'?'فعال':'Active') ?></option>
            <option value="0" <?= ((int)$editGroup['is_active']===0?'selected':'') ?>><?= ($lang==='fa'?'غیرفعال':'Disabled') ?></option>
          </select>

          <button class="btn primary" type="submit"><?= ($lang==='fa'?'ذخیره':'Save') ?></button>
        </form>

        <div class="spacer"></div>
        <form method="post" onsubmit="return confirm('Delete group?');">
          <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
          <input type="hidden" name="action" value="delete">
          <input type="hidden" name="id" value="<?= (int)$editGroup['id'] ?>">
          <button class="btn danger" type="submit"><?= ($lang==='fa'?'حذف گروه':'Delete group') ?></button>
        </form>
      <?php endif; ?>
    </div>

    <?php if ($action === 'manage' && $gid>0): ?>
    <div class="spacer"></div>
    <div class="admin-card">
      <h2 style="margin:0 0 12px;font-size:16px;">
        <?= ($lang==='fa'?'مدیریت اعضا':'Members') ?>
      </h2>
      <form method="post" class="form">
        <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="action" value="add_member">
        <input type="hidden" name="id" value="<?= (int)$gid ?>">

        <label><?= ($lang==='fa'?'نام کاربری':'Username') ?></label>
        <input name="username" placeholder="مثلاً: ali" required>
        <button class="btn" type="submit"><?= ($lang==='fa'?'افزودن':'Add') ?></button>
      </form>

      <div class="spacer"></div>
      <table class="admin-table">
        <thead>
          <tr>
            <th>ID</th>
            <th><?= ($lang==='fa'?'کاربر':'User') ?></th>
            <th><?= ($lang==='fa'?'نقش':'Role') ?></th>
            <th><?= ($lang==='fa'?'آخرین بازدید':'Last seen') ?></th>
            <th></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($members as $m): ?>
            <tr>
              <td><?= (int)$m['id'] ?></td>
              <td>
                <b><?= e($m['display_name']) ?></b>
                <div class="muted">@<?= e($m['username']) ?></div>
              </td>
              <td><?= e($m['role']) ?></td>
              <td class="muted"><?= e($m['last_seen_at'] ?? '') ?></td>
              <td>
                <form method="post" style="margin:0" onsubmit="return confirm('Remove?');">
                  <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
                  <input type="hidden" name="action" value="remove_member">
                  <input type="hidden" name="id" value="<?= (int)$gid ?>">
                  <input type="hidden" name="user_id" value="<?= (int)$m['id'] ?>">
                  <button class="btn danger" type="submit"><?= ($lang==='fa'?'حذف':'Remove') ?></button>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>
  </div>

  <div class="admin-col-8">
    <div class="admin-card">
      <div style="display:flex;align-items:center;gap:10px;">
        <h2 style="margin:0;font-size:16px;">
          <?= ($lang==='fa'?'لیست گروه‌ها':'Groups list') ?>
        </h2>
        <div class="grow"></div>
        <a class="btn ghost" href="<?= e(url('admin/dashboard.php')) ?>" data-close-admin-nav="1"><?= ($lang==='fa'?'داشبورد':'Dashboard') ?></a>
      </div>

      <div class="spacer"></div>

      <table class="admin-table">
        <thead>
          <tr>
            <th>ID</th>
            <th><?= ($lang==='fa'?'نام':'Name') ?></th>
            <th><?= ($lang==='fa'?'اعضا':'Members') ?></th>
            <th><?= ($lang==='fa'?'وضعیت':'Status') ?></th>
            <th><?= ($lang==='fa'?'ایجاد':'Created') ?></th>
            <th></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($groups as $g): ?>
            <tr>
              <td><?= (int)$g['id'] ?></td>
              <td>
                <b><?= e($g['name']) ?></b>
                <?php if (!empty($g['description'])): ?>
                  <div class="muted"><?= e($g['description']) ?></div>
                <?php endif; ?>
              </td>
              <td><?= (int)$g['members'] ?></td>
              <td><?= ((int)$g['is_active']===1 ? ($lang==='fa'?'فعال':'Active') : ($lang==='fa'?'غیرفعال':'Disabled')) ?></td>
              <td class="muted"><?= e($g['created_at']) ?></td>
              <td style="white-space:nowrap;">
                <a class="btn" href="<?= e(url('admin/groups.php?action=edit&id='.(int)$g['id'])) ?>" data-close-admin-nav="1"><?= ($lang==='fa'?'ویرایش':'Edit') ?></a>
                <a class="btn ghost" href="<?= e(url('admin/groups.php?action=manage&id='.(int)$g['id'])) ?>" data-close-admin-nav="1"><?= ($lang==='fa'?'اعضا':'Members') ?></a>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<?php admin_page_end(); ?>
