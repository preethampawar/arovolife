# Cloudways deployment runbook — arovolife

> **Repo**: `git@github.com:preethampawar/arovolife.git`, branch `main`
> **Routine deploy**: §2.1 (staging and production, step by step)

| | Staging | Production |
|---|---|---|
| Cloudways server | `1611779` (`karonix-8GB-Server`, shared with other apps) | `1674229` (`arovolife-prod-4GB`, dedicated) |
| Cloudways app | `6390605` `arovolife-staging` | `6692015` `arovolife-prod` |
| App sys_user (the `<app>` in paths) | `ahdhesuhty` | `hrnpvxpkgw` |
| SSH | `master_mvgumpkwtu@139.59.92.229` | `master_hkmtmetvcf@139.59.92.246` |
| App root | `/home/master/applications/ahdhesuhty/public_html/app` | `/home/master/applications/hrnpvxpkgw/public_html/app` |
| URL (also the `--health-url`) | `https://phplaravel-1611779-6390605.cloudwaysapps.com/` | `https://arovolife.com/` |
| CLI PHP | `php` (8.4) | **`php8.4`** — bare `php` is 8.2 on this server |
| Redis ACL | not enforced — leave `REDIS_USERNAME/PASSWORD/PREFIX` unset | enforced — `.env` needs `REDIS_USERNAME`, `REDIS_PASSWORD` and `REDIS_PREFIX=hrnpvxpkgw:` |
| Queue workers | flock'd master crontab (§1.9) | flock'd master crontab (§1.9) |

In the paths below, `<app>` means the sys_user from this table.

The Laravel project lives at `…/public_html/app/` and Laravel's own `public/`
folder is the webroot. Cloudways must be configured to serve from
`app/public`, not the default `public_html`.

`public_html` is a synced copy with **no `.git`** — Cloudways keeps the clone
in `/home/master/applications/<app>/git_repo`. So code reaches the server
only through the Cloudways *Pull*, never through `git` run in `public_html`,
and `app:status` reports `commit=unknown`.

---

## 0. Prerequisites (verify once before first deploy)

| Check | How |
|---|---|
| PHP version ≥ 8.3 | Cloudways → *Server Management → Settings & Packages → PHP* → choose **8.3** (or 8.4 if available) |
| MySQL 8.0 | Cloudways → *Server Management → Settings & Packages → MySQL* |
| Required PHP extensions | `bcmath, ctype, curl, dom, fileinfo, gd, mbstring, openssl, pdo_mysql, redis, tokenizer, xml, zip` — all enabled by default on Cloudways but verify under *Settings & Packages → Advanced* |
| Composer 2 | `composer --version` (Cloudways ships v2 by default) |
| Node LTS for asset builds | Vite 8 requires `^20.19.0 \|\| >=22.12.0`. The Debian system Node at `/usr/bin/node` is **20.5.1 and cannot build** — always build from nvm, never the system binary. Install with `nvm install --lts && nvm alias default 'lts/*'` (per-master-user nvm; ssh key is master-user scoped). nvm is **not** sourced by any login profile, so each build shell must run `export NVM_DIR="$HOME/.nvm"; . "$NVM_DIR/nvm.sh"` first — this is also why the nvm default is safe on a shared server: it changes nothing for the other apps, which keep resolving to the system Node. |
| Redis enabled | *Settings & Packages → Advanced → Redis* → **ON**. ACL is on, so also grab the per-app username / password / prefix — see §1.5 |
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

> **How staging and production are actually set up:** through Cloudways
> *Application → Deployment via Git* (repo `git@github.com:preethampawar/arovolife.git`,
> branch `main`, deploy path `public_html`). Cloudways keeps the clone in
> `/home/master/applications/<app>/git_repo` and syncs the files into
> `public_html`, which is why `public_html` has no `.git`. The manual clone
> below is the fallback for a server without that feature; if you use it,
> §2.1 Step 1 becomes `git pull` in `public_html`.

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
# nvm is not auto-sourced — activate it, or you get the system Node 20.5.1
# and the build fails. Confirm `node -v` reports the LTS before building.
export NVM_DIR="$HOME/.nvm"; . "$NVM_DIR/nvm.sh"; nvm use default
node -v

npm ci        # re-run after any Node major change: native addons are ABI-specific
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
- `SESSION_DRIVER=redis`, `CACHE_STORE=redis`, `QUEUE_CONNECTION=database` — and it stays `database` on Cloudways too; the Supervisord panel's `redis` label is a config alias onto the database driver (§1.9)

  > **`CACHE_STORE`, not `CACHE_DRIVER`.** Laravel 11 renamed the key.
  > `config/cache.php` reads `env('CACHE_STORE', 'database')`, so a `.env`
  > carrying the old `CACHE_DRIVER` name gets no error — the app silently
  > serves every cache read, cache lock, Pennant flag lookup and rate-limiter
  > bucket out of MySQL. `php artisan app:deploy` now hard-fails when it
  > finds the stale key. Confirm with:
  > `php artisan tinker --execute="echo config('cache.default');"` → `redis`.

- `REDIS_HOST=127.0.0.1`, `REDIS_PORT=6379`, and **nothing else** — leave
  `REDIS_USERNAME`, `REDIS_PASSWORD` and `REDIS_PREFIX` unset.

  > ⚠ **Do not copy the triplet out of *Access Details → Redis*.** Cloudways
  > displays a per-app prefix / username / password and reports
  > `redis_acl_status: 1`, but on server 1611779 none of it is applied —
  > verified 2026-08-28:
  >
  > ```
  > redis-cli ACL GETUSER ahdhesuhty        → (nil)      # user was never created
  > redis-cli --user ahdhesuhty --pass …    → WRONGPASS
  > redis-cli PING                          → PONG       # default is still nopass
  > ```
  >
  > Putting those values in `.env` fails every Redis call, which takes down
  > **sessions as well as cache** — they share the connection. Staging went
  > down this way once.
  >
  > Regenerating the password in the console does not help: re-tested the same
  > day with a fresh password and `ACL GETUSER` was still `(nil)`. Saving the
  > panel also did *not* restrict `default`, so nothing else on the box broke —
  > but nothing was provisioned either.

  Redis is therefore unauthenticated and shared with the other eight apps on
  the box. Hardening it is a real task, not a checkbox:

  1. Ask Cloudways support what saving the ACL panel does to the `default`
     user. The same server hosts karonix and wineslekka **production**, all
     connecting unauthenticated — if `default` becomes restricted they break
     simultaneously.
  2. Apply the panel, then `redis-cli ACL GETUSER <sys_user>` to confirm the
     user now exists, and an unauthenticated `redis-cli PING` to confirm the
     other apps still connect.
  3. Only then add the triplet to arovolife's `.env`, with `.env.bak` ready.
  4. Setting `REDIS_PREFIX` re-namespaces the session keyspace and signs every
     logged-in distributor out — maintenance window on production.
- `MAIL_*` — real SMTP/SES creds (not `log`)
- `KYC_*`, `SMS_*`, `WHATSAPP_*` — live provider keys
- `FILESYSTEM_DISK=s3` plus AWS keys (KYC document encryption-at-rest)

### 1.6 Database + first deploy

The first deploy and every subsequent one go through the same artisan
command, `php artisan app:deploy`. On a fresh install you only need to
make sure `PROD_ADMIN_*` is in `.env` so the seeder can provision an
initial admin user.

```bash
cd /home/master/applications/<app>/public_html/app

# Confirm these are already set in .env (otherwise add them once):
#   PROD_ADMIN_EMAIL=ops@arovolife.com
#   PROD_ADMIN_PASSWORD=<strong-password>
#   PROD_ADMIN_NAME=Arovolife Operations

# Same shell setup and command as a routine deploy — see §2.1 for the
# per-environment version (production needs php8.4).
php artisan app:deploy --maintenance --health-url=<environment URL>
```

The command runs composer install, vite build, `storage:link`,
migrations under maintenance mode, the idempotent `ProductionSeeder`
(which provisions the admin user from `PROD_ADMIN_*`, ledger accounts,
settings, feature flags, placeholder content pages and a placeholder
catalogue), cache rebuilds, queue restart, and an HTTP smoke test.

> **Do not** run `db:seed` with the default seeder in production — it
> seeds demo distributors with PII via `DemoDownlineSeeder`.
> `ProductionSeeder` is the only seeder safe to run on prod: it inserts
> roles, the admin (from env), settings, feature flags, content pages,
> the chart of accounts, and a placeholder catalogue — all using
> "create-if-missing" semantics so admin-edited values are never
> overwritten on subsequent runs.

### 1.7 Storage + permissions (only if §1.6 reported a permissions error)

`app:deploy` already calls `storage:link` and rebuilds caches, but it
cannot `chown`. If you see "Permission denied" while writing to
`storage/logs/` or `bootstrap/cache/`, run:

```bash
cd /home/master/applications/ahdhesuhty/public_html/app

# Master user runs PHP-FPM under `master`; storage and bootstrap/cache
# must be writable by that user.
chmod -R 775 storage bootstrap/cache
chown -R master:www-data storage bootstrap/cache 2>/dev/null || true
```

You can also click **Application → Application Settings → Reset
Permissions** in the Cloudways UI for the equivalent.

### 1.8 First-boot caches (only if §1.6 was skipped)

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

### 1.9 Queue workers

**Current setup on both servers: a flock'd master crontab.** Cloudways'
Supervisord form can only generate `queue:work redis …` jobs, so both
environments run their workers from the master user's crontab instead
(`crontab -l` as the SSH user). One line per queue; `flock -n` guarantees at
most one worker per queue — which `compensation` requires — and
`--stop-when-empty` lets each worker exit once its queue drains, so cron
starts a fresh one (on fresh code) within a minute.

Production (`/usr/bin/php8.4` — the CLI `php` is 8.2):

```cron
# arovolife-prod — scheduler + queue workers (database driver, ADR-0011). php8.4: CLI php is 8.2.
* * * * * cd /home/master/applications/hrnpvxpkgw/public_html/app && umask 002 && /usr/bin/php8.4 artisan schedule:run >> /dev/null 2>&1
* * * * * flock -n /tmp/arovolife-prod-q-otp.lock -c "cd /home/master/applications/hrnpvxpkgw/public_html/app && umask 002 && /usr/bin/php8.4 artisan queue:work database --queue=otp --stop-when-empty --tries=3 --timeout=120" >> /dev/null 2>&1
* * * * * flock -n /tmp/arovolife-prod-q-default.lock -c "cd /home/master/applications/hrnpvxpkgw/public_html/app && umask 002 && /usr/bin/php8.4 artisan queue:work database --queue=default --stop-when-empty --tries=3 --timeout=120" >> /dev/null 2>&1
* * * * * flock -n /tmp/arovolife-prod-q-compensation.lock -c "cd /home/master/applications/hrnpvxpkgw/public_html/app && umask 002 && /usr/bin/php8.4 artisan queue:work database --queue=compensation --stop-when-empty --tries=1 --timeout=3600" >> /dev/null 2>&1
```

Staging (same shape, plain `php`, lock files `/tmp/arovolife-q-<queue>.lock`;
its scheduler runs from *Cron Job Management*, §1.10). The staging box is
shared with other apps — edit only the arovolife lines.

```cron
* * * * * flock -n /tmp/arovolife-q-otp.lock -c 'cd /home/master/applications/ahdhesuhty/public_html/app && umask 002 && php artisan queue:work database --queue=otp --stop-when-empty --tries=3 --timeout=120' >> /dev/null 2>&1
* * * * * flock -n /tmp/arovolife-q-default.lock -c 'cd /home/master/applications/ahdhesuhty/public_html/app && umask 002 && php artisan queue:work database --queue=default --stop-when-empty --tries=3 --timeout=120' >> /dev/null 2>&1
* * * * * flock -n /tmp/arovolife-q-compensation.lock -c 'cd /home/master/applications/ahdhesuhty/public_html/app && umask 002 && php artisan queue:work database --queue=compensation --stop-when-empty --tries=1 --timeout=3600' >> /dev/null 2>&1
```

An idle queue therefore has no worker process at all; `app:status` reads
that as OK (§2.3). `queue:restart` needs no follow-up — running workers exit
and cron respawns them.

The Supervisord Jobs described below are the original setup and are kept
for reference. Staging still has them; `app:status` counts them only when
their artisan path is this app's.

#### Supervisord Jobs (original setup)

*Application → Application Settings → Supervisord Jobs → Add New Job.* The
panel is a form, not a command line. Its **Connection Driver** field is
read-only and always says `redis`; the artisan command it generates is
`queue:work redis --queue=… --sleep=… --tries=… --timeout=…`.

**The queue is NOT on Redis, and that field does not change it.** The
`redis` argument is a connection *name* resolved through `config/queue.php`,
where it is mapped onto the **database driver** — identical to the `database`
connection, defined once so they cannot drift. The worker Cloudways launches
drains the `jobs` table in MySQL. `QUEUE_CONNECTION` stays `database` in the
`.env` on every environment, exactly as `.env.example` ships it. If a staging
`.env` says `QUEUE_CONNECTION=redis`, correct it — it was set that way before
the alias existed, and it still works only by accident of both names now
meaning the same thing.

Why not Redis: the server's Redis is shared with eight other applications
under `maxmemory-policy allkeys-lfu`, which may evict any key — including a
queued job — silently, with no exception, no `failed_jobs` row and no log
line. On the compensation path that is a distributor not credited with
nothing anywhere saying so. `CACHE_STORE` and `SESSION_DRIVER` stay on Redis;
a lost cache entry is regenerated, a lost job is not.

Three jobs, not one. Every queued class used to land on `default` behind a
single pool, so an engine chain or a full recompute — minutes to hours of
ledger work — parked in front of every OTP, order confirmation and KYC mail
on the platform. Priority ordering on one pool would not fix that: a long
job still occupies the worker it is holding. And the panel's **Queue field
accepts exactly one name** — lowercase alphanumeric, no commas — so
`otp,default` is rejected with "Only lowercase alphanumeric characters are
allowed". One queue, one job.

**Job 1 — OTP delivery** (edit the existing `Job_1`)

| Field | Value |
|---|---|
| Connection Driver | `redis` (read-only; see above) |
| Number of Processes | `1` |
| Timeout (Seconds) | `120` |
| Sleep time (Seconds) | `3` |
| Queue | `otp` |
| Maximum Tries | `3` |
| Environment | *(blank)* |
| Artisan Path | `public_html/app/artisan` |

A verification code is the one queued job a human is actively waiting on.
Its own worker means it never queues behind a burst of order mail.

**Job 2 — everything else distributor-facing** (add new)

| Field | Value |
|---|---|
| Connection Driver | `redis` (read-only; see above) |
| Number of Processes | `2` |
| Timeout (Seconds) | `120` |
| Sleep time (Seconds) | `3` |
| Queue | `default` |
| Maximum Tries | `3` |
| Environment | *(blank)* |
| Artisan Path | `public_html/app/artisan` |

**Job 3 — compensation engines** (add new)

| Field | Value |
|---|---|
| Connection Driver | `redis` (read-only; see above) |
| Number of Processes | `1` (**do not raise this**) |
| Timeout (Seconds) | `999` (the panel's maximum — see below) |
| Sleep time (Seconds) | `3` |
| Queue | `compensation` |
| Maximum Tries | `1` |
| Environment | *(blank)* |
| Artisan Path | `public_html/app/artisan` |

One process is a correctness requirement, not a capacity choice. The
connection's `retry_after` is 90s while `RunEngineChainJob` and
`RecomputeAllJob` set timeouts of 3600s and 7200s, so a long job goes
"available" again while it is still running. With a single worker there is
nothing to re-reserve it; with two, the second picks it up and the ledger
takes concurrent writes (R-61).

**Why Timeout is 999 and not 7200.** The panel refuses anything above 999
seconds (verified on staging 2026-08-29). That is fine: the field becomes
`queue:work --timeout=999`, and Laravel gives a job's own `$timeout`
property precedence over the command-line value
(`Illuminate\Queue\Worker::timeoutForJob`). `RunEngineChainJob` (3600s) and
`RecomputeAllJob` (7200s) both declare theirs, so the panel value governs
only the short jobs on this queue (group-BV propagation and the like),
which finish in seconds. Do not leave the panel default of 60 either — it
would still kill those short jobs' worker restarts at the one-minute mark.

**Why Job 3 has Tries 1 when the others have 3.** `--tries` is how many
times Laravel re-runs a job that throws or is killed, with nobody in the
loop. For an OTP that is right — resending is harmless. On the money path
the retry itself is safe — every engine is either one transaction or
per-row idempotent, and `wallet_ledger_entries` is UNIQUE on
`(type, reference_type, reference_id)`, so a replay cannot double-credit —
but it is *blind*: it re-runs an engine chain before anyone has read why it
stopped, and with tries 3 the first two failures never reach `failed_jobs`
at all. (R-61 is the separate, concurrent case: two workers on one job.)
With tries 1 the first failure lands in `failed_jobs`, shows in Pulse, and
the run is marked failed on the Engine Runs page, where it is re-triggered
deliberately once the cause is known; the Ledger column on Run events lists
what the failed run committed before it stopped. The cost is that a transient blip
also fails the job until someone looks; on this queue a visible failure
that waits for a human beats an invisible retry that may double-pay. No
compensation job declares its own `$tries`, so the panel value is the one
that applies. `docker/docker-compose.yml` mirrors these values locally
(`--tries=1 --timeout=7200` on `queue-compensation`; the panel caps at 999,
which is equivalent because the long jobs carry their own `$timeout`).
Rationale: ADR-0011.

Two panel defaults bite: **Timeout 60** (above) and **Artisan Path
`public_html/artisan`**, which is wrong for this repo — Laravel lives one
level down, so the job starts, finds no artisan, and sits in FATAL. Confirm
both jobs show RUNNING afterwards (*View Jobs Status*, or the MCP
`supervisord_queue_status`). `QueueRoutingTest` asserts every job in
`app/Modules/Compensation/Jobs/` carries `onQueue('compensation')` and that
the `redis` connection is still the database driver.

Total worker processes go from 1 to 4. Restart all three jobs after every deploy so
they pick up new code.

### 1.10 Scheduler

*Application → Application Settings → Cron Job Management* → **Add Basic
Cron Job**:

| Field | Value |
|---|---|
| Time interval | `* * * * *` (every minute) |
| Command | `php /home/master/applications/ahdhesuhty/public_html/app/artisan schedule:run >> /dev/null 2>&1` |

This drives cooling-off reminders (D-7 / D-1), audit-log
compaction, and any future scheduled jobs. That is the staging setup
(path `ahdhesuhty`). Production runs `schedule:run` from the master
crontab with `/usr/bin/php8.4` instead (§1.9), because the panel cron
would use the 8.2 CLI.

The schedule also writes a heartbeat every minute
(`ops:scheduler-heartbeat` in `routes/console.php`); `app:status` fails
its Scheduler row when that is more than 3 minutes old.

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
2. Hit `/register?sponsor=111222333&placement=111222333` (the L0 ADN) — confirm referral resolves.
3. Sign in as the admin seed user, click any distributor → "Impersonate" → confirm banner shows and "Return to admin" works.
4. Open `/admin/tree` — confirm the company tree renders.

---

## 2. Subsequent deploys (the routine path)

Every post-pull task is wrapped in a single artisan command,
`php artisan app:deploy` (defined at
`app/app/Console/Commands/DeployCommand.php`). It runs composer install
`--no-dev`, `npm ci && npm run build`, `storage:link`, migrations
(optionally inside maintenance mode), the idempotent `ProductionSeeder`,
config/route/view/event cache rebuilds, `queue:restart`, an HTTP
smoke test against `--health-url`, a one-line-per-step summary, and a
closing `app:status` service health table (see §2.3). Every line is teed to
`storage/logs/deploy.log` with ISO-8601 timestamps. Refuses to run
unless `APP_ENV` is `staging` or `production`.

A deploy is always two moves: **pull the code** (Cloudways), then **run
`app:deploy`** (SSH). The pull alone copies files and nothing else — no
composer, no Vite build, no migrations, no cache rebuild, no queue restart.

### 2.1 Step by step — staging, then production

Deploy to staging first, check it, then repeat on production.

**Step 0 — before you start (laptop)**

1. The commit is on `main` and pushed: `git log origin/main -1`.
2. Scan the diff for `migrations/`, `composer.lock`, `package-lock.json`,
   `config/` and `database/seeders/ProductionSeeder.php` so you know which
   steps will do real work.
3. Production only: if a migration drops or rewrites data, take a backup
   first (§3c).

**Step 1 — pull the code (Cloudways)**

Either the panel: *Application → Deployment via Git → Pull* (branch `main`),
or the Cloudways MCP / API:

```
tool:      execute_tool  (toolset: git, tool_name: git_pull)
staging:   {"server_id":"1611779","app_id":"6390605","branch_name":"main"}
production:{"server_id":"1674229","app_id":"6692015","branch_name":"main"}
```

The call is asynchronous: poll `operation_status` with the returned
`operation_id` until it reports completed. Never run `git` inside
`public_html` — it has no `.git` (see the table at the top).

**Step 2 — run the deploy (SSH)**

Staging:

```bash
ssh master_mvgumpkwtu@139.59.92.229
export NVM_DIR="$HOME/.nvm"; . "$NVM_DIR/nvm.sh"; nvm use default   # Node v24 for the Vite build
cd /home/master/applications/ahdhesuhty/public_html/app
php artisan app:deploy --maintenance \
    --health-url=https://phplaravel-1611779-6390605.cloudwaysapps.com/
```

Production:

```bash
ssh master_hkmtmetvcf@139.59.92.246
export PATH="$HOME/bin:$PATH"                                       # ~/bin/php -> 8.4 (composer calls php)
export NVM_DIR="$HOME/.nvm"; . "$NVM_DIR/nvm.sh"; nvm use default   # Node v24 for the Vite build
cd /home/master/applications/hrnpvxpkgw/public_html/app
php8.4 artisan app:deploy --maintenance --health-url=https://arovolife.com/
```

The same thing as one command from the laptop (production shown):

```bash
ssh master_hkmtmetvcf@139.59.92.246 'export PATH="$HOME/bin:$PATH"; export NVM_DIR="$HOME/.nvm"; . "$NVM_DIR/nvm.sh"; nvm use default; cd /home/master/applications/hrnpvxpkgw/public_html/app && php8.4 artisan app:deploy --maintenance --health-url=https://arovolife.com/'
```

Why the shell setup matters: nvm is not sourced by any login profile, so
without it the build runs on the system Node 20.5.1 and Vite 8 refuses it;
on production, bare `php` is 8.2, so composer and artisan must see PHP 8.4.

**Step 3 — read the result**

Watch it stream `▶ <step>` / `✓` / `✘` lines. It ends with:

- `── deploy summary ──` — one line per step: `✓`, `✘ failed` (a hard step;
  everything after it was skipped and the command exits non-zero) or
  `⚠ warning` (a soft step; the deploy carried on).
- The `app:status` table (§2.3) — every row should be `OK`.
- `✓ deploy complete`, or `✘ deploy finished with errors`.

Tail the persisted copy from another shell if you want:

```bash
tail -F /home/master/applications/<app>/public_html/app/storage/logs/deploy.log
```

**Step 4 — spot-check**

1. Open the environment URL and sign in.
2. If the commit changed a held content page, publish it deliberately:
   `php artisan content:publish <slug>` — the deploy never *rewrites* a
   published page. It does publish a consent page (terms, ethics,
   compensation, privacy) that is still a draft or missing
   (`content:publish … --if-unpublished`), because registration refuses
   while any of them is unpublished.
3. Re-run the health report any time: `php artisan app:status` (production:
   `php8.4 artisan app:status`).

### 2.2 Useful flag combinations

Production: use `php8.4 artisan` and the same shell setup as §2.1.

| Scenario | Command |
|---|---|
| Code-only redeploy (composer.lock + package-lock.json unchanged) | `php artisan app:deploy --skip-composer --skip-npm --health-url=…` |
| Frontend-only redeploy | `php artisan app:deploy --skip-migrate --skip-seed --health-url=…` |
| Hot-fix (no migrations, no maintenance window) | `php artisan app:deploy --skip-migrate --skip-seed --health-url=…` |
| Health report only (nothing changed on disk that needs a build) | `php artisan app:status` |
| Dry inspection of what would run | `php artisan app:deploy --skip-composer --skip-npm --skip-migrate --skip-seed --skip-cache --skip-queue` |

### 2.3 Service health report (`app:status`)

The last step of every deploy runs `php artisan app:status --wait=75` in a
fresh process. It is read-only and can be run by hand at any time:

| Check | FAIL when | WARN when |
|---|---|---|
| Release | — (shows env, commit, PHP version — catch the CLI-is-8.2 trap here; `commit=unknown` is normal, `public_html` has no `.git`) | — |
| Maintenance mode | the app is still down | — |
| Database | `select 1` fails | — |
| Cache | a write/read round-trip on the default store fails | — |
| Migrations | any migration is pending | — |
| Worker: otp / default / compensation | a runnable job has waited over 2 minutes and no `queue:work` process of this app drains that queue | a job is waiting and cron has not started a worker yet |
| Queue backlog | — | a job has waited over 15 minutes |
| Failed jobs | — | any failure in the last 24 hours |
| Scheduler | the per-minute heartbeat is older than 3 minutes | no heartbeat yet (first deploy of it, or a cache flush) |

Both servers launch workers from a flock'd crontab with
`--stop-when-empty` (§1.9), so an idle queue has no worker process at all —
that reads as OK ("idle — no jobs waiting"). The check fails only when work
is waiting with nobody to take it. Only PHP processes whose artisan resolves
to this app's own are counted: the staging box also runs other apps' workers
on a queue named `default`, and the `flock` / `sh -c` launchers are not
workers. `--wait` keeps polling because `queue:restart` only signals workers
to exit and cron respawns them within a minute.

A failing status check is reported as `⚠ service status` and does **not**
fail the deploy — the release is already live, so act on the table instead.
Skip it with `--skip-status`; change the wait with `--status-wait=<seconds>`.

### 2.4 Cloudways Deploy Hook (alternative — currently unused)

The same command works as the Cloudways Deploy Hook if you ever want
push-to-deploy. Configure under *Application → Application Settings →
Deploy Hooks* — the §2.1 Step 2 command for that environment, including its
shell setup (nvm, and `php8.4` on production).

Deploys stay manual (§2.1) until the team is ready for hands-off CD.

---

## 3. Rollback

Two rollback strategies depending on what broke.

### 3a. Code rollback only

`public_html` has no `.git`, so the server cannot be reset to an old commit.
Roll back through `main` instead — `git revert` keeps history and needs no
force-push:

```bash
# Laptop
git log --oneline -5                   # find the bad commit(s)
git revert <bad-sha>                   # one revert commit per bad commit
git push origin main
```

Then §2.1 Step 1 (pull) and Step 2 with no migrations to re-run:

```bash
php artisan app:deploy --skip-migrate --skip-seed --health-url=<environment URL>
```

(Production: `php8.4 artisan …` after the §2.1 shell setup.)

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
ssh master_hkmtmetvcf@139.59.92.246    # staging: master_mvgumpkwtu@139.59.92.229
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
| Failed jobs | `/pulse` (see §4.1), or `php artisan queue:failed` |
| Audit log volume spike | Check `audit_log` table count daily; sudden 10× = suspicious |

Set up Cloudways → *Server Management → Monitoring → Alerts* to email
the on-call when CPU > 80% for 5 min, disk > 85%, or load > 6.

---

### 4.1 Pulse

`/pulse` — queue throughput and wait time, slow jobs, slow queries, slow
requests, exceptions and cache hit rates. This is the platform's only queue
observability: before it, a failed job landed in `failed_jobs` and nobody was
told, which on a bonus-crediting job means a distributor finds the gap before
we do.

**Access.** Restricted to the `developer` role by the middleware in
`config/pulse.php`. Admins get 403 — Pulse shows job payloads and exception
traces, so it is a developer surface like feature flags and plan settings.
Note that a Gate alone cannot hold it: `AppServiceProvider`'s `Gate::before`
answers true for every super-staff user before any definition is consulted, so
the middleware is what actually closes the door. DEV-13/14/15 in
`DeveloperRoleTest` assert developer 200 / admin 403 / guest redirect — if
those ever go green-to-red after a middleware change, the dashboard is exposed.

**Storage.** Recorders write to the application database on the default
`storage` ingest driver, so there is no extra daemon and no Supervisor entry.
Retention is 7 days, trimmed by lottery on ingest. Deliberately not the
server's Redis — see §1.5.

**Killswitch.** `PULSE_ENABLED=false` in `.env` then `php artisan config:cache`
stops all recording without removing the package. Reach for it if ingest write
volume ever shows up in the MySQL slow log.

**Not wired up.** The `Servers` recorder needs `php artisan pulse:check`
running as a daemon. It is left unconfigured — a second always-on process for
host metrics that Cloudways *Server Management → Monitoring* already reports.
The Servers card on the dashboard stays empty as a result; that is expected.

---

## 5. Common pitfalls

| Symptom | Cause | Fix |
|---|---|---|
| 404 on `/` | Webroot still default | Set webroot to `app/public` (§1.2), reload Apache |
| 500 with empty page | `APP_DEBUG=false` masking config error | `tail storage/logs/laravel.log`; usually a missing env var |
| Mixed-content warnings after SSL | `APP_URL` still `http://` | Update `APP_URL` to `https://…`, re-cache config |
| Sessions not persisting after login | `SESSION_DOMAIN` mismatch | Set to `.arovolife.com` (leading dot for subdomains) |
| Queue jobs appear stuck | Old worker still running pre-deploy code | `php artisan queue:restart` |
| `migrate` fails with `Class "…" not found` right after composer added a package | `app:deploy` (pre-2026-08-29) ran migrations in the same process that ran `composer install`, on the old autoloader | Re-run `php artisan app:deploy …` — composer is a no-op and the fresh process sees the package. Fixed: post-composer artisan steps now run in a child process |
| Emails send but no bonus is ever credited | Supervisord Job 3 (queue `compensation`) is down or was never added | Check *Application Settings → Supervisord Jobs → View Jobs Status*; Jobs 1 and 2 do not drain `compensation` |
| A new compensation job never runs | Job missing `onQueue('compensation')`, so it sits on `default` | `QueueRoutingTest` catches this — run it before deploying |
| Duplicate ledger writes from one engine run | Job 3 raised above 1 process | Return it to 1; `retry_after` (90s) is far below the engine timeouts, so a second worker re-reserves a job that is still running (R-61) |
| Every engine run fails at exactly 60s | Job 3 left on the panel's default Timeout of 60 | Set Timeout to 999, the panel maximum (§1.9) |
| Saving a job fails with "Only lowercase alphanumeric characters are allowed" | Queue field given a comma list such as `otp,default` | One queue name per job; that is why there are three jobs (§1.9) |
| A Supervisord job shows FATAL and never RUNNING | Artisan Path left at the panel default `public_html/artisan` | Set it to `public_html/app/artisan` — Laravel is one level down in this repo |
| Jobs run, but `failed_jobs.connection` says `redis` and someone "fixes" `config/queue.php` | The `redis` connection is deliberately the database driver; the name is forced by Cloudways | Leave the alias; `QueueRoutingTest` fails if it is reverted. See §1.9 |
| `npm run build` fails with a Vite / Node version error during `app:deploy` | nvm not sourced, so the system Node 20.5.1 ran | `export NVM_DIR="$HOME/.nvm"; . "$NVM_DIR/nvm.sh"; nvm use default` before the deploy (§2.1) |
| Production composer or artisan fails on PHP 8.4 syntax / platform check | Bare `php` on the production CLI is 8.2 | Use `php8.4 artisan …` and `export PATH="$HOME/bin:$PATH"` (§2.1) |
| Production `migrate` or cache fails with `NOPERM` | Redis ACL is enforced there and `REDIS_PREFIX` is missing | Set `REDIS_USERNAME`, `REDIS_PASSWORD` and `REDIS_PREFIX=hrnpvxpkgw:` in `.env` |
| `git` in `public_html` says "not a git repository" | Cloudways syncs `public_html` from `git_repo`; there is no `.git` there | Pull through Cloudways (§2.1 Step 1); roll back with `git revert` on `main` (§3a) |
| `app:status` Worker row FAILs after a deploy | A job waited over 2 min and no cron worker started | `crontab -l` — the flock'd worker lines must exist and use this app's path (§1.9); check `/tmp/*-q-*.lock` isn't held by a hung worker |
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
# Optional: silently BCC every outgoing email on STAGING for inbox-side
# verification. Comma-separated for multiple recipients. Leave UNSET on prod.
MAIL_GLOBAL_BCC=preetham.pawar@gmail.com
SUPPORT_EMAIL=support@arovolife.com

# Storage (KYC docs)
# All four AWS_* values below are MANDATORY. The app refuses to boot
# without them — see AppServiceProvider::assertS3IsConfigured(). KYC
# documents are PII, so there is no local-disk fallback. Objects land
# under <bucket>/kyc/...
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

# Genealogy bootstrap — no env vars needed. On first deploy (empty
# distributors table) ProductionSeeder builds the 31 reserved company
# distributors (root ADN 444555666, tree levels 0-4, sponsorship edges
# included — see ReservedAdns / R-66). The referral link for the first
# real recruits is: https://APP_URL/register?sponsor=444555666
# The old PROD_ROOT_* single-root vars are retired (2026-08-31).

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
                STAGING                                           PRODUCTION
SSH:            master_mvgumpkwtu@139.59.92.229                   master_hkmtmetvcf@139.59.92.246
App root:       /home/master/applications/ahdhesuhty/public_html/app
                                                                  /home/master/applications/hrnpvxpkgw/public_html/app
URL:            https://phplaravel-1611779-6390605.cloudwaysapps.com/
                                                                  https://arovolife.com/
Artisan:        php artisan                                       php8.4 artisan  (+ export PATH="$HOME/bin:$PATH")
Pull:           git_pull 1611779 / 6390605 / main                 git_pull 1674229 / 6692015 / main

Shell setup:    export NVM_DIR="$HOME/.nvm"; . "$NVM_DIR/nvm.sh"; nvm use default
Deploy:         <artisan> app:deploy --maintenance --health-url=<URL>
Code-only:      <artisan> app:deploy --skip-composer --skip-npm --health-url=<URL>
Health report:  <artisan> app:status
Rollback:       laptop: git revert <bad-sha> && git push origin main; then pull + deploy
                (no git on the server -- public_html has no .git)
App logs:       storage/logs/laravel.log     (tail -F)
Deploy log:     storage/logs/deploy.log      (tail -F)
Failed jobs:    <artisan> queue:failed
Workers:        master crontab, one flock'd line per queue (crontab -l -- §1.9):
                  otp           tries 3  timeout 120
                  default       tries 3  timeout 120
                  compensation  tries 1  timeout 3600  (never >1 process)
                all `queue:work database --stop-when-empty`; cron respawns them each minute
DB backup:      mysqldump … | gzip > ~/backups/<ts>.sql.gz   (§3c)
Open tinker:    <artisan> tinker
```
