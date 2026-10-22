<?php
declare(strict_types=1);

if (PHP_VERSION_ID < 80200) {
    http_response_code(500);
    exit('RemoveBG requires PHP 8.2 or newer.');
}

define('APP_ROOT', dirname(__DIR__));

require_once APP_ROOT . '/includes/functions.php';
require_once APP_ROOT . '/includes/csrf.php';
require_once APP_ROOT . '/includes/image_validator.php';
require_once APP_ROOT . '/includes/rate_limiter.php';
require_once APP_ROOT . '/includes/cleanup.php';
require_once APP_ROOT . '/services/BackgroundRemovalService.php';
require_once APP_ROOT . '/services/providers/PixelcutProvider.php';

$GLOBALS['rbg_config'] = rbg_load_config();

ini_set('display_errors', '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);
try {
    ini_set('error_log', storage_dir('logs') . DIRECTORY_SEPARATOR . 'php-error.log');
} catch (Throwable $e) {
    // fall back to the server's default error log
}

if (PHP_SAPI !== 'cli') {
    send_security_headers();
    if (session_status() === PHP_SESSION_NONE) {
        session_name('rbgsid');
        session_set_cookie_params([
            'lifetime' => 0,
            'path'     => '/',
            'secure'   => is_https(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        @session_start();
    }
}
