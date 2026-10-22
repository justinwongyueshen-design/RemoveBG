<?php
declare(strict_types=1);

/** Error that is safe to show to the visitor (message) with a machine code. */
final class AppException extends RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        string $userMessage,
        public readonly int $httpStatus = 400,
        public readonly ?string $logDetail = null,
        public readonly ?int $retryAfter = null
    ) {
        parent::__construct($userMessage);
    }
}

function rbg_defaults(): array
{
    return [
        'app_env' => 'production',
        'provider' => 'pixelcut',
        'api_key' => '',
        'api_url' => 'https://api.developer.pixelcut.ai/v1/remove-background',
        'response_mode' => 'binary',
        'trusted_result_hosts' => ['pixelcut.app'],
        'api_timeout_seconds' => 60,
        'ca_bundle' => '',
        'max_upload_bytes' => 10485760,
        'max_image_width' => 6000,
        'max_image_height' => 6000,
        'max_image_pixels' => 16000000,
        'max_result_bytes' => 52428800,
        'output_format' => 'png',
        'storage_dir' => APP_ROOT . '/storage',
        'temp_file_retention_minutes' => 15,
        'rate_limit_max' => 10,
        'rate_limit_window_seconds' => 600,
        'trusted_proxy_header' => '',
        'app_secret' => '',
    ];
}

function rbg_load_config(): array
{
    $cfg = rbg_defaults();
    $candidates = [dirname(APP_ROOT, 2) . '/removebg-config.php', dirname(APP_ROOT) . '/removebg-config.php', APP_ROOT . '/config/config.php'];
    foreach ($candidates as $file) {
        if (is_file($file)) {
            $loaded = require $file;
            if (is_array($loaded)) {
                $cfg = array_replace($cfg, $loaded);
            }
            break;
        }
    }
    $env = [
        'APP_ENV' => 'app_env',
        'BACKGROUND_REMOVAL_PROVIDER' => 'provider',
        'BACKGROUND_REMOVAL_API_KEY' => 'api_key',
        'BACKGROUND_REMOVAL_API_URL' => 'api_url',
        'MAX_UPLOAD_SIZE' => 'max_upload_bytes',
        'MAX_IMAGE_WIDTH' => 'max_image_width',
        'MAX_IMAGE_HEIGHT' => 'max_image_height',
        'MAX_IMAGE_PIXELS' => 'max_image_pixels',
        'OUTPUT_FORMAT' => 'output_format',
        'TEMP_FILE_RETENTION_MINUTES' => 'temp_file_retention_minutes',
        'API_TIMEOUT_SECONDS' => 'api_timeout_seconds',
    ];
    foreach ($env as $name => $key) {
        $v = getenv($name);
        if ($v !== false && $v !== '') {
            $cfg[$key] = is_int(rbg_defaults()[$key]) ? (int)$v : $v;
        }
    }
    if ($cfg['output_format'] !== 'png') {
        $cfg['output_format'] = 'png'; // only transparent PNG is supported
    }
    return $cfg;
}

function cfg(string $key, mixed $default = null): mixed
{
    return $GLOBALS['rbg_config'][$key] ?? $default;
}

function h(?string $s): string
{
    return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function is_https(): bool
{
    return (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off')
        || (($_SERVER['SERVER_PORT'] ?? '') === '443')
        || (strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https');
}

function send_security_headers(): void
{
    if (headers_sent()) {
        return;
    }
    header("Content-Security-Policy: default-src 'self'; script-src 'self' https://cdn.jsdelivr.net; "
        . "style-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net https://cdnjs.cloudflare.com; "
        . "font-src 'self' https://cdnjs.cloudflare.com; img-src 'self' data: blob:; connect-src 'self'; "
        . "object-src 'none'; base-uri 'self'; form-action 'self'; frame-ancestors 'none'");
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
    if (is_https()) {
        header('Strict-Transport-Security: max-age=31536000');
    }
}

function json_out(int $status, array $payload, array $extraHeaders = []): never
{
    if (!headers_sent()) {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        foreach ($extraHeaders as $k => $v) {
            header($k . ': ' . $v);
        }
    }
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function json_error(AppException $e): never
{
    $headers = $e->retryAfter !== null ? ['Retry-After' => (string)$e->retryAfter] : [];
    json_out($e->httpStatus, [
        'ok' => false,
        'error' => ['code' => $e->errorCode, 'message' => $e->getMessage()],
    ], $headers);
}

function storage_dir(string $sub = ''): string
{
    $base = rtrim((string)cfg('storage_dir'), '/\\');
    $dir = $sub === '' ? $base : $base . DIRECTORY_SEPARATOR . $sub;
    if (!is_dir($dir) && !@mkdir($dir, 0750, true) && !is_dir($dir)) {
        throw new AppException('storage_unavailable',
            'The server cannot use its temporary storage right now. Please contact the site owner.',
            500, 'cannot create storage directory');
    }
    if (!is_writable($dir)) {
        throw new AppException('storage_unavailable',
            'The server cannot use its temporary storage right now. Please contact the site owner.',
            500, 'storage directory not writable');
    }
    return $dir;
}

function app_log(string $level, string $message, array $context = []): void
{
    $message = str_replace(["\r", "\n"], ' ', $message);
    $line = gmdate('c') . ' [' . $level . '] ' . $message;
    if ($context) {
        $line .= ' ' . json_encode($context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
    try {
        @file_put_contents(storage_dir('logs') . DIRECTORY_SEPARATOR . 'app.log', $line . "\n", FILE_APPEND | LOCK_EX);
    } catch (Throwable $e) {
        error_log('[removebg] ' . $line);
    }
}

function random_id(): string
{
    return bin2hex(random_bytes(16));
}

function is_valid_id(mixed $id): bool
{
    return is_string($id) && preg_match('/^[a-f0-9]{32}$/', $id) === 1;
}

function client_ip(): string
{
    $hdr = (string)cfg('trusted_proxy_header', '');
    if ($hdr !== '' && !empty($_SERVER[$hdr])) {
        $ip = trim(explode(',', (string)$_SERVER[$hdr])[0]);
        if (filter_var($ip, FILTER_VALIDATE_IP)) {
            return $ip;
        }
    }
    return (string)($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
}

function app_secret(): string
{
    static $secret = null;
    if ($secret !== null) {
        return $secret;
    }
    $configured = (string)cfg('app_secret', '');
    if (strlen($configured) >= 32) {
        return $secret = $configured;
    }
    $file = storage_dir() . DIRECTORY_SEPARATOR . '.app_secret';
    if (!is_file($file)) {
        $fh = @fopen($file, 'x');
        if ($fh) {
            fwrite($fh, bin2hex(random_bytes(32)));
            fclose($fh);
            @chmod($file, 0600);
        }
    }
    $value = trim((string)@file_get_contents($file));
    if (strlen($value) < 32) {
        usleep(100000);
        $value = trim((string)@file_get_contents($file));
    }
    if (strlen($value) < 32) {
        throw new AppException('storage_unavailable',
            'The server cannot use its temporary storage right now. Please contact the site owner.',
            500, 'cannot create app secret');
    }
    return $secret = $value;
}

/** Random value tied to this browser session; included in download tokens. */
function session_bind(): string
{
    if (session_status() === PHP_SESSION_ACTIVE && empty($_SESSION['rbg_bind'])) {
        $_SESSION['rbg_bind'] = bin2hex(random_bytes(16));
    }
    return (string)($_SESSION['rbg_bind'] ?? '');
}

function make_download_token(string $id, int $ttlSeconds): string
{
    $exp = time() + $ttlSeconds;
    return $exp . '.' . hash_hmac('sha256', $id . '|' . $exp . '|' . session_bind(), app_secret());
}

function verify_download_token(string $id, string $token): void
{
    if (!is_valid_id($id) || !preg_match('/^(\d{10})\.([a-f0-9]{64})$/', $token, $m)) {
        throw new AppException('invalid_token', 'This download link is not valid.', 403);
    }
    $expected = hash_hmac('sha256', $id . '|' . $m[1] . '|' . session_bind(), app_secret());
    if (!hash_equals($expected, $m[2])) {
        throw new AppException('invalid_token', 'This download link is not valid.', 403);
    }
    if ((int)$m[1] < time()) {
        throw new AppException('token_expired', 'This download link has expired. Please process the image again.', 410);
    }
}

function busy_acquire(): void
{
    $now = time();
    $stale = (int)cfg('api_timeout_seconds', 60) + 30;
    if (isset($_SESSION['rbg_busy']) && ($now - (int)$_SESSION['rbg_busy']) < $stale) {
        throw new AppException('busy', 'An image is already being processed. Please wait for it to finish.', 409);
    }
    $_SESSION['rbg_busy'] = $now;
    session_write_close(); // release the session lock so a duplicate request can see the flag
}

function busy_release(): void
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        @session_start();
    }
    unset($_SESSION['rbg_busy']);
    session_write_close();
}
