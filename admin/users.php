<?php
require_once __DIR__ . '/../app/bootstrap.php';
require_once __DIR__ . '/_layout.php';

$u = require_admin();
$lang = $u['lang'] ?? 'fa';

$actorRole = (string)($u['role'] ?? 'user');
$assignable = assignable_roles_for($actorRole);

// Shared role labels for UI.
$roleLabels = [
  'super_admin' => ['fa'=>'سوپر ادمین','en'=>'Super admin'],
  'admin' => ['fa'=>'ادمین','en'=>'Admin'],
  'support' => ['fa'=>'پشتیبان','en'=>'Support'],
  'public' => ['fa'=>'کاربر عمومی','en'=>'Public user'],
  'hidden1' => ['fa'=>'کاربر مخفی سطح ۱','en'=>'Hidden level 1'],
  'hidden2' => ['fa'=>'کاربر مخفی سطح ۲','en'=>'Hidden level 2'],
];

$err = '';
$ok = '';
$action = str_param('action');
$edit_id = int_param('id');

// Handle create / update / reset / delete
if (is_post()) {
    csrf_validate();

    $action = str_param('action');

    if ($action === 'create') {
        $username = str_param('username');
        $display = str_param('display_name');
        $pass = str_param('password');
        $role = str_param('role', 'public');
        $lang2 = str_param('lang', 'fa');

        if ($username === '' || $display === '' || $pass === '') {
            $err = ($lang === 'fa') ? 'همه فیلدها الزامی است.' : 'All fields required.';
        } elseif (!preg_match('/^[a-zA-Z0-9_\.-]{3,50}$/', $username)) {
            $err = ($lang === 'fa') ? 'نام کاربری نامعتبر است.' : 'Invalid username.';
        } elseif (strlen($pass) < 8) {
            $err = ($lang === 'fa') ? 'رمز حداقل ۸ کاراکتر.' : 'Password min 8.';
        } else {
            $hash = password_hash($pass, PASSWORD_DEFAULT);
            try {
                // Enforce assignable roles; fallback to public.
                $roleEff = role_effective($role);
                if (!in_array($roleEff, $assignable, true)) {
                    $roleEff = 'public';
                }
                $ins = db()->prepare('INSERT INTO users (username, display_name, password_hash, role, lang, is_active) VALUES (?,?,?,?,?,1)');
                $ins->execute([
                    $username,
                    $display,
                    $hash,
                    $roleEff,
                    ($lang2 === 'en' ? 'en' : 'fa'),
                ]);
                $newId = (int)db()->lastInsertId();
                audit_log((int)$u['id'], 'admin.user.create', 'user', $newId, ['username' => $username]);
                $ok = ($lang === 'fa') ? 'کاربر ساخته شد.' : 'User created.';
            } catch (Throwable $t) {
                $err = 'Error: ' . $t->getMessage();
            }
        }
    } elseif ($action === 'update') {
        $id = int_param('id');
        $display = str_param('display_name');
        $role = str_param('role', 'public');
        $lang2 = str_param('lang', 'fa');
        $active = str_param('is_active', '1') === '1' ? 1 : 0;

        if ($id <= 0 || $display === '') {
            $err = ($lang === 'fa') ? 'ورودی نامعتبر.' : 'Invalid input.';
        } else {
            $roleEff = role_effective($role);
            // Non-super admins cannot set super_admin/hidden2.
            if (!in_array($roleEff, $assignable, true)) {
                // If not assignable, keep existing role.
                $cur = db()->prepare('SELECT role FROM users WHERE id=?');
                $cur->execute([$id]);
                $curRole = (string)($cur->fetchColumn() ?: 'public');
                $roleEff = $curRole;
            }
            // Prevent admin from self-promoting to super_admin.
            if ($id === (int)$u['id'] && !is_super_admin($u) && $roleEff === 'super_admin') {
                $roleEff = 'admin';
            }

            $upd = db()->prepare('UPDATE users SET display_name=?, role=?, lang=?, is_active=? WHERE id=?');
            $upd->execute([$display, $roleEff, ($lang2 === 'en' ? 'en' : 'fa'), $active, $id]);
            audit_log((int)$u['id'], 'admin.user.update', 'user', $id, ['display_name' => $display, 'role' => $role, 'lang' => $lang2, 'is_active' => $active]);
            $ok = ($lang === 'fa') ? 'ویرایش شد.' : 'Updated.';
        }
    } elseif ($action === 'reset') {
        $id = int_param('id');
        $newpass = str_param('newpass');
        if ($id <= 0 || strlen($newpass) < 8) {
            $err = ($lang === 'fa') ? 'رمز حداقل ۸ کاراکتر.' : 'Password min 8.';
        } else {
            $hash = password_hash($newpass, PASSWORD_DEFAULT);
            $upd = db()->prepare('UPDATE users SET password_hash=? WHERE id=?');
            $upd->execute([$hash, $id]);
            audit_log((int)$u['id'], 'admin.user.reset_password', 'user', $id);
            $ok = ($lang === 'fa') ? 'رمز ریست شد.' : 'Password reset.';
        }
    } elseif ($action === 'delete') {
        $id = int_param('id');
        if ($id === (int)$u['id']) {
            $err = ($lang === 'fa') ? 'حذف خود ادمین مجاز نیست.' : 'Cannot delete yourself.';
        } elseif ($id > 0) {
            $del = db()->prepare('DELETE FROM users WHERE id=?');
            $del->execute([$id]);
            audit_log((int)$u['id'], 'admin.user.delete', 'user', $id);
            $ok = ($lang === 'fa') ? 'کاربر حذف شد.' : 'User deleted.';
        }
    }
}

// Filters
$q = trim(str_param('q'));
$roleF = str_param('role');
$activeF = str_param('active');

$where = [];
$args = [];

if ($q !== '') {
    $where[] = '(username LIKE ? OR display_name LIKE ?)';
    $args[] = '%' . $q . '%';
    $args[] = '%' . $q . '%';
}
if ($roleF !== '') {
    $roleEffF = role_effective($roleF);
    $allowedFilter = ['super_admin','admin','support','public','hidden1','hidden2','user'];
    if (in_array($roleF, $allowedFilter, true)) {
        $where[] = 'role=?';
        $args[] = ($roleEffF === 'public' && $roleF === 'user') ? 'public' : $roleEffF;
    }
}

// Role-aware listing: only super_admin can see hidden2.
if (!is_super_admin($u)) {
    $where[] = "role <> 'hidden2'";
}
if ($activeF === '1' || $activeF === '0') {
    $where[] = 'is_active=?';
    $args[] = (int)$activeF;
}

$sql = 'SELECT id, username, display_name, role, lang, is_active, created_at, last_seen_at FROM users';
if ($where) $sql .= ' WHERE ' . implode(' AND ', $where);
$roleEffActor = role_effective((string)($u['role'] ?? 'user'));
if ($roleEffActor !== 'super_admin') {
    // hidden2 must be invisible to everyone except super_admin.
    $sql .= ($where ? ' AND' : ' WHERE') . ' role<>' . "'hidden2'";
}
$sql .= ' ORDER BY role DESC, display_name';

$st = db()->prepare($sql);
$st->execute($args);
$list = $st->fetchAll();

$editUser = null;
if ($action === 'edit' && $edit_id > 0) {
    $st2 = db()->prepare('SELECT id, username, display_name, role, lang, is_active FROM users WHERE id=?');
    $st2->execute([$edit_id]);
    $editUser = $st2->fetch();
}

admin_page_start(($lang === 'fa' ? 'مدیریت کاربران' : 'Users'), $u, 'users');
?>

<div class="admin-grid">
  <div class="admin-col-6">
    <div class="admin-card">
      <h2 style="margin:0 0 12px; font-size:16px;"><?= ($lang==='fa'?'ساخت کاربر جدید':'Create user') ?></h2>

      <?php if ($err): ?><div class="alert error"><?= e($err) ?></div><?php endif; ?>
      <?php if ($ok): ?><div class="alert ok"><?= e($ok) ?></div><?php endif; ?>

      <form method="post" class="form">
        <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="action" value="create">

        <div class="grid2">
          <div>
            <label><?= ($lang==='fa'?'نام کاربری':'Username') ?></label>
            <input name="username" required>
          </div>
          <div>
            <label><?= ($lang==='fa'?'نام نمایشی':'Display name') ?></label>
            <input name="display_name" required>
          </div>
        </div>

        <div class="grid2">
          <div>
            <label><?= ($lang==='fa'?'رمز اولیه':'Initial password') ?></label>
            <input type="password" name="password" required>
          </div>
          <div>
            <label><?= ($lang==='fa'?'نقش':'Role') ?></label>
            <select name="role">
              <?php
                foreach ($assignable as $r):
                  $lbl = $lang==='fa' ? ($roleLabels[$r]['fa'] ?? $r) : ($roleLabels[$r]['en'] ?? $r);
                  echo '<option value="'.e($r).'">'.e($lbl).'</option>';
                endforeach;
              ?>
            </select>
          </div>
        </div>

        <div class="grid2">
          <div>
            <label>Lang</label>
            <select name="lang">
              <option value="fa">fa</option>
              <option value="en">en</option>
            </select>
          </div>
          <div style="display:flex; align-items:flex-end;">
            <button class="btn primary" type="submit" style="width:100%;"><?= ($lang==='fa'?'ساخت کاربر':'Create user') ?></button>
          </div>
        </div>

        <div style="margin-top:8px; font-size:12px; opacity:.7; line-height:1.7;">
          <?= ($lang==='fa'?'نام کاربری فقط حروف/عدد/._- و حداقل ۳ کاراکتر. رمز حداقل ۸ کاراکتر.':'Username: letters/numbers/._- (min 3). Password min 8.') ?>
        </div>
      </form>
    </div>

    <?php if ($editUser): ?>
      <div class="spacer"></div>
      <div class="admin-card">
        <h2 style="margin:0 0 12px; font-size:16px;">
          <?= ($lang==='fa'?'ویرایش کاربر':'Edit user') ?>: <?= e($editUser['username']) ?>
        </h2>

        <form method="post" class="form">
          <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
          <input type="hidden" name="action" value="update">
          <input type="hidden" name="id" value="<?= (int)$editUser['id'] ?>">

          <label><?= ($lang==='fa'?'نام نمایشی':'Display name') ?></label>
          <input name="display_name" value="<?= e($editUser['display_name']) ?>" required>

          <div class="grid2">
            <div>
              <label><?= ($lang==='fa'?'نقش':'Role') ?></label>
              <select name="role">
                <?php
                  $curRole = role_effective((string)$editUser['role']);
                  $opts = array_values(array_unique(array_merge([$curRole], $assignable)));
                  foreach ($opts as $r):
                    // Only super_admin can even see hidden2.
                    if ($r === 'hidden2' && !is_super_admin($u)) continue;
                    $lbl = $lang==='fa' ? ($roleLabels[$r]['fa'] ?? $r) : ($roleLabels[$r]['en'] ?? $r);
                    $sel = ($curRole === $r) ? 'selected' : '';
                    echo '<option value="'.e($r).'" '.$sel.'>'.e($lbl).'</option>';
                  endforeach;
                ?>
              </select>
            </div>
            <div>
              <label>Lang</label>
              <select name="lang">
                <option value="fa" <?= ($editUser['lang']==='fa'?'selected':'') ?>>fa</option>
                <option value="en" <?= ($editUser['lang']==='en'?'selected':'') ?>>en</option>
              </select>
            </div>
          </div>

          <label><?= ($lang==='fa'?'فعال':'Active') ?></label>
          <select name="is_active">
            <option value="1" <?= ((int)$editUser['is_active']===1?'selected':'') ?>>Yes</option>
            <option value="0" <?= ((int)$editUser['is_active']===0?'selected':'') ?>>No</option>
          </select>

          <button class="btn primary" type="submit"><?= ($lang==='fa'?'ذخیره تغییرات':'Save changes') ?></button>
        </form>

        <div class="spacer"></div>
        <h3 style="margin:0 0 8px; font-size:14px;"><?= ($lang==='fa'?'ریست رمز':'Reset password') ?></h3>
        <form method="post" class="form">
          <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
          <input type="hidden" name="action" value="reset">
          <input type="hidden" name="id" value="<?= (int)$editUser['id'] ?>">
          <div class="grid2">
            <div>
              <label><?= ($lang==='fa'?'رمز جدید':'New password') ?></label>
              <input type="password" name="newpass" required>
            </div>
            <div style="display:flex; align-items:flex-end;">
              <button class="btn" type="submit" style="width:100%;"><?= ($lang==='fa'?'ریست رمز':'Reset') ?></button>
            </div>
          </div>
        </form>

        <div class="spacer"></div>
        <form method="post" onsubmit="return confirm('Delete user?');">
          <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
          <input type="hidden" name="action" value="delete">
          <input type="hidden" name="id" value="<?= (int)$editUser['id'] ?>">
          <button class="btn danger" type="submit"><?= ($lang==='fa'?'حذف کاربر':'Delete user') ?></button>
        </form>
      </div>
    <?php endif; ?>
  </div>

  <div class="admin-col-6">
    <div class="admin-card">
      <div style="display:flex; align-items:center; gap:10px;">
        <h2 style="margin:0; font-size:16px;"><?= ($lang==='fa'?'لیست کاربران':'Users list') ?></h2>
        <div class="grow"></div>
      </div>

      <form method="get" style="margin-top:12px;" class="form">
        <div class="grid2">
          <div>
            <label><?= ($lang==='fa'?'جستجو':'Search') ?></label>
            <input name="q" value="<?= e($q) ?>" placeholder="<?= ($lang==='fa'?'نام/یوزرنیم...':'name/username...') ?>">
          </div>
          <div>
            <label><?= ($lang==='fa'?'نقش':'Role') ?></label>
            <select name="role">
              <option value=""><?= ($lang==='fa'?'همه':'All') ?></option>
              <option value="admin" <?= ($roleF==='admin'?'selected':'') ?>>Admin</option>
              <option value="user" <?= ($roleF==='user'?'selected':'') ?>>User</option>
            </select>
          </div>
        </div>
        <div class="grid2">
          <div>
            <label><?= ($lang==='fa'?'وضعیت':'Status') ?></label>
            <select name="active">
              <option value=""><?= ($lang==='fa'?'همه':'All') ?></option>
              <option value="1" <?= ($activeF==='1'?'selected':'') ?>>Active</option>
              <option value="0" <?= ($activeF==='0'?'selected':'') ?>>Inactive</option>
            </select>
          </div>
          <div style="display:flex; align-items:flex-end; gap:10px;">
            <button class="btn" type="submit" style="flex:1;"><?= ($lang==='fa'?'اعمال':'Apply') ?></button>
            <a class="btn ghost" href="<?= e(url('admin/users.php')) ?>" style="flex:1;"><?= ($lang==='fa'?'پاک کردن':'Reset') ?></a>
          </div>
        </div>
      </form>

      <div class="spacer"></div>
      <table class="admin-table">
        <thead>
          <tr>
            <th>ID</th>
            <th><?= ($lang==='fa'?'کاربر':'User') ?></th>
            <th><?= ($lang==='fa'?'نقش':'Role') ?></th>
            <th><?= ($lang==='fa'?'فعال':'Active') ?></th>
            <th><?= ($lang==='fa'?'آخرین بازدید':'Last seen') ?></th>
            <th></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($list as $row): ?>
            <tr>
              <td><?= (int)$row['id'] ?></td>
              <td>
                <div style="font-weight:800;"><?= e($row['display_name']) ?></div>
                <div style="font-size:12px; opacity:.7;">@<?= e($row['username']) ?></div>
              </td>
              <td><?= e($row['role']) ?></td>
              <td><?= ((int)$row['is_active']===1?'Yes':'No') ?></td>
              <td style="font-size:12px; opacity:.7; white-space:nowrap;">
                <?= e($row['last_seen_at'] ?? '') ?>
              </td>
              <td>
                <a class="btn" href="<?= e(url('admin/users.php?action=edit&id='.(int)$row['id'])) ?>"><?= ($lang==='fa'?'مدیریت':'Manage') ?></a>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>

      <?php if (!$list): ?>
        <div style="padding:10px; opacity:.7;"><?= ($lang==='fa'?'موردی یافت نشد.':'No results.') ?></div>
      <?php endif; ?>
    </div>
  </div>
</div>

<?php admin_page_end(); ?>
