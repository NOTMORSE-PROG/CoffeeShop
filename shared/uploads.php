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

    if (!@move_uploaded_file($file['tmp_name'], $dir . '/' . $name)) {
        error_log('Could not move the upload into ' . $dir);
        return ['ok' => false, 'error' => 'The server could not save the picture.'];
    }

    @chmod($dir . '/' . $name, 0644);

    return ['ok' => true, 'path' => UPLOAD_DIR . '/' . $name];
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
