# RemoveBG - AI Background Removal (no SQL database)

Upload an image, remove its background with a real AI service (Pixelcut), compare before/after,
pick a background colour and download a transparent PNG or a PNG with the colour baked in.

**No database of any kind.** No MySQL, MariaDB, SQLite, PDO, accounts, admin panel or history.
State is limited to short-lived files: a processed PNG (deleted when fetched, or after 15 min),
tiny rate-limit counters, logs, and PHP's normal session cookie (CSRF + download binding).

Stack: PHP 8.2+ (PHP 8.3+ recommended), HTML/CSS, vanilla JS, Bootstrap 5, Font Awesome (both from CDN), cURL, GD (recommended), Canvas API.

## Provider
**Pixelcut** Remove Background - `POST https://api.developer.pixelcut.ai/v1/remove-background`
- Auth: `X-API-Key` header (sent server-side only).
- Request: multipart field `image` (used here, so it works from localhost) or JSON `image_url`.
- Response: `Accept: image/*` returns PNG bytes (default here); `Accept: application/json` returns `{"result_url": ...}`
  (set `response_mode` to `url`; the URL is only fetched from `trusted_result_hosts`, and result URLs are time-limited).
- Documented error codes handled: malformed_image, unsupported_image_format, file_size_too_large, resolution_too_high, unknown_foreground; plus HTTP 401/403/402/429/5xx.
- Pixelcut's exact file-size/resolution limits and pricing were not published in the pages I could read. Check your dashboard and set `max_upload_bytes` / `max_image_*` accordingly.
- WebP is converted to PNG by GD before sending (provider WebP support was not confirmed).

Provider code lives in `services/providers/PixelcutProvider.php`; add another class implementing
`BackgroundRemovalProvider` and register it in `BackgroundRemovalService::fromConfig()`.

## Configuration
Copy `config/config.example.php` to `config/config.php` (or to `removebg-config.php` one or two folders above the project, outside the web root, which takes priority)
and set `api_key`. Environment variables override the file: `APP_ENV`, `BACKGROUND_REMOVAL_PROVIDER`, `BACKGROUND_REMOVAL_API_KEY`,
`BACKGROUND_REMOVAL_API_URL`, `MAX_UPLOAD_SIZE`, `MAX_IMAGE_WIDTH`, `MAX_IMAGE_HEIGHT`, `MAX_IMAGE_PIXELS`, `OUTPUT_FORMAT`,
`TEMP_FILE_RETENTION_MINUTES`, `API_TIMEOUT_SECONDS`. No `.env` loader is included (so no `.env.example`).

## Structure
```
index.php            landing page + tool UI
process.php          POST: CSRF -> rate limit -> validate -> provider -> store -> signed single-use link (upload+process merged)
download.php         GET: verifies expiring HMAC token bound to the browser session, streams PNG, deletes it if consume=1
config/              bootstrap.php, config.example.php
includes/            functions, csrf, image_validator, rate_limiter, cleanup
services/            BackgroundRemovalService + providers/PixelcutProvider
assets/              css/style.css, js/app.js
storage/             originals/ processed/ rate_limits/ logs/ (created at runtime; move outside web root if possible)
cron/cleanup.php     CLI cleanup for cron / Task Scheduler
```

## How the pieces behave
- Validation (server): upload error codes, `is_uploaded_file`, size, `finfo` MIME, `getimagesize` type match, dimension + pixel limits, memory guard, GD decode.
- Provider output is verified as a real PNG (signature, header, decode) before being saved. Failures return JSON errors; nothing fake is ever returned.
- Duplicate requests: button lock in the browser plus a per-session busy flag on the server (HTTP 409).
- Rate limit: file counters keyed by an HMAC of the IP (raw IPs are not stored). Default 10 per 10 minutes.
- Cleanup: opportunistic (at most every 5 min, during normal requests) plus `cron/cleanup.php`.
- Export with a background composites on a canvas, so the colour is in the file's pixels. Images above 16.7 MP are downscaled for that export (Safari canvas limit).

## Verification status - read this
- Not executed: this build environment had **no PHP and no network**, so the PHP code has **not been run or linted**, and **no live Pixelcut request has been made**. Only `app.js` was syntax-checked (node) and PHP files got a rough bracket-balance check.
- Treat the first run as a test: work through the checklist below and expect to fix small issues.
- Do not consider live AI processing working until step 5 below succeeds.

## Testing checklist
1. `php -l` on every `.php` file (`for f in $(find . -name "*.php"); do php -l $f; done`).
2. Landing page loads; mobile width layout OK.
3. Upload a .txt renamed to .png -> rejected. Upload a truncated JPG -> rejected. Upload > limit -> rejected. Upload oversized dimensions -> rejected.
4. With no API key -> friendly "not configured" error (no key/paths shown).
5. With a real key: upload a photo -> cutout appears; check `storage/logs/app.log` shows `processed`.
6. Wrong key -> "rejected credentials" message; unplug network -> network message.
7. Download transparent PNG; open it in an editor and confirm alpha. Export White/Black/custom and confirm pixel colour.
8. Click Remove twice quickly -> only one request. Send 11 requests in 10 min -> 429.
9. Open the result link twice (consume=1) -> second gives "no longer available". Edit the token -> 403. Wait past retention -> 410/404.
10. Browse to `/storage/`, `/config/config.php`, `/includes/functions.php` -> all 403/404.
11. `php cron/cleanup.php` prints a count; old files vanish.
12. `grep -ri "pdo\|mysql\|sqlite" .` returns nothing relevant.
