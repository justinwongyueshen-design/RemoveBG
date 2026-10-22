<?php
declare(strict_types=1);

/**
 * Pixelcut Remove Background API (https://www.pixelcut.ai/docs/developer-guide).
 * POST {api_url}  header X-API-Key  multipart field "image" (or JSON image_url).
 * Accept: image/*  -> PNG bytes     Accept: application/json -> {"result_url": "..."}
 */
final class PixelcutProvider implements BackgroundRemovalProvider
{
    public function __construct(
        private string $apiKey,
        private string $apiUrl,
        private int $timeout,
        private string $mode,
        private array $trustedHosts,
        private int $maxResultBytes,
        private string $caBundle = ''
    ) {
    }

    public function removeBackground(string $path, string $mime): string
    {
        if ($this->apiKey === '' || stripos($this->apiKey, 'PASTE_') === 0) {
            throw new AppException('not_configured',
                'Background removal is not configured on this server yet. Please contact the site owner.',
                503, 'API key missing');
        }
        $this->assertHttps($this->apiUrl);
        if (!function_exists('curl_init')) {
            throw new AppException('not_configured', 'This server is missing a required component. Please contact the site owner.',
                503, 'cURL extension missing');
        }

        $post = [
            'image'  => new CURLFile($path, $mime, $mime === 'image/png' ? 'image.png' : 'image.jpg'),
            'format' => 'png',
        ];
        $accept = $this->mode === 'url' ? 'application/json' : 'image/*';
        $response = $this->http($this->apiUrl, ['X-API-Key: ' . $this->apiKey, 'Accept: ' . $accept], $post);
        return $this->processApiResponse($response);
    }

    /** Turns a provider response into verified PNG bytes. */
    public function processApiResponse(array $res): string
    {
        $status = $res['status'];
        if ($status < 200 || $status >= 300) {
            throw $this->handleProviderError($status, $res['body']);
        }
        $type = strtolower($res['headers']['content-type'] ?? '');
        $body = $res['body'];

        if (str_starts_with($type, 'application/json') || ($body !== '' && ltrim($body)[0] === '{')) {
            $json = json_decode($body, true);
            $url = is_array($json) ? ($json['result_url'] ?? null) : null;
            if (!is_string($url) || $url === '') {
                throw new AppException('invalid_provider_response', 'The background-removal service returned an unexpected response.',
                    502, 'JSON without result_url');
            }
            $body = $this->downloadResult($url);
        }
        return $this->verifyPng($body);
    }

    /** Maps provider failures to safe, user-friendly errors. Never echoes the provider body. */
    public function handleProviderError(int $status, string $body): AppException
    {
        $detail = 'provider HTTP ' . $status . ': ' . substr(preg_replace('/\s+/', ' ', $body) ?? '', 0, 300);
        $lower = strtolower($body);

        $known = [
            'malformed_image'         => ['invalid_image', 'The background-removal service could not read this image.', 422],
            'unsupported_image_format'=> ['unsupported_format', 'The background-removal service does not support this image format.', 415],
            'file_size_too_large'     => ['file_too_large', 'The image is too large for the background-removal service.', 413],
            'file size too large'     => ['file_too_large', 'The image is too large for the background-removal service.', 413],
            'resolution_too_high'     => ['dimensions_too_large', 'The image resolution is too high for the background-removal service.', 422],
            'unknown_foreground'      => ['no_subject', 'No clear subject was detected in this image. Try a different image.', 422],
        ];
        foreach ($known as $needle => [$code, $msg, $http]) {
            if (str_contains($lower, $needle)) {
                return new AppException($code, $msg, $http, $detail);
            }
        }
        if ($status === 401 || $status === 403) {
            return new AppException('provider_auth',
                'The background-removal service rejected this server\'s credentials. Please contact the site owner.', 502, $detail);
        }
        if ($status === 402 || str_contains($lower, 'quota') || str_contains($lower, 'credit')) {
            return new AppException('provider_quota',
                'The background-removal service has reached its usage limit. Please try again later.', 503, $detail);
        }
        if ($status === 429) {
            return new AppException('provider_rate_limited',
                'The background-removal service is busy. Please wait a moment and try again.', 429, $detail, 30);
        }
        if ($status >= 500) {
            return new AppException('provider_error',
                'The background-removal service had a problem. Please try again.', 502, $detail);
        }
        return new AppException('provider_rejected',
            'The background-removal service could not process this image.', 502, $detail);
    }

    private function verifyPng(string $bytes): string
    {
        $bad = fn(string $why) => new AppException('invalid_provider_image',
            'The background-removal service returned an invalid image. Please try again.', 502, $why);

        if (strlen($bytes) < 67 || substr($bytes, 0, 8) !== "\x89PNG\r\n\x1a\n") {
            throw $bad('result is not a PNG');
        }
        $info = @getimagesizefromstring($bytes);
        if ($info === false || $info[2] !== IMAGETYPE_PNG || $info[0] < 1 || $info[1] < 1 || $info[0] > 20000 || $info[1] > 20000) {
            throw $bad('result PNG header invalid');
        }
        if (extension_loaded('gd')) {
            $avail = ini_bytes((string)ini_get('memory_limit')) - memory_get_usage(true);
            if ($info[0] * $info[1] * 5 + 8388608 < $avail && @imagecreatefromstring($bytes) === false) {
                throw $bad('result PNG failed to decode');
            }
        }
        $colourType = ord($bytes[25] ?? "\0");
        if (!in_array($colourType, [4, 6], true) && !str_contains(substr($bytes, 0, 4096), 'tRNS')) {
            app_log('warning', 'provider PNG has no alpha channel', ['colour_type' => $colourType]);
        }
        return $bytes;
    }

    private function downloadResult(string $url): string
    {
        $p = parse_url($url);
        $host = strtolower((string)($p['host'] ?? ''));
        if (($p['scheme'] ?? '') !== 'https' || $host === '' || isset($p['user']) || isset($p['pass'])
            || (isset($p['port']) && (int)$p['port'] !== 443)) {
            throw new AppException('invalid_provider_response', 'The background-removal service returned an unexpected response.',
                502, 'result_url rejected (scheme/host)');
        }
        $trusted = false;
        foreach ($this->trustedHosts as $t) {
            $t = strtolower(trim((string)$t));
            if ($t !== '' && ($host === $t || str_ends_with($host, '.' . $t))) {
                $trusted = true;
                break;
            }
        }
        if (!$trusted) {
            throw new AppException('invalid_provider_response', 'The background-removal service returned an unexpected response.',
                502, 'result_url host not trusted: ' . $host);
        }
        // No API key is sent to the result host.
        $res = $this->http($url, ['Accept: image/*'], null);
        if ($res['status'] < 200 || $res['status'] >= 300) {
            throw new AppException('provider_error', 'The processed image could not be retrieved. Please try again.',
                502, 'result download HTTP ' . $res['status']);
        }
        return $res['body'];
    }

    private function assertHttps(string $url): void
    {
        if (strtolower((string)parse_url($url, PHP_URL_SCHEME)) !== 'https') {
            throw new AppException('not_configured', 'Background removal is not configured correctly. Please contact the site owner.',
                503, 'api_url must use https');
        }
    }

    /** @return array{status:int,headers:array<string,string>,body:string} */
    private function http(string $url, array $headers, ?array $post): array
    {
        $body = '';
        $respHeaders = [];
        $max = $this->maxResultBytes;
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT        => $this->timeout,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_WRITEFUNCTION  => function ($ch, $chunk) use (&$body, $max) {
                $body .= $chunk;
                return strlen($body) > $max ? 0 : strlen($chunk);
            },
            CURLOPT_HEADERFUNCTION => function ($ch, $line) use (&$respHeaders) {
                $parts = explode(':', $line, 2);
                if (count($parts) === 2) {
                    $respHeaders[strtolower(trim($parts[0]))] = trim($parts[1]);
                }
                return strlen($line);
            },
        ]);
        if ($post !== null) {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $post);
        }
        if ($this->caBundle !== '') {
            curl_setopt($ch, CURLOPT_CAINFO, $this->caBundle);
        }

        $ok = curl_exec($ch);
        $errno = curl_errno($ch);
        $error = curl_error($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);

        if ($ok === false) {
            $detail = 'cURL error ' . $errno . ': ' . $error;
            if ($errno === 28) {
                throw new AppException('provider_timeout', 'The background-removal service took too long to respond. Please try again.', 504, $detail);
            }
            if ($errno === 60 || $errno === 77 || $errno === 35 || $errno === 51 || $errno === 58 || $errno === 83) {
                throw new AppException('network_error', 'The server could not make a secure connection to the background-removal service. Please contact the site owner.', 502, $detail);
            }
            if ($errno === 23) {
                throw new AppException('invalid_provider_response', 'The background-removal service returned an unexpectedly large response.', 502, $detail);
            }
            throw new AppException('network_error', 'The server could not reach the background-removal service. Please try again.', 502, $detail);
        }
        return ['status' => $status, 'headers' => $respHeaders, 'body' => $body];
    }
}
