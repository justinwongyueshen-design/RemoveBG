# Deploy on cPanel shared hosting

1. **Upload**: put the project in a folder such as `public_html/removebg` (or your domain's document root). Use File Manager or FTP.
2. **PHP version**: cPanel > *MultiPHP Manager* > choose PHP 8.3+. In *MultiPHP INI Editor* / *Select PHP Version > Extensions* enable `curl`, `fileinfo`, `gd`, `mbstring`.
3. **Private files (strongly recommended)**: create `/home/CPANELUSER/removebg-storage` (outside `public_html`) and set `'storage_dir' => '/home/CPANELUSER/removebg-storage'`.
   The config is searched in this order: `removebg-config.php` two folders above the project, then one folder above, then `config/config.php`.
   Example: project in `/home/CPANELUSER/public_html/removebg` -> put `removebg-config.php` in `/home/CPANELUSER/` (outside the web root).
   If the project is the document root itself (`public_html`), the parent `/home/CPANELUSER/` is used. If you keep `config/config.php`, `config/.htaccess` blocks web access (verify in step 8).
4. **API key**: set `api_key` in the config file. Never place it in JS or HTML. Restrict file permissions to 640 or 600.
5. **Limits**: the bundled `.user.ini` sets `upload_max_filesize=12M`, `post_max_size=14M`, `max_execution_time=120` (applies on PHP-FPM/CGI; allow ~5 min to take effect). Or use MultiPHP INI Editor.
   Some hosts cap these globally; if so, lower `max_upload_bytes` to fit.
6. **Permissions**: folders 755 (or 750), files 644. `storage` (or your private storage dir) must be writable by your account.
7. **HTTPS**: *SSL/TLS Status* > run AutoSSL, then force HTTPS (cPanel *Domains > Force HTTPS Redirect*). The app sends HSTS only on HTTPS.
8. **Verify protection** (do not skip): these must all return 403/404:
   `/removebg/config/config.php`, `/removebg/storage/`, `/removebg/storage/logs/app.log`, `/removebg/includes/functions.php`, `/removebg/.user.ini`.
   If any is reachable, your host ignores `.htaccess`; move storage/config outside `public_html`.
9. **Test**: process an image, download a transparent PNG and a coloured one, then read `storage/logs/app.log` and cPanel *Errors*.
10. **Cron** (if available): cPanel > Cron Jobs, every 15 minutes:
    `/usr/local/bin/php /home/CPANELUSER/public_html/removebg/cron/cleanup.php >/dev/null 2>&1`
    Cleanup also runs opportunistically during normal traffic (at most every 5 minutes), so cron is optional.
11. Behind Cloudflare or a proxy? Set `'trusted_proxy_header'` (e.g. `HTTP_CF_CONNECTING_IP`) so rate limiting sees real visitor IPs.

Limitations: shared hosts may block outbound HTTPS, limit execution time/memory, or ignore `.htaccess`. No database is needed or used.
