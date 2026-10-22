# Install on Windows 10/11 with XAMPP (no MySQL)

1. Install XAMPP with **PHP 8.2 or newer** (PHP 8.3+ recommended). You only need **Apache**; never start MySQL.
2. Copy the project to `C:\xampp\htdocs\RemoveBG`.
3. In `C:\xampp\php\php.ini` make sure these lines are enabled (no leading `;`), then restart Apache:
   `extension=curl`, `extension=fileinfo`, `extension=gd`, `extension=mbstring`.
   Check with `C:\xampp\php\php.exe -m`.
4. SSL certificates for cURL: download `cacert.pem` from curl.se/docs/caextract.html, save it as `C:\xampp\php\extras\ssl\cacert.pem`,
   and either set `curl.cainfo` in php.ini or set `'ca_bundle'` in the config. **Do not disable SSL verification.**
5. Copy `config\config.example.php` to `config\config.php` and set `api_key` (from the Pixelcut developer dashboard).
   Adjust `max_upload_bytes`, `max_image_*`, `api_timeout_seconds` if needed.
   Upload limits in php.ini (`upload_max_filesize`, `post_max_size`) must be at least slightly above `max_upload_bytes`; the bundled `.htaccess` sets 12M/14M.
   Make sure Apache has `AllowOverride All` for htdocs (default in XAMPP) so `.htaccess` is honoured.
6. Private storage (recommended): create `C:\xampp\removebg-storage` and set `'storage_dir' => 'C:/xampp/removebg-storage'`.
   Otherwise the default `RemoveBG\storage` is used and protected by `.htaccess`. Windows normally gives your user write access; if not,
   right-click the folder > Properties > Security and allow the Apache user (usually your account or SYSTEM) Modify.
7. Open `http://localhost/RemoveBG/`.
8. Upload a test image and press **Remove background**. Check `storage\logs\app.log` for a `processed` line.
9. Download the transparent PNG; export with White/Black/custom colour and verify in an image editor.
10. Verify cleanup: after processing, `storage\processed` is empty (consumed). Run `C:\xampp\php\php.exe C:\xampp\htdocs\RemoveBG\cron\cleanup.php`.
    Optional: schedule that command in Task Scheduler every 15 minutes.

## Troubleshooting
| Symptom | Fix |
|---|---|
| "not configured" | `config.php` missing or `api_key` still the placeholder |
| "secure connection" error / cURL 60 | add `cacert.pem` (step 4) |
| "rejected this server's credentials" | wrong/revoked API key |
| "usage limit" | Pixelcut quota exhausted |
| "took too long" | raise `api_timeout_seconds` and `max_execution_time` |
| "cannot use its temporary storage" | fix folder permissions / path in `storage_dir` |
| Upload fails above ~2 MB | `upload_max_filesize` / `post_max_size` too low in php.ini |
| Blank page | see `storage\logs\php-error.log` and Apache `error.log` |
