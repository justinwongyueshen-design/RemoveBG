<?php
declare(strict_types=1);

function ini_bytes(string $value): int
{
    $value = trim($value);
    if ($value === '' || $value === '-1') {
        return PHP_INT_MAX;
    }
    $n = (int)$value;
    switch (strtolower(substr($value, -1))) {
        case 'g': $n *= 1024; // no break
        case 'm': $n *= 1024; // no break
        case 'k': $n *= 1024;
    }
    return $n;
}

/**
 * Validates an entry of $_FILES on the server. Never trusts the client name or MIME type.
 * Returns the file to send to the provider plus metadata, or throws AppException.
 *
 * @return array{path:string,mime:string,width:int,height:int,size:int,temp_files:string[]}
 */
function validate_uploaded_image(?array $file): array
{
    if (!is_array($file) || !isset($file['error']) || is_array($file['error'])) {
        throw new AppException('upload_failed', 'No image was received. Please choose a file and try again.', 400);
    }

    switch ($file['error']) {
        case UPLOAD_ERR_OK:
            break;
        case UPLOAD_ERR_INI_SIZE:
        case UPLOAD_ERR_FORM_SIZE:
            throw new AppException('file_too_large', 'The image is larger than the allowed upload size.', 413);
        case UPLOAD_ERR_PARTIAL:
            throw new AppException('upload_failed', 'The upload was interrupted. Please try again.', 400);
        case UPLOAD_ERR_NO_FILE:
            throw new AppException('upload_failed', 'No image was received. Please choose a file and try again.', 400);
        default:
            throw new AppException('storage_unavailable',
                'The server could not store the upload. Please contact the site owner.', 500,
                'upload error code ' . (int)$file['error']);
    }

    $tmp = $file['tmp_name'] ?? '';
    if (!is_string($tmp) || $tmp === '' || !is_uploaded_file($tmp)) {
        throw new AppException('upload_failed', 'The upload could not be processed. Please try again.', 400);
    }

    $size = filesize($tmp);
    if ($size === false || $size === 0) {
        throw new AppException('empty_file', 'The selected file is empty.', 400);
    }
    if ($size > (int)cfg('max_upload_bytes')) {
        throw new AppException('file_too_large', 'The image is larger than the allowed upload size.', 413);
    }

    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($tmp);
    $allowed = ['image/jpeg' => IMAGETYPE_JPEG, 'image/png' => IMAGETYPE_PNG, 'image/webp' => IMAGETYPE_WEBP];
    if ($mime === false || !isset($allowed[$mime])) {
        throw new AppException('unsupported_format', 'Unsupported file type. Please upload a JPG, PNG or WebP image.', 415);
    }

    $info = @getimagesize($tmp);
    if ($info === false || ($info[2] ?? null) !== $allowed[$mime]) {
        throw new AppException('invalid_image', 'This file is not a valid image or is corrupted.', 422);
    }
    [$w, $h] = [(int)$info[0], (int)$info[1]];
    if ($w < 1 || $h < 1) {
        throw new AppException('invalid_image', 'This file is not a valid image or is corrupted.', 422);
    }
    if ($w > (int)cfg('max_image_width') || $h > (int)cfg('max_image_height') || $w * $h > (int)cfg('max_image_pixels')) {
        throw new AppException('dimensions_too_large',
            'The image dimensions are too large. Maximum is ' . (int)cfg('max_image_width') . ' x '
            . (int)cfg('max_image_height') . ' pixels.', 422);
    }

    $result = ['path' => $tmp, 'mime' => $mime, 'width' => $w, 'height' => $h, 'size' => (int)$size, 'temp_files' => []];
    $needsDecode = $mime === 'image/webp';

    if (extension_loaded('gd')) {
        // Guard memory before decoding (GD uses roughly 4-5 bytes per pixel).
        $available = ini_bytes((string)ini_get('memory_limit')) - memory_get_usage(true);
        if ($w * $h * 5 + 8388608 > $available) {
            throw new AppException('dimensions_too_large', 'The image is too large for this server to process.', 422);
        }
        $img = @imagecreatefromstring((string)file_get_contents($tmp));
        if ($img === false) {
            throw new AppException('invalid_image', 'This file is not a valid image or is corrupted.', 422);
        }
        if ($needsDecode) {
            // The provider's WebP support is not confirmed, so convert to PNG (keeps alpha).
            imagealphablending($img, false);
            imagesavealpha($img, true);
            $out = storage_dir('originals') . DIRECTORY_SEPARATOR . random_id() . '.png';
            if (!@imagepng($img, $out, 6)) {
                throw new AppException('storage_unavailable', 'The server could not prepare the image.', 500, 'webp->png failed');
            }
            $result['path'] = $out;
            $result['mime'] = 'image/png';
            $result['temp_files'][] = $out;
        }
        unset($img);
    } elseif ($needsDecode) {
        throw new AppException('unsupported_format', 'WebP is not supported on this server. Please upload a JPG or PNG image.', 415);
    }

    return $result;
}
