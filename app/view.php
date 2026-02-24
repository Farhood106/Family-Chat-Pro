<?php
// app/view.php

declare(strict_types=1);

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/i18n.php';

function render_header(string $title, array $user, array $opts = []): void
{
    $lang = $user['lang'] ?? 'fa';
    $dir = lang_dir($lang);
    $extraHead = $opts['extra_head'] ?? '';
    echo "<!doctype html>\n";
    echo '<html lang="' . e($lang) . '" dir="' . e($dir) . '">' . "\n";
    echo "<head>\n";
    echo "  <meta charset=\"utf-8\">\n";
    echo "  <meta name=\"viewport\" content=\"width=device-width, initial-scale=1\">\n";
    echo "  <title>" . e($title) . "</title>\n";
    echo "  <link rel=\"stylesheet\" href=\"" . e(url('assets/app.css')) . "\">\n";
    // Make the app base available to JS so API calls work from any nested folder.
    echo "  <meta name=\"app-base\" content=\"" . e(url('')) . "\">\n";
    echo "  <script>window.APP_BASE=" . json_encode(url('')) . ";</script>\n";
    echo $extraHead;
    echo "</head>\n";
    echo "<body>\n";
}

function render_footer(array $opts = []): void
{
    $extraJs = $opts['extra_js'] ?? '';
    echo "<script src=\"" . e(url('assets/app.js')) . "\"></script>\n";
    echo $extraJs;
    echo "</body></html>";
}
