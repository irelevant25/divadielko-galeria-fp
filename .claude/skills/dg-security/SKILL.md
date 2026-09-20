---
name: dg-security
description: Security and privacy model of the Divadielko Galéria site and the checklist for reviewing changes against it — output escaping and the rich-text allow-list, SQL and the entities.php whitelist, CSRF, roles (redaktor vs administrátor), sessions and the no-cookie promise, login throttling, upload validation, security headers and CSP, redirects, mail header safety, IP anonymisation, secrets, folder protection (.htaccess and router.php), backups. Use whenever a change touches login.php, api.php, admin.php POST actions, includes/auth.php, media.php, mail.php, bootstrap.php headers, .htaccess or router.php; whenever user input reaches SQL, HTML, a file path, a header or a redirect; when adding an API action, admin action, upload type or third-party embed; and for any "is this secure", "security review", "skontroluj bezpečnosť", "môže toto niekto zneužiť" request. Lists known accepted limitations so they are not re-reported or "fixed" blindly.
---

# Security and privacy

Small site, two or three trusted editors, but it is public, it accepts uploads and it stores messages
from the public. The protections below were verified end-to-end (hostile requests against a test
copy); the smoke suite re-checks most of them. When you change any of these areas, keep the invariant
and keep the test.

## Invariants

### Output
- Every dynamic value in HTML goes through `e()`. Multi-line plain text → `paragraphs()` (escapes, then
  adds `<p>` / `<br>`). File names → `media_url()` (`rawurlencode`). JSON inside an attribute →
  `e(json_encode(…))`; JSON inside a `<script type="application/…json">` block → `json_encode` with
  `JSON_HEX_TAG` so `</script>` cannot appear.
- The only user-authored HTML is the `richtext` field. `rich_html()` does not strip — it **rebuilds**
  the markup from an allow-list (`p br strong em a ul ol li`; `href` only `https? mailto tel # /path`),
  so unknown tags, all attributes, handlers and `javascript:` disappear by construction. It runs on
  save *and* on output. `cms.js` mirrors the list (`RTE_KEEP`, `RTE_HREF`) — change both or neither.

### SQL
- Values are always `?` parameters (`db_all / db_one / db_value / db_exec`).
- Identifiers are interpolated in a few places (`"SELECT * FROM $entity"`). That is safe only because
  `cms_def($entity)` has already checked the name against `entities.php`, and column names come from
  its field list. Any new code path must call `cms_def()` first; never build an identifier from input.
- `cms_save()` writes only fields described in `entities.php` — extra keys in the request
  (`deleted_at`, `sort`, `id`, `password_hash`…) are ignored. Do not add "generic" setters that bypass it.

### CSRF
- One token per session (`csrf_token()`); HTML forms send it as `csrf`, `cms.js` as `X-CSRF-Token`;
  `csrf_valid()` compares with `hash_equals`. Every state-changing request checks it — including
  logout (POST only) and the login form itself.
- The public contact form cannot use a session (that would set a cookie). It carries a signed
  timestamp instead (`contact_form_token()`), plus honeypot, a 3-second minimum, a 6-hour maximum and
  5 messages per hour per IP hash.
- GET never changes state. (`setup.php?key=…` is the documented exception: it applies migrations.)

### Who may do what
- `api.php`: everything except `action=contact` requires `current_user()` → otherwise 401.
- `admin.php`: every admin-only action starts with `$admin || forbid();` → 403. Today that is: delete
  message, delete file, purge from archive, all user management, all backup actions and download,
  site mode. Editors may: edit content, upload, read and triage messages, restore from archive,
  reorder sections, download originals, change their own password. **A new action needs a conscious
  decision and, if admin-only, the guard as its first line** — the smoke suite has a `roles` section; add the action to its list.
- An admin cannot delete, deactivate or demote themself (no lock-out).
- Backups contain password hashes and visitors' messages → admin-only, never web-reachable
  (`storage/.htaccess`), names validated by `BACKUP_FILE_PATTERN` before touching the file system.

### Sessions, login, cookies
- **Visitors never get a cookie** — this is why the site has no cookie banner. `current_user()` opens
  a session only when the `dg_session` cookie already exists; only `/login.php` creates one. Anything
  that calls `session_start_secure()` or `csrf_token()` on a public page breaks the promise (the smoke
  suite asserts no `Set-Cookie` for visitors).
- Cookie: HttpOnly, SameSite=Lax, Secure under HTTPS (also detected via `X-Forwarded-Proto`), strict
  mode, id regenerated on login, 8 h idle timeout, role and `active` re-read from the DB every request.
- Login: generic failure message, dummy `password_verify` for unknown users (timing), 8 failures per
  15 min per IP hash, passwords ≥ 10 characters, rehash on login when the algorithm changes.
  Passwords are never logged — `login_attempts` stores the attempted *username* only.
- `next` after login must match `^/(?!/)[A-Za-z0-9._/?=&#%-]*$` — own paths only. Never redirect to a
  URL taken from input anywhere else either.

### Uploads and files
- Extension allow-list `MEDIA_TYPES` (no SVG, no HTML); content must match: `finfo` MIME + for images
  `getimagesize`. The stored name is a fresh slug (`media_slug` + `media_unique_base`) — the client's
  file name is never used as a path. Any file name that arrives in a request later goes through
  `media_safe_name()` (base name only, safe characters, allowed extension) and an `is_file` check
  inside `assets/`.
- Images are re-encoded to AVIF, which drops metadata and any smuggled payload. Originals go to
  `assets_original/`, which is never served; logged-in users download them through `admin.php?download=`.
- `assets/.htaccess` refuses script-like extensions and sets `nosniff`; nothing in `assets/` executes.
- Upload chunks are keyed by user id + a random 32-hex id, sizes are checked against the declared
  size and `upload.max_size`, stale `.part` files are purged after a day.

### Headers
- `security_headers()`: CSP (`default-src 'self'`, scripts only self + Umami host, frames only
  youtube-nocookie, `object-src 'none'`, `base-uri 'self'`, `form-action 'self'`, `frame-ancestors 'self'`),
  `nosniff`, `X-Frame-Options`, `Referrer-Policy`. `private_headers()` adds `noindex` + `no-store` for
  login, admin, API and setup. Widening the CSP is a privacy decision for the user, not a fix.
- Placeholders answer 503 + `Retry-After` so search engines do not index them as the site.

### Mail
- User input reaches only the body and `Reply-To`. Header values pass `mail_header_safe()` (no CR/LF),
  display names `mail_name()` (quoted or RFC 2047), subjects `mail_encode()`. The recipient is never
  taken from the request. The message is saved to the database first; mail is best-effort.

### Privacy
- IP addresses are stored only as `ip_hash()` = HMAC with the install's `secret`.
- No third-party requests until a visitor plays a YouTube video (posters are downloaded to `assets/`
  at save time; the iframe uses `youtube-nocookie.com` and is created on click). Analytics is
  self-hosted, cookieless Umami, skipped for logged-in users and localhost.

### Secrets and folders
- Secrets live only in `includes/config.local.php` (git-ignored): `secret`, DB password, `setup_key`.
  Never print them, never copy them into the repository, tests or skills. The origin server address
  and SFTP details stay out of the repository as well — it is public on GitHub and the origin hides behind Cloudflare.
- `includes/`, `pages/`, `storage/`, `assets_original/` are closed twice: their own `.htaccess`
  (`Require all denied`) and rewrite rules in the root `.htaccess` (also dotfiles, `*.md`, `*.sql`, `router.php`).
  **`router.php` re-implements those rules for `php -S`, and the smoke suite tests `router.php`, not
  Apache** — change both files together, and remember that a green smoke run says nothing about a typo in `.htaccess`.
- Errors: log with `error_log('[module] …')`, show a translated generic message. Exception text goes
  to users only in admin-only flows (backup / migration failures), where it is the point.

## Known and accepted — do not re-report, do not "fix" without asking

| Limitation | Why it stands |
| --- | --- |
| GIFs, and images on a server that cannot write AVIF, are stored as uploaded (header-validated only) | animated GIFs must survive; they are served as images with `nosniff` from a no-exec folder; uploaders are trusted staff |
| `style-src 'unsafe-inline'` | a few inline styles / `<noscript>` style; scripts stay strict |
| `base_url()` trusts the `Host` header while `canonical_base` is empty | README lists setting `canonical_base` as a go-live task |
| First visit to `setup.php` is open until `config.local.php` exists | there is no secret yet to protect it with; finish the install right after uploading |
| Rate limits key on `REMOTE_ADDR` | behind Cloudflare this may be a shared edge address unless the host restores the visitor IP; trusting `CF-Connecting-IP` is only safe when the origin accepts Cloudflare traffic exclusively — a hosting decision |
| Changing a password does not end that user's other sessions | sessions are file-based PHP sessions; 8 h idle timeout; deactivating the account does cut access at once |
| A published „Práve hráme“ item shows its production even when the production is hidden in the repertoire | publishing the run is the explicit decision |

## Reviewing a change — walk the data

For each new or changed input (query, form field, JSON key, header, file name, file content):
where does it end up? **HTML** → `e()` / `paragraphs()` / `rich_html()`. **SQL** → parameter; identifier
only via `entities.php`. **File system** → `media_safe_name()` + fixed base directory. **Header / mail**
→ `mail_header_safe()`, never raw. **Redirect** → fixed path or the `next` pattern. **JavaScript** →
`textContent`, `data-*`, JSON block; never string-built HTML.

Then: does a state change check login, CSRF and (if needed) admin? Could the change make a public page
start a session? Is anything hidden from visitors now present in their HTML? Did `.htaccess` and
`router.php` both get the rule? Is there a smoke check that would fail if this protection were removed?

```
php .claude/skills/dg-dev/scripts/testsite.php smoke --only=public,login,roles,api,upload,visibility,contact,throttle
```
