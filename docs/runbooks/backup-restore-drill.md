# Backup & restore drill (T-5.7)

The exit gate asks for "backup + restore drill into staging". This is the
procedure, and the evidence template that has to be filled in when it is run.

> **Why a drill and not just a backup.** An untested backup is a belief, not a
> control. The failure modes that matter — a dump that excludes a table, a
> restore that silently drops rows on a foreign key, a `mysqldump` that
> succeeded but wrote a truncated file because the disk filled — all look
> exactly like a healthy backup until the day you need it. The only way to know
> the restore works is to perform it.

---

## What is being protected

Production data lives in two places, and both must be in scope:

| Store | What is in it | Loss impact |
|---|---|---|
| MySQL (Amazon RDS; **not** Cloudways-managed) | Everything — distributors, KYC references, the double-entry ledger, BV ledger, bonus results, grievances, orders | Unrecoverable. The ledger is append-only and is the record of every rupee owed |
| Application storage (`storage/app`) | KYC document images, grievance attachments | PII under DPDP; also the evidence behind every KYC approval |

A database restore without the matching storage restore leaves KYC rows
pointing at objects that do not exist, so the two are drilled together.

---

## Before you start

- **Never run this against production.** The restore step overwrites the
  target database entirely.
- Confirm the target is the staging application on Cloudways and that its
  `DB_DATABASE` is the staging database, not the production one. The two are
  on the same RDS instance.
- Announce the window. Staging is unusable while the restore runs and anyone
  mid-UAT will lose their session data.
- Have somewhere off-server to hold the dump. A production dump contains PII
  and must not sit on a shared server any longer than the drill takes.

---

## Procedure

### 1. Take the dump

```bash
# On the production application server.
mysqldump \
  --host="$DB_HOST" --user="$DB_USERNAME" --password="$DB_PASSWORD" \
  --single-transaction --quick --routines --triggers --events \
  --set-gtid-purged=OFF \
  "$DB_DATABASE" \
  | gzip > ~/backups/arovolife-$(date +%Y%m%d-%H%M%S).sql.gz
```

`--single-transaction` is what makes the dump consistent without locking the
site: it takes one repeatable-read snapshot for the whole run. Without it a
dump taken while an order is being paid can capture the order row and miss its
ledger entries, and the restore then fails the balance check in step 5 — which
is the drill working, but for the wrong reason.

Record the byte size. A dump materially smaller than the last one is the
signal that something was excluded.

### 2. Copy it off, and take the storage side too

```bash
tar czf ~/backups/storage-$(date +%Y%m%d-%H%M%S).tar.gz -C ~/applications/<app>/public_html storage/app
```

Move both files to the off-server location (S3 with server-side encryption).
Delete the local copies when the transfer is verified.

### 3. Restore into staging

```bash
# On the staging application server. THIS DESTROYS THE STAGING DATABASE.
gunzip < arovolife-<timestamp>.sql.gz \
  | mysql --host="$DB_HOST" --user="$DB_USERNAME" --password="$DB_PASSWORD" "$DB_DATABASE"

tar xzf storage-<timestamp>.tar.gz -C ~/applications/<staging-app>/public_html
```

### 4. Bring the application up

```bash
php artisan migrate --force     # forward-only; the dump may predate a migration
php artisan config:clear && php artisan cache:clear
php artisan queue:restart
```

### 5. Verify — this is the part that makes it a drill

Row counts prove the tables arrived. They do not prove the data is usable.
Check all six:

| # | Check | How | Pass condition |
|---|---|---|---|
| 1 | Nothing is missing | `SELECT COUNT(*) FROM distributors, orders, ledger_entries, bv_ledger_entries, tickets` | Within one day's activity of production |
| 2 | **The ledger still balances** | `SELECT SUM(CASE WHEN side='debit' THEN amount_paise ELSE -amount_paise END) FROM ledger_entries` | Exactly `0` |
| 3 | The Genos tree is intact | `php artisan tinker` → closure row count vs `SUM(depth+1)` over distributors | Equal |
| 4 | KYC documents resolve | Open any verified KYC record in admin and view the document | Image renders |
| 5 | A grievance attachment downloads | Open a ticket with an attachment, stream it | File downloads intact |
| 6 | The app works | Log in as an admin and as a distributor; place a test order | Both succeed |

Check 2 is the one that matters most. A partial restore of an append-only
double-entry ledger will usually still *look* fine — the pages render, the
counts are plausible — and will be out of balance by exactly the entries that
did not make it.

### 6. Record the result and reset staging

Fill in the evidence block below, commit it, then restore staging to its own
data (or re-seed it) so nobody mistakes production PII on staging for test
data. **Production PII must not be left sitting on staging after the drill.**

---

## Evidence

Copy this block, fill it in, and commit it under
`docs/compliance/evidence/backup-drill-<date>.md`.

```
Drill date:            YYYY-MM-DD
Performed by:
Witnessed by:

Dump timestamp:                     Size:
Storage archive timestamp:          Size:
Restored into:                      (staging app + database name)

Time from starting the restore to check 6 passing:   ____ minutes

Check 1 row counts       PASS / FAIL   notes:
Check 2 ledger balance   PASS / FAIL   sum was:
Check 3 closure rows     PASS / FAIL
Check 4 KYC document     PASS / FAIL
Check 5 attachment       PASS / FAIL
Check 6 smoke test       PASS / FAIL

Staging reset afterwards:  YES / NO
Production PII removed from staging:  YES / NO

Recovery time objective demonstrated:  ____ minutes
Recovery point objective demonstrated: ____ hours (age of the dump used)
```

RTO and RPO are the two numbers the business actually needs. Until this drill
has been run once, both are unknown — and "we have daily backups" is not an
answer to "how long would we be down".

---

## Frequency

- Once before launch (this gate).
- Then quarterly, and after any change to the database topology or the backup
  configuration.
- The quarterly run can use the most recent automated snapshot rather than a
  fresh manual dump — the point is to prove the restore path, not the dump
  command.
