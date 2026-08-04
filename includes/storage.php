<?php
declare(strict_types=1);

/**
 * Local and MEGA S4 upload storage.
 *
 * Object keys intentionally match the existing uploads/... database paths so
 * storage can be changed without a schema migration.
 */

function znp_storage_driver(): string
{
    return setting('storage_driver', 'local') === 'mega_s4' ? 'mega_s4' : 'local';
}

function znp_storage_uses_s4(): bool
{
    return znp_storage_driver() === 'mega_s4';
}

function znp_storage_key(string $path): string
{
    $key = ltrim(str_replace('\\', '/', trim($path)), '/');
    $uploadsPosition = strpos($key, 'uploads/');
    if ($uploadsPosition !== false) {
        $key = substr($key, $uploadsPosition);
    }
    if ($key === '' || strpos($key, 'uploads/') !== 0 || preg_match('#(?:^|/)\.\.(?:/|$)#', $key)) {
        throw new InvalidArgumentException('Invalid upload storage path.');
    }
    return $key;
}

function znp_storage_local_path(string $path): string
{
    return dirname(__DIR__) . '/' . znp_storage_key($path);
}

function znp_storage_temp_directory(): string
{
    $directory=dirname(__DIR__).'/tmp';
    if(!is_dir($directory)&&!mkdir($directory,0775,true))throw new RuntimeException('The temporary upload folder could not be created.');
    if(!is_writable($directory))throw new RuntimeException('The temporary upload folder is not writable.');
    return $directory;
}

function znp_storage_temp_path(string $extension=''): string
{
    $extension=strtolower(preg_replace('/[^a-z0-9]/i','',$extension)??'');
    return znp_storage_temp_directory().'/upload-'.bin2hex(random_bytes(20)).($extension!==''?'.'.$extension:'');
}

function znp_storage_remove_temp_file(string $path): void
{
    $directory=rtrim(str_replace('\\','/',znp_storage_temp_directory()),'/').'/';$normalized=str_replace('\\','/',$path);
    if(!str_starts_with($normalized,$directory)||dirname($normalized)!==rtrim($directory,'/'))throw new InvalidArgumentException('Invalid temporary upload path.');
    if(is_file($normalized)&&!@unlink($normalized))throw new RuntimeException('The temporary upload file could not be removed.');
}

function znp_storage_store_uploaded_file(string $path,string $uploadedFile,string $extension,string $contentType='application/octet-stream'): void
{
    $key=znp_storage_key($path);
    if(znp_storage_uses_s4()){
        $temporary=znp_storage_temp_path($extension);
        if(!move_uploaded_file($uploadedFile,$temporary))throw new RuntimeException('The uploaded file could not be staged.');
        try{znp_storage_put_file($key,$temporary,$contentType);}finally{znp_storage_remove_temp_file($temporary);}
        return;
    }
    $destination=znp_storage_local_path($key);if(!is_dir(dirname($destination))&&!mkdir(dirname($destination),0775,true))throw new RuntimeException('The upload folder is not writable.');
    if(!move_uploaded_file($uploadedFile,$destination))throw new RuntimeException('The uploaded file could not be saved.');
}

function znp_storage_store_generated_file(string $path,string $contents,string $extension,string $contentType='application/octet-stream'): void
{
    $key=znp_storage_key($path);
    if(znp_storage_uses_s4()){
        $temporary=znp_storage_temp_path($extension);
        if(file_put_contents($temporary,$contents,LOCK_EX)===false)throw new RuntimeException('The generated upload could not be staged.');
        try{znp_storage_put_file($key,$temporary,$contentType);}finally{znp_storage_remove_temp_file($temporary);}
        return;
    }
    $destination=znp_storage_local_path($key);if(!is_dir(dirname($destination))&&!mkdir(dirname($destination),0775,true))throw new RuntimeException('The upload folder is not writable.');
    if(file_put_contents($destination,$contents,LOCK_EX)===false)throw new RuntimeException('The generated upload could not be saved.');
}

function znp_storage_local_paths(string $path): array
{
    $key = znp_storage_key($path);
    $paths = [dirname(__DIR__) . '/' . $key];
    $documentRoot = rtrim(str_replace('\\', '/', (string)($_SERVER['DOCUMENT_ROOT'] ?? '')), '/');
    if ($documentRoot !== '') {
        $paths[] = $documentRoot . '/' . $key;
    }
    return array_values(array_unique($paths));
}

function znp_storage_local_file(string $path): ?string
{
    foreach (znp_storage_local_paths($path) as $localFile) {
        if (is_file($localFile)) {
            return $localFile;
        }
    }
    return null;
}

function znp_mega_s4_config(): array
{
    return [
        'endpoint' => rtrim(trim(setting('mega_s4_endpoint')), '/'),
        'region' => trim(setting('mega_s4_region')),
        'bucket' => trim(setting('mega_s4_bucket')),
        'access_key' => trim(decrypt_setting(setting('mega_s4_access_key'))),
        'secret_key' => trim(decrypt_setting(setting('mega_s4_secret_key'))),
    ];
}

function znp_mega_s4_client(): Aws\S3\S3Client
{
    $autoload = __DIR__ . '/../vendor/autoload.php';
    if (!class_exists(Aws\S3\S3Client::class)) {
        if (!is_file($autoload)) {
            throw new RuntimeException('The MEGA S4 SDK is not installed. Run composer install.');
        }
        require_once $autoload;
    }

    $config = znp_mega_s4_config();
    foreach (['endpoint', 'region', 'bucket', 'access_key', 'secret_key'] as $required) {
        if ($config[$required] === '') {
            throw new RuntimeException('MEGA S4 configuration is incomplete.');
        }
    }

    return new Aws\S3\S3Client([
        'version' => 'latest',
        'region' => $config['region'],
        'endpoint' => $config['endpoint'],
        'use_path_style_endpoint' => true,
        'signature_version' => 'v4',
        'request_checksum_calculation' => 'when_required',
        'response_checksum_validation' => 'when_required',
        'credentials' => [
            'key' => $config['access_key'],
            'secret' => $config['secret_key'],
        ],
        'http' => [
            'connect_timeout' => 8,
            'timeout' => 600,
        ],
    ]);
}

function znp_storage_put_file(string $path, string $localFile, string $contentType = 'application/octet-stream'): void
{
    $key = znp_storage_key($path);
    if (!is_file($localFile) || !is_readable($localFile)) {
        throw new RuntimeException('The processed upload file could not be read.');
    }
    if (!znp_storage_uses_s4()) {
        return;
    }

    $config = znp_mega_s4_config();
    znp_mega_s4_client()->putObject([
        'Bucket' => $config['bucket'],
        'Key' => $key,
        'SourceFile' => $localFile,
        'ContentType' => $contentType !== '' ? $contentType : 'application/octet-stream',
    ]);
}

function znp_storage_remove_local(string $path): void
{
    foreach (znp_storage_local_paths($path) as $localFile) {
        if (is_file($localFile) && !@unlink($localFile)) {
            throw new RuntimeException('The local upload file could not be removed.');
        }
    }
}

function znp_storage_delete(string $path): void
{
    $key = znp_storage_key($path);
    if (znp_storage_uses_s4()) {
        $config = znp_mega_s4_config();
        znp_mega_s4_client()->deleteObject([
            'Bucket' => $config['bucket'],
            'Key' => $key,
        ]);
    }
    znp_storage_remove_local($key);
}

function znp_storage_contents(string $path): string
{
    $key = znp_storage_key($path);
    $localFile = znp_storage_local_file($key);
    if ($localFile !== null) {
        $contents = file_get_contents($localFile);
        if ($contents === false) throw new RuntimeException('The stored file could not be read.');
        return $contents;
    }
    if (znp_storage_uses_s4()) {
        $config = znp_mega_s4_config();
        $result = znp_mega_s4_client()->getObject(['Bucket' => $config['bucket'], 'Key' => $key]);
        return (string)$result['Body'];
    }
    throw new RuntimeException('The stored file was not found.');
}

function znp_storage_url(string $path, string $expires = '+15 minutes'): string
{
    $key = znp_storage_key($path);
    if (!znp_storage_uses_s4()) {
        return app_upload_url($key);
    }

    try {
        return znp_storage_download_url($key, '', '', 'inline', $expires);
    } catch (Throwable $exception) {
        error_log('Upload URL could not be generated from MEGA S4: ' . $exception->getMessage());
        return app_upload_url($key);
    }
}

function znp_storage_download_url(
    string $path,
    string $filename = '',
    string $contentType = '',
    string $disposition = 'attachment',
    string $expires = '+15 minutes'
): string {
    $key = znp_storage_key($path);
    $config = znp_mega_s4_config();
    $client = znp_mega_s4_client();
    $arguments = ['Bucket' => $config['bucket'], 'Key' => $key];
    if ($filename !== '') {
        $safeFilename = str_replace(["\r", "\n", '"'], '', basename($filename));
        $arguments['ResponseContentDisposition'] = ($disposition === 'inline' ? 'inline' : 'attachment')
            . '; filename="' . $safeFilename . '"';
    }
    if ($contentType !== '') {
        $arguments['ResponseContentType'] = $contentType;
    }
    $command = $client->getCommand('GetObject', $arguments);
    return (string)$client->createPresignedRequest($command, $expires)->getUri();
}

function znp_storage_send_file(
    string $path,
    string $filename,
    string $contentType = 'application/octet-stream',
    string $disposition = 'attachment'
): void {
    $key = znp_storage_key($path);
    $localFile = znp_storage_local_file($key);
    $safeFilename = str_replace(["\r", "\n", '"'], '', basename($filename));
    $safeDisposition = $disposition === 'inline' ? 'inline' : 'attachment';
    if (znp_storage_uses_s4()) {
        header('Location: ' . znp_storage_download_url($key, $safeFilename, $contentType, $safeDisposition));
        exit;
    }
    if ($localFile !== null) {
        header('Content-Type: ' . ($contentType !== '' ? $contentType : 'application/octet-stream'));
        header('Content-Length: ' . filesize($localFile));
        header('Content-Disposition: ' . $safeDisposition . '; filename="' . $safeFilename . '"');
        readfile($localFile);
        exit;
    }
    http_response_code(404);
    exit('File not found.');
}

function znp_mega_s4_test_connection(): array
{
    try {
        $config = znp_mega_s4_config();
        $client = znp_mega_s4_client();
        $client->headBucket(['Bucket' => $config['bucket']]);
        return [
            'ok' => true,
            'message' => 'MEGA S4 connection successful. Bucket access was verified.',
        ];
    } catch (Throwable $exception) {
        error_log('MEGA S4 connection test failed: ' . $exception->getMessage());
        $message = trim($exception->getMessage());
        if ($exception instanceof Aws\Exception\AwsException) {
            $awsMessage = trim((string)$exception->getAwsErrorMessage());
            if ($awsMessage !== '') {
                $message = $awsMessage;
            }
        }
        return [
            'ok' => false,
            'message' => 'MEGA S4 connection failed: ' . ($message !== '' ? $message : 'Unknown connection error.'),
        ];
    }
}
