<?php
declare(strict_types=1);

define('RBG_API', true);
require __DIR__ . '/config/bootstrap.php';

$tempFiles = [];
$busy = false;
$service = null;
$response = null;
$error = null;

try {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        header('Allow: POST');
        throw new AppException('method_not_allowed', 'Invalid request method.', 405);
    }
    session_bind();
    csrf_verify();

    $length = (int)($_SERVER['CONTENT_LENGTH'] ?? 0);
    if ($length > (int)cfg('max_upload_bytes') + 1048576) {
        throw new AppException('file_too_large', 'The image is larger than the allowed upload size.', 413);
    }
    if ($length > 0 && empty($_FILES) && empty($_POST)) {
        // PHP discards the body when it exceeds post_max_size
        throw new AppException('file_too_large', 'The image is larger than the server allows.', 413);
    }

    rate_limit_check('process');
    busy_acquire();
    $busy = true;
    cleanup_expired();

    $service = BackgroundRemovalService::fromConfig();
    $image = $service->validateImage($_FILES['image'] ?? null);
    $tempFiles = $image['temp_files'];

    $started = microtime(true);
    $png = $service->removeBackground($image);
    [$id] = $service->saveProcessedImage($png);

    $ttl = max(1, (int)cfg('temp_file_retention_minutes', 15)) * 60;
    $token = make_download_token($id, $ttl);

    app_log('info', 'processed', [
        'ms' => (int)((microtime(true) - $started) * 1000),
        'in_bytes' => $image['size'],
        'out_bytes' => strlen($png),
        'w' => $image['width'],
        'h' => $image['height'],
    ]);

    $response = [
        'ok' => true,
        'job_id' => $id,
        'result_url' => 'download.php?id=' . $id . '&t=' . $token . '&consume=1',
        'expires_in' => $ttl,
    ];
} catch (AppException $e) {
    $error = $e;
    if ($e->logDetail !== null || $e->httpStatus >= 500) {
        app_log('error', $e->errorCode . ': ' . ($e->logDetail ?? $e->getMessage()));
    }
} catch (Throwable $t) {
    app_log('error', 'unexpected ' . get_class($t) . ': ' . $t->getMessage() . ' @ ' . basename($t->getFile()) . ':' . $t->getLine());
    $error = new AppException('server_error', 'Something went wrong on the server. Please try again.', 500);
} finally {
    if ($service !== null) {
        $service->deleteTemporaryFiles($tempFiles);
    }
    if ($busy) {
        busy_release();
    }
}

if ($error !== null) {
    json_error($error);
}
json_out(200, $response);
