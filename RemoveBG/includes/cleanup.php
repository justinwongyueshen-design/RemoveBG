<?php
declare(strict_types=1);

function rbg_sweep(string $dir, int $maxAgeSeconds): int
{
    $removed = 0;
    $items = @scandir($dir);
    if ($items === false) {
        return 0;
    }
    $now = time();
    foreach ($items as $name) {
        if ($name[0] === '.' || $name === 'index.html') {
            continue;
        }
        $path = $dir . DIRECTORY_SEPARATOR . $name;
        if (is_file($path) && ($now - (int)@filemtime($path)) > $maxAgeSeconds && @unlink($path)) {
            $removed++;
        }
    }
    return $removed;
}

/**
 * Deletes expired temporary files. Returns how many files were removed.
 * Without $force it runs at most once every 5 minutes (opportunistic cleanup).
 */
function cleanup_expired(bool $force = false): int
{
    try {
        $marker = storage_dir() . DIRECTORY_SEPARATOR . '.last_cleanup';
        if (!$force && is_file($marker) && (time() - (int)filemtime($marker)) < 300) {
            return 0;
        }
        @touch($marker);

        $retention = max(1, (int)cfg('temp_file_retention_minutes', 15)) * 60;
        $orphanAge = max(300, (int)cfg('api_timeout_seconds', 60) + 120);
        $window = max(60, (int)cfg('rate_limit_window_seconds', 600));

        $n  = rbg_sweep(storage_dir('processed'), $retention);
        $n += rbg_sweep(storage_dir('originals'), $orphanAge);
        $n += rbg_sweep(storage_dir('rate_limits'), $window * 2);

        // Keep logs from growing forever.
        $log = storage_dir('logs') . DIRECTORY_SEPARATOR . 'app.log';
        if (is_file($log) && filesize($log) > 2097152) {
            @rename($log, $log . '.1');
        }
        return $n;
    } catch (Throwable $e) {
        error_log('[removebg] cleanup failed: ' . $e->getMessage());
        return 0;
    }
}
