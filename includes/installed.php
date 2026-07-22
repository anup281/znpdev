<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$configFile = $root . '/config.php';
$installLock = $root . '/data/install.lock';

if (!file_exists($configFile)) {
    http_response_code(503);
    $assetPrefix = str_contains($_SERVER['SCRIPT_NAME'] ?? '', '/admin/') ? '../' : '';
    echo '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width">';
    echo '<title>Website Configuration Required</title><link rel="stylesheet" href="' . $assetPrefix . 'assets/site.css"></head>';
    echo '<body class="admin-body"><main class="installer-shell"><section class="installer-card">';
    echo '<h1>Website Configuration Required</h1><p>The website configuration file is missing. Please restore <strong>config.php</strong> from the most recent production backup.</p>';
    echo '</section></main></body></html>';
    exit;
}

require_once $configFile;

if (!defined('APP_INSTALLED') || APP_INSTALLED !== true) {
    http_response_code(503);
    $assetPrefix = str_contains($_SERVER['SCRIPT_NAME'] ?? '', '/admin/') ? '../' : '';
    echo '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width">';
    echo '<title>Website Configuration Required</title><link rel="stylesheet" href="' . $assetPrefix . 'assets/site.css"></head>';
    echo '<body class="admin-body"><main class="installer-shell"><section class="installer-card">';
    echo '<h1>Website Configuration Required</h1><p>The website configuration is incomplete. Please restore <strong>config.php</strong> from the most recent production backup.</p>';
    echo '</section></main></body></html>';
    exit;
}
