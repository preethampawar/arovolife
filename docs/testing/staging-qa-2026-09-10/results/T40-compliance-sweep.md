# T40 — Compliance sweep of last week's user-facing changes
Verdict: FAIL (2 High, 0 Critical) — written by the orchestrator from the compliance-officer agent's report.

## Findings
| Sev | Rule | File:line | Finding | Fix |
|---|---|---|---|---|
| High | DSA §6.2 / DSR 5(1)(d); R-75, R-73 | `app/app/Console/Actions/PlatformResetAction.php:201`, `app/database/seeders/DatabaseSeeder.php:15`, `docs/runbooks/fresh-install-and-reset.md:139` | R-75's control is "compensation.md is written but NOT seeded". Both `platform:reset` and `db:seed` call `ContentPageSeeder`, whose `run()` publishes all five pages — a staging reset before UAT publishes the un-notified Wednesday–Tuesday payout week and 8th-of-month cadence. `content:publish` exists but nothing stops the blanket path. | `ContentPageSeeder::run()` should publish an explicit slug list excluding `compensation` until the §6.2 notice runs. |
| High | DPDP §5/§8(7); R-80(a) | `app/app/Modules/Messaging/Services/MessagingSettingsService.php:38` (`reporting_enabled => true`) | Moderation reads private messages; the §4.5b purpose and §5 retention rows exist only in the unpublished `privacy.md`. Any environment taking a report before `php artisan content:publish privacy` moderates against an unpublished notice. | Run `content:publish privacy` on staging/prod and verify the live page, or ship the setting OFF. |
| Medium | Copy vs model | `app/resources/views/income/wallet.blade.php:159` and `:169` | "Deductions appear here after your first payout is processed" and "withheld from payout" contradict the shipped credit-time deduction model. | Reword to credit-time deduction. |
| Medium | T&C §4 | `app/resources/views/shop/pay-unavailable.blade.php:16` | Promises a refund with no 7-working-day timeline. | Add the timeline. |
| Low | DSR grievance | FAQ page | "raise it with us" not linked to grievance contact. | Link it. |
| Low | pre-existing | `shop/confirmation.blade.php:71` | Conflates order-return and agreement cooling-off. | Separate the two. |

## Clean
Hard rule 2 (all new debits — `repurchase_transfer`, `admin_charge_debit`, `tds_debit`, forfeits — are debits; credits stay BV/order-anchored); hard rule 3 wording on all new income pages; IndianNumber use; brand casing; cooling-off refund copy vs T&C §4.2(3); messaging audience / PII guard / RBAC.
