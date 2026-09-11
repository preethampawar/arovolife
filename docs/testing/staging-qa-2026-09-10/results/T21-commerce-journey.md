# T21 — Commerce journey (shop → cart → checkout → pay → My Orders → invoice → admin ship/deliver → customer)

Verdict: **PASS-with-notes** (money maths and every DB row are exact; four product/config defects found, one of them a pricing contradiction the customer sees)

Account used: distributor ADN **608628172** (distributor id 5, user id 6). Admin: admin@arovolife.test.
Session: Chrome, one login at a time, logged out between every account switch, tab closed at the end.

**Order numbers placed: `ORD-260910-YOAJJK` (order id 17, ₹1,557.00) and `ORD-260910-KVTQW1` (order id 18, ₹459.00).**
Both are **online / stub**, because **COD cannot be selected — see D2**; the second order was therefore used to test the mainland-India rule instead (D3).

## Checks

| # | Check | Result | Evidence |
|---|-------|--------|----------|
| 1 | Category filter on /shop | PASS | `/shop?category=health-care` returns exactly the 4 Health Care variants (Herbal Green Tea, Immunity Booster, Multi-Vitamin, detox) with BV badges. |
| 2 | Product page renders (images, facts, BV) | PASS | PDP `/shop/p/immunity-booster`: price ₹549 / MRP ₹650 / "16% off" / "600 BV" badge, Product facts (Country of origin India, HSN 3004), details paragraph, 30-day-returns + GST-invoice trust badges. No rich attribute table on these two seeded SKUs (they have none in the catalogue) — not a defect. |
| 3 | After-login pricing | **FAIL → D1** | Logged out: no BV badges, no distributor price. Logged in: BV badges appear on listing + PDP, and PDP shows "DISTRIBUTOR PRICE ₹450.00". **Cart and order charge ₹549.00** (`CartService.php:99` writes `sale_price_paise`). |
| 4 | Add 2 variants, qty 2 and 1 | PASS | Immunity Booster (variant 6) × 2 and Herbal Green Tea (variant 5) × 1; cart badge 3. |
| 5 | Cart totals recomputed by hand | PASS | Displayed: Total BV 1,799 · Subtotal ₹1,310.51 · GST ₹186.49 · Shipping ₹60.00 · Total ₹1,557.00. Hand check: IB 2×₹549 = ₹1,098 incl 18% → tax ₹167.49, ex-tax ₹930.51; GT 1×₹399 incl 5% → tax ₹19.00, ex-tax ₹380.00. Σ ex-tax 1,310.51 ✓ Σ tax 186.49 ✓ gross 1,497 < 4,000 → ₹60 shipping ✓ "Add ₹2,503.00 more to get free shipping" = 4,000 − 1,497 ✓ total 1,557 ✓ BV 2×600+599 = 1,799 ✓ |
| 6 | Coupon field — invalid code | PASS | `NOTACODE123` → inline red "That promo code is not valid."; totals unchanged. |
| 7 | Checkout: saved address vs new address | PASS | Saved address radio pre-fills line1/city/state/pincode (order 17); "＋ Use a new address" fields accepted for order 18; `save_address` unchecked → no new `customer_addresses` row (max id still 416, dated 2026-09-01). |
| 8 | Mainland-India-only rule | **FAIL → D3** | Setting `commerce.shipping.india_mainland_only = true`. Order 18 placed and accepted to "Port Blair, Andaman and Nicobar Islands 744101" (`orders.ship_state`/`ship_pincode` confirm). State is a free-text input; the key is referenced nowhere outside `AdminSettingsController.php:554`. |
| 9 | T&C-of-sale consent required | PASS | `accept_terms` is `required` in the form and `['required','accepted']` in `CheckoutController.php:207`. Submitting unticked scrolled to and blocked on the consent box; no POST left the browser. |
| 10 | Payment method choice | **FAIL → D2** | Only one radio exists (`payment_method=online`). View is hardcoded "Payment method (online only)"; `CheckoutController.php:171` sets `$allowedMethods = [Order::PAYMENT_ONLINE]`; `Order` has no COD constant. `payments.cod.enabled=true` on staging is an orphan settings row referenced nowhere in the codebase. |
| 11 | Confirmation modal before Place Order (project UI rule) | PASS | "Confirm your order / Place this order? / Impact: … 30-day cooling-off return window after delivery." with Cancel + Confirm (screenshot). Same modal on Clear cart and on both admin fulfilment actions. |
| 12 | Order confirmation page | PASS | `/shop/confirmation/ORD-260910-YOAJJK`: order no, items with per-line BV, totals identical to cart, ship-to block, "Your 30-day return window" panel. **Continue Shopping is the filled primary button** (solid brand-blue, full pill) — 3e718c9c holds. Note: page offers no link to the order or invoice. |
| 13 | orders row (order 17) | PASS | `status=paid`, `payment_method=online`, `subtotal_paise=149700`, `gst_paise=18649`, `shipping_paise=6000`, `total_paise=155700`, `attributed_distributor_id=5`, `self_consumption=1`, `attribution_source=logged_in`, `placed_at=paid_at=2026-09-10 17:23:19` (IST). Order 18: 39900/1900/6000/45900, same attribution. |
| 14 | order_items BV snapshot | PASS | id 23: variant 5, qty 1, unit 39900, `bv_paise=59900`; id 24: variant 6, qty 2, unit 54900, `bv_paise=60000` (per-unit, matches T02). id 25: order 18, variant 5, qty 1, bv 59900. |
| 15 | bv_ledger accrual = qty × variant BV; **when** it accrues | PASS | id 18 = 179900 paise (1,799 BV) = 599 + 2×600 ✓; id 19 = 59900 (599 BV) ✓. Both `type=accrual`, `effective_at` equal to `paid_at` to the second. Accrual happens **on payment, not on delivery**: `OrderStateMachine::markPaid()` sets status/paid_at, writes the `order.paid` audit row, then — comment at lines 78-82 — `$this->bvLedger->accrue($order);` ("BV accrues as soon as payment is received (product-owner decision, 2026-06-02 — ADR-0006 revised)"). Row timing matches the code exactly. |
| 16 | group_bv_credits to the upline | PASS | Order 17 → ids 21 (ancestor 1, side **L**, 179900) and 22 (ancestor 2, side **R**, 179900). Order 18 → ids 23 (anc 1, L, 59900) and 24 (anc 2, R, 59900). Matches `genealogy_closure` for descendant 5 (anc 2 depth 1, anc 1 depth 2) and `distributors`: d5 sits on d2's **R**, d2 sits on d1's **L**. One row per ancestor, full amount, no duplication. |
| 17 | payment_intents (stub captured) | PASS | id 15 order 17 gateway `stub` status `captured` 155700; id 16 order 18 `stub`/`captured`/45900. Both visible on /admin/payments as "Stub (test) — Captured". |
| 18 | audit_log order.placed / order.paid | PASS | 3185 `order.placed` actor 6 · 3186 `order.paid` actor NULL · 3187 `bv.accrued` (bv_ledger_entry 18) · 3188/3189/3190 the same triple for order 18. |
| 19 | notifications / failed_jobs do not grow | PASS (delivery unverified) | `notifications` = 0 before and after (order mail is a queued Mailable, not a DB notification). `failed_jobs` = **11 before and 11 after** all six state changes. `jobs` = 0 (drained). No ERROR/WARNING in `storage/logs/laravel.log` in the 17:23–17:36 window. Actual mailbox delivery not observable from here (F03 daily-limit risk stands). |
| 20 | My Orders list — S.No column, Indian number format | PASS | Columns `S.No / Order / Date / Total / Status / BV / BV status`; rows 1-4; "2,50,000 BV", "₹1,557.00" — lakh grouping correct (67815315 holds). |
| 21 | Order detail | PASS | Status chip, items with per-line BV, totals, "Business Volume — Accumulated — 1,799 BV from this order", ship-to, invoice link, action button. After delivery it also shows "Tracking: Bluedart — BD123456789IN". |
| 22 | Invoice page (`orders.invoice`) | PASS-with-note → D4 | `/orders/ORD-260910-YOAJJK/invoice` renders: seller + CIN, order no/date, BILLED TO, "YOUR AROVOLIFE DISTRIBUTOR ADN 608628172", per-line HSN/qty/rate/GST%/amount, Taxable ₹1,310.51 + GST ₹186.49 + Shipping ₹60.00 = ₹1,557.00 (foots to the order row). R-28 correctly disclosed: "This is an order summary / payment receipt, not a GST tax invoice." **But the PDP and cart promise "GST invoice — Issued for every order" (D4).** |
| 23 | Printable-page contact footer | PASS | Invoice ends centered: "+91 88866 62949 | support@arovolife.com | www.arovolife.com" plus company + CIN line, with a "Download / Print" button and the Save-as-PDF hint. |
| 24 | Cooling-off / return action | PASS | Before shipping the detail page offers "Cancel this order" (`/cancel`). After delivery: "Returns & Refunds — Your 30-day cooling-off window is open — 29 days remaining. You can return this order for a refund." → `/orders/ORD-260910-YOAJJK/return`. Form opened, **not submitted**: 5 reasons (Cooling-off cancellation / Damaged on arrival / Dissatisfied / General buyback / Termination buyback), notes field, and a "What happens next?" block with the 7-working-day refund timeline. |
| 25 | Easy Purchase | PASS | PDP shows a per-product link `…/shop/p/immunity-booster?ref=608628172` with the copy "Purchases made through it for the next 30 days are attributed to you (ADN 608628172)" — opened, loads fine. Cart "Share this cart (Easy Purchase)" produced `shared_carts` id 1 code **QEI7KFDZ0W**; `/shop/easy-cart/QEI7KFDZ0W` loaded the shared item into the cart with the flash "1 product added from a shared cart." |
| 26 | Admin → Commerce → Orders lists both | PASS | `/admin/commerce/orders` rows 1-2 = KVTQW1 (₹459, 599 BV) and YOAJJK (₹1,557, 1,799 BV), attribution 608628172 / logged_in. |
| 27 | Mark Shipped (carrier + tracking) + confirm modal + audit | PASS | Modal "Confirm shipment … Impact: sets the order to SHIPPED, recognises revenue in the ledger, and emails the customer their shipping details." Order 17 → Bluedart / BD123456789IN, `shipped_at 17:34:16`, audit_log **3191** `order.shipped` actor 1. Order 18 → Delhivery / DL987654321IN, `shipped_at 17:35:12`, audit_log **3193**. |
| 28 | Mark Delivered + cooling-off opens | PASS | Modal "Confirm delivery … OPENS the statutory 30-day cooling-off window". Order 17 `delivered_at 17:34:48`, audit_log **3192** `order.delivered` actor 1, flash "Delivery recorded. 30-day cooling-off clock opened.", sidebar "COOLING-OFF — 29 days remaining — Closes 10 Oct 2026 — Status: open". Fulfilment panel then correctly says "No fulfilment actions available in status delivered." |
| 29 | Status-change emails queued | PASS (queued, delivery unverified) | `notifications.email_on_status_change=true`; after all three transitions `jobs`=0 and `failed_jobs` still 11, no mail exception in the log. |
| 30 | Admin → Commerce → BV ledger shows the new rows | PASS | `/admin/commerce/bv-ledger/5`: LIFETIME NET BV **6,02,398 BV**, four accrual rows with running balance 3,50,000 → 6,00,000 → 6,01,799 → 6,02,398. |
| 31 | BV ledger CSV export | **UNVERIFIED** | The "⬇ Export CSV" control and route `admin.commerce.bv-ledger.export` exist, but I did not trigger the download (file downloads need the user's explicit permission) and the in-page `fetch()` of the endpoint was refused by the browser tool's cookie guard. Re-test manually. |
| 32 | Admin → Payments shows the stub intents captured | PASS | Both new rows "Stub (test) … Captured", filter chip "Captured(16)". |
| 33 | Customer sees Delivered + tracking | PASS | My Orders: KVTQW1 = Shipped, YOAJJK = Delivered, both "Accumulated". Detail shows the Bluedart tracking number. |
| 34 | Notification bell shows the status change | **NO** → D6 | Bell badge stays empty; `notifications` table has 0 rows. Order events are e-mail-only by design (`OrderNotificationChannels::default()`), so the bell can never reflect them. Expectation mismatch, not a crash. |
| 35 | Dashboard personal BV increased by exactly the new BV | PASS | 6,00,000 BV before → **6,02,398 BV** after = +2,398 = 1,799 + 599 exactly. |
| 36 | Console errors | PASS | `read_console_messages` (errors only) over shop, PDP, cart, checkout, dashboard: none. |
| 37 | Page speed (> 3 s?) | PASS | navigation timing: /dashboard ttfb 225 ms load 278 ms · /shop 203/804 · /orders 168/216 · PDP 141/250. Nothing near 3 s. |
| 38 | "Group BV" wording | PASS | Every surface visited says "Genos BV" / "Left group" / "Right group". No occurrence of "Group BV". |
| 39 | Income-projection copy on the shop | PASS-with-note | Shop, PDP, cart, checkout and confirmation carry no earnings language. The only borderline string is the My Orders intro: "Each order accrues BV to your lifetime personal BV total — which determines your purchase title and **unlocks higher GSB slabs**." It states a plan mechanism, not a rupee figure — flagging for T40's judgement rather than calling it a breach. |

## Defects

### D1 — High (money / mis-selling): the "Distributor price" shown after login is never charged
- **Repro:** log in as any distributor → `/shop/p/immunity-booster` → the block "DISTRIBUTOR PRICE ₹450.00" appears under the ₹549 price → add to cart → cart line is **₹549.00**, order total ₹1,557.00, `order_items.unit_price_paise = 54900`.
- **Expected:** either the distributor is charged ₹450, or the ₹450 figure is labelled so a member cannot read it as the price they will pay.
- **Actual:** `CartService.php:99` writes `'unit_price_paise' => $variant->sale_price_paise`. `distributor_price_paise` is used **only** for display (`ProductVariant.php:69,74`, admin product form, admin offers page) and for computing purchase-offer prices (`PurchaseOfferSettings::offerPricePaise`). No cart/checkout/order path ever reads it.
- Affects 5 of the 13 live variants (those with a non-zero DP below sale price). The blade comment calls it "a factual catalogue price tier", so this may be intentional — if so it is still a UX/mis-selling problem, because the page presents it as *the distributor's* price with no explanation of when it applies. **Needs a product decision.**

### D2 — Medium (config vs code): COD is enabled in settings but does not exist in the product
- `payments.cod.enabled = true` in the staging `settings` table, and `orders.payment_method` is `enum('online','cod')`, but the string `payments.cod.enabled` appears **nowhere** in the codebase, `Order` defines only `PAYMENT_ONLINE`, the checkout view is hardcoded "Payment method (online only)", and `CheckoutController.php:171` allows only `online`.
- Consequence: the COD half of this task is untestable, and the orphan row is exactly the kind of thing that makes a QA brief (and an operator) believe a capability exists. Either implement COD or delete the settings row; it is not in the admin settings registry, so nobody can turn it off from the UI either.

### D3 — Medium (compliance/ops): `commerce.shipping.india_mainland_only` is enforced nowhere
- **Repro:** with the setting `true`, checkout with state "Andaman and Nicobar Islands", pincode 744101 → order **ORD-260910-KVTQW1** created and captured; `orders.ship_state = 'Andaman and Nicobar Islands'`.
- The key is registered in `AdminSettingsController.php:554` with the description "Restrict orders to mainland Indian addresses. Turn off to accept orders for the Andaman & Nicobar islands, Lakshadweep, etc." and is read by **no** service. `ShippingService` reads only `fee_rupees` and `free_threshold_rupees`. The state field is free text with no list and no validation.
- An admin toggling this believes islands are blocked; they are not.

### D4 — Medium (copy/compliance): "GST invoice — Issued for every order" vs "not a GST tax invoice"
- PDP and cart show the trust badge "GST invoice · Issued for every order"; the document actually issued says "This is an order summary / payment receipt, **not a GST tax invoice**" (correct, because R-28 defers GSTIN + CGST/SGST split).
- Two consumer-facing statements contradict each other. Until R-28 ships, the badge should read "Order summary / receipt" or similar.

### D5 — Low (display rule): distributor price bypasses the Indian number formatter
- `app/Modules/Catalog/Models/ProductVariant.php:74` → `'₹'.Number::format($this->distributor_price_paise / 100, 2)`. Project rule is `IndianNumber::format` for every displayed number. Harmless at ₹450; a ₹1,00,000 DP would print "1,00,000" wrongly as "100,000.00".

### D6 — Low (UX expectation): order status changes produce no in-app notification
- Bell badge never changes on ship/deliver; `notifications` stays at 0 rows. Email is the only channel (`OrderNotificationChannels::default()`). Combined with F54 (the bell links only to /messages) a distributor has no in-product trace of a shipment. Decide whether that is intended.

### D7 — Low (UI polish), three small things seen in passing
- Checkout: with "Billing same as shipping" ticked (the default) the Billing Address card is a tall empty white box in the right column.
- Clear-cart modal says "All **1 item** will be removed" when the cart holds one line of qty 2 — it counts lines, not units.
- Delivered 10 Sep with the window closing 10 Oct is shown as "**29** days remaining" (today counted as day 1). Consistent across order detail, admin and the return form, so cosmetic — but confirm it is the intended convention before a customer argues about the last day.
- Also re-confirmed on staging: cart thumbnails are picsum hot-links (F34) and every product card uses the same VitaPlus art (F37).

### Not defects, recorded for accuracy
- The stub gateway captures instantly, so no payment page appears between Place Order and the confirmation, while the confirm modal says "You'll be taken to complete payment online now." That copy will be correct once Razorpay is live; it reads oddly only in stub mode.
- Two early login attempts and several button clicks did not register (no POST left the browser); retrying the identical action worked every time. This is browser-automation click flakiness in my session, not a product fault — the failed login attempts left no `auth` log entry and no error banner, which is the only part worth a second look if a human sees the same thing.

## Mutations made on staging

| Table | Row ids | Before → after |
|---|---|---|
| `orders` | **17** (`ORD-260910-YOAJJK`) | created; placed→paid→shipped (Bluedart / BD123456789IN, 17:34:16)→delivered (17:34:48); total_paise 155700 |
| `orders` | **18** (`ORD-260910-KVTQW1`) | created; placed→paid→shipped (Delhivery / DL987654321IN, 17:35:12); total_paise 45900; ship_state "Andaman and Nicobar Islands" (deliberate D3 test) |
| `order_items` | **23, 24** (order 17), **25** (order 18) | created |
| `bv_ledger_entries` | **18** (order 17, 179900 paise), **19** (order 18, 59900 paise) | created, type `accrual`, distributor 5 |
| `group_bv_credits` | **21** (o17→anc 1, L), **22** (o17→anc 2, R), **23** (o18→anc 1, L), **24** (o18→anc 2, R) | created |
| `payment_intents` | **15** (order 17, 155700), **16** (order 18, 45900) | created, gateway stub, status captured |
| `audit_log` | **3185-3193** | order.placed 17 / order.paid 17 / bv.accrued 18 / order.placed 18 / order.paid 18 / bv.accrued 19 / order.shipped 17 / order.delivered 17 / order.shipped 18 |
| `shared_carts` | **1** (code `QEI7KFDZ0W`, distributor 5, expires 2026-10-10) | created by the Easy Purchase share test |
| distributor 5 lifetime BV | — | 6,00,000 BV → **6,02,398 BV** (+2,398) |
| `customer_addresses` | none | save-address unchecked; max id still 416 |
| `failed_jobs` | none | 11 → 11 |

Cart was emptied at the end; both accounts logged out; Chrome tab closed.

## Notes for the orchestrator (≤ 10 lines)

- Both orders are live staging data now: order 17 is **delivered with an open cooling-off window to 10 Oct**, order 18 is **shipped**. If F10's windowed recompute from 2026-09-01 is approved, these two orders' BV (2,398) and their 4 group-BV credits are inside that window and will be replayed.
- D1 (distributor price displayed but not charged) needs a **product decision**, not just a fix — it is the only finding a customer would notice as being about money.
- D2 and D3 are the same family as F17/F26: a setting that promises behaviour the code does not implement. Worth a sweep of the settings registry for other keys read by nobody.
- COD could not be tested at all; if the client expects COD at launch it is a missing feature, not a bug.
- BV ledger CSV export is the one unverified check — needs a manual click by a human (downloads are outside my permission).
- Order emails: queue drained, `failed_jobs` unchanged, no ERROR in the log — but nothing proves the messages actually left ElasticEmail (F03).
