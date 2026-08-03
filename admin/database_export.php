<?php
declare(strict_types=1);

require_once __DIR__.'/../includes/auth.php';

function database_export_is_super_admin(?array $user): bool
{
    return is_array($user) && in_array(normalized_role((string)($user['role'] ?? '')), ['super admin', 'super administrator'], true);
}

function database_export_token_directory(): string
{
    return rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.'znp-database-export-tokens';
}

function database_export_token_path(string $token): string
{
    return database_export_token_directory().DIRECTORY_SEPARATOR.hash('sha256', $token).'.json';
}

function database_export_identifier(string $identifier): string
{
    return '`'.str_replace('`', '``', $identifier).'`';
}

function database_export_sql_value(mixed $value): string
{
    if ($value === null) return 'NULL';
    $encoded = bin2hex((string)$value);
    return $encoded === '' ? "''" : '0x'.$encoded;
}

function database_export_create_statement(PDO $pdo, string $type, string $name): string
{
    $statement = $pdo->query('SHOW CREATE '.$type.' '.database_export_identifier($name))->fetch(PDO::FETCH_ASSOC);
    if (!is_array($statement)) throw new RuntimeException('Could not read the definition for '.$name.'.');
    foreach ($statement as $key => $value) {
        if (str_starts_with((string)$key, 'Create ')) return rtrim((string)$value, ';');
        if ((string)$key === 'SQL Original Statement') return rtrim((string)$value, ';');
    }
    throw new RuntimeException('Could not locate the CREATE statement for '.$name.'.');
}

function database_export_write(string $sql): void
{
    echo $sql;
    if (ob_get_level() > 0) @ob_flush();
    flush();
}

function database_export_stream(): never
{
    @set_time_limit(0);
    @ini_set('memory_limit', '-1');
    while (ob_get_level() > 0) ob_end_clean();

    $filename = preg_replace('/[^A-Za-z0-9_.-]+/', '-', DB_NAME).'-'.gmdate('Ymd-His').'.sql';
    header('Content-Type: application/sql; charset=utf-8');
    header('Content-Disposition: attachment; filename="'.$filename.'"');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: no-referrer');

    $pdo = db();
    $tables = [];
    $views = [];
    foreach ($pdo->query('SHOW FULL TABLES')->fetchAll(PDO::FETCH_NUM) as $row) {
        if (strcasecmp((string)($row[1] ?? ''), 'VIEW') === 0) $views[] = (string)$row[0];
        else $tables[] = (string)$row[0];
    }

    database_export_write("-- ZNP Development database export\n-- Database: ".DB_NAME."\n-- Generated: ".gmdate('c')."\n\nSET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\nSET UNIQUE_CHECKS=0;\n\n");
    $pdo->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
    $pdo->exec('START TRANSACTION WITH CONSISTENT SNAPSHOT');

    try {
        foreach ($tables as $table) {
            $quoted = database_export_identifier($table);
            database_export_write("--\n-- Table structure for {$quoted}\n--\n\nDROP TABLE IF EXISTS {$quoted};\n".database_export_create_statement($pdo, 'TABLE', $table).";\n\n");

            $query = $pdo->query('SELECT * FROM '.$quoted);
            $columns = [];
            for ($index = 0; $index < $query->columnCount(); $index++) {
                $meta = $query->getColumnMeta($index);
                $columns[] = database_export_identifier((string)($meta['name'] ?? $index));
            }
            $prefix = 'INSERT INTO '.$quoted.' ('.implode(',', $columns).') VALUES ';
            $batch = [];
            while ($row = $query->fetch(PDO::FETCH_NUM)) {
                $batch[] = '('.implode(',', array_map('database_export_sql_value', $row)).')';
                if (count($batch) >= 100) {
                    database_export_write($prefix.implode(",\n", $batch).";\n");
                    $batch = [];
                }
            }
            if ($batch) database_export_write($prefix.implode(",\n", $batch).";\n");
            database_export_write("\n");
        }

        foreach ($views as $view) {
            $quoted = database_export_identifier($view);
            database_export_write("--\n-- View structure for {$quoted}\n--\n\nDROP VIEW IF EXISTS {$quoted};\n".database_export_create_statement($pdo, 'VIEW', $view).";\n\n");
        }

        try {
            foreach ($pdo->query('SHOW TRIGGERS')->fetchAll(PDO::FETCH_ASSOC) as $trigger) {
                $name = (string)($trigger['Trigger'] ?? '');
                if ($name === '') continue;
                $quoted = database_export_identifier($name);
                $create = database_export_create_statement($pdo, 'TRIGGER', $name);
                database_export_write("--\n-- Trigger structure for {$quoted}\n--\n\nDROP TRIGGER IF EXISTS {$quoted};\nDELIMITER $$\n{$create}$$\nDELIMITER ;\n\n");
            }
        } catch (Throwable $ignored) {
            database_export_write("-- Trigger definitions could not be read with the current database permissions.\n\n");
        }

        try {
            $routines = $pdo->query("SELECT ROUTINE_NAME,ROUTINE_TYPE FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA=DATABASE() ORDER BY ROUTINE_TYPE,ROUTINE_NAME")->fetchAll(PDO::FETCH_ASSOC);
            foreach ($routines as $routine) {
                $name = (string)($routine['ROUTINE_NAME'] ?? '');
                $type = strtoupper((string)($routine['ROUTINE_TYPE'] ?? ''));
                if ($name === '' || !in_array($type, ['FUNCTION', 'PROCEDURE'], true)) continue;
                $quoted = database_export_identifier($name);
                $create = database_export_create_statement($pdo, $type, $name);
                database_export_write("--\n-- {$type} structure for {$quoted}\n--\n\nDROP {$type} IF EXISTS {$quoted};\nDELIMITER $$\n{$create}$$\nDELIMITER ;\n\n");
            }
        } catch (Throwable $ignored) {
            database_export_write("-- Stored routine definitions could not be read with the current database permissions.\n\n");
        }

        try {
            foreach ($pdo->query('SHOW EVENTS')->fetchAll(PDO::FETCH_ASSOC) as $event) {
                $name = (string)($event['Name'] ?? '');
                if ($name === '') continue;
                $quoted = database_export_identifier($name);
                $create = database_export_create_statement($pdo, 'EVENT', $name);
                database_export_write("--\n-- Event structure for {$quoted}\n--\n\nDROP EVENT IF EXISTS {$quoted};\nDELIMITER $$\n{$create}$$\nDELIMITER ;\n\n");
            }
        } catch (Throwable $ignored) {
            database_export_write("-- Event definitions could not be read with the current database permissions.\n\n");
        }

        $pdo->exec('COMMIT');
        database_export_write("SET UNIQUE_CHECKS=1;\nSET FOREIGN_KEY_CHECKS=1;\n");
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        database_export_write("\n-- EXPORT FAILED: ".str_replace(["\r", "\n"], ' ', $error->getMessage())."\n");
    }
    exit;
}

header('Referrer-Policy: no-referrer');
header('X-Robots-Tag: noindex, nofollow, noarchive');

$downloadToken = trim((string)($_GET['token'] ?? ''));
if ($downloadToken !== '') {
    if (!preg_match('/^[a-f0-9]{64}$/', $downloadToken)) {
        http_response_code(404);
        exit('This database export link is invalid.');
    }
    $tokenPath = database_export_token_path($downloadToken);
    $metadata = is_file($tokenPath) ? json_decode((string)file_get_contents($tokenPath), true) : null;
    if (!is_array($metadata) || (int)($metadata['expires_at'] ?? 0) < time()) {
        if (is_file($tokenPath)) @unlink($tokenPath);
        http_response_code(410);
        exit('This database export link has expired or has already been used.');
    }
    @unlink($tokenPath);
    database_export_stream();
}

require_admin();
if (!database_export_is_super_admin(admin_user())) {
    http_response_code(403);
    exit('Only a Super Admin may create a full database export.');
}

$exportUrl = '';
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_check((string)($_POST['csrf_token'] ?? ''))) {
        $error = 'Your session expired. Refresh the page and try again.';
    } else {
        try {
            $directory = database_export_token_directory();
            if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
                throw new RuntimeException('The private export-token directory could not be created.');
            }
            foreach (glob($directory.DIRECTORY_SEPARATOR.'*.json') ?: [] as $oldToken) {
                if (filemtime($oldToken) < time() - 3600) @unlink($oldToken);
            }
            $token = bin2hex(random_bytes(32));
            $metadata = json_encode(['expires_at' => time() + 900, 'created_by' => (int)(admin_user()['id'] ?? 0)], JSON_THROW_ON_ERROR);
            if (file_put_contents(database_export_token_path($token), $metadata, LOCK_EX) === false) {
                throw new RuntimeException('The one-time export token could not be saved.');
            }
            @chmod(database_export_token_path($token), 0600);
            $path = app_url('/admin/database_export.php?token='.$token);
            $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
            $host = preg_replace('/[^A-Za-z0-9.:[\]-]/', '', (string)($_SERVER['HTTP_HOST'] ?? ''));
            $exportUrl = $host !== '' ? $scheme.'://'.$host.$path : $path;
        } catch (Throwable $exception) {
            $error = $exception->getMessage();
        }
    }
}

require __DIR__.'/_header.php';
?>
<div class="admin-page-head"><div><h1>Database Export</h1><p>Create a full SQL snapshot for development and diagnostics.</p></div></div>
<?php if ($error !== ''): ?><div class="status error"><?=e($error)?></div><?php endif; ?>
<section class="admin-panel">
    <h2>Full Database Dump</h2>
    <p>This export contains the complete database, including private and authentication data. The download link expires after 15 minutes and works once.</p>
    <?php if ($exportUrl !== ''): ?>
        <div class="status success">The one-time export link is ready.</div>
        <p><a class="primary admin-small-button" href="<?=e($exportUrl)?>" rel="noreferrer">Download SQL Export</a></p>
        <label>One-time URL</label>
        <input class="znp-field-full" type="text" readonly value="<?=e($exportUrl)?>" onclick="this.select()">
        <p class="admin-help-text">Copy this URL for the authorized downloader. Opening it consumes the link.</p>
    <?php else: ?>
        <form method="post" onsubmit="return confirm('Create a one-time link for a complete production database export?')">
            <input type="hidden" name="csrf_token" value="<?=e(csrf_token())?>">
            <button class="primary" type="submit">Create One-Time Export Link</button>
        </form>
    <?php endif; ?>
</section>
<?php require __DIR__.'/_footer.php'; ?>
