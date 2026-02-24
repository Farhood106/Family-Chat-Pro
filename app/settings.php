<?php
// app/settings.php

declare(strict_types=1);

require_once __DIR__ . '/db.php';

function setting_get(string $key, ?string $default = null): ?string
{
    static $cache = [];
    if (array_key_exists($key, $cache)) {
        return $cache[$key];
    }
    $stmt = db()->prepare('SELECT value FROM app_settings WHERE `key`=? LIMIT 1');
    $stmt->execute([$key]);
    $row = $stmt->fetch();
    $val = $row ? (string)$row['value'] : $default;
    $cache[$key] = $val;
    return $val;
}

function setting_bool(string $key, bool $default = false): bool
{
    $v = setting_get($key, $default ? '1' : '0');
    return $v === '1' || strtolower((string)$v) === 'true';
}

function setting_int(string $key, int $default = 0): int
{
    $v = setting_get($key, (string)$default);
    return (int)$v;
}

function setting_set(string $key, string $value): void
{
    $stmt = db()->prepare('INSERT INTO app_settings (`key`,`value`) VALUES (?,?) ON DUPLICATE KEY UPDATE value=VALUES(value)');
    $stmt->execute([$key, $value]);
}
