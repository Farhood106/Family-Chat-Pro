<?php
// admin/_layout.php
// Shared admin layout (sidebar + topbar). Keeps admin pages consistent and responsive.

declare(strict_types=1);

require_once __DIR__ . '/../app/view.php';

/**
 * Render the start of an admin page, including a responsive sidebar navigation.
 */
function admin_page_start(string $title, array $user, string $active = 'dashboard'): void
{
    $lang = $user['lang'] ?? 'fa';
    $extraHead = "\n<link rel=\"stylesheet\" href=\"" . e(url('assets/app.css')) . "\">\n";
    // header already loads app.css; keep extra head empty.
    render_header($title, $user);

    $items = [
        ['k' => 'dashboard', 'href' => url('admin/dashboard.php'), 'fa' => 'داشبورد', 'en' => 'Dashboard', 'icon' => '📊'],
        ['k' => 'users', 'href' => url('admin/users.php'), 'fa' => 'کاربران', 'en' => 'Users', 'icon' => '👤'],
        ['k' => 'groups', 'href' => url('admin/groups.php'), 'fa' => 'گروه‌ها', 'en' => 'Groups', 'icon' => '👥'],
        ['k' => 'logs', 'href' => url('admin/logs.php'), 'fa' => 'لاگ‌ها', 'en' => 'Logs', 'icon' => '🧾'],
        ['k' => 'settings', 'href' => url('admin/settings.php'), 'fa' => 'تنظیمات', 'en' => 'Settings', 'icon' => '⚙️'],
    ];

    echo '<div class="admin-shell">';

    // Mobile topbar
    echo '<header class="admin-topbar">';
    echo '  <button class="icon-btn" id="adminNavOpen" type="button" aria-label="Menu">&#9776;</button>';
    echo '  <div class="admin-topbar-title">' . e($lang === 'fa' ? 'مدیریت' : 'Admin') . '</div>';
    echo '  <div class="grow"></div>';
    echo '  <a class="btn ghost" href="' . e(url('index.php')) . '">' . e($lang === 'fa' ? 'بازگشت' : 'Back') . '</a>';
    echo '</header>';

    echo '<div class="drawer-backdrop" id="adminNavBackdrop"></div>';

    // Sidebar
    echo '<aside class="admin-nav" id="adminNav">';
    echo '  <div class="admin-brand">';
    echo '    <div class="admin-brand-badge">💬</div>';
    echo '    <div>'; 
    echo '      <div class="admin-brand-title">' . e($lang === 'fa' ? 'Family Chat' : 'Family Chat') . '</div>';
    echo '      <div class="admin-brand-sub">' . e($lang === 'fa' ? 'پنل مدیریت' : 'Admin Panel') . '</div>';
    echo '    </div>';
    echo '  </div>';
    echo '  <nav class="admin-nav-items">';
    foreach ($items as $it) {
        $isActive = $it['k'] === $active;
        $label = $lang === 'fa' ? $it['fa'] : $it['en'];
        echo '    <a class="admin-nav-item' . ($isActive ? ' active' : '') . '" href="' . e($it['href']) . '">';
        echo '      <span class="admin-nav-ico">' . e($it['icon']) . '</span>';
        echo '      <span>' . e($label) . '</span>';
        echo '    </a>';
    }
    echo '  </nav>';

    echo '  <div class="admin-nav-foot">';
    echo '    <div class="admin-user">';
    echo '      <div class="admin-user-name">' . e($user['display_name'] ?? $user['username'] ?? '') . '</div>';
    echo '      <div class="admin-user-meta">' . e($lang === 'fa' ? 'ادمین' : 'Admin') . '</div>';
    echo '    </div>';
    echo '    <a class="btn danger w100" href="' . e(url('logout.php')) . '">' . e($lang === 'fa' ? 'خروج' : 'Logout') . '</a>';
    echo '  </div>';
    echo '</aside>';

    echo '<main class="admin-main">';
}

/**
 * Render the end of an admin page.
 */
function admin_page_end(): void
{
    echo '</main></div>';
    render_footer([
        // NOTE: Use escaped quotes in selector to avoid PHP parse errors.
        'extra_js' => "\n<script>\n(function(){\n  const openBtn=document.getElementById('adminNavOpen');\n  const backdrop=document.getElementById('adminNavBackdrop');\n  if(!openBtn||!backdrop) return;\n  function open(){document.documentElement.classList.add('admin-nav-open');}\n  function close(){document.documentElement.classList.remove('admin-nav-open');}\n  openBtn.addEventListener('click', open);\n  backdrop.addEventListener('click', close);\n  document.addEventListener('keydown', (e)=>{ if(e.key==='Escape') close();});\n  document.addEventListener('click', (e)=>{ const a=e.target.closest('a[data-close-admin-nav=\"1\"]'); if(a) close();});\n})();\n</script>\n"
    ]);
}
