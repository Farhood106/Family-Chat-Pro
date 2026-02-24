# Family Chat Pro (PHP 8+ / MySQL 8+) – Shared Hosting Ready

## Features
- Admin-only user creation (no public signup)
- Direct (1:1) chat
- Group chat with Join / Leave (messages hidden until Join)
- Optional file upload + image preview (local preview + inline image in chat)
- Soft delete (sender or admin) → replaces content with “message deleted”
- Typing indicator (polling)
- Load older messages (pagination)
- Message search (optional)
- Admin panel:
  - Users: create / edit / reset password / delete / activate/deactivate
  - Groups: create / remove members
  - Settings toggles stored in DB (`app_settings`)
- Security: PDO prepared statements, CSRF, basic rate limiting, session auth, `.htaccess` hardening

## Install (cPanel)
1) Upload the **entire folder** to: `public_html/chat/` (or any folder you want).
2) In phpMyAdmin, import: `sql/schema.sql`
3) Edit: `app/config.php` and set DB credentials.
4) Make sure this is writable: `storage/uploads` (permission 775 is usually fine).
5) Open once: `/chat/install.php` → create the first admin.
6) **Delete `install.php`** after creating admin.
7) Login: `/chat/login.php`

## Notes
- This project uses **polling** (no WebSocket / no Node / no Redis / no Cron).
- Uploads are stored under `storage/uploads` and served via `api/file.php` with permission checks.
- To use Vazirmatn font, put `Vazirmatn.woff2` into `assets/fonts/` (optional).

## Upgrade (existing install)
If you already imported an older schema, run this once in phpMyAdmin:
- `sql/upgrade_v2.sql` (adds Seen/read receipts table)

## Default settings
Edit via Admin → Settings.

