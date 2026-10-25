# Deploying to Hostinger

This guide takes the ERP from GitHub to a live Hostinger shared-hosting account. Follow the sections in order the first time. Later updates are just `git push` (see §14).

**Secrets never go in chat, email, git or screenshots.** They go in two places only:

- the server's `.env` file (`~/domains/<domain>/app/shared/.env`), edited over SSH;
- GitHub → repository → **Settings → Secrets and variables → Actions**.

Placeholders used below:

| Placeholder | Example | Where it comes from |
|---|---|---|
| `<domain>` | `erp.example.lk` | Your domain or subdomain |
| `<user>` | `u123456789` | hPanel → Advanced → SSH Access → *Username* |
| `<host>` / `<port>` | `123.45.67.89` / `65002` | hPanel → Advanced → SSH Access |
| `<php>` | `/opt/alt/php84/usr/bin/php` | §2 |

## How it fits together

```
~/domains/<domain>/
├── public_html  →  app/current/public        (the only folder the web can see, §6)
└── app/
    ├── releases/20261021-031500-ab12cd3/     one folder per deploy (last 5 kept)
    ├── releases/20261022-090010-9f8e7d6/
    ├── current  →  releases/20261022-090010-9f8e7d6
    ├── shared/.env                            server settings (never in git)
    ├── shared/storage/                        uploads, logs, sessions, backups
    └── deploy.env                             optional: settings for the deploy script
```

A push to `main` runs the tests on GitHub, builds the front end (Node never runs on Hostinger), uploads a new release over SSH, installs Composer packages, caches config/routes/views, runs migrations (maintenance mode only while they run), switches `current` in one step, restarts the queue, clears OPcache and checks `/health`. If the health check fails it switches back to the previous release by itself.

---

## 1. Domain, DNS and SSL

1. **Domain.** Use a domain or subdomain just for the ERP, e.g. `erp.lankahome.lk` (and `staging.lankahome.lk` for the rehearsal). In hPanel → **Websites → Add website** (or **Domains → Subdomains**), add it so it gets its own folder `~/domains/<domain>/public_html`.
   - If hPanel puts a subdomain inside another site's folder (e.g. `~/domains/lankahome.lk/public_html/erp`), that's fine: use that path as `WEB_ROOT` (§6) and put `app/` **outside** any `public_html`.
2. **DNS.** If the domain's nameservers are Hostinger's, hPanel creates the records. If DNS is somewhere else (e.g. Cloudflare), add an `A` record for `<domain>` pointing to the IP in hPanel → **Websites → Dashboard → Website details**. Wait until `ping <domain>` shows that IP.
3. **SSL.** hPanel → **Security → SSL** → install the free SSL for `<domain>`. Wait until `https://<domain>` shows a padlock. Turn on **Force HTTPS** there too (the app also redirects).
   - Every QR code on an invoice and every WhatsApp/SMS document link uses this https:// address. Choose it before you print invoices.

## 2. PHP version, extensions and limits

1. hPanel → **Advanced → PHP Configuration → PHP version**: choose **8.4** (or newer).
2. **PHP extensions** tab, tick: `pdo_mysql`, `mysqli`, `gd`, `intl`, `bcmath`, `mbstring`, `fileinfo`, `zip`, `exif`, `openssl`, `curl`, `opcache`, `sodium`, `ctype`, `tokenizer`, `xml`, `dom`.
3. **PHP options** tab:

   | Option | Value | Why |
   |---|---|---|
   | `upload_max_filesize` | `16M` | Product photos (the app limit is 5 MB) |
   | `post_max_size` | `20M` | Must be larger than the upload size |
   | `memory_limit` | `256M` | Image resizing, PDFs, reports |
   | `max_execution_time` | `60` | Report exports, backups |
   | `opcache.enable` | on | Speed |

4. **Which PHP does SSH and cron use?** The web uses the hPanel version, but the command line may not. After §4, in SSH:

   ```bash
   php -v                     # if this says 8.4, `php` is fine for commands you type
   ls -d /opt/alt/php8*/usr/bin/php   # the full paths available, e.g. /opt/alt/php84/usr/bin/php
   /opt/alt/php84/usr/bin/php -v      # must say 8.4
   /opt/alt/php84/usr/bin/php -m | grep -Ei 'gd|intl|bcmath|zip|pdo_mysql'
   ```

   Use that full path as `<php>` in cron (§10), in the GitHub variable `PHP_BIN` (§8) and in `deploy.env`. The rest of this guide writes `<php>`.

## 3. MySQL database

1. hPanel → **Databases → MySQL Databases** → create a database and a user (Hostinger adds the `u123456789_` prefix). Use a long generated password; type it **only** into the server `.env` in §7.
2. Note the database name, the username and the host (`localhost` on shared hosting).
3. Leave it empty: the first deploy creates the tables (§8), and `app:install` adds the essentials (§9).

## 4. SSH access and the deploy key

GitHub Actions logs in to Hostinger with an SSH key made only for deploying.

1. hPanel → **Advanced → SSH Access** → **Enable**. Note the IP, port and username.
2. **On your own computer**, make a key with no passphrase (Actions can't type one):

   ```bash
   ssh-keygen -t ed25519 -C "github-deploy" -f ~/.ssh/erp_deploy -N ""
   ```

   This makes `~/.ssh/erp_deploy` (private, it goes to GitHub only) and `~/.ssh/erp_deploy.pub` (public, it goes to Hostinger).
3. hPanel → **SSH Access → SSH keys → Add SSH key**: paste the contents of `~/.ssh/erp_deploy.pub` (`cat ~/.ssh/erp_deploy.pub`).
4. Test it, and save the server's fingerprint for GitHub:

   ```bash
   ssh -i ~/.ssh/erp_deploy -p <port> <user>@<host> 'echo connected; php -v | head -1'
   ssh-keyscan -p <port> <host> > ~/erp_known_hosts.txt
   ```

   You will paste the private key and `erp_known_hosts.txt` into GitHub secrets in §8 yourself. Don't send them to anyone.

## 5. Email (SMTP)

The app sends receipt emails, the daily sales report, error alerts and backup alerts.

1. hPanel → **Emails** → create a mailbox, e.g. `receipts@<domain>`, with a generated password.
2. Settings for `.env` (§7): `MAIL_HOST=smtp.hostinger.com`, `MAIL_PORT=465`, `MAIL_SCHEME=smtps`, `MAIL_USERNAME` and `MAIL_FROM_ADDRESS` = the mailbox, `MAIL_PASSWORD` = its password.
3. Set up SPF and DKIM for the domain (hPanel → **Emails → Email deliverability** or DNS), or receipts land in spam.
4. After the first deploy, check it in SSH: `cd ~/domains/<domain>/app/current && <php> artisan app:test-mail you@example.com`.

## 6. Web root: point the domain at `current/public`

Only `public/` may be visible on the web. Never upload the whole project into `public_html`. Pick one mode; you'll put it in the GitHub variable `WEB_ROOT_MODE` (§8).

**A. `symlink` (recommended).** `public_html` becomes a link to `app/current/public`. Each deploy then goes live by switching `current`, with nothing copied. Set it up once in SSH:

```bash
cd ~/domains/<domain>
mkdir -p app
mv public_html public_html.old     # keep Hostinger's default page until it works; delete later
```

The first deploy creates the link (`WEB_ROOT=/home/<user>/domains/<domain>/public_html`, `WEB_ROOT_MODE=symlink`). Check with `ls -l ~/domains/<domain>/` → `public_html -> /home/<user>/domains/<domain>/app/current/public`.

**B. `forward` (fallback).** Use it if Hostinger won't serve a symlinked `public_html` (you get a 403/404 after the first deploy), or if hPanel keeps recreating the folder. `public_html` stays a real folder. Each deploy writes a 1-line `index.php` (`require __DIR__.'/../app/current/public/index.php';`) and the `.htaccess` into it, and links (or copies) `build/` and `storage/`. This needs `app/` right next to `public_html`, as in the diagram.

```bash
cd ~/domains/<domain> && mkdir -p app && mkdir -p public_html.old && mv public_html/* public_html.old/ 2>/dev/null
```

**C. `none`.** You've pointed the document root somewhere yourself. The deploy doesn't touch the web folder.

**Moving an existing install** (the whole project copied into `public_html`, served through the root `.htaccess`):

1. Back it up first: `cd ~/domains/<domain>/public_html && <php> artisan backup:run` if it has backups set up, or export the database in hPanel → phpMyAdmin, and copy `storage/app/public` (the photos and logos).
2. Create `app/shared/` (§7) and **copy the old `.env`** into `app/shared/.env`, keeping the same `APP_KEY`, then add the new keys from `.env.production.example`.
3. Move the uploads: `mkdir -p ~/domains/<domain>/app/shared/storage/app && cp -a ~/domains/<domain>/public_html/storage/app/public ~/domains/<domain>/app/shared/storage/app/`.
4. Keep the same database. The deploy only runs the new migrations, so orders, invoices and their **QR / digital-copy links stay valid**.
5. `mv public_html public_html.old`, then deploy (§8). When the new site works, delete `public_html.old` (it contains a copy of `.env`).
6. If you set `PUBLIC_DISK_ROOT` / `PUBLIC_DISK_URL` for the old layout, remove them (or change them to the new paths).

**Uploaded images (`/storage`).** Each release links `public/storage` → `shared/storage/app/public`. If symlinks don't work at all, set `PUBLIC_DISK_URL=https://<domain>/storage` in `.env`: Laravel's `storage.fallback` route then serves images (images only, never other files).

## 7. The server `.env`

Create it once. Deploys never overwrite it.

```bash
mkdir -p ~/domains/<domain>/app/shared
nano ~/domains/<domain>/app/shared/.env
```

Paste the contents of [`.env.production.example`](../.env.production.example) from the repository, then fill in:

| Key | Value |
|---|---|
| `APP_KEY` | Run `echo "base64:$(openssl rand -base64 32)"` in SSH and paste the output. **Never change it after go-live**: it encrypts sessions and 2FA secrets. Keep a copy in your password manager. |
| `APP_URL` | `https://<domain>`, no trailing slash |
| `APP_NAME` | Your company name |
| `DB_DATABASE` / `DB_USERNAME` / `DB_PASSWORD` | From §3 |
| `MAIL_*` | From §5 |
| `ERROR_ALERT_EMAIL`, `BACKUP_ALERT_EMAIL` | The owner's or manager's email |
| `HEALTH_TOKEN` | Run `openssl rand -hex 32`. The same value goes into a GitHub secret (§8). |
| `OPCACHE_RESET_TOKEN` | Another `openssl rand -hex 32` (also a GitHub secret) |
| `TRUSTED_PROXIES` | Empty, or `REMOTE_ADDR` if you turn on the Hostinger CDN (§11) |
| `BACKUP_S3_*` | From §13 (you can do this after the first deploy) |

Then lock the file down: `chmod 600 ~/domains/<domain>/app/shared/.env`.

The app **refuses to start** in production if the `.env` is unsafe: debug on, no key, an http:// URL, the wrong queue/cache/session driver, no mail, or the wrong timezone. The deploy stops at "prepare" with the reason and the live site is not touched. Fix the `.env` and re-run the deploy.

Optional: `~/domains/<domain>/app/deploy.env` holds settings for the server-side script, e.g. `PHP_BIN=/opt/alt/php84/usr/bin/php`, `COMPOSER_BIN=/usr/local/bin/composer`, `KEEP=5`.

## 8. GitHub secrets and the first deploy

GitHub → repository → **Settings → Environments → New environment** `production` (you can add yourself as a required reviewer if you want to approve each deploy). Then **Settings → Secrets and variables → Actions**:

**Secrets** (*New repository secret*, or on the `production` environment):

| Name | Value |
|---|---|
| `SSH_HOST` | `<host>` |
| `SSH_PORT` | `<port>` (e.g. `65002`) |
| `SSH_USER` | `<user>` |
| `SSH_PRIVATE_KEY` | The whole content of `~/.ssh/erp_deploy`, including the `-----BEGIN…` / `-----END…` lines |
| `SSH_KNOWN_HOSTS` | The content of `~/erp_known_hosts.txt` from §4 |
| `DEPLOY_PATH` | `/home/<user>/domains/<domain>/app` |
| `APP_URL` | `https://<domain>` |
| `HEALTH_TOKEN` | The same value as in `.env` |
| `OPCACHE_RESET_TOKEN` | The same value as in `.env` |

**Variables** (*Variables* tab):

| Name | Value |
|---|---|
| `PHP_BIN` | `<php>`, e.g. `/opt/alt/php84/usr/bin/php` |
| `WEB_ROOT` | `/home/<user>/domains/<domain>/public_html` |
| `WEB_ROOT_MODE` | `symlink` (or `forward`, see §6) |
| `DEPLOY_ENABLED` | `true`. Set this last, once everything above is ready. Without it, pushes only run the tests and never touch the server. |

**First deploy:** GitHub → **Actions → Deploy → Run workflow** on `main` (every later push to `main` deploys by itself). Watch the steps:

1. *test*: PHP tests, code style. A failure stops here, and nothing reaches the server.
2. *Upload release*, *Prepare*, *Activate*: creates the tables on the first run.
3. *Health check*: `/health?scope=deploy` must answer 200.

Delete the downloaded `~/erp_known_hosts.txt` afterwards, and keep `~/.ssh/erp_deploy` only if you also want to deploy from your computer (§14).

## 9. First admin and essential data

In SSH:

```bash
cd ~/domains/<domain>/app/current
<php> artisan app:install         # migrations + first locations, company settings (payment methods, registers, cash categories come with the migrations); no demo data
<php> artisan app:create-admin    # asks name, email and a password (hidden, ≥12 chars with letters and numbers)
```

Then sign in at `https://<domain>/login`:

1. **Two-factor login**: you are taken there straight away (required for admins and accountants). Save the recovery codes in your password manager.
2. **Settings**: company name, logo, VAT registration and number, document prefixes, return window, the daily report email addresses.
3. **Locations**: rename/add the warehouse and showrooms, with receipt header/footer and business-day start; **Shift & cash → Registers** per showroom.
4. **Staff**: managers, cashiers, storekeepers and accountants, each with their home location and a PIN.
5. Load the go-live data in the order of `docs/DATA-IMPORT.md` (products, suppliers, customers, opening stock per location, open orders, supplier balances), dry run first.

Demo seeders refuse to run in production. Never run `db:seed` or `demo:reset` on the live server.

## 10. Cron (scheduler and queue)

One cron line runs everything: queued jobs (emails, large report exports), the scheduler heartbeat, report summaries, the daily report email, backups and clean-up.

hPanel → **Advanced → Cron Jobs → Custom** (every minute: `* * * * *`), command:

```
* * * * * /opt/alt/php84/usr/bin/php /home/<user>/domains/<domain>/app/current/artisan schedule:run >> /dev/null 2>&1
```

(Use your `<php>` path; in hPanel's form you type only the part after the five `*`.) Because it points to `current`, it never needs changing after a deploy.

What runs (`routes/console.php`):

| When | Task |
|---|---|
| Every minute | Scheduler heartbeat (for `/health`); `queue:work --stop-when-empty --max-time=50` (no overlap) |
| Hourly | Re-summarise the last 3 days of reports |
| Every 5 min | The owner's daily email at the time set in Settings, if the last location didn't close the day before then |
| 02:30 / 03:00 / 09:00 | Clean old backups / back up / check backups are fresh |
| 04:30–05:00 | Prune report exports (7 days), failed jobs (30 days), password resets |

Check after 2 minutes: `curl -s "https://<domain>/health?token=<HEALTH_TOKEN>"` → `"scheduler": {"status": "ok"}`. (Type the token in your own terminal only.)

## 11. Hostinger CDN and caching

The app is safe behind a cache: every page and JSON response says `Cache-Control: no-store, private` and `Vary: Cookie`, and LiteSpeed's page cache is switched off in `.htaccess`. Only `/build/*` (hashed file names) and `/storage/products|categories|logos|attributes/*` (random file names) are cached for a year.

If you turn on **hPanel → Performance → CDN**:

1. Set `TRUSTED_PROXIES=REMOTE_ADDR` in `.env` (so the app sees the customer's real IP for rate limits and https), then run `<php> artisan config:cache` in `current/`.
2. In the CDN settings, **exclude from cache / bypass** (if the option is there): `/login*`, `/two-factor*`, `/terminal*`, `/pos*`, `/sales*`, `/cash*`, `/doc/*`, `/q/*`, `/reports*`, `/health`, `/deploy/*` (in practice: the whole site except `/build/*` and `/storage/*`). The no-store headers already stop caching; the exclusions are extra safety.
3. Leave HTML minification and "Rocket Loader"-style script rewriting **off**: they break the CSP nonce and Inertia.
4. After a deploy, purging isn't needed (new build files get new names).
5. Test: sign in as a cashier on one device and as a manager on another; each must see their own name. Run `curl -sI https://<domain>/login | grep -i cache-control` → `no-store`.

Without the CDN, leave `TRUSTED_PROXIES` empty.

## 12. Uptime monitoring

`/health` checks the database, storage, scheduler heartbeat (cron), queue backlog and failed jobs, and backup age. It answers **503** when something is down, **200** with `"status": "degraded"` for warnings, and **404** without the token.

UptimeRobot (free) or Better Stack:

1. New monitor → **HTTP(s)**, URL `https://<domain>/health?token=<HEALTH_TOKEN>` (type the token straight into the monitoring site), every 5 minutes.
2. Alert contacts: the owner's and manager's email/SMS. It alerts on the 503 (database, storage, cron stopped, queue stuck).
3. Optional second monitor, **Keyword**, the same URL, alert when `"degraded"` **exists**. This catches failed jobs and old backups.
4. Optional: `https://<domain>/login` as a plain HTTP monitor (no token).

Unexpected errors (HTTP 500) are emailed to `ERROR_ALERT_EMAIL`, at most once per error every 15 minutes. Logs are in `~/domains/<domain>/app/shared/storage/logs/laravel-YYYY-MM-DD.log` (14 days).

## 13. Backups and restore

Nightly at 03:00 `backup:run` makes one zip with a MySQL dump and the uploaded images (`storage/app/public`). The code is in git, and the `.env` is kept in your password manager (it isn't backed up, on purpose). The backups are kept like this: all for 7 days, then 4 weekly, 6 monthly and 1 yearly. If a backup fails, or the newest one is over 2 days old, `BACKUP_ALERT_EMAIL` gets an email, and `/health` warns after 30 hours.

**Off-site storage (do this: a backup on the same server isn't a backup).** Any S3-compatible bucket:

- **Backblaze B2:** create a private bucket and an application key limited to that bucket. Endpoint `https://s3.<region>.backblazeb2.com`, region e.g. `eu-central-003`.
- **Cloudflare R2:** create a bucket and an API token (Object Read & Write, that bucket only). Endpoint `https://<account-id>.r2.cloudflarestorage.com`, region `auto`.

In `.env`: `BACKUP_S3_BUCKET`, `BACKUP_S3_ENDPOINT`, `BACKUP_S3_REGION`, `BACKUP_S3_KEY`, `BACKUP_S3_SECRET`, `BACKUP_S3_PATH_STYLE=true`. Optional: `BACKUP_ARCHIVE_PASSWORD` encrypts the zip (AES-256). Store that password in your password manager: without it you can't restore. Then:

```bash
cd ~/domains/<domain>/app/current
<php> artisan config:cache
<php> artisan backup:run          # first backup now
<php> artisan backup:list         # shows it on the "backups" disk
```

If `backup:run` says mysqldump is missing, find it with `which mysqldump` and set `DB_DUMP_BINARY_PATH` to its folder (e.g. `/usr/bin`).

**Restore** (test it once before go-live, ideally into a staging copy):

```bash
<php> artisan app:restore-backup --list                    # newest first
<php> artisan app:restore-backup                           # newest; asks to confirm
<php> artisan app:restore-backup --file=retail-erp/2026-10-21-03-00-02.zip
<php> artisan app:restore-backup --database-only           # or --files-only
```

It downloads the zip, switches on maintenance mode, wipes and reloads the database, restores the images, clears caches and switches maintenance mode off. Sales and payments taken after that backup are lost. After a restore, check the sales list, today's payments and shifts, a product photo and one invoice's QR / digital-copy link.

Hostinger's own daily/weekly backups (hPanel → Files → Backups) are a useful second copy.

## 14. Updates and rollback

**Normal update:** merge or push to `main`. Actions tests, builds and deploys. Maintenance mode is on only while migrations run (a few seconds), with a branded "back in a moment" page that retries by itself. Pages without migrations switch over with no downtime.

- Deploy during quiet hours when a release has migrations.
- Shared terminals and open screens reload by themselves (Inertia detects the new build).

**Automatic rollback:** if `/health?scope=deploy` fails after the switch, the workflow puts the previous release back and the run turns red.

**Manual rollback** (bad release that passed the health check). Database migrations are **not** undone, so only roll back across releases whose migrations are backwards-compatible, or restore a backup.

```bash
# from your computer (needs scripts/deploy/.deploy.env, see below)
scripts/deploy/rollback.sh                    # previous release
scripts/deploy/rollback.sh 20261021-031500-ab12cd3

# or in SSH on the server
cd ~/domains/<domain>/app
bash current/scripts/deploy/remote.sh list
bash current/scripts/deploy/remote.sh rollback            # previous
bash current/scripts/deploy/remote.sh rollback <release>
```

Set `PHP_BIN` (and `WEB_ROOT`, `WEB_ROOT_MODE`) in `app/deploy.env` so these server commands use the right PHP.

**Deploy from your computer** (when GitHub Actions is down): copy `scripts/deploy/.deploy.env.example` to `scripts/deploy/.deploy.env` (git-ignored) and fill it in **on your computer**. Then `scripts/deploy/deploy.sh` (or `--dry-run` first). It refuses uncommitted changes, runs the tests, builds, uploads and activates like the workflow, and rolls back if the health check fails.

After an update that changes `.env` keys, add them to `shared/.env` first, then deploy (config is cached at deploy time). To apply a `.env` edit without deploying: `cd ~/domains/<domain>/app/current && <php> artisan config:cache`.

## 15. Troubleshooting

| Symptom | Cause and fix |
|---|---|
| Deploy fails at *Prepare* with "Production configuration problems" | The `.env` is unsafe (the message lists what). Fix `shared/.env` and run the workflow again. The live site wasn't touched. |
| Deploy fails at *Upload* with "Host key verification failed" | `SSH_KNOWN_HOSTS` is wrong or the server changed. Redo `ssh-keyscan` (§4). |
| "Permission denied (publickey)" | `SSH_PRIVATE_KEY` is incomplete (needs the BEGIN/END lines) or the public key isn't in hPanel. |
| `composer: command not found` / wrong PHP in Prepare | Set the `PHP_BIN` variable; set `COMPOSER_BIN=/usr/local/bin/composer` in `app/deploy.env` (`which composer`). |
| Health check fails right after deploy (auto rollback) | Run `curl -s "https://<domain>/health?scope=deploy&token=…"` in your terminal and look at `database` / `storage`. Check `shared/storage/logs`. |
| 403 / blank page after the first deploy | Web root: `ls -l ~/domains/<domain>/`. With `symlink` mode, if Hostinger won't follow the link, switch `WEB_ROOT_MODE` to `forward` (§6) and redeploy. |
| 500 error, "No application encryption key" | `APP_KEY` is missing in `shared/.env`. |
| "419 Page expired" on every login | `APP_URL` doesn't match the address in the browser, or cookies are blocked: `SESSION_SECURE_COOKIE=true` needs https. With the CDN, set `TRUSTED_PROXIES=REMOTE_ADDR`. |
| Redirect loop | CDN or proxy sends http to the server: set `TRUSTED_PROXIES=REMOTE_ADDR`, `config:cache`. |
| Product photos don't show | `ls -l current/public/storage` should point to `../storage/app/public`. If symlinks are blocked, set `PUBLIC_DISK_URL=https://<domain>/storage`. |
| "Upload too large" on photos | Raise `upload_max_filesize` / `post_max_size` (§2). |
| `/health` says scheduler `fail` | The cron isn't running: check the line in §10, the PHP path, and run it by hand once to see errors. |
| Queue backlog (emails/exports late) | Cron is running but jobs fail: `<php> artisan queue:failed`, then `queue:retry all` after fixing. |
| Quotation / daily report emails don't arrive | Wrong `MAIL_*`, or SPF/DKIM missing (spam). Check the log; run `<php> artisan app:test-mail you@example.com`. |
| Invoice QR / WhatsApp link says "not found" | The document was printed from another database (e.g. staging): reprint it from the live site. |
| "Open a shift first" at the till | Payments need an open shift (Shift & cash). A manager can switch the rule off in Shift & cash → Registers & settings, but leave it on in production. |
| Staff locked out of PIN | 10 wrong PINs lock it for 15 minutes; a manager can press **Locked · unlock** next to the person on the Staff page. |
| Admin lost the 2FA phone | Sign in with a recovery code. If none are left, another admin can't remove it from the UI on purpose: in SSH run `<php> artisan tinker` → `App\Models\User::where('email','…')->update(['two_factor_secret'=>null,'two_factor_recovery_codes'=>null,'two_factor_confirmed_at'=>null]);` |
| Site stuck on "back in a moment" | A migration failed during deploy. Fix it and redeploy, or `cd current && <php> artisan up`. |
| Wrong times / business day | `APP_TIMEZONE=Asia/Colombo` (the app refuses other values in production) and Locations → business day start. |


## 16. Staging and the go-live rehearsal

Rehearse everything on a staging subdomain before go-live: same host, same steps, **its own database and folder**, so nothing can touch the live data.

1. Subdomain `staging.<domain>` with its own folder (§1), SSL, and a second MySQL database and user (§3, e.g. `u123456789_erp_staging`).
2. On the server, a second app root: `~/domains/staging.<domain>/app/{shared/.env, shared/storage}` (§7) with **its own `APP_KEY`, database, `HEALTH_TOKEN` and `APP_URL=https://staging.<domain>`**, `MAIL_MAILER=log` (so no customer or owner gets staging email) and `REQUIRE_TWO_FACTOR=true` as in production.
3. GitHub → Settings → Environments → **New environment `staging`** with the same secrets as `production` but the staging values (`DEPLOY_PATH`, `APP_URL`, `HEALTH_TOKEN`, `OPCACHE_RESET_TOKEN`). Deploy: Actions → Deploy → Run workflow → target **staging**. Pushes to `main` still go to production only.
   From your computer instead: `cp scripts/deploy/.deploy.env.example scripts/deploy/.deploy.env.staging`, fill it in, then `DEPLOY_ENV_FILE=scripts/deploy/.deploy.env.staging scripts/deploy/deploy.sh`.
4. In SSH: `app:install`, `app:create-admin`, cron line for the staging path (§10), then work through `docs/GO-LIVE-CHECKLIST.md`.
5. **Full rehearsal import** (`docs/DATA-IMPORT.md`): every file, dry runs first, then commits, then the checks at the end of that guide. Keep the reports. Fix the source files, not the data in the app, and run it again until every report is understood.
6. Restore test: `app:restore-backup` on staging from a production-style backup (§13).

Staging may be wiped any time: `cd current && <php> artisan migrate:fresh --force && <php> artisan app:install` (never on production, where `APP_ENV=production` refuses demo data anyway). For demos and training on staging (not production), `APP_ENV=staging` allows `<php> artisan demo:reset --force --days=60`: a full history of sales, shifts, returns, deposits and closed days.
