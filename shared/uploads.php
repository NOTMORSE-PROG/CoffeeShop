<?php
/**
 * Image uploads for menu items.
 *
 * The rules here exist because an upload form is the easiest way to get a
 * file that runs as code onto a server.
 *
 *  - The type is decided by reading the file, not by trusting its extension
 *    or the browser's declared MIME type. Both are attacker-controlled.
 *  - Only JPEG, PNG, GIF and WebP are accepted. SVG is deliberately refused:
 *    it is XML that can carry script, and it would be served from our own
 *    origin. The drink art shipped with the system is SVG because we wrote
 *    it; uploads are a different trust level.
 *  - The stored name is random. Nothing from the original filename survives,
 *    so a name like `shell.php.jpg` or `../../config` cannot do anything.
 *  - Files land under assets/, where the Apache config refuses to execute
 *    PHP. That is the backstop if everything above is somehow bypassed.
 */

declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/helpers.php';

/** Where uploaded menu pictures live, relative to the project root. */
const UPLOAD_DIR = 'assets/img/products/uploads';

/** Largest accepted upload, in bytes. */
const UPLOAD_MAX_BYTES = 2 * 1024 * 1024;

/**
 * Longest side of a stored picture, in pixels.
 *
 * A menu photo is shown at 240px on the customer site and 44px in the admin
 * list. Keeping a 4000px phone photo to serve that is wasteful three times
 * over: disk on a small hosting plan, the customer's mobile data, and the
 * time the page takes to paint. 900px is generous for a retina phone screen.
 */
const UPLOAD_MAX_EDGE = 900;

/** JPEG quality for the stored copy. 82 is visually clean at this size. */
const UPLOAD_JPEG_QUALITY = 82;

/** Image types we accept, mapped to the extension we store them with. */
const UPLOAD_TYPES = [
    IMAGETYPE_JPEG => 'jpg',
    IMAGETYPE_PNG  => 'png',
    IMAGETYPE_GIF  => 'gif',
    IMAGETYPE_WEBP => 'webp',
];

/**
 * Handle one uploaded picture.
 *
 * @param array|null $file One entry from $_FILES, or null.
 * @return array{ok: bool, path?: string, error?: string, skipped?: bool}
 *         `skipped` is true when no file was submitted at all, which is not
 *         an error: it just means "keep whatever the item already had".
 */
function handle_image_upload(?array $file): array
{
    if ($file === null || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return ['ok' => true, 'skipped' => true];
    }

    switch ($file['error']) {
        case UPLOAD_ERR_OK:
            break;
        case UPLOAD_ERR_INI_SIZE:
        case UPLOAD_ERR_FORM_SIZE:
            return ['ok' => false, 'error' => 'That picture is too large. The limit is 2 MB.'];
        case UPLOAD_ERR_PARTIAL:
            return ['ok' => false, 'error' => 'The picture only partly uploaded. Please try again.'];
        case UPLOAD_ERR_NO_TMP_DIR:
        case UPLOAD_ERR_CANT_WRITE:
            error_log('Upload failed, server side: error code ' . $file['error']);
            return ['ok' => false, 'error' => 'The server could not save the picture.'];
        default:
            return ['ok' => false, 'error' => 'The picture could not be uploaded.'];
    }

    // Confirms the file really came through PHP's upload handler and is not
    // some other path the request tricked us into reading.
    if (!is_uploaded_file($file['tmp_name'])) {
        return ['ok' => false, 'error' => 'That file was not a genuine upload.'];
    }

    if (($file['size'] ?? 0) > UPLOAD_MAX_BYTES) {
        return ['ok' => false, 'error' => 'That picture is too large. The limit is 2 MB.'];
    }

    if (($file['size'] ?? 0) === 0) {
        return ['ok' => false, 'error' => 'That file is empty.'];
    }

    // The real test: can PHP parse it as an image, and is it a type we allow?
    $info = @getimagesize($file['tmp_name']);

    if ($info === false) {
        return ['ok' => false, 'error' => 'That file is not an image we can read.'];
    }

    $type = $info[2] ?? null;

    if (!isset(UPLOAD_TYPES[$type])) {
        return [
            'ok'    => false,
            'error' => 'Please use a JPG, PNG, GIF or WebP picture. SVG files are not accepted.',
        ];
    }

    // A picture this large is a mistake rather than a menu photo, and would
    // exhaust memory if anything later tried to process it.
    if (($info[0] ?? 0) > 6000 || ($info[1] ?? 0) > 6000) {
        return ['ok' => false, 'error' => 'That picture is too big. Keep it under 6000 pixels a side.'];
    }

    $dir = APP_ROOT . '/' . UPLOAD_DIR;

    if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
        error_log('Could not create the upload directory: ' . $dir);
        return ['ok' => false, 'error' => 'The server could not save the picture.'];
    }

    // Nothing from the submitted filename is reused.
    $name = bin2hex(random_bytes(16)) . '.' . UPLOAD_TYPES[$type];
    $destination = $dir . '/' . $name;

    // Store a resized copy rather than the original. A phone photo is several
    // megabytes and thousands of pixels wide; the site shows it at 240.
    // Re-encoding also strips EXIF, which on a phone photo usually carries
    // the location where it was taken.
    if (!shrink_image($file['tmp_name'], $destination, $type, $info)) {
        // If resizing is unavailable, keeping the original is better than
        // refusing the upload. The size guard above still applies.
        if (!@move_uploaded_file($file['tmp_name'], $destination)) {
            error_log('Could not move the upload into ' . $dir);
            return ['ok' => false, 'error' => 'The server could not save the picture.'];
        }
    }

    @chmod($destination, 0644);

    return ['ok' => true, 'path' => UPLOAD_DIR . '/' . $name];
}

/**
 * Write a resized, re-encoded copy of an uploaded image.
 *
 * Returns false when it cannot be done, so the caller can fall back to
 * storing the original. GD is present on effectively every PHP host, but
 * this should not be the thing that breaks an upload if it is missing.
 */
function shrink_image(string $source, string $destination, int $type, array $info): bool
{
    if (!extension_loaded('gd')) {
        return false;
    }

    $width  = (int) ($info[0] ?? 0);
    $height = (int) ($info[1] ?? 0);

    if ($width < 1 || $height < 1) {
        return false;
    }

    $image = match ($type) {
        IMAGETYPE_JPEG => @imagecreatefromjpeg($source),
        IMAGETYPE_PNG  => @imagecreatefrompng($source),
        IMAGETYPE_GIF  => @imagecreatefromgif($source),
        IMAGETYPE_WEBP => @imagecreatefromwebp($source),
        default        => false,
    };

    if (!$image) {
        return false;
    }

    // Only ever scale down. Enlarging a small picture just wastes space and
    // makes it look worse.
    $scale = min(1.0, UPLOAD_MAX_EDGE / max($width, $height));
    $newWidth  = max(1, (int) round($width * $scale));
    $newHeight = max(1, (int) round($height * $scale));

    $canvas = imagecreatetruecolor($newWidth, $newHeight);

    if (!$canvas) {
        imagedestroy($image);
        return false;
    }

    // Keep transparency for the formats that have it, otherwise a PNG logo
    // comes out on a black background.
    if ($type === IMAGETYPE_PNG || $type === IMAGETYPE_GIF || $type === IMAGETYPE_WEBP) {
        imagealphablending($canvas, false);
        imagesavealpha($canvas, true);
        $transparent = imagecolorallocatealpha($canvas, 0, 0, 0, 127);
        imagefilledrectangle($canvas, 0, 0, $newWidth, $newHeight, $transparent);
    }

    imagecopyresampled($canvas, $image, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);

    $saved = match ($type) {
        IMAGETYPE_JPEG => imagejpeg($canvas, $destination, UPLOAD_JPEG_QUALITY),
        IMAGETYPE_PNG  => imagepng($canvas, $destination, 6),
        IMAGETYPE_GIF  => imagegif($canvas, $destination),
        IMAGETYPE_WEBP => imagewebp($canvas, $destination, UPLOAD_JPEG_QUALITY),
        default        => false,
    };

    imagedestroy($image);
    imagedestroy($canvas);

    return (bool) $saved;
}

/**
 * Remove a previously uploaded picture.
 *
 * Only ever deletes inside the upload directory, so it cannot be pointed at
 * the drink art that ships with the system, or at anything else on disk.
 */
function delete_uploaded_image(?string $path): void
{
    if ($path === null || $path === '') {
        return;
    }

    // Must be one of ours, and must not try to climb out of the folder.
    if (!str_starts_with($path, UPLOAD_DIR . '/') || str_contains($path, '..')) {
        return;
    }

    $full = APP_ROOT . '/' . $path;
    $real = realpath($full);
    $base = realpath(APP_ROOT . '/' . UPLOAD_DIR);

    if ($real === false || $base === false || !str_starts_with($real, $base)) {
        return;
    }

    @unlink($real);
}

/** Whether a stored image path points at an upload rather than shipped art. */
function is_uploaded_image(?string $path): bool
{
    return $path !== null && str_starts_with($path, UPLOAD_DIR . '/');
}
