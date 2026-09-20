---
name: dg-deploy
description: Deploy the Divadielko Galéria site to the Websupport hosting — upload over OpenSSH sftp, verify every file size, run migrations through setup.php?key=…, and handle the fact that the live site and the test subdomain share one database. Use ONLY when the user asks to deploy, upload, publish, "nasaď", "nahraj na server", "daj to na test / na ostrú", to run migrations on the server, to copy media between the two installs, to roll a deployment back, or asks how deployment works. Never start an upload on your own initiative — a deploy changes a public website and needs the user's explicit go-ahead and their SFTP password.
---

# Deploying

Hosting: Websupport, Apache + PHP 8.5 (local development runs 8.2), Cloudflare in front. No SSH
shell, no git on the server — files go up over **SFTP**, the database is updated by opening `setup.php`.

| Install | Remote folder | Note |
| --- | --- | --- |
| test | `/divadielkogaleria.sk/sub/test` | `test.divadielkogaleria.sk`, `'noindex' => true` in its config |
| main | `/divadielkogaleria.sk/web` | the live site |

**Both installs use the same PostgreSQL database** (content, users, site mode are shared) but each has
its own `assets/`, `assets_original/`, `storage/` and `includes/config.local.php`. Everything below
follows from that.

Facts about this hosting that were verified on the server (2026-09-20):

- **No ffmpeg.** Uploaded video and audio are never converted there: browser-playable files (`.mp4`,
  `.webm`, `.mp3` …) are served exactly as uploaded, others (`.mov`, `.mkv` …) are refused. Images do
  become AVIF. admin → Súbory shows „ffmpeg nie“. Recommend YouTube links for anything but short clips.
- **The mode can be forced per install.** `'mode' => '…'` in an install's `config.local.php` overrides
  the shared database setting, so the two domains can show different pages. Going live on the main
  domain = remove that key from `web/includes/config.local.php` **and** choose „Ostrá stránka“ in admin →
  Nastavenia (which, being a database setting, applies to the test subdomain too).
- Apache honours the `.htaccess` protections (its error log shows „client denied“ for `includes/`,
  `storage/`, `pages/`), and the PHP 8.5 error log is the place to look after a deploy:
  `/divadielkogaleria.sk/logs/` and `/divadielkogaleria.sk/logs-test.divadielkogaleria.sk/` (rotated
  daily, gzip). Most of it is bots probing non-existent subdomains; grep for `PHP message`.

The SFTP host and user name are deliberately **not in this repository** (it is public, and the origin
server hides behind Cloudflare). They are in Claude's project memory (`deploy-sftp-websupport`);
otherwise ask the user. The password always comes from the user for the session — never store it.

## Before touching the server

1. The user asked for it, and you know the target: test, main, or both (the usual path is test →
   look at it → main).
2. Work is committed on `main`; `git status` is clean. The upload takes files from disk and plans
   only **tracked** files — on a dirty tree a modified `entities.php` would go up while its new,
   still untracked migration stayed behind. `deploy.php plan` therefore refuses a dirty tree
   (`--allow-dirty` is for looking at a plan, never for uploading from it).
3. `php .claude/skills/dg-dev/scripts/check.php` and `… testsite.php smoke` pass. New migration?
   It was rehearsed with `testsite.php create --clone-db` (`dg-migration`).
4. Decide the order if there is a migration — see "One database" below.

## 1 · Plan

```
php .claude/skills/dg-deploy/scripts/deploy.php plan --target=test
```

Prints what will be uploaded and writes two sftp command files into the temp folder; it prints their
paths (`…-upload.sftp`, `…-verify.sftp`) — use those exact paths below as `$UPLOAD` and `$VERIFY`.
By default it plans **all** deployable files (≈55 small files): uploading everything
every time is what keeps the server from drifting. `--since=<commit>` limits it to changed files.

Never uploaded: `includes/config.local.php`, anything in `assets/`, `assets_original/`, `storage/`
except their `.htaccess`, `router.php`, `README.md`, `CLAUDE.md`, `.claude/`, `.gitignore`.

**Root `.htaccess` is left out unless `--with-htaccess`.** It is the one file whose mistake takes the
whole site down (every request becomes a 500 or a redirect loop), and a server copy may have been
edited by hand. When it changed in the repository: `get` the remote one and diff it first; upload to
the **test** subdomain, request `https://test…/` and `http://test…/` straight away (expect the normal
page and a 301 to https), and only then upload to the main domain — keeping the downloaded copy at
hand to put back. The HTTPS redirect block in it is switched on (since 2026-09-20); it must be
commented out only on a hosting without a certificate.

## 2 · Upload (OpenSSH `sftp`, password through `SSH_ASKPASS`)

Run from the repository root — the `put` paths in the command file are relative.

```bash
export DG_SFTP_PASSWORD='…'   # from the user, for this shell only
ASKPASS="$(mktemp)"; printf '#!/bin/sh\nprintf "%%s\\n" "$DG_SFTP_PASSWORD"\n' > "$ASKPASS"; chmod 700 "$ASKPASS"
SSH_ASKPASS="$ASKPASS" SSH_ASKPASS_REQUIRE=force \
  sftp -o StrictHostKeyChecking=accept-new "$DG_SFTP_USER@$DG_SFTP_HOST" < "$UPLOAD" | tee "$TEMP/dg-upload.log"
```

- The askpass script only echoes an environment variable, so the password never lands in a file.
  Delete the script and `unset DG_SFTP_PASSWORD` when done.
- Feed commands on **stdin, not `-b`**: batch mode switches password authentication off.
- In this mode sftp does **not stop on errors**. Read the log for `Permission denied`, `No such file`,
  `Failure`, `Connection closed` — and always do step 3.
- What does not work here (learned the hard way on 2026-09-19): **FTPS** with the temporary FTP
  account failed for every file over ~12 kB *and left 0-byte files behind*, which took the live code
  down; curl's `sftp://` cannot negotiate encryption with this server. Use OpenSSH `sftp`, nothing else.

## 3 · Verify — every time

```bash
SSH_ASKPASS="$ASKPASS" SSH_ASKPASS_REQUIRE=force \
  sftp "$DG_SFTP_USER@$DG_SFTP_HOST" < "$VERIFY" > "$TEMP/dg-listing.txt"
php .claude/skills/dg-deploy/scripts/deploy.php verify --target=test --listing="$TEMP/dg-listing.txt"
```

It compares the size of every remote file with the local one and fails on a missing, different or
empty file. **If it fails, upload again and verify again before anything else** — a half-uploaded
install is a broken website. It also notes files that exist only on the server (renamed or deleted in
the repository — remove them by hand with sftp `rm` when they matter).

Sizes are compared with the files **on disk**, byte for byte. This working tree has mixed line endings
(some files CRLF, some LF) and the server holds exactly those bytes — so do not renormalise line
endings, re-checkout or switch machines between upload and verify, or everything reads as DIFFERENT.
(The listing uses `ls -la`: plain `ls -l` hides the `.htaccess` files.) Run before an upload, the same
command is a cheap answer to "what is deployed right now?" — files that differ are the ones changed
since the last deploy.

### Looking at production data without database credentials

A change that alters what visitors see may depend on content you cannot see locally. The backups are
the safe window: `storage/backups/*.json` on the server is the whole database as JSON (newest
`pred-NNN` one, or ask the user to press „Vytvoriť zálohu“ for a fresh one). `get` it into the temp
folder, read the tables you need with a few lines of PHP (`$data['tables']['productions']` …), delete
it afterwards — it also contains password hashes and visitors' messages, so never print those, never
copy it into the repository. No need to touch `config.local.php` or connect to the database.

## 4 · Migrate — once

Open `https://<host>/setup.php?key=<setup_key>`. The page says which backup it made
(„Záloha pred aktualizáciou: …“) and which migrations it applied, or „Databáza je aktuálna.“

- The key is `setup_key` in the **server's** `includes/config.local.php`. Simplest: ask the user to
  open the URL. With their go-ahead you may instead `get` that file into the temp folder (never into
  the repository), read only the key with PHP, delete the file at once. That file also holds the
  database password — never print it, never leave it lying around, keep the key out of logs and commits.
- Because the database is shared, run it from **one** install only. The other then reports „aktuálna“.
- If the backup cannot be written (`storage/` not writable) nothing is migrated — fix permissions, retry.

### One database, two installs — order matters

Between "new code uploaded" and "migration applied", new code runs on the old schema; after the
migration, any install still on old code runs on the new schema. A page that hits a missing column
throws, and `index.php` shows visitors the maintenance page (503) until things line up.

- **Additive migration** (new nullable column / table): the zero-risk order is **migration file first**
  — upload only the migration (`deploy.php plan --target=… --only=includes/migrations/`), open
  `setup.php?key=…`, then upload the rest of the code to both installs. Old code ignores new columns, so nothing is ever out of step. (The other
  order — all code, then migrate — also works, but until the migration runs every *save* of the
  affected entity fails with „Chyba databázy“, because `cms_save()` writes all columns named in
  `entities.php`; pages keep rendering.)
- **Destructive migration** (drop / rename / tighten): upload the code to **both** folders, verify
  both, *then* migrate once. For anything risky, switch the site to „Údržba“ first (admin →
  Nastavenia) and back to „Ostrá stránka“ after checking — logged-in users still see the real page in between.

## 5 · Check the result

- `/` answers 200 (live) or 503 (wip / maintenance) and the HTML has no PHP warning; log in and open a pencil.
- Closed doors: `/includes/config.php` → 403, `/storage/` → 403, `/setup.php` without key → 404.
  (`/.git/…` answers 509 — the hosting blocks such probes before they reach the site.)
- `http://` answers `301` to `https://` on both domains (the HTTPS block of the root `.htaccess`);
  an `https://` request must never be redirected again — that would be a loop.
- admin → Zálohy shows the pre-migration backup as „Zlučiteľná“; admin → Súbory still says AVIF / ffmpeg „áno“.
- Cloudflare needs no purge: CSS / JS URLs carry `?v=<mtime>` and HTML is not cached.
- PHP 8.5 can warn about things 8.2 accepts — if a page misbehaves only on the server, look there first.

## Media between the installs

A file uploaded through one domain exists only in that install's `assets/`, while the shared database
references it from both — so the other domain shows a broken image. To even them out, copy `assets/`
(and `assets_original/` if originals matter) between the two remote folders with sftp `get -r` / `put -r`.
Media are never part of a code deploy and never part of a database backup.

## Rolling back

- **Code**: check out the previous commit in a separate worktree, plan + upload + verify from there.
- **Content**: admin → Zálohy → restore the automatic backup. It restores rows, not structure —
  migrations are never undone. If the new schema is the problem, write a forward migration that repairs it.

## First install on a new hosting

`README.md` → „Nasadenie na websupport.sk“: upload, open `/setup.php` (writes `config.local.php`
with generated `secret` and `setup_key`), then `/setup.php?key=…` to migrate and create the first admin.
Do this immediately after uploading — until the config exists, the install page is open to anyone.
