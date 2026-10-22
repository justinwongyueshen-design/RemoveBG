<?php
declare(strict_types=1);

// Run from cron / Task Scheduler:  php /path/to/RemoveBG/cron/cleanup.php
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit;
}
require dirname(__DIR__) . '/config/bootstrap.php';
$removed = cleanup_expired(true);
echo 'RemoveBG cleanup: ' . $removed . " expired file(s) removed.\n";
