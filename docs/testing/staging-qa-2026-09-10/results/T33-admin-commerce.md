# T33 — Admin orders, BV ledger + export, payments, refunds, returns/buyback
Verdict: PASS-with-notes

## Session modes
- Chrome MCP, own tab (1049838091). **No session existed** when the task started (shared tab sat on `/login`), so per the SESSION RULE UPDATE I signed in myself as `admin@arovolife.test` (users.id 1, role `admin`) and will sign out at the end.
- Distributor step ran as a **direct QA login** (ADN 608628172 / QaPass!2026 — owner of order 17), not impersonation, because no staff session pre-existed.
- DB read-only over SSH + mysql. No engines triggered, no settings changed, nothing deleted, no order cancelled, no settle/retry pressed.
- Harness note: MCP `ref` clicks were inert; every button was driven through its own DOM handler with `javascript_tool` (`el.click()`), which reproduced the real confirm-modal flow.

## Checks
| # | Check | Result | Evidence |
|---|---|---|---|
| 1 | Orders list status counts foot to DB | PASS | UI tabs Paid (16) / Shipped (1) / Delivered (1) = `select status,count(*) from orders` → paid 16, shipped 1, delivered 1 |
| 2 | Order totals paise → rupees, lakh grouping | PASS | ORD-260910-YOAJJK ₹1,557.00 = 155700 p; ORD-260910-KVTQW1 ₹459.00 = 45900 p; BV rendered "2,50,000 BV" / "3,50,000 BV" (lakh grouping) |
| 3 | BV shown as BV, not rupees | PASS | list + detail render "1,799 BV", "599 BV" |
| 4 | Order 17 detail arithmetic | PASS | Subtotal (taxable) ₹1,310.51 = (149700−18649)/100; GST ₹186.49; Shipping ₹60.00; Total ₹1,557.00; line GST 19.00 (5%) + 167.49 (18%) = 186.49 |
| 5 | Order 17 timeline paid → shipped → delivered | PASS | Placed/Paid 17:23, Shipped 17:34 (Bluedart BD123456789IN), Delivered 17:34; matches `orders.paid_at/shipped_at/delivered_at` |
| 6 | Order 17 BV credited rows | PASS | `bv_ledger_entries` id 18 (distributor 5, order 17, 179 900 bv_paise, accrual); `group_bv_credits` id 21 (ancestor 1, side L, 179 900) + id 22 (ancestor 2, side R, 179 900) |
| 7 | Invoice link on order detail | **FAIL** | No invoice link/button anywhere on `admin/commerce/orders/17` or on `admin/payments/15`; `grep -rn invoice resources/views/admin/commerce/` → no match. See F2 |
| 8 | R-28 tax-invoice deferral (Info) | INFO | `invoices` row for order 17: `seller_gstin` NULL, `buyer_gstin` NULL, `cgst_paise` 0, `sgst_paise` 0, all tax in `igst_paise` although seller_state TG = place_of_supply Telangana. Deferred per R-28, recorded as Info not defect |
| 9 | Ship→Deliver order 18 via confirm modal | PASS | modal title "Confirm delivery", body "Mark this order as delivered?", impact copy names the 30-day cooling-off consequence and "not easily reversible" |
| 10 | Order 18 delivery persisted | PASS | `orders.id=18 status=delivered delivered_at=2026-09-10 23:07:44` |
| 11 | Delivery audit row | PASS | `audit_log` id 3219, actor_id 1, action `order.delivered`, subject order 18, details `{"order_no":"ORD-260910-KVTQW1","cooling_off_ends_at":"2026-10-10T23:07:44+05:30"}` |
| 12 | Cooling-off opened on delivery | PASS | UI "29 days remaining · Closes 10 Oct 2026 · Status: open"; `order_cooling_off` row created by `OrderStateMachine::markDelivered` |
| 13 | Customer notification on delivery | PASS (indirect) | `OrderStatusChanged` → `SendOrderStatusChangedMail` (ShouldQueue) → `OrderStatusChangedNotification`; setting `notifications.email_on_status_change=true`; `jobs`=0 and no new `failed_jobs` after 23:07 → job queued and drained without failure. No row in `notifications` because `OrderNotificationChannels::default()` is mail-only (no database channel) |
| 14 | BV ledger index foots to DB | PASS | UI Net 20,41,798 BV / Entries 18 / Distributors 7 = `select sum(bv_paise),count(*),count(distinct distributor_id) from bv_ledger_entries` → 204179800, 18, 7 |
| 15 | Per-distributor BV page (d5, ADN 608628172) | PASS | 4 rows, running balance 3,50,000 → 6,00,000 → 6,01,799 → 6,02,398 BV; matches `bv_ledger_entries` ids 5/12/18/19 |
| 16 | BV CSV export ungrouped | PASS | summary CSV `"608628172","Arovolife Private Limited","602398","0","602398","4",…`; per-distributor CSV `"2026-09-10 17:23:19","ORD-260910-YOAJJK","accrual","1799","601799"` — no lakh separators |
| 17 | Export PII limited to ADN + name | PASS | summary CSV header `ADN,Name,Accrued BV,Reversed BV,Net BV,Orders,Last Activity` — no email/phone/PAN |
| 18 | Export audited | PASS | `audit_log` 3194/3195 `bv.report.exported`, details `{"scope":"distributor:608628172","row_count":4}` |
| 19 | Personal vs group BV labelling | PARTIAL | The BV Ledger is personal BV only (`bv_ledger_entries`); it is never called "personal BV" — headings read "NET BV" / "LIFETIME NET BV". No group BV and therefore no Left/Right on this report at all. See F4 |
| 20 | Refunds page foots to `refund_intents` | PASS | `/admin/payments/refunds` — all three sections empty ("No unsettled refunds", "None owed outside the gateway", "None waiting"); `select count(*) from refund_intents` = 0 |
| 21 | payment_intents for orders 17/18 reachable | PARTIAL | Both captured intents (id 15 ₹1,557.00, id 16 ₹459.00, gateway `stub`, mode test) are listed on `/admin/payments` and open at `/admin/payments/{id}` with a link back to the order — but the **order detail does not link forward to the payment**. See F3 |
| 22 | Settle / invoice / retry buttons gated | PASS (from routes) | `POST payments/refunds/{r}/retry`, `/settle`, `payments/orders/{o}/settle`, `/invoice`, `payments/{i}/sync` all carry `can:finance.record`; reads carry `can:audit.read`. Nothing pressed |
| 23 | Authorization gates read from routes | PASS-with-notes | ship/deliver/cancel → `can:commerce.order.manage`; returns inspect/approve/reject → `can:finance.record`; returns receive/not-returned → `can:returns.receive`; payments read → `can:audit.read`, payments writes → `can:finance.record`. **BV ledger index/show/export carry no `can:` gate at all.** See F5 |
| 24 | Console errors | PASS | `read_console_messages` (onlyErrors) → none on any admin page visited |
| 25 | Return window rule read from code | PASS | `BuybackMatrix::policy()` — cooling_off 30 d (saleable only, GST refunded), damage 10 d, dissatisfaction 30 d, general/termination buyback no window (saleable only, ex-GST voucher). `OpenReturn::guardEligibility` adds the GSB-disbursal gate |
| 26 | Order 17 eligible for return | PASS | delivered 2026-09-10, 0 days since delivery; cooling-off open (29 d left); GSB gate clear — `payout_batches.processed_at` max = 2026-09-08 09:00 < order created 2026-09-10 17:23, so no batch was processed after the order |
| 27 | Distributor opens return on order 17 | PASS | reason `dissatisfaction` (chosen deliberately over `cooling_off`, which `OpenReturn` auto-refunds with no admin gate and would have skipped inspect/approve). Notes "QA test 2026-09-10 — please ignore". Confirm modal shown before submit. RMA-1SKZGBQL9R created |
| 28 | Buyback-matrix copy shown to the buyer | **FAIL** | The return form names only the window per reason. No deduction %, no statement that damage-non-saleable / general / termination buyback refund **excludes GST** and issues a voucher rather than a credit note, and no statement that shipping is refunded only on cooling-off. See F1 |
| 29 | Per-line return | **FAIL** | The form has no line/quantity selector; `OpenReturn` hardcodes `order_item_id => null, qty => null`. Order 17 (2 SKUs, 3 units) can only be returned whole. See F6 |
| 30 | Admin inspect → approve | PASS | inspect modal: "Record inspection result? … computes the refund amount. The order will move to 'refund_inspection'." Condition **Saleable**. Computed refund panel: Base (ex-GST) ₹1,310.51 + GST refund ₹186.49 = **Net refund ₹1,497.00**, "Matrix version v1 · ADR-0009 §8" — exactly `BuybackMatrix(dissatisfaction, saleable)` = taxable + GST, shipping (₹60) correctly NOT refunded |
| 31 | Approve confirm modal | PASS | "Impact: posts ledger reversal (Dr revenue.sales/GST Cr liability.refund_payable), reverses BV accrual, moves order to refund_approved… This cannot be undone from here." |
| 32 | Receive step | N/A — by design | The receipt block in `resources/views/admin/returns/show.blade.php:199` is wrapped in `@if($return->isCoolingOff() && …)`. Non-cooling-off returns have no separate receive gate; the goods-in-hand fact is captured by `return_inspections.received_at` (2026-09-10 23:13:41) instead. See F7 (Info) |
| 33 | `return_requests` + `return_inspections` rows | PASS | `return_requests` id 1 (rma RMA-1SKZGBQL9R, order 17, reason dissatisfaction, opened_by_customer_id 190, status `refunded`); `return_inspections` id 1 (condition saleable, inspector_user_id 1, received_at 23:13:41) |
| 34 | `refund_intents` row + status in manual/stub mode | PASS-with-notes | id 1: order 17, payment_intent_id 15, gateway `stub`, amount 149 700 p, reason_code dissatisfaction, idempotency_key `refund:17`, status **`processed`**, `settled_via` stub, `processed_at` 23:14:00, `gateway_refund_id` NULL, `held_at` NULL. It auto-settled — it never appeared on the unsettled-refunds worklist. See F8 |
| 35 | BV reversal — personal | PASS | new `bv_ledger_entries` id 20: distributor 5, order 17, **−179 900** bv_paise, type `reversal`. d5 total 60 239 800 → 60 059 900 p. UI: Lifetime Net 6,00,599 BV / Accrued 6,02,398 / Reversed 1,799 |
| 36 | BV reversal — group, originally-credited upline only (ADR-0010) | PASS | `group_bv_credits` for order 17 were ancestor 1 (side L) and ancestor 2 (side R), 179 900 each. `group_bv_reversals` created exactly 2 rows: ancestor 1 / L / 179 900 and ancestor 2 / R / 179 900, `absorbed_paise` 179 900 each, `debt_paise` 0. No other upline touched |
| 37 | No group BV debt created | PASS | `group_bv_debts` 0 → 0 (both reversals fully absorbed same-day) |
| 38 | R-60 — repurchase-wallet credit never returns as cash | PASS | `audit_log` 3226 details `repurchase_credit_paise: 0, redeem_points_paise: 0, discount_paise: 0` — this order used no wallet credit, so nothing to return; the code path (`RefundOrder`) restores credit via `WalletService::restoreRepurchaseCreditForOrder`, never as cash. Nothing to fault here, but the rule was **not exercised** on real credit — see Notes |
| 39 | No payout / wallet cash entry created | PASS | `wallet_ledger_entries` 40 rows / 12 540 984 p **before and after** — unchanged. No `payout_line_items`, no `payout_batches` change |
| 40 | Double-entry ledger balanced | PASS | tx 19: Dr acct 11 (revenue.sales) 131 051 + Dr acct 9 (gst_output) 18 649 / Cr acct 19 (refund_payable) 149 700. tx 20 (settlement): Dr acct 19 149 700 / Cr acct 1 149 700. Both balanced |
| 41 | Orders list re-foots after the refund | PASS | tabs now Paid (16) / Delivered (1) / **Refunded (1)**; ORD-260910-YOAJJK row shows "Refunded" |
| 42 | Slowest page | PASS | `/admin/commerce/orders` 302 ms; every other page 151–188 ms. Nothing above 3 s |

## Findings

**F1 — Medium (compliance-adjacent, UX copy). The return form never tells the buyer the buy-back deduction.**
`/orders/{orderNo}/return` lists the five reasons with their windows only. It does not say that `damage`-non-saleable, `general_buyback` and `termination_buyback` refund the DS Price **less GST** (a voucher, not a credit note), nor that shipping is refunded only on a cooling-off cancellation. The buyer picks a reason blind to what it costs them; on this order that difference is ₹186.49 of GST and ₹60.00 of shipping on a ₹1,557.00 order. The matrix is already implemented (`BuybackMatrix::policy`) and the admin side renders it ("Base (ex-GST) / GST refund / Net refund, Matrix version v1") — only the buyer-facing side omits it.
Expected: per-reason refund treatment shown before submit. Actual: window only.

**F2 — Medium. No invoice anywhere in the admin order screens.**
`admin/commerce/orders/17` and `admin/payments/15` have no invoice link or "generate invoice" control; `grep -rn invoice resources/views/admin/commerce/` returns nothing. The route `POST admin/payments/orders/{order}/invoice` (`can:finance.record`) exists but is unreachable from any page I could find, and the `invoices` row for the order is never surfaced. Support cannot see or re-issue a buyer's tax invoice from the console.

**F3 — Low. Order detail does not link to its payment intent.**
`admin/payments/15` links forward to `admin/commerce/orders/17`, but the order page's PAYMENT panel is a bare "Online" with no link, no gateway, no intent id. One-way navigation.

**F4 — Low. BV Ledger never labels itself "personal BV".**
Headings read "NET BV" / "LIFETIME NET BV". The report is personal BV (`bv_ledger_entries`) only and contains no group BV, so there is no Left/Right labelling to check — but a reader cannot tell from the page which of the two they are looking at, and the platform elsewhere distinguishes personal BV from Genos BV carefully.

**F5 — Low. BV Ledger routes carry no `can:` gate.**
`admin/commerce/bv-ledger`, `/export`, `/{distributor}`, `/{distributor}/export` sit in the admin group with no permission middleware, while `admin/analytics` was deliberately gated on `can:audit.read` with the comment "a page that composes a league table out of everyone's trading is a different thing…". The BV Ledger index *is* that league table (every ADN, name, volume, order count, CSV export). Practical exposure is nil today — all five admin roles hold `audit.read` — so this is an inconsistency to close, not an open door.
Gate matrix read from `role_has_permissions`: `audit.read` → all 5 roles; `commerce.order.manage` → developer/admin/admin-operations; `finance.record` → developer/admin/admin-finance; `returns.receive` → developer/admin/admin-operations. Separation of duties on ship/deliver/cancel, inspect/approve/reject and receive is correct; **nothing finance-only is reachable by all five roles.**

**F6 — Medium. Partial (per-line) returns are not supported.**
The return form has no item or quantity selector and `OpenReturn::execute()` writes `order_item_id => null, qty => null` unconditionally, with the comment "order-level return". A buyer of order 17 (Green Tea ×1 + Immunity Booster ×2) who is dissatisfied with one SKU must return all three units or nothing. The schema already carries `order_item_id` and `qty`, so the gap is in the flow, not the data model.

**F7 — Info (design note, not a defect). Non-cooling-off refunds are released at approval, with no separate goods-received gate.**
The "Mark received & release refund" / "Close as not returned" block only renders for cooling-off returns (`show.blade.php:199`), because only those hold entitlements. For dissatisfaction/damage/buyback the refund is created and settled on Approve. This is defensible — the inspection step records physical condition and stamps `return_inspections.received_at`, so approval already implies the goods are in hand — but it means the receive/not-returned controls could not be exercised on this return, and the two paths differ in whether "received" is an explicit, separately-audited act.

**F8 — Info (staging-only). The stub gateway auto-settles refunds.**
`refund_intents` id 1 went to `status=processed`, `settled_via=stub`, `processed_at` 1 second after creation, with `gateway_refund_id` NULL — no human NEFT, no UTR. It therefore never appeared on the unsettled-refunds worklist and the "Cooling-off returns awaiting receipt" / "Refunds owed outside the gateway" sections stayed empty. Consequence for QA: **the unsettled-refund worklist and the settle/retry controls cannot be exercised on staging while orders are paid through the `stub` gateway.**

**F9 — Info (data hygiene, staging). `invoices` rows for orders 17/18 are stale and do not match the orders.**
`invoices.id 17` (order_id 17) is dated 2026-06-14, total ₹604.00 against an order totalling ₹1,557.00, and its `invoice_lines` point at `order_item_id` 34/35 with HSN 3401 while order 17's items are HSN 0902 and 3004. Same for order 18 (invoice ₹6,144.00 vs order ₹459.00). These are orphans left by the QA `reset-purchase-data` / recompute tooling re-using order ids; `invoices` was not cleaned with `orders`. Harmless while no screen renders them (F2), but it would print a wrong tax invoice the moment one does.

**R-28 confirmation (Info, not a defect).** The invoice model carries `seller_gstin`, `buyer_gstin`, `cgst_paise`, `sgst_paise`, `igst_paise`, `place_of_supply`, `irn` — but on the staging rows `seller_gstin`/`buyer_gstin` are NULL and the whole tax sits in `igst_paise` with `cgst`/`sgst` 0 even though `seller_state` TG = `place_of_supply` Telangana (an intra-state supply that should split CGST/SGST). Consistent with the R-28 deferral; recorded as Info.

## Mutations made on staging
| # | Table / object | Before → After |
|---|---|---|
| 1 | `orders` id 18 | status `shipped` → `delivered`; `delivered_at` NULL → 2026-09-10 23:07:44 |
| 2 | `order_cooling_off` | new row for order 18, opened 23:07:44, ends 2026-10-10 23:07:44, status open |
| 3 | `audit_log` 3219 | new — `order.delivered`, actor 1, order 18 |
| 4 | `return_requests` | 0 → 1 row: id 1, RMA-1SKZGBQL9R, order 17, reason dissatisfaction, notes "QA test 2026-09-10 — please ignore", status `refunded` |
| 5 | `return_inspections` | 0 → 1 row: id 1, condition saleable, inspector_user_id 1, received_at 23:13:41 |
| 6 | `refund_intents` | 0 → 1 row: id 1, order 17, 149 700 p, gateway stub, status `processed`, settled 23:14:00 |
| 7 | `orders` id 17 | `delivered` → `refunded`; refund_approved_at 23:13:59, refunded_at 23:14:00 |
| 8 | `bv_ledger_entries` | 18 → 19 rows; new id 20 = distributor 5, order 17, **−179 900 bv_paise**, type reversal. Distributor 5 total 60 239 800 → **60 059 900** p (6,02,398 → 6,00,599 BV) |
| 9 | `group_bv_reversals` | 0 → 2 rows: (ancestor 1, side L, 179 900, absorbed 179 900, debt 0) and (ancestor 2, side R, 179 900, absorbed 179 900, debt 0) |
| 10 | `group_bv_debts` | 0 → 0 (no change) |
| 11 | `wallet_ledger_entries` | 40 rows / 12 540 984 p → **unchanged** |
| 12 | `ledger_tx` / `ledger_entries` | +2 tx (19, 20), +5 entries (41–45), both balanced at 149 700 p |
| 13 | `audit_log` 3220–3228 | 3220/3221 `bv.report.exported` (my CSV exports); 3222 `return.opened` (actor 6 = the distributor); 3223 `return.inspected`; 3224 `bv.reversed`; 3225 `refund.created`; 3226 `order.refund_approved`; 3227 `refund.settled`; 3228 `bv.group_reversed` |
| 14 | Sessions | signed in as `admin@arovolife.test`, out; in as ADN 608628172, out; back in as admin; **signed out at the end** |

Nothing else was mutated. No engine triggered, no setting or flag changed, no order cancelled, no settle/retry pressed, nothing deleted.

## Notes for the orchestrator
- Return flow **completed end to end**: open (distributor) → inspect → approve → auto-settle. The `receive` / `not-returned` controls could not be reached because they only render for cooling-off returns (F7) — a cooling-off return would exercise them, but it auto-refunds with no admin gate at all, so it tests a different path.
- R-60 was **not truly exercised**: order 17 carried no repurchase-wallet credit and no redeem points, so `repurchase_credit_paise`/`redeem_points_paise` were both 0. A future task should place an order part-paid from the repurchase wallet and refund it to prove the credit returns to the wallet and not as cash.
- Order 17 is now `refunded` and order 18 `delivered` with an open cooling-off clock to 2026-10-10 — later tasks should not assume order 17 is still delivered.
- F8 blocks any QA of the unsettled-refund worklist / settle / retry while staging orders are paid on the `stub` gateway.
- F9 (stale `invoices` rows) is the kind of orphan `reset-purchase-data` leaves behind — worth checking what else it misses.
