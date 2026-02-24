<?php
// app/bootstrap.php

declare(strict_types=1);

// Secure-ish session defaults for shared hosting.
// NOTE: Some shared hosts / reverse proxies set $_SERVER['HTTPS'] incorrectly.
// If we set a "secure" session cookie while the user is on plain HTTP,
// the browser won't send the cookie back and CSRF validation will fail.
ini_set('session.use_strict_mode', '1');
ini_set('session.cookie_httponly', '1');
ini_set('session.cookie_samesite', 'Lax');

$isHttps = false;
if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
    $isHttps = true;
}
if (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower((string)$_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https') {
    $isHttps = true;
}
if (!empty($_SERVER['SERVER_PORT']) && (int)$_SERVER['SERVER_PORT'] === 443) {
    $isHttps = true;
}

// Only mark the cookie as secure when we are confident the request is HTTPS.
ini_set('session.cookie_secure', $isHttps ? '1' : '0');

session_start();

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/csrf.php';
require_once __DIR__ . '/settings.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/rate_limit.php';
require_once __DIR__ . '/i18n.php';
require_once __DIR__ . '/tickets.php';

// Content Security Policy (soft default; adjust if you add CDNs)
header("Content-Security-Policy: default-src 'self'; img-src 'self' data:; style-src 'self' 'unsafe-inline'; script-src 'self' 'unsafe-inline';");
