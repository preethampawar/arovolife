# Cloudways deployment runbook — arovolife (Karonix Wellness app)

> **Server**: Cloudways 8 GB DO/Vultr droplet
> **Cloudways application name**: Karonix Wellness
> **Cloudways application slug**: `ahdhesuhty`
> **Master user**: `master` (default Cloudways)
> **Application path on server**: `/home/master/applications/ahdhesuhty/public_html`
> **Webroot (document root)**: `/home/master/applications/ahdhesuhty/public_html/app/public`
> **Repo**: `git@github.com:preethampawar/arovolife.git`

The Laravel project lives at `…/public_html/app/` and Laravel's own `public/`
folder is the webroot. Cloudways must be configured to serve from
`app/public`, not the default `public_html`.

---

## 0. Prerequisites (verify once before first deploy)

| Check | How |
|---|---|
| PHP version ≥ 8.3 | Cloudways → *Server Management → Settings & Packages → PHP* → choose **8.3** (or 8.4 if available) |
| MySQL 8.0 | Cloudways → *Server Management → Settings & Packages → MySQL* |
| Required PHP extensions | `bcmath, ctype, curl, dom, fileinfo, gd, mbstring, openssl, pdo_mysql, redis, tokenizer, xml, zip` — all enabled by default on Cloudways but verify under *Settings & Packages → Advanced* |
| Composer 2 | `composer --version` (Cloudways ships v2 by default) |
| Node 20+ for asset builds | If missing: `nvm install 20` (per-master-user nvm is fine; ssh key is master-user scoped) |
| Redis enabled | *Settings & Packages → Advanced → Redis* → **ON** |
| 8 GB RAM, swap ≥ 2 GB | `free -h` (Cloudways auto-creates swap) |
| Outbound HTTPS to Resend / Mailgun / SES, SMS gateway, Aadhaar/PAN/penny-drop providers | Test from server: `curl -I https://api.resend.com` etc. |

---

## 1. First-time setup

### 1.1 Open SSH access (Cloudways console)

1. *Server Management → Master Credentials* — note `master` user IP, SSH password (or rotate to key auth).
2. *Application → Application Settings → SSH Public Keys* — paste the operator's public key for the **master user**, not the application user.
3. From your laptop:
   ```bash
   ssh master@<server-ip>
   ```

### 1.2 Configure Cloudways webroot to `app/public`

1. *Application → Application Settings → General → Webroot* → `app/public`.
2. *Save and Reload Apache/Nginx*.

If left at the default the site will 404 because Laravel's front controller lives one level deeper.

### 1.3 Clone the repo into `public_html`

```bash
ssh master@<server-ip>
cd /home/master/applications/ahdhesuhty/public_html

# public_html starts non-empty (Cloudways places a default index). Wipe it.
rm -rf ./* ./.[!.]*

# A read-only deploy key is the safest pattern; create one on the server:
ssh-keygen -t ed25519 -f ~/.ssh/arovolife_deploy -C "ahdhesuhty@cloudways" -N ""
cat ~/.ssh/arovolife_deploy.pub
# → add it as a deploy key (read-only) at
#   https://github.com/preethampawar/arovolife/settings/keys/new
cat >> ~/.ssh/config <<'EOF'
Host github-arovolife
    HostName github.com
    User git
    IdentityFile ~/.ssh/arovolife_deploy
    IdentitiesOnly yes
EOF
chmod 600 ~/.ssh/config

# Clone with the alias so the deploy key is used:
git clone github-arovolife:preethampawar/arovolife.git .
```

After clone, the directory layout matches the repo:

```
public_html/
├── .gitignore
├── README.md
├── app/                ← Laravel root
│   ├── composer.json
│   ├── public/         ← webroot (configured in 1.2)
│   └── …
├── docs/
└── …
```

### 1.4 Install application dependencies

```bash
cd /home/master/applications/ahdhesuhty/public_html/app

composer install --no-dev --optimize-autoloader --no-interaction

# Frontend assets (Vite). Build once on the server, commit nothing.
npm ci
npm run build
```

### 1.5 Create the production `.env`

```bash
cp .env.example .env
nano .env       # or scp from your laptop
php artisan key:generate --force
```

Fill every variable from the production env checklist
(`docs/runbooks/cloudways-deployment.md → §A` below). Critical ones the
site can't run without:

- `APP_ENV=production`, `APP_DEBUG=false`, `APP_URL=https://app.arovolife.com`
- `APP_KEY` (generated above)
- `DB_*` — Cloudways auto-creates DB; values are at *Application → Access Details*
- `REDIS_HOST=127.0.0.1`, `REDIS_PORT=6379`, `REDIS_PASSWORD=` (Cloudways internal Redis has no password by default)
- `SESSION_DRIVER=redis`, `CACHE_DRIVER=redis`, `QUEUE_CONNECTION=database` (Phase 1)
- `MAIL_*` — real SMTP/SES creds (not `log`)
- `KYC_*`, `SMS_*`, `WHATSAPP_*` — live provider keys
- `FILESYSTEM_DISK=s3` plus AWS keys (KYC document encryption-at-rest)

### 1.6 Database

```bash
# On Cloudways the DB is already provisioned; just run migrations.
cd /home/master/applications/ahdhesuhty/public_html/app
php artisan migrate --force          # --force is required in production

# Optional: provision the admin user via env, then run the prod seeder.
# Re-running this seeder is safe — it never overwrites existing rows.
# Skip the export if PROD_ADMIN_EMAIL is already in .env.
export PROD_ADMIN_EMAIL=ops@arovolife.com
export PROD_ADMIN_PASSWORD='<strong-password>'
export PROD_ADMIN_NAME='Arovolife Operations'

php artisan db:seed --class=ProductionSeeder --force
```

> **Do not** run `db:seed` with the default seeder in production — it
> seeds demo distributors with PII via `DemoDownlineSeeder`.
> `ProductionSeeder` is the only seeder safe to run on prod: it inserts
> roles, the admin (from env), settings, feature flags, content pages,
> the chart of accounts, and a placeholder catalogue — all using
> "create-if-missing" semantics so admin-edited values are never
> overwritten on subsequent runs.

### 1.7 Storage + permissions

```bash
cd /home/master/applications/ahdhesuhty/public_html/app

php artisan storage:link

# Master user runs PHP-FPM under `master`; storage and bootstrap/cache
# must be writable by that user.
chmod -R 775 storage bootstrap/cache
chown -R master:www-data storage bootstrap/cache 2>/dev/null || true
```

### 1.8 First-boot caches

```bash
php artisan config:clear
php artisan route:clear
php artisan view:clear

php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan event:cache
```

If `config:cache` errors, it's almost always an `env()` call outside a
config file — fix the source, then re-cache.

### 1.9 Queue worker (Supervisor)

Cloudways exposes Supervisor under *Application → Application Settings →
Supervisord Queues*. Add a queue:

| Field | Value |
|---|---|
| Command | `php /home/master/applications/ahdhesuhty/public_html/app/artisan queue:work --queue=default --sleep=3 --tries=3 --max-time=3600` |
| Number of processes | `2` (8 GB tier comfortably handles this) |
| Auto restart | ON |

Phase 1 uses the database queue driver; restart workers after every
deploy so they pick up new code (see §3).

### 1.10 Scheduler

*Application → Application Settings → Cron Job Management* → **Add Basic
Cron Job**:

| Field | Value |
|---|---|
| Time interval | `* * * * *` (every minute) |
| Command | `php /home/master/applications/ahdhesuhty/public_html/app/artisan schedule:run >> /dev/null 2>&1` |

This drives cooling-off reminders (D-20 / D-7 / D-1), audit-log
compaction, and any future scheduled jobs.

### 1.11 SSL

*Application → SSL Certificate → Let's Encrypt* — enter the apex and
www domains, hit Install. Then turn ON *Force HTTPS Redirection*.

After SSL, set in `.env`:
```
SESSION_SECURE_COOKIE=true
SESSION_DOMAIN=.arovolife.com
```
…and re-run §1.8 to refresh the config cache.

### 1.12 First boot — smoke test

From your laptop:

```bash
curl -I https://app.arovolife.com/                              # 200 OK
curl -I https://app.arovolife.com/login                         # 200 OK
curl -I https://app.arovolife.com/p/terms                       # 200 OK (content page)
curl -fsSL https://app.arovolife.com/contact-us | grep -q csrf  # confirms Blade rendered
```

Then in a browser:

1. Hit `/contact-us`, submit a real inquiry — confirm it lands in the admin inbox.
2. Hit `/register?ref=AL-0000000001` (the L0 ADN) — confirm referral resolves.
3. Sign in as the admin seed user, click any distributor → "Impersonate" → confirm banner shows and "Return to admin" works.
4. Open `/admin/tree` — confirm the company tree renders.

---

## 2. Subsequent deploys (the routine path)

Use this every time after the first one. ~30 seconds of degraded
response, no real downtime as long as no migration is running.

```bash
ssh master@<server-ip>
cd /home/master/applications/ahdhesuhty/public_html/app

# 1. Pull and lock the codebase
php artisan down --render="errors::503" --secret="<random-token>"  # optional; only if migrations involved

git fetch origin main
git reset --hard origin/main           # discards any drift on the server

# 2. Dependencies (skip if composer.lock and package-lock.json unchanged)
composer install --no-dev --optimize-autoloader --no-interaction
npm ci && npm run build

# 3. Migrations — review first
php artisan migrate --pretend          # show what would run
php artisan migrate --force            # apply

# 4. Re-cache config, routes, views, events
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan event:cache

# 5. Restart queue workers so they pick up new code
php artisan queue:restart

# 6. Bring the site back up
php artisan up
```

> **Note**: `php artisan down` blocks all traffic except those who hit
> `/<secret>`. Skip it for code-only deploys; use it only when a
> migration alters in-flight columns.

### 2.1 Cloudways Git deployment (recommended)

Cloudways pulls the repo and then runs a post-deploy script you point it
at. We ship that script in the repo at `scripts/deploy-staging.sh` so
deploy logic is version-controlled alongside the code.

1. *Application → Deploy via Git → Deployment via Git → Settings*.
2. Authorise the deploy key created in §1.3.
3. Branch: `main`. Deployment path: leave blank (defaults to `public_html`).
4. *Application → Application Settings → Deploy Hooks* — set the hook to:

   ```bash
   bash /home/master/applications/ahdhesuhty/public_html/scripts/deploy-staging.sh
   ```

5. Hit *Deploy now*. The script does the rest: composer install, vite
   build, migrations under maintenance mode, the idempotent
   `ProductionSeeder`, cache rebuilds, queue restart, and an HTTP smoke
   test against the public hostname.

The script logs every step to `app/storage/logs/deploy.log` for
post-mortem.

### 2.2 Manual deploy (when SSH is your only option)

```bash
ssh master@<server-ip>
bash /home/master/applications/ahdhesuhty/public_html/scripts/deploy-staging.sh
```

Same script, called by hand. The Cloudways hook and the manual path are
identical; pick whichever is more convenient on the day.

---

## 3. Rollback

Two rollback strategies depending on what broke.

### 3a. Code rollback only

```bash
ssh master@<server-ip>
cd /home/master/applications/ahdhesuhty/public_html/app

git log --oneline -5                   # find the last-known-good commit
git reset --hard <good-sha>

composer install --no-dev --optimize-autoloader --no-interaction
npm ci && npm run build

php artisan config:cache && php artisan route:cache && php artisan view:cache
php artisan queue:restart
```

### 3b. Code + DB rollback

If the bad deploy ran a migration that's not idempotent, you need to:

1. Restore the latest pre-deploy backup (Cloudways → *Server Management → Backups → Restore*). The 8 GB tier auto-backs-up daily; if the deploy was after the latest snapshot, the restore loses post-snapshot data.
2. Then do §3a.

If you can write a `down()` for the migration:
```bash
php artisan migrate:rollback --step=1 --force
```

### 3c. Production database backup (manual, before risky deploys)

```bash
ssh master@<server-ip>
mysqldump -u <db-user> -p<db-pass> <db-name> \
  --single-transaction --quick --skip-lock-tables \
  | gzip > ~/backups/arovolife-$(date +%Y%m%d-%H%M%S).sql.gz
```

Store off-server (S3) for any production DB dump containing PII —
DPDP-2023 retention rules apply.

---

## 4. Monitoring & alerts

Phase 1 baseline (more in Phase 12 per `phase_1_deferrals.md`):

| Signal | Where |
|---|---|
| Cloudways server CPU/RAM/Disk | *Server Management → Monitoring* — set 80% thresholds |
| MySQL slow queries | *Server Management → MySQL → Slow Query Log* |
| Laravel logs | `tail -F /home/master/applications/ahdhesuhty/public_html/app/storage/logs/laravel.log` |
| Failed jobs | `php artisan queue:failed` (email weekly to ops) |
| Audit log volume spike | Check `audit_log` table count daily; sudden 10× = suspicious |

Set up Cloudways → *Server Management → Monitoring → Alerts* to email
the on-call when CPU > 80% for 5 min, disk > 85%, or load > 6.

---

## 5. Common pitfalls

| Symptom | Cause | Fix |
|---|---|---|
| 404 on `/` | Webroot still default | Set webroot to `app/public` (§1.2), reload Apache |
| 500 with empty page | `APP_DEBUG=false` masking config error | `tail storage/logs/laravel.log`; usually a missing env var |
| Mixed-content warnings after SSL | `APP_URL` still `http://` | Update `APP_URL` to `https://…`, re-cache config |
| Sessions not persisting after login | `SESSION_DOMAIN` mismatch | Set to `.arovolife.com` (leading dot for subdomains) |
| Queue jobs appear stuck | Old worker still running pre-deploy code | `php artisan queue:restart` |
| `php artisan config:cache` errors with `RuntimeException` | An `env()` call outside `config/` | Search code, move env reads into a config file |
| Permission denied on `storage/logs/laravel.log` | Wrong owner after rsync | `chown -R master:www-data storage bootstrap/cache && chmod -R 775 …` |

---

## §A — Production env-var quick reference

(Same list as the Phase-1-exit checklist; reproduced here so this runbook is self-contained.)

```ini
# Core
APP_ENV=production
APP_DEBUG=false
APP_KEY=base64:<32-byte>
APP_URL=https://app.arovolife.com
APP_TIMEZONE=Asia/Kolkata
LOG_LEVEL=warning
LOG_PII_SCRUB=true

# DB (from Cloudways → Application → Access Details)
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=ahdhesuhty
DB_USERNAME=ahdhesuhty
DB_PASSWORD=<from-cloudways>

# Session / cache / queue
SESSION_DRIVER=redis
SESSION_LIFETIME=120
SESSION_SECURE_COOKIE=true
SESSION_DOMAIN=.arovolife.com
CACHE_DRIVER=redis
QUEUE_CONNECTION=database
REDIS_HOST=127.0.0.1
REDIS_PORT=6379
REDIS_PASSWORD=

# Mail
MAIL_MAILER=smtp
MAIL_HOST=<smtp-host>
MAIL_PORT=587
MAIL_USERNAME=<user>
MAIL_PASSWORD=<pass>
MAIL_ENCRYPTION=tls
MAIL_FROM_ADDRESS=no-reply@arovolife.com
MAIL_FROM_NAME="arovolife"
SUPPORT_EMAIL=support@arovolife.com

# Storage (KYC docs)
FILESYSTEM_DISK=s3
AWS_ACCESS_KEY_ID=<key>
AWS_SECRET_ACCESS_KEY=<secret>
AWS_DEFAULT_REGION=ap-south-1
AWS_BUCKET=arovolife-kyc-prod

# KYC providers (live keys)
KYC_PAN_PROVIDER=<vendor>
KYC_PAN_API_URL=<url>
KYC_PAN_API_KEY=<key>
KYC_AADHAAR_PROVIDER=<vendor>
KYC_AADHAAR_API_URL=<url>
KYC_AADHAAR_API_KEY=<key>
KYC_AADHAAR_LICENCE_KEY=<key>
KYC_BANK_PENNYDROP_PROVIDER=<vendor>
KYC_BANK_PENNYDROP_URL=<url>
KYC_BANK_PENNYDROP_KEY=<key>
KYC_ENCRYPTION_KEY=<32-byte-base64>
KYC_ENCRYPTION_KEY_ID=v1

# SMS / WhatsApp (DLT-registered)
SMS_PROVIDER=<vendor>
SMS_API_KEY=<key>
SMS_SENDER_ID=AROVOL
WHATSAPP_PROVIDER=<vendor>
WHATSAPP_API_KEY=<key>

# Business rules
COOLING_OFF_DAYS=30
COOLING_OFF_REMINDERS_DAYS=20,7,1
AGE_DEFAULT=18
AGE_MAHARASHTRA=21
PLACEMENT_DEFAULT_SIDE=L
PLACEMENT_ALLOW_SPONSOR_OVERRIDE=true

# Production seeder (only used by ProductionSeeder; ignore if seeding by hand)
PROD_ADMIN_EMAIL=ops@arovolife.com
PROD_ADMIN_PASSWORD=<strong-password>
PROD_ADMIN_NAME=Arovolife Operations
PROD_ADMIN_PHONE=+919999999999

# Auth hardening
PASSWORD_MIN_LENGTH=12
PASSWORD_HIBP_CHECK=true
LOGIN_MAX_ATTEMPTS=5
LOGIN_LOCKOUT_MINUTES=15
BCRYPT_ROUNDS=12

# Phase 12 (leave defaults / unset for Phase 1)
# MFA_REQUIRED_FOR_ADMINS=false
# MFA_REQUIRED_FOR_DISTRIBUTORS=false
# OTEL_*=
# PROMETHEUS_METRICS_ENABLED=false
```

---

## §B — Operator quick-reference card

```
SSH:          ssh master@<server-ip>
App root:     /home/master/applications/ahdhesuhty/public_html/app
Webroot:      /home/master/applications/ahdhesuhty/public_html/app/public
Logs:         storage/logs/laravel.log
Deploy:       cd app && git pull && composer install --no-dev -o && npm ci && npm run build && \
              php artisan migrate --force && php artisan config:cache && \
              php artisan route:cache && php artisan view:cache && \
              php artisan queue:restart
Rollback:     git reset --hard <good-sha> && repeat the build steps above
DB backup:    mysqldump … | gzip > ~/backups/<ts>.sql.gz
Tail logs:    tail -F storage/logs/laravel.log
Failed jobs:  php artisan queue:failed
Open tinker:  php artisan tinker
```
