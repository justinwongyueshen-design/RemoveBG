<?php
declare(strict_types=1);

define('RBG_API', true);
require __DIR__ . '/config/bootstrap.php';

try {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
        header('Allow: GET');
        throw new AppException('method_not_allowed', 'Invalid request method.', 405);
    }
    $id = $_GET['id'] ?? '';
    $token = $_GET['t'] ?? '';
    if (!is_string($id) || !is_string($token)) {
        throw new AppException('invalid_token', 'This download link is not valid.', 403);
    }
    verify_download_token($id, $token);

    $path = storage_dir('processed') . DIRECTORY_SEPARATOR . $id . '.png'; // $id is validated hex only
    if (!is_file($path)) {
        throw new AppException('file_missing', 'This image is no longer available. Please process it again.', 404);
    }
    $consume = ($_GET['consume'] ?? '') === '1';
    $asAttachment = ($_GET['dl'] ?? '') === '1';
    $size = (int)filesize($path);

    ignore_user_abort(true);
    header('Content-Type: image/png');
    header('Content-Length: ' . $size);
    header('Content-Disposition: ' . ($asAttachment ? 'attachment' : 'inline') . '; filename="removebg-' . substr($id, 0, 8) . '.png"');
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    readfile($path);
    if ($consume) {
        @unlink($path); // single-use: the browser keeps its own copy for editing/downloading
    }
} catch (AppException $e) {
    json_error($e);
} catch (Throwable $t) {
    app_log('error', 'download unexpected ' . get_class($t) . ': ' . $t->getMessage());
    json_error(new AppException('server_error', 'Something went wrong on the server. Please try again.', 500));
}
