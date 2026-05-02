# ADR-0004 — Append-only double-entry ledger as a standalone module

- **Status:** Accepted
- **Date:** 2026-04-24
- **Deciders:** Laravel Architect, Finance, Compliance Officer
- **Supersedes:** —
- **Superseded by:** —

## Context

Phase 2 introduces real money flow: customer payments, COGS, GST output, GST ITC, commission hold/unlock, TDS, admin charges, refunds, chargebacks. By Phase 4 these interact with distributor wallets and weekly/monthly payouts.

If we track money with mutable integer columns (`wallet.balance += X`), we guarantee financial holes — one missed debit, one duplicate credit, and balance-sheet reconciliation becomes forensic. A DSR-compliant platform cannot afford this.

We need a single source of truth that:
- Never mutates a past transaction (audit trail requirement).
- Enforces `sum(debits) = sum(credits)` on every transaction.
- Is writable by Commerce, Tax, Payments, Returns, Compensation, and (later) Wallet, with no circular module dependencies.

## Options considered

### A. Put ledger inside Wallet module

- **Pros:** Groups all money together.
- **Cons:** Commerce, Tax, Payments all need to write to ledger — so each develops a dependency on Wallet. Wallet becomes a god module. Circular dep between Wallet and Compensation is inevitable.

### B. Put ledger inside Compliance

- **Pros:** Compliance already owns audit_log.
- **Cons:** Conflates "who did what in admin UI" (audit_log) with "where did money move" (ledger). They have different retention and query patterns.

### C. Ledger is its own module at the same level as Commerce, Tax, Payments, Wallet

- **Pros:** Every other module depends *on* Ledger; Ledger depends on nothing. Unidirectional dependency graph. Retention and schema tuned for financial queries.
- **Cons:** One more module to wire up.

## Decision

Adopt **Option C** — `app/app/Modules/Ledger/` is a standalone module.

### Schema

```sql
ledger_accounts (
    id, code VARCHAR(64) UNIQUE, name, type ENUM('asset','liability','equity','revenue','expense'),
    parent_id NULL, currency CHAR(3) DEFAULT 'INR'
)

ledger_tx (
    id, occurred_at, source_module VARCHAR(32), source_type VARCHAR(64), source_id BIGINT,
    idempotency_key VARCHAR(128) UNIQUE, memo, created_by_user_id
)

ledger_entries (
    id, ledger_tx_id, account_id, side ENUM('debit','credit'),
    amount_paise BIGINT NOT NULL CHECK (amount_paise > 0),
    currency CHAR(3) DEFAULT 'INR'
)
```

### Rules

1. **Append-only.** No UPDATE or DELETE on `ledger_tx` or `ledger_entries`. Corrections are done with a reversing transaction.
2. **Balanced writes.** `Ledger\Services\LedgerPoster::post($lines)` is the ONLY way to write entries. It asserts `sum(debit) = sum(credit)` within a DB transaction and rolls back if not. Direct `DB::table('ledger_entries')->insert(...)` calls are forbidden by a Larastan rule.
3. **Idempotency.** Every `ledger_tx` has an `idempotency_key`. Retried inserts with the same key return the existing tx without writing twice.
4. **All money in paise.** `amount_paise BIGINT`. No floats anywhere in money math.

### Chart of accounts (seeded)

| Code | Name | Type |
|---|---|---|
| `asset.cash.gateway.razorpay` | Cash held at Razorpay | asset |
| `asset.cash.bank.settlement` | Settlement bank account | asset |
| `asset.inventory` | Product inventory at cost | asset |
| `asset.gst_input_itc` | GST Input Tax Credit | asset |
| `liability.customer_prepayment` | Customer has paid, product not yet delivered | liability |
| `liability.commission_held` | Commissions accrued, cooling-off not expired | liability |
| `liability.commission_payable` | Commissions unlocked, not yet paid | liability |
| `liability.tds_payable` | TDS deducted, owed to Income Tax Dept | liability |
| `liability.gst_output` | GST collected, owed to CGST/SGST/IGST | liability |
| `revenue.sales` | Recognised product revenue | revenue |
| `revenue.shipping` | Shipping charges | revenue |
| `revenue.house_margin` | Un-attributed retail margin retained by company | revenue |
| `revenue.admin_charge` | 3% / ₹30k admin charge income | revenue |
| `expense.cogs` | Cost of goods sold | expense |
| `expense.commission` | Commission expense | expense |
| `equity.retained` | Retained earnings | equity |

## Consequences

- **Positive**
  - One source of truth for every paise. Daily balance cron can reconcile with one SQL query.
  - Immune to double-spend via idempotency keys.
  - Refunds are mechanically reversible (mirror-image tx).
  - Regulator-friendly audit trail.

- **Negative**
  - Every Commerce/Tax/Payments write has to route through `LedgerPoster::post()`. Slight latency overhead (two inserts + balance check per tx).
  - Developers must learn double-entry basics (or use helper DSL: `LedgerPoster::transfer($from, $to, $amount)` generates the pair).

- **Neutral**
  - Wallet becomes a *projection* of ledger balances, not a source of truth. `wallets.available_paise` is a denormalised cache, reconciled nightly.

## Enforcement

- CI test `DoubleEntryBalancesTest` — generates 10,000 randomised orders through ship / deliver / refund cycles; asserts `sum(debit) = sum(credit)` across the whole `ledger_entries` table.
- Larastan rule — no direct `DB::table('ledger_*')->insert()` outside the `Ledger\Services\LedgerPoster` class.
- Daily ops cron — alarms if any `ledger_account.type=asset` balance differs from `sum(entries for that account)`.

## References

- CLAUDE.md architecture principles ("Double-entry ledger — the wallet is never a mutable integer")
- ADR-0003 (Commerce Customer entity)
- CGST Rules §46 (GST invoicing requirements)
