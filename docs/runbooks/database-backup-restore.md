# Database backup & restore

Settings → **Database backups** (`/admin/database-backups`), open to the `admin`
and `developer` roles. An admin can list, download and take a backup; restore is
`developer`-only. Every other role gets a 404. The S3 listing is the record of what exists; there is no table of
backups, because a restore would roll that table back too.

## What runs when

- `db:backup:run` daily at **23:45 IST** on production and staging (the day's
  closing state, before the overnight engines start at 00:05). Not on local: local carries the
  staging bucket credentials.
- Each run: gzipped mysqldump of the default connection → verified (exit code 0
  and mysqldump's `-- Dump completed` trailer) → uploaded to the `db-backups`
  disk → backups older than 30 days pruned (only after a successful backup).
- After the database backup, in the same run, two more copies are made (each on
  its own: a failure of either is logged and shown in the mail, and never fails
  or undoes the database backup):
  - the server settings file (`.env`) as a password-protected ZIP,
    `arovolife-{env}-{Ymd-His}-{trigger}.env.zip`, next to the dumps and kept for
    the same 30 days;
  - the local uploads (the `public` disk: catalogue images under `catalog/`)
    mirrored to `files/public/…` in the same bucket folder. Additive only:
    a file is copied when it is missing there or its size changed; nothing is
    ever deleted from either side, and the files are never pruned.
- Every active `admin` and `developer` account gets one mail with the result and
  the compressed size, and a link to the backups page. The admin setting `notifications.backup_status_emails` (up to 10 addresses, comma-separated) adds plain mailboxes that get the same mail without the link. Manual runs and `--no-mail` send nothing.
- Files live under `db-backups/<APP_ENV>/` in the bucket, named
  `arovolife-{env}-{Ymd-His}-{scheduled|manual|pre-restore}.sql.gz` (IST). One
  environment never sees another's backups.

## Environment variables

| Variable | Default | Notes |
|---|---|---|
| `DB_BACKUP_BUCKET` | `AWS_BUCKET` | Bucket for the `db-backups` disk. |
| `DB_BACKUP_DISK` | `db-backups` | Disk the backups are written to. Leave unset on servers. |
| `DB_BACKUP_PHP_BINARY` | resolved by PhpExecutableFinder | Set `/usr/bin/php8.4` on Cloudways — the page starts the CLI from FPM, where the first `php` on PATH is not 8.4. |
| `DB_BACKUP_CLIENT_OPTIONS` | empty | Space-separated options appended to both `mysqldump` and `mysql`, e.g. `--skip-ssl`. |
| `ENV_BACKUP_PASSWORD` | none | Password for the settings-file ZIP. **20+ characters.** Lives only in the server's `.env` — never in the database or the repo. The owner keeps the same password offline. Unset or shorter than 20: no settings copy is made (never an unprotected one), the mail says so, and the page shows an amber notice. |
| `DB_BACKUP_MYSQLDUMP_BINARY` / `DB_BACKUP_MYSQL_BINARY` | `mysqldump` / `mysql` | Client binaries. |

| `AUDIT_ARCHIVE_DISK` | `audit-archives` | Disk the monthly audit log archives are written to. Leave unset on servers. |

The password is passed only through `MYSQL_PWD`, never on the command line.

Signed download addresses stay usable for 24 hours (config `db_backup.download_link_hours`).

**Local docker stack:** the app image carries `mariadb-connector-c` (see
`docker/Dockerfile`) so its MariaDB client can log in to the MySQL 8.0 `db`
container; without that package the dump fails on the missing
`caching_sha2_password` plugin. Set `DB_BACKUP_DISK=local` to try a backup
locally without writing to the shared bucket.

## Audit log archives

**What:** each finished calendar month (IST) of the `audit_log` table is exported
to its own gzipped file. This is export only: nothing is deleted, updated or
truncated in `audit_log`, and the application never prunes these files.

**When:** `audit-log:archive` runs on the 1st of every month at 05:00 IST
(production and staging), after the month-start engines. It archives the month
that just ended plus any earlier finished month that has no file yet. The daily
backup mail adds a line when months are still waiting.

**Where:** the `audit-archives` disk, in the backup bucket under
`audit-log-archives/<APP_ENV>/`, one file per month:
`arovolife-<env>-audit-log-YYYY-MM.jsonl.gz`. Files can also be downloaded from
**Settings → Database backups → Audit log archives** (each download is audit-logged).
Set `AUDIT_ARCHIVE_DISK` only to point the disk elsewhere.

**Format:** gzip, one JSON object per line. Line 1 is the manifest:
`{"manifest":true,"environment","month","timezone":"Asia/Kolkata","rows","first_id","last_id","first_prev_hash","last_row_hash","generated_at"}`.
Every following line is one row in id order: `id, actor_id, action, subject_type,
subject_id, before_hash, after_hash, row_hash, prev_hash` (hex), `hash_version`
(integer, null for rows sealed by the first hashing method), `details` (JSON),
`ip`, `created_at`. A month with no rows is a manifest-only file. A file is
uploaded only after it has been read back and the line count and last id match.

**Manual run:**

```
php artisan audit-log:archive                  # every finished month not yet archived
php artisan audit-log:archive --month=2026-10  # one month (skipped if the file exists)
php artisan audit-log:archive --month=2026-10 --force   # rewrite that file
```

The current month is refused. A failure is logged as `audit_archive.failed`
(critical) and the command exits non-zero; other months still run.

## Opening a settings copy

Download the `.env.zip` from the page (Settings file copies) or the bucket, and
open it with the backup password using **Keka or 7-Zip**. macOS's built-in
Archive Utility cannot open AES-256 ZIPs. The ZIP holds one file, `.env`; to
restore it, put that file back at the application root on the server. The copy
contains the keys (`APP_KEY`, `PII_ENCRYPTION_KEY`) that make the encrypted
columns in a dump readable — treat it like the password it is.

## Manual backup (CLI)

```
php artisan db:backup:run --trigger=manual
```

(On Cloudways use `php8.4 artisan …`.)

## Manual restore (CLI)

```
php artisan db:backup:restore arovolife-production-20261119-050000-scheduled.sql.gz
```

In order: download and verify the backup → `php artisan down` → take a
`pre-restore` safety backup of the current database (if that fails: `up`, and
nothing changed) → drop all views and tables → import → audit-log
`admin.database_restored` → `php artisan up`.

After a restore: if the code is newer than the backup, run `php artisan migrate`.
Cached values may be stale for a few minutes. Never `cache:clear` — the Redis is
shared with other apps.

## A restore does not undo real money

A restore rewinds the database to the backup's time. Razorpay and the bank are
not rewound.

- Customer payments made after the backup disappear from the site, but the
  customers have still been charged.
- Payouts sent after the backup look unpaid again, so the next payout run could
  pay them a second time.
- Bonuses and wallet entries computed after the backup are gone; the engines
  must be re-run.

Before restoring production: note the backup's time, compare Razorpay payments
and bank / RazorpayX payouts from that time onward, and reconcile them (re-enter
or refund the payments, mark the payouts paid) before the next payout run.

## When a restore fails midway

If the import fails after the drop, the site **stays in maintenance mode** on
purpose and the error (and a `db_backup.restore_failed_after_drop` critical log
line) names the safety backup. Recover with:

```
php artisan db:backup:restore <the pre-restore file named in the error>
php artisan up
```

Only one backup or restore runs at a time; a second is refused until the first
finishes (lock held up to 4 hours).

## Rebuild after losing the whole server

A dump alone cannot rebuild a server: without the `.env` keys the encrypted PAN
and bank columns are unreadable, and the catalogue images lived on the server's
own disk. In order:

1. Create the new server and app, with the cron lines, as in
   `docs/runbooks/cloudways-deployment.md`.
2. Deploy the code from git.
3. Download the newest `.env.zip` from the bucket (`db-backups/<APP_ENV>/`),
   unzip it with the backup password (Keka or 7-Zip), and place `.env` at the
   application root. Adjust only what differs on the new server (database host
   and credentials).
4. `php artisan db:backup:restore <file>` — the newest `.sql.gz` from the bucket.
5. `php artisan db:backup:files-restore public` — copies back every saved upload
   that is missing; it never overwrites or deletes.
6. `php artisan storage:link`
7. `php artisan up`

The documents on S3 (KYC scans, grievance attachments, payout files) are not
touched by a server loss and need no restore. (On Cloudways use `php8.4 artisan …`.)

## S3 versioning (recommended, not yet applied)

Versioning on the documents bucket protects against an overwritten or deleted
document. These must be run by the owner, with credentials allowed to change the
bucket; nothing in the application runs them.

```
aws s3api put-bucket-versioning --bucket <bucket-name> --versioning-configuration Status=Enabled

aws s3api put-bucket-lifecycle-configuration --bucket <bucket-name> --lifecycle-configuration '{"Rules":[{"ID":"expire-noncurrent-30d","Status":"Enabled","Filter":{},"NoncurrentVersionExpiration":{"NoncurrentDays":30}}]}'
```

The second command replaces the bucket's whole lifecycle configuration; if the
bucket already has rules, add this one to them instead of overwriting.
