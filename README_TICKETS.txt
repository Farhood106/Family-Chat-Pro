Family Chat Pro - Ticket Module (v28)

This package adds "Ticket-style chat" designed for shared hosting (PHP+MySQL, no websocket/cron).
It is built to be SAFE: feature-flagged and default OFF.

Install/Upgrade:
1) Upload and OVERWRITE these files into your existing /chat/ folder.
2) Run SQL: sql/upgrade_v6_tickets.sql (once) in phpMyAdmin.
3) Admin Panel -> Settings:
   - Enable "Tickets" (tickets_enabled = 1)
   - Choose who can open tickets (tickets_for_roles = hidden1 or hidden1,public)

Ticket UI:
- For Hidden level 1 users: /tickets/my.php
- For Support/Admin/SuperAdmin: /tickets/admin.php

Menu integration:
- Include /tickets/_menu_link.php inside your existing three-dots menu.

Rollback:
- Disable tickets in settings (tickets_enabled=0). Core chat remains unchanged.
