<?php
require_once __DIR__ . '/../app/bootstrap.php';
require_admin();
header('Location: ' . url('admin/dashboard.php'));
exit;
