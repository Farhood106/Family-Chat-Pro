<?php
// app/config.php
// Configure DB credentials and base_url before use.

declare(strict_types=1);

return [
    'db' => [
        'host' => 'localhost',
        'name' => 'DB_NAME',
        'user' => 'DB_USER',
        'pass' => 'DB_PASS',
        'charset' => 'utf8mb4',
    ],

    // App base URL without trailing slash. Example: https://example.com/chat
    // Leave empty to auto-detect in most cases.
    'base_url' => '',

    // Storage path for uploads (must be writable)
    'upload_dir' => __DIR__ . '/../storage/uploads',

    // Storage path for user avatars (must be writable)
    'avatar_dir' => __DIR__ . '/../storage/avatars',

    // Allowed file mime types (basic safe set)
    'allowed_mimes' => [
        'image/jpeg',
        'image/png',
        'image/webp',
        'application/pdf',
        'text/plain',
    ],

    // Fallback max size in MB if DB setting is missing
    'max_upload_fallback_mb' => 8,
];
