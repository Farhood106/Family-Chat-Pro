<?php
require_once __DIR__ . '/app/bootstrap.php';

// If already logged in
if (auth_user()) {
    redirect(url('index.php'));
}

$lang = 'fa';
$dir = lang_dir($lang);

$err = '';
$ok = '';

$selfReg = setting_bool('self_register_enabled', false);

// Maintenance mode: allow only admins to login.
$maintenance = setting_bool('maintenance_mode', false);

if (is_post()) {
    csrf_validate();

    $action = str_param('action', 'login');

    $username = str_param('username');
    $password = str_param('password');

    if ($action === 'register') {
        if ($maintenance) {
            $err = ($lang === 'fa') ? 'سیستم در حالت تعمیرات است.' : 'Maintenance mode.';
        } else {
        if (!$selfReg) {
            $err = ($lang === 'fa') ? 'ثبت‌نام غیرفعال است.' : 'Registration is disabled.';
        } else {
            $display = str_param('display_name');
            $pass2 = str_param('password2');

            $limit = setting_int('register_limit_per_min', 6);
            if (!rate_limit_check(null, 'register', $limit)) {
                $err = ($lang === 'fa') ? 'تعداد تلاش زیاد است. کمی صبر کنید.' : 'Too many attempts. Try again.';
            } elseif ($username === '' || $display === '' || $password === '' || $pass2 === '') {
                $err = ($lang === 'fa') ? 'همه فیلدها الزامی است.' : 'All fields are required.';
            } elseif (!preg_match('/^[a-zA-Z0-9_\.-]{3,50}$/', $username)) {
                $err = ($lang === 'fa') ? 'نام کاربری نامعتبر است.' : 'Invalid username.';
            } elseif (strlen($password) < 8) {
                $err = ($lang === 'fa') ? 'رمز حداقل ۸ کاراکتر.' : 'Password must be at least 8 chars.';
            } elseif ($password !== $pass2) {
                $err = ($lang === 'fa') ? 'تکرار رمز یکسان نیست.' : 'Passwords do not match.';
            } else {
                try {
                    $hash = password_hash($password, PASSWORD_DEFAULT);
                    $ins = db()->prepare('INSERT INTO users (username, display_name, password_hash, role, lang, is_active) VALUES (?,?,?,?,?,1)');
                    $ins->execute([$username, $display, $hash, 'public', $lang]);
                    $newId = (int)db()->lastInsertId();
                    audit_log($newId, 'user.register', 'user', $newId, ['username' => $username]);
                    // Auto login after successful registration
                    $_SESSION['uid'] = $newId;
                    redirect(url('index.php'));
                } catch (Throwable $t) {
                    // Likely duplicate username
                    $err = ($lang === 'fa') ? 'نام کاربری تکراری است.' : 'Username already exists.';
                }
            }
        }}
    } else {
        $limit = setting_int('login_limit_per_min', 8);
        if (!rate_limit_check(null, 'login', $limit)) {
            $err = ($lang === 'fa') ? 'تعداد تلاش زیاد است. کمی صبر کنید.' : 'Too many attempts. Try again.';
        } else {
            if (login_attempt($username, $password)) {
                $u2 = auth_user();
                // Maintenance mode: only admins stay logged in.
                if ($maintenance && $u2 && ($u2['role'] ?? 'user') !== 'admin') {
                    logout();
                    $err = ($lang === 'fa') ? 'سیستم در حالت تعمیرات است.' : 'Maintenance mode.';
                } else {
                    audit_log($u2 ? (int)$u2['id'] : null, 'user.login', 'user', $u2 ? (int)$u2['id'] : null);
                    redirect(url('index.php'));
                }
            } else {
                $err = ($lang === 'fa') ? 'اطلاعات ورود اشتباه است.' : 'Invalid credentials.';
            }
        }
    }
}
?>
<!doctype html>
<html lang="<?= e($lang) ?>" dir="<?= e($dir) ?>">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= e(t('login',$lang)) ?> - Family Chat Pro</title>
  <link rel="stylesheet" href="<?= e(url('assets/app.css')) ?>">
</head>
<body class="auth">
  <div class="auth-card">
    <h1><?= e(t('login',$lang)) ?></h1>
    <p class="muted"><?= $selfReg ? 'ورود یا ثبت‌نام' : 'ورود فقط با یوزرنیم و رمز (ثبت‌نام توسط ادمین)' ?></p>

    <?php if ($err): ?><div class="alert error"><?= e($err) ?></div><?php endif; ?>

    <?php if ($selfReg): ?>
    <div class="auth-tabs" style="display:flex; gap:10px; margin:10px 0 14px;">
      <button type="button" class="btn ghost" id="tabLogin" style="flex:1;"><?= e(t('login',$lang)) ?></button>
      <button type="button" class="btn ghost" id="tabRegister" style="flex:1;">ثبت‌نام</button>
    </div>
    <?php endif; ?>


    <form method="post" id="formLogin">
      <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
      <input type="hidden" name="action" value="login">
      <label><?= e(t('username',$lang)) ?></label>
      <input name="username" autocomplete="username" required>

      <label><?= e(t('password',$lang)) ?></label>
      <input type="password" name="password" autocomplete="current-password" required>

      <button class="btn primary" type="submit"><?= e(t('login',$lang)) ?></button>
      <div class="spacer"></div>
    </form>

    <?php if ($selfReg): ?>
    <form method="post" id="formRegister" style="display:none;">
      <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
      <input type="hidden" name="action" value="register">

      <label><?= e(t('username',$lang)) ?></label>
      <input name="username" autocomplete="username" required>

      <label>نام نمایشی</label>
      <input name="display_name" autocomplete="name" required>

      <label><?= e(t('password',$lang)) ?></label>
      <input type="password" name="password" autocomplete="new-password" required>

      <label>تکرار رمز</label>
      <input type="password" name="password2" autocomplete="new-password" required>

      <button class="btn primary" type="submit">ثبت‌نام</button>
      <div class="spacer"></div>
      <div class="muted">با ثبت‌نام، قوانین خانواده را رعایت کنید 🙂</div>
    </form>
    <?php endif; ?>
  </div>

  <?php if ($selfReg): ?>
  <script>
  (function(){
    const tabLogin = document.getElementById('tabLogin');
    const tabRegister = document.getElementById('tabRegister');
    const f1 = document.getElementById('formLogin');
    const f2 = document.getElementById('formRegister');

    if(!tabLogin || !tabRegister || !f1 || !f2) return;

    function showLogin(){
      f1.style.display='block';
      f2.style.display='none';
      tabLogin.classList.add('primary');
      tabLogin.classList.remove('ghost');
      tabRegister.classList.add('ghost');
      tabRegister.classList.remove('primary');
    }

    function showRegister(){
      f1.style.display='none';
      f2.style.display='block';
      tabRegister.classList.add('primary');
      tabRegister.classList.remove('ghost');
      tabLogin.classList.add('ghost');
      tabLogin.classList.remove('primary');
    }

    tabLogin.addEventListener('click', showLogin);
    tabRegister.addEventListener('click', showRegister);

    // default
    showLogin();
  })();
  </script>
  <?php endif; ?>


</body>
</html>
