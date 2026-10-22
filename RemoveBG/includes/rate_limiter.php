<?php
declare(strict_types=1);

/** Fixed-window counter per visitor, stored in small files (no database). */
function rate_limit_check(string $bucket = 'process'): void
{
    $max = (int)cfg('rate_limit_max', 10);
    $window = max(1, (int)cfg('rate_limit_window_seconds', 600));
    if ($max <= 0) {
        return;
    }
    $key = hash_hmac('sha256', client_ip() . '|' . $bucket, app_secret()); // raw IPs are never stored
    $file = storage_dir('rate_limits') . DIRECTORY_SEPARATOR . $key . '.json';

    $fh = @fopen($file, 'c+');
    if (!$fh) {
        throw new AppException('storage_unavailable',
            'The server cannot use its temporary storage right now. Please contact the site owner.',
            500, 'rate limit file not writable');
    }
    flock($fh, LOCK_EX);
    $data = json_decode((string)stream_get_contents($fh), true);
    $now = time();
    if (!is_array($data) || ($now - (int)($data['start'] ?? 0)) >= $window) {
        $data = ['start' => $now, 'count' => 0];
    }
    if ((int)$data['count'] >= $max) {
        $retry = max(1, (int)$data['start'] + $window - $now);
        flock($fh, LOCK_UN);
        fclose($fh);
        throw new AppException('rate_limited',
            'Too many requests. Please wait about ' . max(1, (int)ceil($retry / 60)) . ' minute(s) and try again.',
            429, null, $retry);
    }
    $data['count'] = (int)$data['count'] + 1;
    ftruncate($fh, 0);
    rewind($fh);
    fwrite($fh, json_encode($data));
    fflush($fh);
    flock($fh, LOCK_UN);
    fclose($fh);
}
