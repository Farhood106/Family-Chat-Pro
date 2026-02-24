<?php
require_once __DIR__ . '/app/bootstrap.php';
$u = auth_user();
if ($u) {
    audit_log((int)$u['id'], 'user.logout', 'user', (int)$u['id']);
}
logout();
redirect(url('login.php'));
