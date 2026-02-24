# Family Chat Pro - Time Offset Hotfix

This hotfix fixes message times showing as UTC (Greenwich) by:
- Always computing `m.time` on the server using an admin-configurable UTC offset (minutes).
- Removing the JavaScript fallback that displayed `created_at` (UTC) when `m.time` was missing.

## Install (on your existing project)
1) Upload and overwrite these files:
   - `api/fetch_messages.php`
   - `assets/app.js`

2) In phpMyAdmin, run:
   - `sql/upgrade_time_offset.sql`

## Configure
- `app_settings.time_offset_minutes` is the offset from UTC in minutes.
  - Tehran: 210
  - UTC: 0
  - Dubai: 240
