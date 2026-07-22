<?php
declare(strict_types=1);

/**
 * Save a project upload as an optimized web image.
 * Uses GD when available; otherwise safely falls back to the original upload.
 * Returns a web-relative path.
 */
function znp_save_optimized_image(array $upload, string $absoluteDirectory, string $relativeDirectory, string $slug, int $maxDimension = 1920): string
{
    if (($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        throw new RuntimeException('The image upload failed.');
    }
    if (($upload['size'] ?? 0) > 12 * 1024 * 1024) {
        throw new RuntimeException('The image must be smaller than 12 MB.');
    }
    $tmp = (string)$upload['tmp_name'];
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($tmp);
    $supported = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
    if (!isset($supported[$mime])) {
        throw new RuntimeException('Upload a JPG, PNG, or WebP image.');
    }
    if (!is_dir($absoluteDirectory) && !mkdir($absoluteDirectory, 0755, true) && !is_dir($absoluteDirectory)) {
        throw new RuntimeException('The image directory could not be created.');
    }
    $safeSlug = trim(preg_replace('/[^a-z0-9]+/i', '-', strtolower($slug)), '-') ?: 'project';
    $baseName = $safeSlug . '-' . date('YmdHis') . '-' . bin2hex(random_bytes(3));

    $gdAvailable = function_exists('imagecreatefromjpeg') && function_exists('imagecreatetruecolor');
    if (!$gdAvailable) {
        $filename = $baseName . '.' . $supported[$mime];
        if (!move_uploaded_file($tmp, $absoluteDirectory . '/' . $filename)) {
            throw new RuntimeException('The image could not be saved.');
        }
        return trim($relativeDirectory, '/') . '/' . $filename;
    }

    [$width, $height] = getimagesize($tmp) ?: [0, 0];
    if ($width < 1 || $height < 1) {
        throw new RuntimeException('The uploaded image is invalid.');
    }
    $scale = min(1, $maxDimension / max($width, $height));
    $newWidth = max(1, (int)round($width * $scale));
    $newHeight = max(1, (int)round($height * $scale));
    $source = match ($mime) {
        'image/jpeg' => imagecreatefromjpeg($tmp),
        'image/png' => imagecreatefrompng($tmp),
        'image/webp' => function_exists('imagecreatefromwebp') ? imagecreatefromwebp($tmp) : false,
        default => false,
    };
    if (!$source) {
        throw new RuntimeException('The uploaded image could not be processed.');
    }
    $target = imagecreatetruecolor($newWidth, $newHeight);
    imagecopyresampled($target, $source, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);

    $useWebp = function_exists('imagewebp');
    $extension = $useWebp ? 'webp' : 'jpg';
    $filename = $baseName . '.' . $extension;
    $destination = $absoluteDirectory . '/' . $filename;
    $saved = $useWebp ? imagewebp($target, $destination, 84) : imagejpeg($target, $destination, 84);
    imagedestroy($source);
    imagedestroy($target);
    if (!$saved) {
        throw new RuntimeException('The optimized image could not be saved.');
    }
    return trim($relativeDirectory, '/') . '/' . $filename;
}
