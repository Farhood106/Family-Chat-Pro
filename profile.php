<?php
require_once __DIR__ . '/app/bootstrap.php';
require_once __DIR__ . '/app/view.php';

$u = require_login();
$lang = $u['lang'] ?? 'fa';


$err = '';
$ok = '';

// Handle avatar upload (optional)
if (is_post() && str_param('action') === 'avatar') {
    csrf_validate();

    if (empty($_FILES['avatar']) || !is_uploaded_file($_FILES['avatar']['tmp_name'])) {
        $err = ($lang==='fa') ? 'یک فایل تصویر انتخاب کنید.' : 'Please choose an image file.';
    } else {
        $f = $_FILES['avatar'];
        $maxMb = (int)setting_int('max_file_mb', 8);
        $maxBytes = max(1, $maxMb) * 1024 * 1024;

        if (($f['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
            $err = ($lang==='fa') ? 'خطا در آپلود فایل.' : 'Upload error.';
        } elseif (($f['size'] ?? 0) > $maxBytes) {
            $err = ($lang==='fa') ? 'حجم تصویر زیاد است.' : 'Image is too large.';
        } else {
            $cfg = require __DIR__ . '/app/config.php';
            $dir = (string)($cfg['avatar_dir'] ?? (__DIR__ . '/storage/avatars'));
            if (!is_dir($dir)) @mkdir($dir, 0775, true);

            $tmp = (string)$f['tmp_name'];
            $mime = (string)(mime_content_type($tmp) ?: '');
            $allowed = ['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp'];
            if (!isset($allowed[$mime])) {
                $err = ($lang==='fa') ? 'فرمت تصویر مجاز نیست (jpg/png/webp).' : 'Invalid image type (jpg/png/webp).';
            } else {
                // Extra safety: verify it's an actual image
                $imgInfo = @getimagesize($tmp);
                if ($imgInfo === false) {
                    $err = ($lang==='fa') ? 'فایل تصویر معتبر نیست.' : 'Invalid image file.';
                } else {
                    $ext = $allowed[$mime];
                    $name = 'u' . (int)$u['id'] . '_' . time() . '.' . $ext;
                    $dest = rtrim($dir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $name;
                    if (!@move_uploaded_file($tmp, $dest)) {
                        $err = ($lang==='fa') ? 'ذخیره تصویر ناموفق بود.' : 'Failed to save image.';
                    } else {
                        // Store as relative path
                        $rel = 'storage/avatars/' . $name;

                        // Remove old avatar file (best-effort)
                        $old = db()->prepare('SELECT avatar_path FROM users WHERE id=? LIMIT 1');
                        $old->execute([$u['id']]);
                        $oldRow = $old->fetch();
                        if (!empty($oldRow['avatar_path'])) {
                            $oldPath = __DIR__ . '/' . ltrim((string)$oldRow['avatar_path'], '/');
                            if (is_file($oldPath)) @unlink($oldPath);
                        }

                        $upd = db()->prepare('UPDATE users SET avatar_path=? WHERE id=?');
                        $upd->execute([$rel, $u['id']]);
                        audit_log((int)$u['id'], 'user.avatar.update', 'user', (int)$u['id']);

                        // Refresh user data in session for UI
                        $u = auth_user() ?? $u;
                        $ok = ($lang==='fa') ? 'عکس پروفایل به‌روزرسانی شد.' : 'Avatar updated.';
                    }
                }
            }
        }
    }
}

if (is_post() && str_param('action') === 'password') {
    csrf_validate();

    $current = str_param('current');
    $pass1 = str_param('pass1');
    $pass2 = str_param('pass2');

    if ($pass1 === '' || $pass2 === '' || $current === '') {
        $err = ($lang === 'fa') ? 'همه فیلدها الزامی است.' : 'All fields are required.';
    } elseif ($pass1 !== $pass2) {
        $err = ($lang === 'fa') ? 'رمز جدید و تکرار یکی نیست.' : 'Passwords do not match.';
    } elseif (strlen($pass1) < 8) {
        $err = ($lang === 'fa') ? 'رمز حداقل ۸ کاراکتر.' : 'Password must be at least 8 chars.';
    } else {
        $stmt = db()->prepare('SELECT password_hash FROM users WHERE id=?');
        $stmt->execute([$u['id']]);
        $row = $stmt->fetch();
        if (!$row || !password_verify($current, $row['password_hash'])) {
            $err = ($lang === 'fa') ? 'رمز فعلی اشتباه است.' : 'Current password is wrong.';
        } else {
            $hash = password_hash($pass1, PASSWORD_DEFAULT);
            $upd = db()->prepare('UPDATE users SET password_hash=? WHERE id=?');
            $upd->execute([$hash, $u['id']]);
            audit_log((int)$u['id'], 'user.password.change', 'user', (int)$u['id']);
            $ok = ($lang === 'fa') ? 'رمز با موفقیت تغییر کرد.' : 'Password updated.';
        }
    }
}

render_header(t('profile',$lang), $u);
?>
<div class="page">
  <div class="page-card">
    <div class="page-head">
      <a class="btn ghost" href="<?= e(url('index.php')) ?>">←</a>
      <h1><?= e(t('change_password',$lang)) ?></h1>
    </div>

    <?php if ($err): ?><div class="alert error"><?= e($err) ?></div><?php endif; ?>
    <?php if ($ok): ?><div class="alert ok"><?= e($ok) ?></div><?php endif; ?>

    <div class="split">
      <div class="split-col">
        <h2 class="h2"><?= ($lang==='fa'?'عکس پروفایل':'Profile photo') ?></h2>
        <div class="profile-avatar">
          <div class="avatar xl">
            <?php if (!empty($u['avatar_path'])): ?>
              <img src="<?= e(avatar_url($u['avatar_path'])) ?>" alt="" loading="lazy">
            <?php else: ?>
              <?= e(mb_strtoupper(mb_substr((string)$u['display_name'],0,1))) ?>
            <?php endif; ?>
          </div>
        </div>
        <form method="post" class="form" enctype="multipart/form-data">
          <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
          <input type="hidden" name="action" value="avatar">
          <label><?= ($lang==='fa'?'انتخاب تصویر (jpg/png/webp)':'Choose an image (jpg/png/webp)') ?></label>
          <input type="file" name="avatar" accept="image/jpeg,image/png,image/webp" required>
          <button class="btn primary" type="submit"><?= ($lang==='fa'?'آپلود':'Upload') ?></button>
          <div class="muted" style="margin-top:6px">
            <?= ($lang==='fa'?'حجم طبق تنظیمات ادمین محدود می‌شود.':'Size is limited by admin settings.') ?>
          </div>
        </form>
      </div>

      <div class="split-col">
        <h2 class="h2"><?= e(t('change_password',$lang)) ?></h2>
        <form method="post" class="form">
      <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
      <input type="hidden" name="action" value="password">

      <label><?= ($lang==='fa'?'رمز فعلی':'Current password') ?></label>
      <input type="password" name="current" required>

      <label><?= ($lang==='fa'?'رمز جدید':'New password') ?></label>
      <input type="password" name="pass1" required>

      <label><?= ($lang==='fa'?'تکرار رمز جدید':'Repeat new password') ?></label>
      <input type="password" name="pass2" required>

      <button class="btn primary" type="submit"><?= ($lang==='fa'?'ثبت':'Save') ?></button>
        </form>
      </div>
    </div>
  </div>
</div>
<?php render_footer(); ?>
