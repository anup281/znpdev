<?php
declare(strict_types=1);

// Local development/hosting credentials belong in config.local.php, which is
// intentionally ignored by Git. Production may alternatively provide these
// values through environment variables.
$localConfig = __DIR__ . '/config.local.php';
if (is_file($localConfig)) {
    require_once $localConfig;
}

if (!defined('DB_HOST')) define('DB_HOST', (string)(getenv('ZNP_DB_HOST') ?: 'localhost'));
if (!defined('DB_NAME')) define('DB_NAME', (string)(getenv('ZNP_DB_NAME') ?: ''));
if (!defined('DB_USER')) define('DB_USER', (string)(getenv('ZNP_DB_USER') ?: ''));
if (!defined('DB_PASS')) define('DB_PASS', (string)(getenv('ZNP_DB_PASS') ?: ''));
if (!defined('APP_ENV')) define('APP_ENV', (string)(getenv('ZNP_APP_ENV') ?: 'production'));
if (!defined('BASE_URL')) define('BASE_URL', rtrim((string)(getenv('ZNP_BASE_URL') ?: ''), '/'));
if (!defined('APP_INSTALLED')) define('APP_INSTALLED', filter_var(getenv('ZNP_APP_INSTALLED') ?: '1', FILTER_VALIDATE_BOOL));
