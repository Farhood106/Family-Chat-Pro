<?php
require_once __DIR__ . '/../app/bootstrap.php';
require_once __DIR__ . '/../app/settings.php';
require_once __DIR__ . '/_layout.php';

$u = require_admin();
$lang = $u['lang'] ?? 'fa';

$err = '';
$ok  = '';

// -----------------------------------------------------------------------------
// Settings schema
// - Keys can be added without DB migration (stored in app_settings)
// - Avoid duplicate keys: duplicate <input name="..."> breaks POST logic
// -----------------------------------------------------------------------------

$schema = [
    'chat' => [
        [
            'key' => 'messages_per_page', 'type' => 'int', 'min' => 10, 'max' => 200,
            'fa' => 'تعداد پیام در هر صفحه', 'en' => 'Messages per page',
            'hint_fa' => 'برای Load older و سبک ماندن صفحه', 'hint_en' => 'Used for paging and load older',
        ],
        [
            'key' => 'typing_enabled', 'type' => 'bool',
            'fa' => 'نمایش در حال تایپ…', 'en' => 'Typing indicator',
            'hint_fa' => 'Typing در چت خصوصی و گروه', 'hint_en' => 'Typing status in chats',
        ],
        ['key' => 'search_enabled', 'type' => 'bool', 'fa' => 'جستجوی پیام‌ها', 'en' => 'Message search'],
        ['key' => 'delete_enabled', 'type' => 'bool', 'fa' => 'حذف پیام', 'en' => 'Delete messages'],
        ['key' => 'allow_leave_group', 'type' => 'bool', 'fa' => 'اجازه خروج از گروه', 'en' => 'Allow leaving groups'],
        ['key' => 'auto_join_groups', 'type' => 'bool', 'fa' => 'عضویت خودکار در گروه‌ها', 'en' => 'Auto-join groups'],
    ],

    'files' => [
        ['key' => 'files_enabled', 'type' => 'bool', 'fa' => 'ارسال فایل', 'en' => 'File sending'],
        ['key' => 'max_file_mb', 'type' => 'int', 'min' => 1, 'max' => 50, 'fa' => 'حداکثر حجم فایل (MB)', 'en' => 'Max file size (MB)'],
        ['key' => 'avatar_enabled', 'type' => 'bool', 'fa' => 'عکس پروفایل فعال باشد', 'en' => 'Enable profile avatars', 'default' => '1'],
        ['key' => 'avatar_max_mb', 'type' => 'int', 'min' => 1, 'max' => 10, 'fa' => 'حداکثر حجم عکس پروفایل (MB)', 'en' => 'Max avatar size (MB)', 'default' => '2'],
    ],

    'security' => [
        [
            'key' => 'advanced_roles_enabled', 'type' => 'bool', 'default' => '0',
            'fa' => 'نقش‌های پیشرفته (۶ سطح)', 'en' => 'Advanced roles (6 levels)',
            'hint_fa' => 'اگر روشن شود نقش‌ها: super_admin/admin/support/public/hidden1/hidden2 فعال می‌شوند.',
            'hint_en' => 'Enables roles: super_admin/admin/support/public/hidden1/hidden2.',
        ],
        [
            'key' => 'admin_can_see_hidden1', 'type' => 'bool', 'default' => '0',
            'fa' => 'ادمین بتواند مخفی سطح ۱ را ببیند (دوطرفه)', 'en' => 'Admin can see hidden1 (mutual)',
            'hint_fa' => 'اگر روشن باشد: admin و hidden1 همدیگر را می‌بینند و می‌توانند چت کنند. اگر خاموش باشد: هیچکدام همدیگر را نمی‌بینند.',
            'hint_en' => 'When enabled: admin and hidden1 can see and chat with each other. When disabled: they are mutually hidden.',
        ],
        ['key' => 'login_limit_per_min', 'type' => 'int', 'min' => 1, 'max' => 60, 'fa' => 'محدودیت تلاش ورود در دقیقه', 'en' => 'Login attempts per minute'],
        ['key' => 'message_limit_per_min', 'type' => 'int', 'min' => 1, 'max' => 200, 'fa' => 'محدودیت ارسال پیام در دقیقه', 'en' => 'Messages per minute'],
        [
            'key' => 'maintenance_mode', 'type' => 'bool', 'default' => '0',
            'fa' => 'حالت تعمیرات (فقط ادمین)', 'en' => 'Maintenance mode (admins only)',
            'hint_fa' => 'اگر روشن شود کاربران عادی لاگین نمی‌شوند',
            'hint_en' => 'When enabled, only admins can login',
        ],
        ['key' => 'register_limit_per_min', 'type' => 'int', 'min' => 1, 'max' => 60, 'fa' => 'محدودیت ثبت‌نام در دقیقه', 'en' => 'Registrations per minute', 'default' => '6'],
    ],

    'registration' => [
        [
            'key' => 'self_register_enabled', 'type' => 'bool', 'default' => '0',
            'fa' => 'ثبت‌نام در صفحه ورود', 'en' => 'Self registration on login',
            'hint_fa' => 'اگر خاموش باشد گزینه ثبت‌نام در لاگین نمایش داده نمی‌شود',
            'hint_en' => 'When disabled, login page won\'t show register',
        ],

        [
            'key' => 'tickets_enabled', 'type' => 'bool', 'default' => '0',
            'fa' => 'تیکت پشتیبانی (Ticket)', 'en' => 'Support tickets',
            'hint_fa' => 'اگر روشن شود، برای نقش‌های مجاز بخش تیکت فعال می‌شود. پیش‌فرض: فقط مخفی سطح ۱.',
            'hint_en' => 'Enables ticket-style support. Default requester: hidden1 only.',
        ],
        [
            'key' => 'tickets_for_hidden1', 'type' => 'bool', 'default' => '1',
            'fa' => 'تیکت برای مخفی سطح ۱', 'en' => 'Tickets for hidden1',
            'hint_fa' => 'مخفی سطح ۱ بتواند تیکت بسازد/ببیند.',
            'hint_en' => 'Allows hidden1 to create/view tickets.',
        ],
        [
            'key' => 'tickets_allow_requester_close', 'type' => 'bool', 'default' => '1',
            'fa' => 'اجازه بستن تیکت توسط درخواست‌کننده', 'en' => 'Requester can close tickets',
            'hint_fa' => 'کاربر درخواست‌کننده بتواند تیکت را Close کند.',
            'hint_en' => 'Requester can close their ticket.',
        ],

        // Auto-add staff (membership)
        [
            'key' => 'tickets_auto_add_super_admin', 'type' => 'bool', 'default' => '1',
            'fa' => 'افزودن خودکار Super Admin به تیکت', 'en' => 'Auto add Super Admin to tickets',
            'hint_fa' => 'سوپرادمین‌ها به صورت خودکار عضو تیکت شوند.',
            'hint_en' => 'Super admins are auto-added as members.',
        ],
        [
            'key' => 'tickets_auto_add_admin', 'type' => 'bool', 'default' => '1',
            'fa' => 'افزودن خودکار Admin به تیکت', 'en' => 'Auto add Admin to tickets',
            'hint_fa' => 'ادمین‌ها به صورت خودکار عضو تیکت شوند.',
            'hint_en' => 'Admins are auto-added as members.',
        ],
        [
            'key' => 'tickets_auto_add_support', 'type' => 'bool', 'default' => '1',
            'fa' => 'افزودن خودکار Support به تیکت', 'en' => 'Auto add Support to tickets',
            'hint_fa' => 'پشتیبان‌ها به صورت خودکار عضو تیکت شوند.',
            'hint_en' => 'Support users are auto-added as members.',
        ],

        // Auto-assign staff (assigned_to)
        [
            'key' => 'tickets_auto_assign', 'type' => 'bool', 'default' => '1',
            'fa' => 'اختصاص خودکار تیکت', 'en' => 'Auto-assign tickets',
            'hint_fa' => 'اگر روشن باشد، هنگام ساخت تیکت یک نفر به صورت خودکار Assigned می‌شود.',
            'hint_en' => 'When enabled, a staff member is auto-assigned on ticket creation.',
        ],
        [
            'key' => 'tickets_assign_mode', 'type' => 'int', 'default' => '1', 'min' => 0, 'max' => 2,
            'fa' => 'روش اختصاص خودکار (0/1/2)', 'en' => 'Auto-assign mode (0/1/2)',
            'hint_fa' => '0=خاموش  1=کمترین تیکت باز (پیشنهادی)  2=Round-robin',
            'hint_en' => '0=off  1=least open tickets (recommended)  2=round-robin',
        ],
        [
            'key' => 'tickets_assign_super_admin', 'type' => 'bool', 'default' => '1',
            'fa' => 'اختصاص خودکار به Super Admin مجاز باشد', 'en' => 'Allow auto-assign to Super Admin',
            'hint_fa' => 'اگر خاموش باشد، هیچ تیکتی به سوپرادمین Assigned نمی‌شود.',
            'hint_en' => 'When disabled, tickets will not be auto-assigned to super admins.',
        ],
        [
            'key' => 'tickets_assign_admin', 'type' => 'bool', 'default' => '1',
            'fa' => 'اختصاص خودکار به Admin مجاز باشد', 'en' => 'Allow auto-assign to Admin',
            'hint_fa' => 'اگر خاموش باشد، هیچ تیکتی به ادمین Assigned نمی‌شود.',
            'hint_en' => 'When disabled, tickets will not be auto-assigned to admins.',
        ],
        [
            'key' => 'tickets_assign_support', 'type' => 'bool', 'default' => '1',
            'fa' => 'اختصاص خودکار به Support مجاز باشد', 'en' => 'Allow auto-assign to Support',
            'hint_fa' => 'اگر خاموش باشد، هیچ تیکتی به پشتیبان Assigned نمی‌شود.',
            'hint_en' => 'When disabled, tickets will not be auto-assigned to support users.',
        ],
    ],

    'presence' => [
        [
            'key' => 'online_window_sec', 'type' => 'int', 'min' => 20, 'max' => 600, 'default' => '60',
            'fa' => 'بازه آنلاین بودن (ثانیه)', 'en' => 'Online window (seconds)',
            'hint_fa' => 'هرچه کمتر، آنلاین دقیق‌تر ولی حساس‌تر',
            'hint_en' => 'Smaller means stricter online status',
        ],
        ['key' => 'presence_poll_sec', 'type' => 'int', 'min' => 5, 'max' => 60, 'default' => '15', 'fa' => 'بازه آپدیت وضعیت (ثانیه)', 'en' => 'Presence refresh (seconds)'],
    ],

    'time' => [
        [
            'key' => 'tz_offset_minutes', 'type' => 'int', 'min' => -720, 'max' => 840, 'default' => '210',
            'fa' => 'اختلاف ساعت از UTC (دقیقه)', 'en' => 'UTC offset (minutes)',
            'hint_fa' => 'مثال تهران: 210 = +03:30. این مقدار روی نمایش ساعت پیام‌ها و آخرین بازدید اثر دارد.',
            'hint_en' => 'Example Tehran: 210 = +03:30. This affects displayed message time and presence.',
        ],
    ],

    'logs' => [
        ['key' => 'logs_enabled', 'type' => 'bool', 'default' => '1', 'fa' => 'ثبت لاگ سیستم', 'en' => 'Enable audit logs'],
        [
            'key' => 'logs_retention_days', 'type' => 'int', 'min' => 0, 'max' => 3650, 'default' => '0',
            'fa' => 'نگهداری لاگ (روز)', 'en' => 'Log retention (days)',
            'hint_fa' => '۰ یعنی نگهداری نامحدود. اگر عدد بگذارید لاگ‌های قدیمی قابل پاکسازی می‌شوند.',
            'hint_en' => '0 means keep forever. Otherwise you can purge old logs.',
        ],
    ],
];

function label(string $k, string $lang, array $row): string
{
    return $lang === 'fa' ? ($row['fa'] ?? $k) : ($row['en'] ?? $k);
}

function hint(string $lang, array $row): string
{
    if ($lang === 'fa') {
        return (string)($row['hint_fa'] ?? '');
    }
    return (string)($row['hint_en'] ?? '');
}

// Ensure defaults exist
foreach ($schema as $section) {
    foreach ($section as $f) {
        $def = $f['default'] ?? null;
        if ($def !== null && setting_get($f['key']) === null) {
            setting_set($f['key'], (string)$def);
        }
    }
}

if (is_post()) {
    csrf_validate();
    try {
        foreach ($schema as $section) {
            foreach ($section as $f) {
                $k = $f['key'];

                if ($f['type'] === 'bool') {
                    $v = isset($_POST[$k]) ? '1' : '0';
                    setting_set($k, $v);
                    continue;
                }

                if ($f['type'] === 'int') {
                    $v = (int)($_POST[$k] ?? (int)setting_get($k, '0'));
                    $min = (int)($f['min'] ?? -2147483648);
                    $max = (int)($f['max'] ?? 2147483647);
                    if ($v < $min) $v = $min;
                    if ($v > $max) $v = $max;
                    setting_set($k, (string)$v);
                }
            }
        }

        $ok = ($lang === 'fa') ? 'تنظیمات ذخیره شد.' : 'Settings saved.';
    } catch (Throwable $t) {
        $err = 'Error: ' . $t->getMessage();
    }
}

admin_page_start(($lang === 'fa' ? 'تنظیمات سیستم' : 'System settings'), $u, 'settings');
?>

<style>
/* --- admin/settings.php mobile polish --- */
.admin-settings-wrap .sec-head{
  display:flex; align-items:center; justify-content:space-between; gap:10px; flex-wrap:wrap;
  margin-top:10px;
}
.admin-settings-wrap .sec-title{margin:0; font-size:14px;}
.admin-settings-wrap .sec-sub{font-size:12px; opacity:.65;}
.admin-settings-wrap .setting-card{
  padding:12px;
  border-radius:14px;
}
.admin-settings-wrap .setting-row{
  display:flex;
  align-items:flex-start;
  justify-content:space-between;
  gap:14px;
  flex-wrap:wrap;
}
.admin-settings-wrap .setting-meta{min-width:220px; flex:1;}
.admin-settings-wrap .setting-meta .label{font-weight:900;}
.admin-settings-wrap .setting-meta .hint{opacity:.75; font-size:12px; line-height:1.8; margin-top:6px;}
.admin-settings-wrap .setting-control{flex-shrink:0; display:flex; align-items:center; gap:10px;}
.admin-settings-wrap input[type="number"],
.admin-settings-wrap input[type="search"],
.admin-settings-wrap input[type="text"],
.admin-settings-wrap select{
  width:100%;
  max-width:260px;
}
.admin-settings-wrap .num-wrap{
  display:flex; align-items:center; gap:10px; justify-content:flex-end;
}
.admin-settings-wrap .num-wrap input[type="number"]{max-width:140px;}
.admin-settings-wrap .savebar{
  margin-top:14px;
  display:flex;
  gap:10px;
  align-items:center;
  justify-content:flex-start;
}

/* Mobile: stack everything, full-width controls, sticky save button */
@media (max-width: 980px){
  .admin-settings-wrap .setting-row{flex-direction:column; align-items:stretch;}
  .admin-settings-wrap .setting-control{justify-content:flex-start; width:100%;}
  .admin-settings-wrap input[type="number"],
  .admin-settings-wrap select{max-width:none;}

  .admin-settings-wrap .num-wrap{width:100%; justify-content:flex-start;}
  .admin-settings-wrap .num-wrap input[type="number"]{max-width:none;}

  /* Make right column go under (tips below) */
  .admin-col-8, .admin-col-4 { width:100% !important; }

  /* Sticky save button */
  .admin-settings-wrap .savebar{
    position: sticky;
    bottom: 10px;
    z-index: 10;
    padding: 10px;
    border-radius: 14px;
    background: rgba(15, 23, 42, .92);
    border: 1px solid rgba(255,255,255,.10);
    backdrop-filter: blur(10px);
  }
  .admin-settings-wrap .savebar .btn{width:100%;}
}
</style>

<div class="admin-grid admin-settings-wrap">
  <div class="admin-col-8">
    <div class="admin-card">
      <h2 style="margin:0 0 10px; font-size:16px;">
        <?= ($lang==='fa'?'تنظیمات اصلی':'Main settings') ?>
      </h2>

      <?php if ($err): ?><div class="alert error"><?= e($err) ?></div><?php endif; ?>
      <?php if ($ok): ?><div class="alert ok"><?= e($ok) ?></div><?php endif; ?>

      <form method="post" class="form" style="gap:14px;">
        <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">

        <?php foreach ($schema as $sectionKey => $fields): ?>
          <div class="spacer"></div>

          <div class="sec-head">
            <h3 class="sec-title">
              <?php
                $secTitle = [
                  'chat' => ['fa'=>'چت', 'en'=>'Chat'],
                  'files' => ['fa'=>'فایل و عکس', 'en'=>'Files & images'],
                  'security' => ['fa'=>'امنیت', 'en'=>'Security'],
                  'presence' => ['fa'=>'آنلاین و آخرین بازدید', 'en'=>'Presence'],
                  'time' => ['fa'=>'زمان و منطقه زمانی', 'en'=>'Time & timezone'],
                  'registration' => ['fa'=>'ثبت‌نام', 'en'=>'Registration'],
                  'logs' => ['fa'=>'لاگ‌ها', 'en'=>'Logs'],
                ];
                echo e($lang==='fa' ? ($secTitle[$sectionKey]['fa'] ?? $sectionKey) : ($secTitle[$sectionKey]['en'] ?? $sectionKey));
              ?>
            </h3>
            <div class="sec-sub">
              <?= ($lang==='fa'?'این بخش را با دقت تغییر دهید':'Change carefully') ?>
            </div>
          </div>

          <div style="display:flex; flex-direction:column; gap:12px;">
            <?php foreach ($fields as $f): $k=$f['key']; ?>
              <div class="admin-card setting-card">
                <div class="setting-row">
                  <div class="setting-meta">
                    <div class="label"><?= e(label($k,$lang,$f)) ?></div>
                    <?php $h = hint($lang, $f); if ($h !== ''): ?>
                      <div class="hint"><?= e($h) ?></div>
                    <?php endif; ?>
                  </div>

                  <div class="setting-control">
                    <?php if ($f['type'] === 'bool'): ?>
                      <label class="switch" style="display:flex; align-items:center; gap:10px;">
                        <input type="checkbox" name="<?= e($k) ?>" value="1" <?= (setting_bool($k, (($f['default'] ?? '0')==='1')) ? 'checked' : '') ?>>
                        <span class="hint" style="margin:0;">
                          <?= setting_bool($k, false) ? ($lang==='fa'?'روشن':'On') : ($lang==='fa'?'خاموش':'Off') ?>
                        </span>
                      </label>
                    <?php else: ?>
                      <div class="num-wrap">
                        <input type="number"
                               name="<?= e($k) ?>"
                               value="<?= (int)setting_int($k, (int)($f['default'] ?? 0)) ?>"
                               min="<?= (int)($f['min'] ?? 0) ?>"
                               max="<?= (int)($f['max'] ?? 999999) ?>">
                        <div class="hint" style="margin:0;"><?= ($lang==='fa'?'عدد':'Number') ?></div>
                      </div>
                    <?php endif; ?>
                  </div>
                </div>
              </div>
            <?php endforeach; ?>
          </div>
        <?php endforeach; ?>

        <div class="spacer"></div>
        <div class="savebar">
          <button class="btn primary" type="submit"><?= ($lang==='fa'?'ذخیره تنظیمات':'Save settings') ?></button>
        </div>
      </form>
    </div>
  </div>

  <div class="admin-col-4">
    <div class="admin-card">
      <h2 style="margin:0 0 10px; font-size:16px;">
        <?= ($lang==='fa'?'راهنما':'Tips') ?>
      </h2>
      <div style="font-size:12px; opacity:.75; line-height:1.9;">
        <?php if ($lang==='fa'): ?>
          <ul style="margin:0; padding-inline-start:18px;">
            <li>برای هاست اشتراکی، آنلاین بودن با آخرین بازدید در بازه مشخص محاسبه می‌شود.</li>
            <li>اگر کاربران پیام زیاد می‌فرستند، محدودیت پیام در دقیقه را کم کنید.</li>
            <li>در حالت تعمیرات، فقط ادمین‌ها می‌توانند وارد شوند.</li>
          </ul>
        <?php else: ?>
          <ul style="margin:0; padding-inline-start:18px;">
            <li>On shared hosting, online status is derived from last seen within a window.</li>
            <li>If users spam, lower the messages-per-minute limit.</li>
            <li>Maintenance mode allows only admins to login.</li>
          </ul>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>

<?php admin_page_end(); ?>
