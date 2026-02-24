<?php
require_once __DIR__ . '/../app/bootstrap.php';

$me = require_login();

$lang = str_param('lang', (string)($me['lang'] ?? 'fa'));
$lang = ($lang === 'en') ? 'en' : 'fa';

$user_id = int_param('user_id');
if ($user_id <= 0) {
    json_out(['ok' => false, 'error' => 'bad_request'], 400);
}

$stmt = db()->prepare('SELECT id, last_seen_at, is_active FROM users WHERE id=? LIMIT 1');
$stmt->execute([$user_id]);
$u = $stmt->fetch();
if (!$u || (int)$u['is_active'] !== 1) {
    json_out(['ok' => false, 'error' => 'not_found'], 404);
}

$last = $u['last_seen_at'] ?? null;
json_out([
    'ok' => true,
    'online' => is_online($last),
    'last_seen_at' => $last ?: null,
    'label' => presence_label($last, $lang),
]);
