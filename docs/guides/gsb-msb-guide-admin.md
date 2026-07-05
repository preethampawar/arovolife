# GSB & Mentorship Bonus — Super Admin Testing & Operations Guide

> Audience: super admin (and finance/compliance operators) testing or running the
> Genos Sales Bonus (GSB) and Mentorship Bonus (MSB) engines.
> Staging base URL: `https://phplaravel-1611779-6390605.cloudwaysapps.com`
> All amounts, slabs and thresholds shown in the UI come from the database
> (Admin → Compensation → Plan Settings) — that page is the single source of truth,
> not this document.

---

## 1. How the money flows (mental model)

1. **A distributor places an order and it is PAID.** The moment an order reaches
   *paid* (online payment success, or finance marking a COD order paid), the
   `order.paid` event fires and a queued job (`PropagateGroupBvJob`) does two things:
   - credits **personal BV** to the buyer's BV ledger (`bv_ledger_entries`), and
   - walks **up the Genos tree** adding the order's BV to every ancestor's
     **left or right group BV for that date** (`group_bv_daily`), depending on
     which side the buyer sits under.
2. **Daily GSB cut-off** (00:10 IST, processes the *previous* day): for every active
   distributor it takes yesterday's left/right group BV plus any carry-forward,
   matches the weaker side against the slab table, and — if a slab is hit and the
   distributor's personal-purchase title allows it — **credits GSB to the wallet**
   and records the result (`gsb_cutoff_results`). The weaker side resets; the
   stronger side carries forward up to the configured cap.
3. **Mentorship Bonus runs inside the same daily cut-off**: whenever a directly
   sponsored distributor is credited GSB, the sponsor is credited the configured
   percentage of it (10% stepping down to 1% per cumulative band, tracked per
   sponsor–sponsee pair) into the wallet (`mentorship_bonus_results`).
4. **Weekly payout** (Tuesday 09:00 IST): all wallets at or above the minimum
   (₹100) are bundled into a payout batch with deductions (repurchase wallet,
   admin charge, TDS). Finance reviews, approves, and exports the NEFT file.

Everything is ledger-backed: the wallet is a projection of `wallet_ledger_entries`,
never a mutable balance.

---

## 2. What runs when (automated schedule, IST)

| Command | Schedule | What it does |
|---|---|---|
| `gsb:daily-cutoff` | daily **00:10** (for the previous day) | GSB slab match + credit, carry-forward update, **Mentorship Bonus** |
| `repurchase:evaluate` | daily **00:30** | Recomputes each distributor's repurchase/income-eligibility status |
| `gsb:weekly-payout` | **Tuesday 09:00** | Builds the weekly payout batch from wallet balances |
| `gbb:monthly-run` | 2nd, 08:00 | Growth Booster Bonus for the previous month |
| `rank:monthly-run` | 8th, 08:00 | Rank Bonus pool distribution |
| `adc:monthly-run` | 8th, 09:30 | Arete Development Center Bonus |
| `fortune:monthly-run` | 9th, 09:00 | Fortune Bonus matrix payout |
| `payout:monthly-run` | 9th, 10:30 | Monthly payout batch for the monthly engines |

**Staging prerequisite (one-time):** the schedule only fires if the server has a
cron entry running `php artisan schedule:run` every minute **and** a queue worker
(`php artisan queue:work database`) — without the worker, orders will be marked
paid but BV never propagates. Until that is configured on Cloudways, run the
commands manually (Section 4).

---

## 3. End-to-end test recipe (clean staging)

1. **Register test distributors** under the reserved company tree — at minimum:
   one sponsor (S), and two downlines placed LEFT and RIGHT under S so S has BV
   on both sides. Complete KYC/activation as usual.
2. **Give the earner a personal-purchase title**: the buyer whose GSB you want to
   see must have enough lifetime personal BV for the slab's title gate (slab 1
   requires the Retailer title). Place personal orders for S first and mark them
   paid.
3. **Place downline orders**: buy products as the LEFT and RIGHT downlines. For
   COD, mark them paid in Admin → Orders (finance permission). Watch for:
   - buyer's personal BV: Admin → BV Ledger → that distributor;
   - upline propagation: Admin → Compensation → a distributor's page (Section 5)
     or tomorrow's Daily Cut-offs entry showing S's left/right BV for today.
4. **Run the cut-off** for the day the BV landed (or wait for 00:10):
   ```bash
   php artisan gsb:daily-cutoff --date=YYYY-MM-DD
   ```
5. **Verify results** in Admin → Compensation → Daily Cut-offs → pick the date:
   every distributor's left BV, right BV, matched slab, GSB credited, carry-forward.
   Check S's wallet gained the GSB amount, and S's **sponsor** gained the
   Mentorship % of it.
6. **Run the weekly payout** (or wait for Tuesday):
   ```bash
   php artisan gsb:weekly-payout
   ```
   Then Admin → Compensation → Weekly Payouts → open the batch → verify gross,
   deductions (repurchase / admin charge / TDS), net → **Approve** (finance role)
   → download the NEFT export.

To restart testing from zero: `php artisan platform:reset-purchases` (keeps all
distributors, wipes purchases/BV/bonuses/wallets) or `php artisan platform:reset`
(wipes everyone except the 31 reserved company distributors). Both prompt before
acting.

---

## 4. Running engines manually

All commands are safe to re-run: results are idempotent per distributor per day
(a CREDITED result is never recomputed).

```bash
php artisan gsb:daily-cutoff --date=2026-07-04          # whole platform, one day
php artisan gsb:daily-cutoff --date=2026-07-04 --distributor=42   # one distributor (retry)
php artisan gsb:weekly-payout
```

Admin → Compensation → **Manual Controls** offers the same operations from the UI,
role-gated: retry a cut-off, recalculate carry-forward, manual wallet
credit/reverse, force payout, freeze/unfreeze GSB for a distributor. Every action
is audit-logged with a reason.

---

## 5. Where to see everything (page map)

| Question | Page |
|---|---|
| What are the GSB slabs / values / title gates? | Admin → Compensation → **Plan Settings** (`/admin/compensation/plan-settings`) — also editable here (finance role) |
| Did BV credit the buyer? | Admin → **BV Ledger** (`/admin/commerce/bv-ledger`) → distributor |
| Did BV reach the upline? | Admin → Compensation → **Daily Cut-offs** → date → the ancestor's left/right BV row; or the per-distributor view below |
| Everything about one distributor's compensation | Admin → Compensation → **Distributor drill-down** (`/admin/compensation/distributors/{id}`) — group BV, cut-off history, wallet, carry-forward |
| Which slab did a distributor reach on a date? | Daily Cut-offs → date → their row (slab + amount) |
| Carry-forward positions | Admin → Compensation → **Carry-forwards** |
| Wallet → bank money movement | Admin → Compensation → **Weekly Payouts** → batch detail + NEFT export |
| Who sponsors whom (MSB pairs) | Admin → Genealogy tree (sponsorship view) |
| Plan rules explained | Admin → Help & Reference → **Compensation** |

---

## 6. Mentorship Bonus (MSB) specifics

- Paid **only on direct sponsees' GSB** — no other bonus type, no deeper levels.
- The percentage steps down per cumulative GSB band received from that sponsee
  (10% on the first band → 1% lifetime), tracked **per sponsor–sponsee pair**.
- Computed in the same daily cut-off run as GSB; credited to the sponsor's wallet
  immediately, paid out with the Tuesday batch.
- Verify: after a sponsee's GSB credit, the sponsor's wallet shows a
  `mentorship` credit and the pair's cumulative counters advance
  (`mentorship_bonus_results`).

---

## 7. Troubleshooting

| Symptom | Likely cause | Fix |
|---|---|---|
| Order paid but no BV anywhere | Queue worker not running | Start `queue:work database`; the queued job will process |
| BV visible but no GSB after 00:10 | Cron not configured, or title gate not met, or weaker side below slab 1 | Run the cut-off manually; check the distributor's personal BV title |
| GSB credited but no bank payout | Below ₹100 minimum (rolls over), no verified bank account (`no_bank_account` hold), or below Retailer title (credited to wallet only) | Daily Cut-offs + Weekly Payout batch detail show hold reasons |
| Need to re-run a wrong day | — | Manual Controls → Retry cut-off (idempotent), or `--distributor=` for one person |

---

*Internal operations document — not for public distribution. Commissions arise
solely from product sales (DSR 2021, Rule 5(1)(c)); nothing in this guide may be
used to project or promise future earnings.*
