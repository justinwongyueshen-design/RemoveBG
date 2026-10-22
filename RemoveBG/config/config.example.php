<?php
/**
 * RemoveBG configuration.
 *
 * 1. Copy this file to config/config.php  (or, better, to ../removebg-config.php
 *    one level ABOVE the project folder, outside the web root).
 * 2. Put your Pixelcut API key below.
 * 3. Never commit the real file. Never put the key in JavaScript or HTML.
 *
 * Every value can also be set with the environment variable shown in comments;
 * environment variables override this file.
 */
return [
    'app_env'  => 'production',                 // APP_ENV
    'provider' => 'pixelcut',                   // BACKGROUND_REMOVAL_PROVIDER
    'api_key'  => 'PASTE_YOUR_PIXELCUT_API_KEY_HERE', // BACKGROUND_REMOVAL_API_KEY
    'api_url'  => 'https://api.developer.pixelcut.ai/v1/remove-background', // BACKGROUND_REMOVAL_API_URL

    // 'binary' = ask the provider for the PNG bytes directly (recommended).
    // 'url'    = ask for JSON {"result_url": ...} and download it (only from hosts below).
    'response_mode'        => 'binary',
    'trusted_result_hosts' => ['pixelcut.app'], // exact host or any subdomain
    'api_timeout_seconds'  => 60,               // API_TIMEOUT_SECONDS
    'ca_bundle'            => '',               // path to cacert.pem if XAMPP/Windows cannot verify SSL

    // Upload limits (verify against Pixelcut's current limits before raising them)
    'max_upload_bytes'  => 10485760,            // MAX_UPLOAD_SIZE  (10 MB)
    'max_image_width'   => 6000,                // MAX_IMAGE_WIDTH
    'max_image_height'  => 6000,                // MAX_IMAGE_HEIGHT
    'max_image_pixels'  => 16000000,            // MAX_IMAGE_PIXELS (16 MP; keep GD memory in mind)
    'output_format'     => 'png',               // OUTPUT_FORMAT (png only)

    // Temporary storage. Prefer a folder OUTSIDE the web root, e.g.
    // 'C:/xampp/removebg-storage' or '/home/CPANELUSER/removebg-storage'.
    'storage_dir' => __DIR__ . '/../storage',
    'temp_file_retention_minutes' => 15,        // TEMP_FILE_RETENTION_MINUTES

    // Rate limit per visitor IP (file counters, no database)
    'rate_limit_max'            => 10,
    'rate_limit_window_seconds' => 600,

    // If you sit behind Cloudflare or a reverse proxy, name the header that holds
    // the real client IP (e.g. 'HTTP_CF_CONNECTING_IP'). Leave empty otherwise.
    'trusted_proxy_header' => '',

    // Optional 32+ character secret for signing download tokens. If empty, a random
    // one is generated once and stored in storage/.app_secret.
    'app_secret' => '',
];
