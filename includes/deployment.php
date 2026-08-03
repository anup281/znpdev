<?php
declare(strict_types=1);

function znp_deployment_paths(): array
{
    $source = realpath(dirname(__DIR__));
    $production = realpath((string)($_SERVER['DOCUMENT_ROOT'] ?? ''));
    if ($source === false || $production === false) {
        throw new RuntimeException('The deployment source or document root could not be resolved.');
    }
    $source = rtrim($source, DIRECTORY_SEPARATOR);
    $production = rtrim($production, DIRECTORY_SEPARATOR);
    if ($production === '' || $production === DIRECTORY_SEPARATOR || $source === $production) {
        throw new RuntimeException('The resolved production document root is not safe for deployment.');
    }
    if (basename($source) !== 'test' || dirname($source) !== $production) {
        throw new RuntimeException('Deployment is only available when this application is installed at /test directly inside the production document root.');
    }
    return [
        'source' => $source,
        'production' => $production,
        'backup' => $production.DIRECTORY_SEPARATOR.'backup',
    ];
}

function znp_deployment_is_preserved(string $name): bool
{
    return in_array($name, ['test', 'backup'], true);
}

function znp_deployment_format_bytes(int $bytes): string
{
    if ($bytes >= 1073741824) return number_format($bytes / 1073741824, 2).' GB';
    if ($bytes >= 1048576) return number_format($bytes / 1048576, 2).' MB';
    if ($bytes >= 1024) return number_format($bytes / 1024, 1).' KB';
    return number_format($bytes).' bytes';
}

function znp_deployment_tree_stats(string $root, bool $production): array
{
    $files = 0;
    $directories = 0;
    $bytes = 0;
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );
    foreach ($iterator as $item) {
        $relative = substr($item->getPathname(), strlen($root) + 1);
        $topLevel = strtok(str_replace('\\', '/', $relative), '/');
        if ($production && znp_deployment_is_preserved((string)$topLevel)) continue;
        if ($item->isLink()) {
            throw new RuntimeException('Symbolic links are not supported: '.$relative);
        }
        if ($item->isDir()) {
            $directories++;
        } elseif ($item->isFile()) {
            $files++;
            $bytes += $item->getSize();
        }
    }
    return ['files' => $files, 'directories' => $directories, 'bytes' => $bytes];
}

function znp_deployment_preflight(): array
{
    $paths = znp_deployment_paths();
    if (!class_exists(ZipArchive::class)) {
        throw new RuntimeException('PHP ZipArchive is required for deployment backups.');
    }
    if (!is_readable($paths['source'])) throw new RuntimeException('/test is not readable.');
    if (is_file($paths['source'].DIRECTORY_SEPARATOR.'composer.json')
        && !is_file($paths['source'].DIRECTORY_SEPARATOR.'vendor'.DIRECTORY_SEPARATOR.'autoload.php')) {
        throw new RuntimeException('/test contains composer.json but vendor/autoload.php is missing. Upload or install Composer dependencies before deployment.');
    }
    if (!is_writable($paths['production'])) throw new RuntimeException('The production document root is not writable.');
    if (!is_dir($paths['backup']) && !@mkdir($paths['backup'], 0750, true)) {
        throw new RuntimeException('/backup could not be created.');
    }
    if (!is_writable($paths['backup'])) throw new RuntimeException('/backup is not writable.');

    $protection = $paths['backup'].DIRECTORY_SEPARATOR.'.htaccess';
    $rules = "Require all denied\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n";
    if (@file_put_contents($protection, $rules, LOCK_EX) === false) {
        throw new RuntimeException('/backup could not be protected from web access.');
    }
    $index = $paths['backup'].DIRECTORY_SEPARATOR.'index.php';
    if (!is_file($index) && @file_put_contents($index, "<?php http_response_code(404); exit;\n", LOCK_EX) === false) {
        throw new RuntimeException('/backup index protection could not be created.');
    }

    $productionStats = znp_deployment_tree_stats($paths['production'], true);
    $freeBytes = disk_free_space($paths['backup']);
    if ($freeBytes !== false && $freeBytes < ($productionStats['bytes'] + 10485760)) {
        throw new RuntimeException('There is not enough free disk space to create a complete production backup.');
    }
    return [
        'paths' => $paths,
        'production' => $productionStats,
        'source' => znp_deployment_tree_stats($paths['source'], false),
        'vendor_ready' => !is_file($paths['source'].DIRECTORY_SEPARATOR.'composer.json')
            || is_file($paths['source'].DIRECTORY_SEPARATOR.'vendor'.DIRECTORY_SEPARATOR.'autoload.php'),
    ];
}

function znp_deployment_add_to_zip(ZipArchive $zip, string $root, string $path): void
{
    $relative = ltrim(str_replace('\\', '/', substr($path, strlen($root))), '/');
    if ($relative === '') return;
    if (is_link($path)) throw new RuntimeException('Symbolic links cannot be included in a deployment backup: '.$relative);
    if (is_dir($path)) {
        $zip->addEmptyDir($relative);
        $entries = scandir($path);
        if ($entries === false) throw new RuntimeException('Could not read '.$relative.'.');
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') continue;
            znp_deployment_add_to_zip($zip, $root, $path.DIRECTORY_SEPARATOR.$entry);
        }
        return;
    }
    if (!is_file($path) || !$zip->addFile($path, $relative)) {
        throw new RuntimeException('Could not add '.$relative.' to the production backup.');
    }
}

function znp_deployment_remove_tree(string $path): void
{
    if (is_link($path) || is_file($path)) {
        if (!@unlink($path)) throw new RuntimeException('Could not remove '.$path.'.');
        return;
    }
    if (!is_dir($path)) return;
    $entries = scandir($path);
    if ($entries === false) throw new RuntimeException('Could not read '.$path.' for removal.');
    foreach ($entries as $entry) {
        if ($entry === '.' || $entry === '..') continue;
        znp_deployment_remove_tree($path.DIRECTORY_SEPARATOR.$entry);
    }
    if (!@rmdir($path)) throw new RuntimeException('Could not remove '.$path.'.');
}

function znp_deployment_clear_production(string $production): void
{
    $entries = scandir($production);
    if ($entries === false) throw new RuntimeException('Could not read the production document root.');
    foreach ($entries as $entry) {
        if ($entry === '.' || $entry === '..' || znp_deployment_is_preserved($entry)) continue;
        znp_deployment_remove_tree($production.DIRECTORY_SEPARATOR.$entry);
    }
}

function znp_deployment_copy_tree(string $source, string $destination): void
{
    if (is_link($source)) throw new RuntimeException('Symbolic links cannot be promoted: '.$source);
    if (is_file($source)) {
        if (!@copy($source, $destination)) throw new RuntimeException('Could not copy '.$source.'.');
        @chmod($destination, fileperms($source) & 0777);
        return;
    }
    if (!is_dir($destination) && !@mkdir($destination, fileperms($source) & 0777, true)) {
        throw new RuntimeException('Could not create '.$destination.'.');
    }
    $entries = scandir($source);
    if ($entries === false) throw new RuntimeException('Could not read '.$source.'.');
    foreach ($entries as $entry) {
        if ($entry === '.' || $entry === '..') continue;
        znp_deployment_copy_tree(
            $source.DIRECTORY_SEPARATOR.$entry,
            $destination.DIRECTORY_SEPARATOR.$entry
        );
    }
}

function znp_deployment_verify_copy(string $source, string $destination): void
{
    if (is_link($source)) throw new RuntimeException('A promoted symbolic link could not be verified: '.$source);
    if (is_file($source)) {
        if (!is_file($destination) || filesize($source) !== filesize($destination)) {
            throw new RuntimeException('Deployment verification failed for '.basename($source).'.');
        }
        return;
    }
    if (!is_dir($source) || !is_dir($destination)) {
        throw new RuntimeException('Deployment directory verification failed for '.basename($source).'.');
    }
    $entries = scandir($source);
    if ($entries === false) throw new RuntimeException('Could not verify '.$source.'.');
    foreach ($entries as $entry) {
        if ($entry === '.' || $entry === '..') continue;
        znp_deployment_verify_copy(
            $source.DIRECTORY_SEPARATOR.$entry,
            $destination.DIRECTORY_SEPARATOR.$entry
        );
    }
}

function znp_deployment_restore(string $production, string $backupZip): void
{
    znp_deployment_clear_production($production);
    $zip = new ZipArchive();
    if ($zip->open($backupZip) !== true) throw new RuntimeException('The rollback backup could not be opened.');
    if (!$zip->extractTo($production)) {
        $zip->close();
        throw new RuntimeException('The rollback backup could not be restored.');
    }
    $zip->close();
}

function znp_deployment_run(): array
{
    @set_time_limit(0);
    @ignore_user_abort(true);
    $preflight = znp_deployment_preflight();
    $paths = $preflight['paths'];
    $lockPath = $paths['backup'].DIRECTORY_SEPARATOR.'.deployment.lock';
    $lock = fopen($lockPath, 'c+');
    if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
        if (is_resource($lock)) fclose($lock);
        throw new RuntimeException('Another deployment is already running.');
    }

    $timestamp = gmdate('Ymd-His');
    $backupZip = $paths['backup'].DIRECTORY_SEPARATOR.'production-'.$timestamp.'-'.bin2hex(random_bytes(4)).'.zip';
    $partialZip = $backupZip.'.partial';
    $deploymentStarted = false;
    $zip = null;
    try {
        $zip = new ZipArchive();
        if ($zip->open($partialZip, ZipArchive::CREATE | ZipArchive::EXCL) !== true) {
            throw new RuntimeException('The production backup could not be created.');
        }
        $entries = scandir($paths['production']);
        if ($entries === false) throw new RuntimeException('The production document root could not be read.');
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..' || znp_deployment_is_preserved($entry)) continue;
            znp_deployment_add_to_zip($zip, $paths['production'], $paths['production'].DIRECTORY_SEPARATOR.$entry);
        }
        if (!$zip->close() || !is_file($partialZip)) throw new RuntimeException('The production backup could not be finalized.');
        $zip = null;
        if (!@rename($partialZip, $backupZip)) throw new RuntimeException('The production backup could not be activated.');

        $verify = new ZipArchive();
        if ($verify->open($backupZip, ZipArchive::CHECKCONS) !== true) {
            throw new RuntimeException('The production backup failed its integrity check.');
        }
        $verify->close();

        $deploymentStarted = true;
        znp_deployment_clear_production($paths['production']);
        $sourceEntries = scandir($paths['source']);
        if ($sourceEntries === false) throw new RuntimeException('/test could not be read for promotion.');
        foreach ($sourceEntries as $entry) {
            if ($entry === '.' || $entry === '..' || znp_deployment_is_preserved($entry)) continue;
            znp_deployment_copy_tree(
                $paths['source'].DIRECTORY_SEPARATOR.$entry,
                $paths['production'].DIRECTORY_SEPARATOR.$entry
            );
        }
        foreach ($sourceEntries as $entry) {
            if ($entry === '.' || $entry === '..' || znp_deployment_is_preserved($entry)) continue;
            znp_deployment_verify_copy(
                $paths['source'].DIRECTORY_SEPARATOR.$entry,
                $paths['production'].DIRECTORY_SEPARATOR.$entry
            );
        }
        if (is_file($paths['source'].DIRECTORY_SEPARATOR.'composer.json')
            && !is_file($paths['production'].DIRECTORY_SEPARATOR.'vendor'.DIRECTORY_SEPARATOR.'autoload.php')) {
            throw new RuntimeException('Deployment verification failed because /vendor/autoload.php was not promoted.');
        }
        return [
            'backup' => basename($backupZip),
            'production' => $preflight['production'],
            'source' => $preflight['source'],
        ];
    } catch (Throwable $exception) {
        if ($zip instanceof ZipArchive) $zip->close();
        @unlink($partialZip);
        if ($deploymentStarted && is_file($backupZip)) {
            try {
                znp_deployment_restore($paths['production'], $backupZip);
            } catch (Throwable $rollbackException) {
                throw new RuntimeException(
                    'Deployment failed and automatic rollback also failed. Deployment error: '
                    .$exception->getMessage().' Rollback error: '.$rollbackException->getMessage()
                );
            }
            throw new RuntimeException('Deployment failed and production was restored automatically: '.$exception->getMessage());
        }
        throw $exception;
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}
