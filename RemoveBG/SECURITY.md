# Security notes

| Area | Control |
|---|---|
| API key | Server-side only (config file or env var); never in HTML/JS; never logged; error messages never include provider bodies to visitors |
| Uploads | upload error checks, `is_uploaded_file`, size cap, `finfo` MIME allow-list, `getimagesize` type match, dimension/pixel caps, memory guard, GD decode; original filename never used |
| Files | random 128-bit hex names, regex-validated ids, no user-supplied paths, private dirs denied by `.htaccess` (prefer storage outside web root) |
| Downloads | HMAC-signed token with expiry, bound to the session; single-use (`consume=1`); `nosniff`, `no-store` |
| SSRF | provider URL must be https; `result_url` (url mode) only from `trusted_result_hosts`, https/443, no credentials, no redirects, API key never sent there |
| CSRF | per-session token in `X-CSRF-Token` header required by `process.php` |
| Abuse | file-based fixed-window rate limit (HMAC of IP), session busy flag, request-size checks, API timeouts, response-size cap |
| Headers | CSP (self + jsdelivr + cdnjs), nosniff, frame deny, referrer policy, HSTS on HTTPS |
| Errors | `display_errors` off; details go to `storage/logs`; users get generic messages |
| Output | all server-rendered values escaped with `h()`; JS writes error text via `textContent` |

Known gaps: CDN assets have no SRI hashes (add them for your pinned versions); the session cookie uses PHP's default file sessions;
Pixelcut handles your users' images under its own policy; this code has not been penetration-tested or even executed (see README).
If an API key was ever pasted into chat, email or a repo, **revoke it and create a new one**.
