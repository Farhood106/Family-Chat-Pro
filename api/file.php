<?php
require_once __DIR__ . '/../app/bootstrap.php';

$me = require_login();
$id = int_param('id');
if ($id <= 0) {
    http_response_code(400);
    exit('Bad id');
}

$stmt = db()->prepare('SELECT chat_type, direct_id, group_id, file_path, file_name, file_mime, file_size, deleted_at FROM messages WHERE id=? LIMIT 1');
$stmt->execute([$id]);
$m = $stmt->fetch();
if (!$m || $m['deleted_at'] !== null || empty($m['file_path'])) {
    http_response_code(404);
    exit('Not found');
}

$chat_type = (string)$m['chat_type'];

if ($chat_type === 'direct') {
    $dc = db()->prepare('SELECT user_a, user_b FROM direct_conversations WHERE id=? LIMIT 1');
    $dc->execute([(int)$m['direct_id']]);
    $row = $dc->fetch();
    if (!$row || ((int)$row['user_a'] !== (int)$me['id'] && (int)$row['user_b'] !== (int)$me['id'])) {
        http_response_code(403);
        exit('Forbidden');
    }
} elseif ($chat_type === 'group') {
    $mem = db()->prepare('SELECT left_at FROM group_members WHERE group_id=? AND user_id=? LIMIT 1');
    $mem->execute([(int)$m['group_id'], (int)$me['id']]);
    $row = $mem->fetch();
    if (!$row || $row['left_at'] !== null) {
        http_response_code(403);
        exit('Forbidden');
    }
} else {
    http_response_code(400);
    exit('Bad chat');
}

$rel = (string)$m['file_path'];
$base = realpath(__DIR__ . '/../storage/uploads');
$path = realpath(__DIR__ . '/../' . $rel);
if (!$base || !$path || strpos($path, $base) !== 0 || !is_file($path)) {
    http_response_code(404);
    exit('Not found');
}

$mime = (string)($m['file_mime'] ?? 'application/octet-stream');
$name = (string)($m['file_name'] ?? 'file');
$size = (int)($m['file_size'] ?? 0);

header('Content-Type: ' . $mime);
header('Content-Length: ' . $size);
header('Content-Disposition: inline; filename="' . str_replace('"','', $name) . '"');
header('X-Content-Type-Options: nosniff');
readfile($path);
exit;
