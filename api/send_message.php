<?php
require_once __DIR__ . '/../app/bootstrap.php';

$me = require_login();

// CSRF protection
csrf_validate();

$limitPerMin = setting_int('message_limit_per_min', 25);
if (!rate_limit_check((int)$me['id'], 'send', $limitPerMin)) {
    json_out(['ok'=>false,'error'=>'RATE_LIMIT'], 429);
}

$chat_type = str_param('chat_type');
$direct_id = int_param('direct_id');
$group_id = int_param('group_id');
$body = str_param('body');

$files_enabled = setting_bool('files_enabled', true);
$max_mb = setting_int('max_file_mb', (require __DIR__ . '/../app/config.php')['max_upload_fallback_mb']);
$max_bytes = $max_mb * 1024 * 1024;

function ensure_direct(int $direct_id, int $me_id): void
{
    $s = db()->prepare('SELECT user_a,user_b FROM direct_conversations WHERE id=?');
    $s->execute([$direct_id]);
    $r = $s->fetch();
    if (!$r) json_out(['ok'=>false,'error'=>'NOT_FOUND'], 404);
    if ((int)$r['user_a'] !== $me_id && (int)$r['user_b'] !== $me_id) json_out(['ok'=>false,'error'=>'FORBIDDEN'], 403);
}

function ensure_group_member(int $group_id, int $me_id): void
{
    $s = db()->prepare('SELECT left_at FROM group_members WHERE group_id=? AND user_id=? LIMIT 1');
    $s->execute([$group_id, $me_id]);
    $r = $s->fetch();
    if (!$r || $r['left_at'] !== null) json_out(['ok'=>false,'error'=>'NOT_MEMBER'], 403);
}

if ($chat_type === 'direct') {
    if ($direct_id <= 0) json_out(['ok'=>false,'error'=>'BAD_REQUEST'], 400);
    ensure_direct($direct_id, (int)$me['id']);
} elseif ($chat_type === 'group') {
    if ($group_id <= 0) json_out(['ok'=>false,'error'=>'BAD_REQUEST'], 400);
    ensure_group_member($group_id, (int)$me['id']);
} else {
    json_out(['ok'=>false,'error'=>'BAD_REQUEST'], 400);
}

$file_path = null;
$file_name = null;
$file_mime = null;
$file_size = null;

if ($files_enabled && isset($_FILES['file']) && $_FILES['file']['error'] !== UPLOAD_ERR_NO_FILE) {
    $f = $_FILES['file'];
    if ($f['error'] !== UPLOAD_ERR_OK) {
        json_out(['ok'=>false,'error'=>'UPLOAD_FAILED'], 400);
    }
    if ((int)$f['size'] > $max_bytes) {
        json_out(['ok'=>false,'error'=>'FILE_TOO_LARGE'], 400);
    }

    $tmp = $f['tmp_name'];
    $mime = mime_content_type($tmp) ?: 'application/octet-stream';
    $cfg = require __DIR__ . '/../app/config.php';
    if (!in_array($mime, $cfg['allowed_mimes'], true)) {
        json_out(['ok'=>false,'error'=>'FILE_TYPE_NOT_ALLOWED'], 400);
    }

    $ext = '';
    $orig = (string)($f['name'] ?? 'file');
    if (preg_match('/\.[a-zA-Z0-9]{1,6}$/', $orig, $m)) {
        $ext = strtolower($m[0]);
    }

    $safeName = preg_replace('/[^a-zA-Z0-9_\.-]+/', '_', $orig);
    $newName = bin2hex(random_bytes(16)) . $ext;

    $uploadDir = $cfg['upload_dir'];
    if (!is_dir($uploadDir)) {
        @mkdir($uploadDir, 0775, true);
    }
    $dest = rtrim($uploadDir, '/\\') . DIRECTORY_SEPARATOR . $newName;
    if (!move_uploaded_file($tmp, $dest)) {
        json_out(['ok'=>false,'error'=>'UPLOAD_SAVE_FAILED'], 500);
    }

    // Public path (under storage/uploads)
    $file_path = 'storage/uploads/' . $newName;
    $file_name = $safeName;
    $file_mime = $mime;
    $file_size = (int)$f['size'];
}

if ($body === '' && !$file_path) {
    json_out(['ok'=>false,'error'=>'EMPTY'], 400);
}

$ins = db()->prepare('INSERT INTO messages (chat_type, direct_id, group_id, sender_id, body, file_path, file_name, file_mime, file_size, created_at) VALUES (?,?,?,?,?,?,?,?,?,?)');
$ins->execute([
    $chat_type,
    $chat_type === 'direct' ? $direct_id : null,
    $chat_type === 'group' ? $group_id : null,
    (int)$me['id'],
    $body !== '' ? $body : null,
    $file_path,
    $file_name,
    $file_mime,
    $file_size,
    now_utc(),
]);

$newId = (int)db()->lastInsertId();

// Return the created message for instant UI update.
$stmt = db()->prepare('SELECT m.id, m.sender_id, u.display_name, m.body, m.file_path, m.file_name, m.file_mime, m.file_size, m.deleted_at, m.deleted_by, m.created_at FROM messages m JOIN users u ON u.id=m.sender_id WHERE m.id=? LIMIT 1');
$stmt->execute([$newId]);
$row = $stmt->fetch();

$message = null;
if ($row) {
    $lang = (string)($me['lang'] ?? 'fa');
    $tf = chat_time_fields((string)($row['created_at'] ?? ''), $lang);
    $message = [
        'id' => (int)$row['id'],
        'sender_id' => (int)$row['sender_id'],
        'sender_name' => (string)$row['display_name'],
        'mine' => true,
        'seen' => false,
        'body' => (string)($row['body'] ?? ''),
        'deleted' => false,
        'can_delete' => true,
        // Use server-converted local fields to avoid timezone/JS inconsistencies.
        'time_text' => (string)$tf['time_text'],
        'day_key' => (string)$tf['day_key'],
        'day_label' => (string)$tf['day_label'],
        'datetime_text' => (string)$tf['datetime_text'],
        'created_at' => (string)$row['created_at'],
        'file' => empty($row['file_path']) ? null : [
            'url' => url('api/file.php?id=' . (int)$row['id']),
            'name' => (string)($row['file_name'] ?? ''),
            'mime' => (string)($row['file_mime'] ?? ''),
            'size' => (int)($row['file_size'] ?? 0),
            'is_image' => is_string($row['file_mime']) && str_starts_with($row['file_mime'], 'image/'),
        ],
    ];
}

json_out(['ok'=>true,'id'=>$newId,'message'=>$message]);
