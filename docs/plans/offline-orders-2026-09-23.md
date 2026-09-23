# Offline Orders (admin-created, paid outside the gateway) — Implementation Plan

Branch: `feat/offline-orders` (from `main` @ `ce5a90b7`).
Client requirement, 2026-09-23. Decisions confirmed by the user the same day (see §Decisions).

## Goal

An admin can create an order **on behalf of a distributor** whose money was paid
outside the platform — cash at reception, bank deposit, UPI, NEFT/IMPS/RTGS,
cheque, or another channel — recording the payment details (channel, amount,
date received, reference, payer, optional proof file). The order is created in
`placed` status (pending). A finance user **confirms** the payment, and from that
moment the order is indistinguishable from a paid shop order: the same
`PaymentConfirmationService::settle()` → `OrderStateMachine::markPaid()` path
runs, so personal BV accrual, Genos (group) BV propagation up the upline,
compensation engines, the GST invoice, emails and every later transition
(pack → ship → deliver → cooling-off → cancel/refund) behave exactly as for a
shop order. Offline orders are marked `payment_method = 'offline'` and carry an
`offline_payments` record, and are visibly labelled **Offline** in admin and to
the buyer.

## Non-goals

- No coupons, no redeem points, no repurchase-wallet credit on an offline order
  (user decision A). The deposit covers the full order total.
- No partial payments / shortfall acceptance (user decision A — exact match).
- No guest (non-distributor) offline orders — the buyer is always a registered
  distributor.
- No buyer GSTIN / B2B fields on the offline form (storefront keeps them).
- No editing of payment details after creation — reject and re-create instead.
- No Action Center provider (a callout on Admin → Payments is added instead).
- No change to Razorpay, refunds engine, invoice generator, BV engines.
- No maker-checker "different person" rule (user decision B — split
  permissions, the same super-admin may do both).

## Decisions (user, 2026-09-23)

| # | Question | Decision |
|---|---|---|
| D1 | Who confirms | **B** — create needs `commerce.order.manage` (admin-operations), confirm/reject needs `finance.record` (admin-finance). A super-admin holding both may do both on one order. |
| D2 | Wallet / points | **A** — neither applied; payable = full order total. |
| D3 | Proof | **A** — reference no. (required except cash) + optional file (JPG/PNG/PDF ≤ 5 MB) on a private disk, served via an audited admin route. |
| D4 | Amount | **A** — amount received must equal `orders.total_paise` exactly, checked at creation and again at confirmation. |

## Architecture decisions

1. **Single source of truth for "paid" — new `PaymentConfirmationService::confirmOffline()`.**
   `markPaid()` has exactly one permitted caller (`tests/Feature/Compliance/MarkPaidChokePointTest.php`).
   The offline confirmation is a new public method **inside** that service that ends in
   the existing private `settle()` — identical to `confirmZeroCash()`. Everything
   downstream (BV ledger accrual in `markPaid`, `OrderStatusChanged(placed→paid)` →
   `PropagateGroupBvOnOrderPaid` → `PropagateGroupBvJob`, invoice after commit,
   status email) is reused unchanged. *Alternative rejected:* calling `markPaid()`
   from a new Commerce service — breaks the choke-point test and hard rule 2.
2. **Order creation reuses `CheckoutService::place()`.** The admin service builds a
   throw-away `Cart` (cart lines snapshotted with `CartService::unitPricePaise()` for the
   distributor's `User` → distributor price tier, BV and GST snapshots exactly as
   `addItem()` writes them, but without its silent stock clamp), then calls
   `place()` with `paymentMethod: 'offline'`. Stock reservation, order-number
   generation, attribution/self-consumption, customer row resolution, order items,
   audit and the `OrderPlaced` event are all reused. `place()` gains two optional
   params: `applyRepurchaseCredit` (default `true`, offline passes `false`) and
   `actorUserId` (audit actor; default = buyer). *Alternative rejected:* a parallel
   order-builder — duplicates pricing/BV/stock logic.
3. **Ledger: prepayment is posted at confirmation, under the existing key.**
   Online orders post `Dr asset.cash.gateway.razorpay / Cr liability.customer_prepayment`
   at placement under idempotency key `order.placed:{id}`. An offline order posts
   nothing at placement (money not yet verified) and at confirmation posts
   `Dr <channel account> / Cr liability.customer_prepayment` **under the same key
   `order.placed:{id}`**. Consequence: `OrderStateMachine::markShipped()` (debits
   prepayment), `cancel()` (reads `order.placed:{id}` to size the reversal → paid ⇒
   `refund_payable`), `RazorpayRefundService::create()` (no captured intent ⇒ logs and
   leaves it to manual settlement) and `RefundPayable`/`RefundWorklist` all work
   **with zero code changes**. Channel accounts:
   - cash → new `asset.cash.office` ("Cash at office (collected at reception)")
   - bank_deposit, upi, bank_transfer, cheque, other → existing `asset.cash.bank.settlement`
4. **Pending = `placed`.** No new order status. `ExpireUnpaidOrdersCommand` and
   `UnpaidExpiringProvider` already filter `payment_method = 'online'`, so a pending
   offline order is never auto-expired. `PaymentController` (shop pay page) already
   refuses non-online orders.
5. **Rejection = cancellation.** Finance rejects → `offline_payments.status = rejected`
   + reason, then `OrderStateMachine::cancel($order, 'offline_payment_rejected', …)`
   releases stock. No ledger entry existed, so none is reversed. An ops cancel via the
   existing Cancel button leaves the payment row `pending`; the UI derives "Not
   confirmed — order cancelled" and `confirmOffline()` refuses a cancelled order.
6. **Buyer self-cancel is blocked while an offline order is pending** (money is
   physically with the company but not yet on the books — a self-cancel would lose
   track of it). After confirmation the normal self-cancel applies (refund owed →
   manual refund worklist). Pending offline cancellation goes through support/finance.
7. **Idempotent create.** Form carries a UUID `form_token`; `offline_payments.idempotency_key`
   is unique. A resubmit returns the already-created order. A (channel, reference_no)
   pair already used by a non-rejected offline payment is refused (one deposit
   cannot fund two orders).
8. **Feature flag** `commerce.offline_orders` (`OfflineOrdersFeature`, default OFF,
   developer-owned). OFF = zero trace: no button, no filter, no routes (404), no
   Payments callout. Existing offline orders still render their Offline card when
   the flag is off (history is never hidden), but confirm/reject/create 404.
9. **Buyer eligibility:** distributor's user `status` ∈ {`active`, `pending`}
   (same people who can buy in the shop). `frozen`, `terminated`, `rejected` are refused.
10. **Terms of sale.** The shop requires `accept_terms`; the offline form requires
    `terms_acknowledged` ("The buyer has agreed to the terms of sale"), stored on
    `offline_payments.terms_acknowledged_at`. `orders.tnc_of_sale_consent_id` stays null.
11. **Quote without JS:** the create form's "Calculate total" button submits the same
    form as **GET** to the create route (`formmethod="get"`), which re-renders with a
    server-computed quote (same pricing + `ShippingService::feeForOrderPaise()`);
    "Create offline order" POSTs. The amount field is pre-filled with the quote.

### Compliance notes

- Hard rule 2: BV/commission arise only in `markPaid()`, reached only after finance
  verifies consideration; the audit row's evidence names the offline payment,
  channel, reference, recorder and confirmer.
- Hard rule 7: this is a **company** sale to its own distributor with payment taken
  at the office/bank — not a distributor listing or retail outlet. Not affected.
- Hard rule 5 (cooling-off): unchanged — opens on delivery as for any order.
- Commit trailer: `Compliance-Review: compliance-officer`.

## Permission matrix

Super staff (`admin`, `developer`) pass every `can:` via `Gate::before`.

| Capability | Route / control | developer | admin | admin-operations | admin-finance | admin-compliance | distributor / guest |
|---|---|---|---|---|---|---|---|
| See "New offline order" button (orders index) | view `@can('commerce.order.manage')` + flag | ✅ | ✅ | ✅ | ❌ hidden | ❌ hidden | n/a |
| Open create form | `GET admin.commerce.offline-orders.create` `can:commerce.order.manage` | ✅ | ✅ | ✅ | 403 | 403 | 403 (admin group role gate) / login redirect |
| Create offline order | `POST admin.commerce.offline-orders.store` `can:commerce.order.manage` | ✅ | ✅ | ✅ | 403 | 403 | 403 / login |
| See Offline card on order page | view (everyone who can open the order) | ✅ | ✅ | ✅ | ✅ | ✅ | n/a |
| Confirm / Reject buttons | view `@can('finance.record')` + pending + flag | ✅ | ✅ | ❌ hidden | ✅ | ❌ hidden | n/a |
| Confirm payment | `POST admin.commerce.offline-orders.confirm` `can:finance.record` | ✅ | ✅ | 403 | ✅ | 403 | 403 / login |
| Reject payment | `POST admin.commerce.offline-orders.reject` `can:finance.record` | ✅ | ✅ | 403 | ✅ | 403 | 403 / login |
| View proof file | `GET admin.commerce.offline-orders.proof` — controller `canAny(['finance.record','commerce.order.manage'])` | ✅ | ✅ | ✅ | ✅ | 403 | 403 / login |
| Payment filter on orders index | view + flag | ✅ | ✅ | ✅ | ✅ | ✅ | n/a |
| Payments page callout (pending offline count) | view `@can('finance.record')` + flag | ✅ | ✅ | ❌ | ✅ | ❌ | n/a |
| Buyer self-cancel of pending offline order | `POST orders.cancel` — controller refuses | n/a | n/a | n/a | n/a | n/a | ❌ error message, button hidden |
| Everything above with flag OFF | 404 / hidden | 404 | 404 | 404 | 404 | 404 | 404 |

Service-layer re-assertion: `OfflineOrderService::create()` asserts the actor
`can('commerce.order.manage')`; `PaymentConfirmationService::confirmOffline()` and
`OfflineOrderService::reject()` assert `can('finance.record')`; all three assert
the flag is active. (Throw `AuthorizationException` / `RuntimeException`.)

## File changes

| # | Path (relative to `app/`) | New/Mod | Summary |
|---|---|---|---|
| 1 | `app/Modules/Commerce/Database/Migrations/2026_09_23_100000_add_offline_to_orders_payment_method.php` | New | Widen enum to `online,cod,offline` (MySQL only) |
| 2 | `app/Modules/Commerce/Database/Migrations/2026_09_23_100100_create_offline_payments_table.php` | New | `offline_payments` table |
| 3 | `app/Modules/Commerce/Database/Migrations/2026_09_23_100200_add_cash_office_ledger_account.php` | New | Insert `asset.cash.office` |
| 4 | `database/seeders/LedgerAccountSeeder.php` | Mod | Add `asset.cash.office` row |
| 5 | `app/Modules/Commerce/Models/Order.php` | Mod | `PAYMENT_OFFLINE`, `isOffline()`, `offlinePayment()` |
| 6 | `app/Modules/Commerce/Models/OfflinePayment.php` | New | Model, channel/status constants, helpers |
| 7 | `app/Modules/Shared/Features/OfflineOrdersFeature.php` | New | Pennant resolver, default false |
| 8 | `app/Modules/Admin/Http/Controllers/AdminFeatureFlagController.php` | Mod | Registry row `commerce.offline_orders` |
| 9 | `config/filesystems.php` | Mod | Disk `offline-payments` (private S3, same shape as `distributor-requests`) |
| 10 | `app/Modules/Commerce/Services/CheckoutService.php` | Mod | `applyRepurchaseCredit`, `actorUserId` params |
| 11 | `app/Modules/Commerce/Services/DTOs/OfflineOrderQuote.php` | New | Quote DTO |
| 12 | `app/Modules/Commerce/Services/DTOs/OfflinePaymentDetails.php` | New | Payment-details input DTO |
| 13 | `app/Modules/Commerce/Services/OfflineOrderService.php` | New | `eligibleDistributor()`, `quote()`, `create()`, `reject()` |
| 14 | `app/Modules/Payments/Services/PaymentConfirmationService.php` | Mod | `confirmOffline()` |
| 15 | `app/Modules/Payments/Models/PaymentIntent.php` | Mod | `CONFIRMED_VIA_OFFLINE = 'offline'` |
| 16 | `app/Modules/Commerce/Http/Requests/StoreOfflineOrderRequest.php` | New | Validation |
| 17 | `app/Modules/Commerce/Http/Controllers/Admin/AdminOfflineOrderController.php` | New | create / store / confirm / reject / proof |
| 18 | `routes/web.php` | Mod | 5 routes |
| 19 | `app/Modules/Commerce/Http/Controllers/Admin/AdminOrderController.php` | Mod | Payment filter; `offlinePayment` + `offlineOrdersOn` to views |
| 20 | `app/Modules/Commerce/Http/Controllers/Storefront/MyOrdersController.php` | Mod | Refuse self-cancel of pending offline |
| 21 | `resources/views/admin/commerce/offline-orders/create.blade.php` | New | Create form + quote |
| 22 | `resources/views/admin/commerce/offline-orders/_payment-card.blade.php` | New | Offline payment card + confirm/reject |
| 23 | `resources/views/admin/commerce/orders-show.blade.php` | Mod | Include card; offline-aware cancel copy |
| 24 | `resources/views/admin/commerce/orders-index.blade.php` | Mod | Button + Offline chip |
| 25 | `resources/views/admin/payments/index.blade.php` | Mod | Pending-offline callout |
| 26 | `app/Modules/Payments/Http/Controllers/Admin/AdminPaymentController.php` | Mod | Pass `pendingOfflineCount` |
| 27 | `resources/views/shop/orders/show.blade.php` | Mod | Offline note; hide cancel while pending |
| 28 | `resources/help/order-management.md` | Mod | "Offline orders" section |
| 29 | `resources/help/payments.md` | Mod | Offline confirmation + refunds note |
| 30 | `tests/Feature/Commerce/OfflineOrderTest.php` | New | Pest feature tests |
| 31 | `tests/Browser/offline-orders.spec.js` | New | Playwright spec |

### Per-file detail

**#1 enum widen** — copy the pattern of
`Compensation/Database/Migrations/2026_09_04_200236_…payout_batches_status.php`:
```php
if (DB::getDriverName() === 'mysql') {
    DB::statement("ALTER TABLE orders MODIFY COLUMN payment_method ENUM('online','cod','offline') NOT NULL DEFAULT 'online'");
}
// down(): refuse if any offline rows exist (throw RuntimeException), else revert to ENUM('online','cod').
```

**#2 `offline_payments`**
```php
Schema::create('offline_payments', function (Blueprint $t) {
    $t->id();
    $t->foreignId('order_id')->unique()->constrained('orders')->cascadeOnDelete();
    $t->string('channel', 24);                 // cash|bank_deposit|upi|bank_transfer|cheque|other
    $t->string('channel_other', 60)->nullable();
    $t->unsignedBigInteger('amount_paise');
    $t->date('received_on');
    $t->string('reference_no', 64)->nullable();
    $t->string('payer_name', 150)->nullable();
    $t->text('notes')->nullable();
    $t->string('proof_storage_key', 255)->nullable();
    $t->string('proof_original_name', 255)->nullable();
    $t->string('proof_mime', 64)->nullable();
    $t->unsignedInteger('proof_size_bytes')->nullable();
    $t->char('proof_sha256', 64)->nullable();
    $t->string('status', 16)->default('pending'); // pending|confirmed|rejected
    $t->timestamp('terms_acknowledged_at');
    $t->foreignId('recorded_by_user_id')->constrained('users');
    $t->foreignId('confirmed_by_user_id')->nullable()->constrained('users');
    $t->timestamp('confirmed_at')->nullable();
    $t->string('confirmation_note', 500)->nullable();
    $t->foreignId('rejected_by_user_id')->nullable()->constrained('users');
    $t->timestamp('rejected_at')->nullable();
    $t->string('rejection_reason', 500)->nullable();
    $t->uuid('idempotency_key')->unique();
    $t->timestamps();
    $t->index(['channel', 'reference_no'], 'idx_offline_payments_channel_ref');
    $t->index('status', 'idx_offline_payments_status');
});
```
(strings, not enums — avoids the SQLite/MySQL enum-widen trap.)

**#3 ledger account** — `DB::table('ledger_accounts')->updateOrInsert(['code' => 'asset.cash.office'], ['code'=>…, 'name' => 'Cash at office (collected at reception)', 'type' => 'asset', timestamps])`; down deletes it only if no `ledger_entries` reference it.

**#4** add `['code' => 'asset.cash.office', 'name' => 'Cash at office (collected at reception)', 'type' => 'asset']` after `asset.cash.bank.settlement`.

**#5 Order** — after `PAYMENT_ONLINE`: `public const PAYMENT_OFFLINE = 'offline';`
```php
public function isOffline(): bool { return $this->payment_method === self::PAYMENT_OFFLINE; }
/** @return HasOne<OfflinePayment, $this> */
public function offlinePayment(): HasOne { return $this->hasOne(OfflinePayment::class); }
/** Offline order still waiting for finance to confirm the money. */
public function isAwaitingOfflineConfirmation(): bool
{ return $this->isOffline() && $this->status === self::STATUS_PLACED && $this->paid_at === null; }
```

**#6 OfflinePayment** (`final`, table `offline_payments`, `$fillable` = every column except id/timestamps; casts: amount_paise int, proof_size_bytes int, received_on date, terms_acknowledged_at/confirmed_at/rejected_at datetime).
```php
public const CHANNEL_CASH = 'cash'; public const CHANNEL_BANK_DEPOSIT = 'bank_deposit';
public const CHANNEL_UPI = 'upi'; public const CHANNEL_BANK_TRANSFER = 'bank_transfer';
public const CHANNEL_CHEQUE = 'cheque'; public const CHANNEL_OTHER = 'other';
public const CHANNELS = [ 'cash' => 'Cash (at reception)', 'bank_deposit' => 'Bank deposit',
  'upi' => 'UPI', 'bank_transfer' => 'NEFT / IMPS / RTGS', 'cheque' => 'Cheque', 'other' => 'Other' ];
public const STATUS_PENDING = 'pending'; public const STATUS_CONFIRMED = 'confirmed'; public const STATUS_REJECTED = 'rejected';
public const DISK = 'offline-payments';
public function order(): BelongsTo; recordedBy(): BelongsTo(User, recorded_by_user_id); confirmedBy(); rejectedBy();
public function ledgerAccount(): string { return $this->channel === self::CHANNEL_CASH ? 'asset.cash.office' : 'asset.cash.bank.settlement'; }
public function channelLabel(): string { $l = self::CHANNELS[$this->channel] ?? ucfirst($this->channel);
    return $this->channel === self::CHANNEL_OTHER && $this->channel_other ? "Other — {$this->channel_other}" : $l; }
/** pending | confirmed | rejected | cancelled (derived: pending payment on a cancelled order) */
public function displayState(): string
```

**#7** mirror `DistributorRequestsFeature` (docblock: gates admin offline-order creation/confirmation; default false; OFF = no trace).

**#8** registry row (place after the commerce-related rows, or after `identity.distributor_requests`):
```php
'commerce.offline_orders' => [
    'class' => OfflineOrdersFeature::class,
    'label' => 'Offline orders',
    'description' => 'Lets operations create an order for a distributor who paid outside the gateway (cash at reception, bank deposit, UPI, NEFT, cheque), recording the payment details and an optional proof file. The order waits in Placed until finance confirms the money; confirmation then runs the same path as a paid shop order — BV, Genos BV up the upline, invoice, emails. OFF leaves no trace: no button, no routes, no filter. Offline orders already created still show their payment card.',
    'owner' => 'developer',
    'requires' => [],
],
```

**#9** `config/filesystems.php` — after `distributor-requests` disk, same keys, `'root' => 'offline-payments'`, comment "Proof of offline payments (deposit slips, UPI screenshots). Private; served only via the audited admin route."

**#10 CheckoutService::place()** — append params `bool $applyRepurchaseCredit = true, ?int $actorUserId = null` (also add to the closure `use`). Change:
```php
if ($buyerDistributorId !== null && $applyRepurchaseCredit) {   // was: if ($buyerDistributorId !== null)
```
and in the `order.placed` audit: `'actor_id' => $actorUserId ?? $authUserId,`. Nothing else changes (offline ≠ ONLINE so no placement ledger entry is posted — intended).

**#11 OfflineOrderQuote** (`final readonly`): `subtotalPaise, gstPaise, shippingPaise, collectionFeePaise, totalPaise, bvPaise` + `list<array{variant_id:int,name:string,sku:string,qty:int,unit_price_paise:int,bv_paise:int,line_total_paise:int}> $lines`.

**#12 OfflinePaymentDetails** (`final readonly`): `string $channel, ?string $channelOther, int $amountPaise, CarbonImmutable $receivedOn, ?string $referenceNo, ?string $payerName, ?string $notes`.

**#13 OfflineOrderService** (`final`, ctor: `DatabaseManager $db, CartService $carts, CheckoutService $checkout, ShippingService $shipping, OrderStateMachine $orders`)
```php
public const REJECT_REASON = 'offline_payment_rejected';

/** Eligible buyer by ADN or null. Status active|pending, has a user. */
public function eligibleDistributor(string $adn): ?Distributor

/** @param array<int,int> $lines variant_id => qty (qty ≥ 1 only) */
public function quote(Distributor $d, array $lines, bool $isCollection): OfflineOrderQuote
// For each active variant of an active product: unit = $this->carts->unitPricePaise($variant, $d->user),
// line = qty*unit; subtotal = Σ line; gst = Σ round(line*gst_bp/(10000+gst_bp)) (same formula as place());
// fees = $this->shipping->feeForOrderPaise($subtotal, $isCollection); total = subtotal + shipping + collection.
// Throws RuntimeException('Add at least one product.') when empty; ('… is not available.') for inactive variants.

/**
 * @param array<int,int> $lines
 * @param array{delivery_type:'ship'|'collect', arete_center_id:?int, name:string, phone:string,
 *              line1:?string, line2:?string, city:?string, state:?string, pincode:?string} $delivery
 */
public function create(Distributor $d, array $lines, array $delivery, OfflinePaymentDetails $payment,
    ?UploadedFile $proof, string $idempotencyKey, User $actor): Order
```
`create()` algorithm:
1. `abort`-style guards: flag active (`Feature::for(null)->active(OfflineOrdersFeature::class)` else RuntimeException), `Gate::forUser($actor)->authorize('commerce.order.manage')`, distributor eligible.
2. Idempotency: `OfflinePayment::where('idempotency_key', $key)->first()?->order` → return it.
3. Duplicate reference: if `referenceNo !== null`, refuse when `OfflinePayment::where('channel',…)->where('reference_no',…)->where('status','!=','rejected')->exists()` → `RuntimeException("Reference {$ref} is already recorded against order {$no}.")`.
4. Store proof (if any) to disk `offline-payments` at `pending/{uuid}.{ext}` (ext from mime map jpg/png/pdf); remember key for cleanup.
5. Pre-check the amount against `quote()` **before** anything is written:
   `$quote = $this->quote($d, $lines, $isCollection); if ($payment->amountPaise !== $quote->totalPaise) throw RuntimeException(sprintf('The order total is %s but the amount received is %s. They must match exactly.', …))`.
6. `$this->db->transaction(...)`:
   - `$cart = Cart::create(['anonymous_key' => 'offline-'.$idempotencyKey, 'expires_at' => now()->addHour()]);`
   - foreach line create the `CartItem` **directly** (NOT `CartService::addItem()` — `addItem()` silently clamps qty to available stock, which would create an order smaller than the admin entered). Snapshot fields identical to `addItem()`:
     `CartItem::create(['cart_id'=>$cart->id,'product_variant_id'=>$v->id,'qty'=>$qty,'unit_price_paise'=>$this->carts->unitPricePaise($v, $d->user),'bv_paise'=>$v->bv_paise,'gst_rate_bp'=>$v->gst_rate_bp]);`
     then `$cart->load('items.variant.product')`. `place()` performs the hard stock check (`InsufficientStockException` while enforcement is on) and rolls everything back.
   - `$order = $this->checkout->place(cart: $cart, buyer: ['name'=>$delivery['name'], 'email'=>$d->user->email, 'phone'=>$delivery['phone'], 'marketing_opt_in'=>false], shipping: $shipping, billing: $shipping, attributedDistributorId: $d->id, attributionSource: 'admin', paymentMethod: Order::PAYMENT_OFFLINE, consentId: null, authUserId: $d->user_id, buyerDistributorId: $d->id, saveShippingAddress: false, shippingLabel: null, areteCenterId: collect ? id : null, redeemPoints: 0, applyRepurchaseCredit: false, actorUserId: $actor->id);`
     (`$shipping` shaped exactly like CheckoutController: collection ⇒ line1..pincode null.)
   - defensive re-check: if `$payment->amountPaise !== $order->total_paise` → throw the same RuntimeException (rolls back order + reservation; cannot normally happen because quote and place share the price/fee sources). Note `place()` dispatches `OrderPlaced` inside our outer transaction; its two listeners are `ShouldQueue` on the database queue, so their job rows roll back with it.
   - `OfflinePayment::create([... 'status' => pending, 'terms_acknowledged_at' => now(), 'recorded_by_user_id' => $actor->id, 'idempotency_key' => $key, proof fields ...])`
   - `AuditLog::create(['actor_id'=>$actor->id,'action'=>'order.offline_created','subject_type'=>'order','subject_id'=>$order->id,'after_hash'=>AuditLog::digest(OfflinePayment::STATUS_PENDING),'details'=>['order_no','distributor_id','channel','amount_paise','received_on','reference_no','has_proof'=>bool]])`
7. On any Throwable after step 4: delete stored proof key, rethrow.

`reject(Order $order, User $actor, string $reason): void` — flag + `Gate::forUser($actor)->authorize('finance.record')`; transaction: lock order + payment; require `isOffline()`, payment `pending`, order `placed`; update payment `rejected`, `rejected_by_user_id`, `rejected_at`, `rejection_reason`; audit `order.offline_rejected`; then (still inside) `$this->orders->cancel($order, self::REJECT_REASON, $actor->id)` (its own nested transaction/savepoint).

**#14 PaymentConfirmationService::confirmOffline()** — add after `confirmZeroCash()`; ctor unchanged (uses `$this->ledger`).
```php
/**
 * An order paid outside the gateway — cash at reception, a bank deposit, UPI,
 * NEFT, a cheque. Staff recorded the payment when they created the order;
 * finance confirms it here after checking it against the bank statement or
 * the cash register. The consideration is the offline_payments row, re-read
 * under lock, never the request.
 */
public function confirmOffline(Order $order, User $actor, ?string $note = null): ConfirmationResult
{
    if (! Feature::for(null)->active(OfflineOrdersFeature::class)) { throw new RuntimeException('Offline orders are not enabled.'); }
    Gate::forUser($actor)->authorize('finance.record');

    return $this->db->transaction(function () use ($order, $actor, $note): ConfirmationResult {
        /** @var Order $locked */
        $locked = Order::lockForUpdate()->findOrFail($order->id);
        /** @var OfflinePayment|null $payment */
        $payment = OfflinePayment::where('order_id', $locked->id)->lockForUpdate()->first();

        if (! $locked->isOffline() || $payment === null) { throw new RuntimeException("Order {$locked->order_no} is not an offline order."); }
        if ($locked->paid_at !== null || $payment->status === OfflinePayment::STATUS_CONFIRMED) {
            return new ConfirmationResult(ConfirmationResult::ALREADY_CONFIRMED);
        }
        if ($payment->status !== OfflinePayment::STATUS_PENDING) { throw new RuntimeException("The payment on {$locked->order_no} was rejected."); }
        if ($locked->status !== Order::STATUS_PLACED) { throw new RuntimeException("Order {$locked->order_no} is {$locked->status}; only a placed order can be confirmed."); }
        if ($payment->amount_paise !== $locked->total_paise || $locked->total_paise <= 0) {
            throw new RuntimeException(sprintf('Recorded amount %d paise does not match the order payable %d paise.', $payment->amount_paise, $locked->total_paise));
        }

        // The prepayment the gateway path posts at placement, posted now that the
        // money is verified — under the same key, so ship, cancel and refund read
        // it exactly as they read an online order's.
        $this->ledger->transfer(
            sourceModule: 'Payments', sourceType: 'order.offline_payment', sourceId: $locked->id,
            idempotencyKey: "order.placed:{$locked->id}",
            debitAccount: $payment->ledgerAccount(), creditAccount: 'liability.customer_prepayment',
            amountPaise: $payment->amount_paise,
            memo: "Offline payment ({$payment->channelLabel()}) for {$locked->order_no}",
            createdByUserId: $actor->id,
        );

        $event = PaymentEvent::create([
            'order_id' => $locked->id, 'gateway' => 'offline', 'direction' => PaymentEvent::DIRECTION_SYSTEM,
            'event_type' => 'offline.confirmed', 'signature_verified' => false,
            'payload' => ['offline_payment_id' => $payment->id, 'channel' => $payment->channel,
                'reference_no' => $payment->reference_no, 'amount_paise' => $payment->amount_paise,
                'received_on' => $payment->received_on->toDateString()],
        ]);

        $payment->update(['status' => OfflinePayment::STATUS_CONFIRMED, 'confirmed_by_user_id' => $actor->id,
            'confirmed_at' => Carbon::now(), 'confirmation_note' => $note]);

        $this->settle($locked, $actor->id, [
            'settlement' => 'offline', 'confirmed_via' => PaymentIntent::CONFIRMED_VIA_OFFLINE,
            'offline_payment_id' => $payment->id, 'channel' => $payment->channel,
            'reference_no' => $payment->reference_no, 'received_on' => $payment->received_on->toDateString(),
            'recorded_by_user_id' => $payment->recorded_by_user_id, 'confirming_event_id' => $event->id,
        ]);

        $order->setRawAttributes($locked->fresh()->getAttributes(), true);

        return new ConfirmationResult(ConfirmationResult::CONFIRMED);
    });
}
```
Imports to add: `OfflinePayment`, `OfflineOrdersFeature`, `User`, `Gate`, `Feature`.
Class docblock: add "offline confirmation" to the list of paths into `settle()`.
Check `ledger->transfer()` accepts `createdByUserId` (it does in `post()`; confirm the `transfer()` signature and drop the arg if absent).

**#15** `public const CONFIRMED_VIA_OFFLINE = 'offline';` after `CONFIRMED_VIA_ZERO_CASH`.

**#16 StoreOfflineOrderRequest** (`authorize()` → `$this->user()?->can('commerce.order.manage') === true`):
```php
'adn' => ['required', 'digits:9'],
'qty' => ['required', 'array'], 'qty.*' => ['nullable', 'integer', 'min:0', 'max:999'],
'delivery_type' => ['required', Rule::in(['ship', 'collect'])],
'arete_center_id' => [Rule::requiredIf($collect), 'nullable', 'integer'],   // membership checked in controller vs collectionChoicesFor()
'buyer_name' => ['required', 'string', 'max:150'],
'buyer_phone' => ['required', 'regex:/^[6-9]\d{9}$/'],
'ship_line1' => [Rule::requiredIf(! $collect), 'nullable', 'string', 'max:255'],
'ship_line2' => ['nullable', 'string', 'max:255'],
'ship_city' => [Rule::requiredIf(! $collect), 'nullable', 'string', 'max:100'],
'ship_state' => [Rule::requiredIf(! $collect), 'nullable', Rule::in(IndianStates::all())],
'ship_pincode' => $collect ? ['nullable', 'regex:/^\d{6}$/'] : ['required', 'regex:/^\d{6}$/', app(ServiceablePincode::class)],
'channel' => ['required', Rule::in(array_keys(OfflinePayment::CHANNELS))],
'channel_other' => ['required_if:channel,other', 'nullable', 'string', 'max:60'],
'amount' => ['required', 'numeric', 'min:1', 'max:10000000', 'decimal:0,2'],
'received_on' => ['required', 'date', 'before_or_equal:today', 'after_or_equal:'.now()->subDays(90)->toDateString()],
'reference_no' => [Rule::requiredIf(fn () => $this->input('channel') !== 'cash'), 'nullable', 'string', 'min:4', 'max:64'],
'payer_name' => ['nullable', 'string', 'max:150'],
'notes' => ['nullable', 'string', 'max:1000'],
'proof' => ['nullable', 'file', 'max:5120', 'mimetypes:image/jpeg,image/png,application/pdf'],
'terms_acknowledged' => ['accepted'],
'form_token' => ['required', 'uuid'],
```
Messages: `reference_no.required` → "A reference number is needed for every channel except cash (UTR, UPI transaction ID, cheque or receipt no.)."; `received_on.after_or_equal` → "Payments older than 90 days cannot be recorded here."; `terms_acknowledged.accepted` → "Confirm the buyer has agreed to the terms of sale."
Helper `amountPaise(): int` → `(int) round((float) $this->input('amount') * 100)`; `lines(): array<int,int>` → qty>0 only.

**#17 AdminOfflineOrderController** (`final`, ctor `OfflineOrderService $offline, PaymentConfirmationService $confirmation`; private `guardFeature()` → `abort_unless(Feature::for(null)->active(OfflineOrdersFeature::class), 404)` called first in every action)
- `create(Request $r): View` — `$distributor = $r->filled('adn') ? $this->offline->eligibleDistributor($r->query('adn')) : null;` (error flag when ADN given but not eligible); catalogue = `ProductVariant::query()->where('status','active')->whereHas('product', fn($q)=>$q->where('status','active'))->with('product')->orderBy('variant_sku')->get()`; unit price per variant for this buyer via `CartService::unitPricePaise`; `$centres = AreteCenter::collectionChoicesFor($distributor?->id)`; prefill address from `CustomerAddressService::forCustomer($customerId)->first()` where customer = `Customer::where('user_id', $distributor->user_id)->first()`; quote when `$r->query('qty')` has any qty>0 (catch RuntimeException → `$quoteError`). `form_token` = `old('form_token', $r->query('form_token', (string) Str::uuid()))`. View `admin.commerce.offline-orders.create`.
- `store(StoreOfflineOrderRequest $r): RedirectResponse` — resolve distributor (null ⇒ back withErrors adn); validate centre ∈ `collectionChoicesFor($d->id)` when collect; build `OfflinePaymentDetails`; call `create()` in try: `RuntimeException|InsufficientStockException` ⇒ `back()->withErrors(['offline' => $e->getMessage()])->withInput()`. Success ⇒ redirect `admin.commerce.orders.show` with status "Offline order {no} created. It stays Placed until finance confirms the payment."
- `confirm(Request $r, Order $order)` — validate `note` nullable max:500 + `verified` accepted ("Tick to confirm you have checked the money has been received."); `confirmOffline($order, $r->user(), note)`; RuntimeException ⇒ back withErrors `offline`. Messages: CONFIRMED ⇒ "Payment confirmed. The order is now paid — BV has been recorded and the invoice issued."; ALREADY ⇒ "Already confirmed — nothing changed."
- `reject(Request $r, Order $order)` — validate `reason` required min:5 max:500; `reject()`; ⇒ "Payment rejected and the order cancelled. Reserved stock released. If money was received, return it to the payer outside the platform."
- `proof(Order $order): Response` — `abort_unless(auth()->user()->canAny(['finance.record','commerce.order.manage']), 403)`; payment with key else 404; audit `order.offline_proof_viewed` (before/after = `AuditLog::digest($payment->proof_sha256)`); S3 ⇒ `redirect()->away(temporaryUrl 15 min)` else `$disk->response(...)` — copy `AdminDistributorRequestController::document()`.

**#18 routes/web.php** — inside the admin group, directly after the `commerce.order.manage` block (line ~428):
```php
// Offline orders — money paid outside the gateway (flag commerce.offline_orders;
// the controller 404s when it is off). Creating one is operations'; confirming
// or rejecting the money is finance's, because confirmation creates BV (R-17).
Route::middleware('can:commerce.order.manage')->group(function (): void {
    Route::get('/commerce/offline-orders/create', [AdminOfflineOrderController::class, 'create'])->name('commerce.offline-orders.create');
    Route::post('/commerce/offline-orders', [AdminOfflineOrderController::class, 'store'])->name('commerce.offline-orders.store');
});
Route::middleware('can:finance.record')->group(function (): void {
    Route::post('/commerce/orders/{order}/offline/confirm', [AdminOfflineOrderController::class, 'confirm'])->name('commerce.offline-orders.confirm');
    Route::post('/commerce/orders/{order}/offline/reject', [AdminOfflineOrderController::class, 'reject'])->name('commerce.offline-orders.reject');
});
Route::get('/commerce/orders/{order}/offline/proof', [AdminOfflineOrderController::class, 'proof'])->name('commerce.offline-orders.proof');
```
+ `use App\Modules\Commerce\Http\Controllers\Admin\AdminOfflineOrderController;` beside the AdminOrderController import.

**#19 AdminOrderController**
- `index()`: `$offlineOn = Feature::for(null)->active(OfflineOrdersFeature::class);` add to the `ListFilters::make` field list when on:
  `FilterField::select('payment_method', 'Payment', [Order::PAYMENT_ONLINE => 'Online', Order::PAYMENT_OFFLINE => 'Offline'], column: 'orders.payment_method', placeholder: 'Any payment')`; add `'payment_method'` to the `hasAny([...])` default-to-today check; pass `'offlineOrdersOn' => $offlineOn`.
- `show()`: add `'offlinePayment'` to `$order->load([...])` as `'offlinePayment.recordedBy', 'offlinePayment.confirmedBy', 'offlinePayment.rejectedBy'`; pass `'offlineOrdersOn'`.

**#20 MyOrdersController::cancel()** — after the status check:
```php
if ($order->isAwaitingOfflineConfirmation()) {
    return redirect()->route('orders.show', $order->order_no)
        ->withErrors(['cancel' => 'Your payment for this order is still being confirmed by our team. Please contact support to cancel it.']);
}
```

**#21 create.blade.php** (extends `admin.layouts.admin`, title/heading "New offline order"). Sections, all Tailwind + `<x-ui.card>`, `<x-help-tip>`, Lucide icons, `@error` lines:
- Form-purpose note (UI convention): "Use this when a distributor has paid outside the website — cash at reception, a bank deposit, UPI, NEFT or a cheque. The order is created as Placed and waits for finance to confirm the money. BV is counted only after that confirmation."
- Step 1 — Distributor: GET form with `adn` input + "Find" button. Shows name, ADN, status when found; red message when not eligible ("No active distributor with that ADN. Blocked, terminated and rejected accounts cannot be ordered for.").
- When a distributor is loaded, one `<form id="offline-order" method="POST" action="store" enctype="multipart/form-data">` with `@csrf`, hidden `adn`, `form_token`:
  - Products table: SKU, product, price (this buyer's tier), BV, qty `<input type="number" name="qty[{id}]" min="0">` (value = `old/request`).
  - Delivery: radio ship/collect; ship address fields (prefilled); centre select from `$centres`.
  - Quote box (if `$quote`): subtotal, GST, shipping/collection, **Total payable**, BV; `$quoteError` in red.
  - Button "Calculate total" `formmethod="get" formaction="{{ route('admin.commerce.offline-orders.create') }}" formnovalidate` (the `@csrf` hidden input rides along in the query — harmless; the `proof` file is not sent on GET).
  - Payment: channel select, channel_other, amount (prefilled `$quote->totalPaise/100`), received_on (default today), reference_no, payer_name, notes, proof file, `terms_acknowledged` checkbox.
  - Submit "Create offline order" with `data-confirm="Create this offline order?" data-confirm-title="Create offline order" data-confirm-impact="Impact: creates the order in PLACED and reserves the stock. Nothing is counted — no BV, no invoice, nothing in the books — until finance confirms the payment."`
  - `@error('offline')` banner at top.

**#22 _payment-card.blade.php** (`$order`, `$offlinePayment`, `$offlineOrdersOn`)
- Header "Payment" + amber pill "Offline". State pill from `displayState()`: pending "Awaiting finance confirmation" (amber), confirmed "Confirmed" (green), rejected "Rejected" (red), cancelled "Not confirmed — order cancelled" (gray).
- Rows: Channel, Amount (IndianNumber rupees), Received on, Reference (mono), Payer, Notes, Recorded by + created_at, Confirmed by/at + note, Rejected by/at + reason, Proof link (`<x-lucide-paperclip>` → proof route) shown when key present and viewer `canAny`.
- When `$offlineOrdersOn && $order->isAwaitingOfflineConfirmation() && $offlinePayment->status === 'pending'` and `@can('finance.record')`:
  - Confirm form: `verified` checkbox "I have checked this money has been received", optional note, button "Confirm payment" (green) with `data-confirm-impact="Impact: marks the order PAID. BV is recorded for the buyer and passed up their Genos, the GST invoice is issued and the buyer is emailed — exactly as for a paid shop order. It cannot be undone; a mistake afterwards is a cancellation and refund."`
  - Reject form: reason textarea (required), button "Reject payment" (red outline) with impact "Impact: marks the payment REJECTED and CANCELS the order, releasing the stock. Nothing was counted, so nothing is reversed. Any money actually received must be returned to the payer outside the platform."
  - `@error('offline')`.

**#23 orders-show.blade.php**
- Replace the Payment card body (lines 209–224): `@if($order->isOffline() && $order->offlinePayment) @include('admin.commerce.offline-orders._payment-card', [...]) @else` existing card `@endif`.
- Cancel form impact (line ~135): when `$order->isAwaitingOfflineConfirmation()` use "Impact: cancels the order and releases the stock. The offline payment has not been confirmed, so nothing is in the books — any money received must be returned to the payer outside the platform. This cannot be undone." (Use a `@php $cancelImpact = … @endphp`.)

**#24 orders-index.blade.php**
- Above the status chips: `@if($offlineOrdersOn) @can('commerce.order.manage')` right-aligned button `<a href="{{ route('admin.commerce.offline-orders.create') }}">` with `<x-lucide-plus class="w-4 h-4"/>` "New offline order" `@endcan @endif`.
- In the Order # cell after the link: `@if($o->payment_method === 'offline')<span class="ml-1 px-1.5 py-0.5 rounded text-[10px] font-medium bg-amber-50 text-amber-800 border border-amber-200 align-middle">Offline</span>@endif` (render regardless of flag — history).

**#25/#26 Payments callout** — `AdminPaymentController::index()` passes `'pendingOfflineCount' => $offlineOn ? Order::where('payment_method', Order::PAYMENT_OFFLINE)->where('status', Order::STATUS_PLACED)->whereNull('paid_at')->count() : 0`. View: at top, `@if($pendingOfflineCount > 0) @can('finance.record')` an amber `<x-ui.card>` "N offline payment(s) awaiting your confirmation" linking to `route('admin.commerce.orders.index', ['status' => 'placed', 'payment_method' => 'offline', 'placed_from' => '', 'placed_to' => ''])`.

**#27 shop/orders/show.blade.php**
- Near the BV note (line ~87) for `$order->isOffline()`: "Paid offline ({{ channelLabel }}). @if pending — We are confirming your payment; your order is processed once it is confirmed. @endif" (load `offlinePayment` in `MyOrdersController::show` — add to its `with()`/`load()`).
- Cancel block (line 141): condition becomes `@if(in_array($order->status, ['placed','paid'], true) && ! $order->isAwaitingOfflineConfirmation())`.

**#28/#29 help docs** — `order-management.md`: new section "## Offline orders" (who creates/confirms, channels, exact-amount rule, what confirmation triggers, reject vs cancel, buyer cannot self-cancel while pending, where finance finds them). Add `placed` row nuance "(offline: awaiting finance confirmation)". `payments.md`: "Offline payments" subsection — ledger accounts (`asset.cash.office` / `asset.cash.bank.settlement`), refunds of a confirmed offline order land in the manual refund worklist.

**#30 tests/Feature/Commerce/OfflineOrderTest.php** — see Test plan (Pest, `RefreshDatabase`, seed `LedgerAccountSeeder` + `RolesAndPermissionsSeeder`, `Feature::for(null)->activate(OfflineOrdersFeature::class)`, `Storage::fake('offline-payments')`, `Bus::fake([PropagateGroupBvJob::class])` where asserted). Build the distributor with the raw-insert fixture pattern from `tests/Feature/Commerce/AdminOrderRepurchaseWalletTest.php::aorwDistributor()` (disable FKs, insert `distributors`, self-sponsor) but with a 9-digit numeric ADN; set `settings` key read by `BvLedgerService::selfPurchaseEarnsBv()` to `'true'` so self-consumption BV accrues. Scoped-role users: `Role::firstOrCreate` + `RolesAndPermissionsSeeder`.

**#31 tests/Browser/offline-orders.spec.js** — uses `fixtures.js` (`adminPage`, `submitAndConfirm`). Distributor ADN from `A11Y_ADN` (default `394325128`). Scoped-role users from env `E2E_OPS_EMAIL/E2E_FIN_EMAIL/E2E_COMPLIANCE_EMAIL` + `E2E_STAFF_PASSWORD`; those tests `test.skip()` with a message when unset.

## Slices

Implementation runs inline in this session (user rule: avoid subagents for work one context can handle), slice by slice, each verified before the next.

| Slice | Title | Files | Depends on | Model |
|---|---|---|---|---|
| S1 | Schema, model, flag, disk | #1–#9, #15 | — | Opus (inline) |
| S2 | Services (SSOT path) + service tests | #10–#14, #30 (service cases) | S1 | Opus (inline) |
| S3 | HTTP: request, controller, routes, order/My-orders controllers | #16–#20, #26, #30 (HTTP + RBAC cases) | S2 | Opus (inline) |
| S4 | Views + help docs | #21–#25, #27–#29 | S3 | Opus (inline) |
| S5 | Playwright + live browser verification | #31 | S4 | Opus (inline) |

## Test plan

Pest (`tests/Feature/Commerce/OfflineOrderTest.php`), run against `arovolife_test`:

| ID | Scenario | Asserts |
|---|---|---|
| OO-01 | Ops creates offline order (ship) | order `placed`, `payment_method=offline`, `attribution_source=admin`, `self_consumption=true`, offline_payments `pending`; **no** `order.placed:{id}` ledger tx; **no** BV ledger rows; stock `reserved` +qty; wallet balance unchanged; audit `order.offline_created` actor = ops user; `order.placed` audit actor = ops user |
| OO-02 | Amount ≠ total | RuntimeException/redirect with error; no order, no reservation, proof file deleted |
| OO-03 | Resubmit same `form_token` | same order returned, one order total |
| OO-04 | Reference reused (non-rejected) | refused |
| OO-05 | Frozen / terminated distributor | refused |
| OO-06 | Finance confirms | order `paid`, `paid_at` set; ledger tx `order.placed:{id}` Dr `asset.cash.bank.settlement` (upi) / Cr `liability.customer_prepayment` = total; cash channel ⇒ Dr `asset.cash.office`; BV accrual rows equal a shop order of the same cart (same `BvLedgerService::accrue`); `PropagateGroupBvJob` dispatched with distributor id + BV; invoice row exists; audit `order.paid` details `confirmed_via=offline`, `offline_payment_id`; payment `confirmed` |
| OO-07 | Confirm twice | second returns ALREADY_CONFIRMED; one ledger tx, one BV accrual |
| OO-08 | Confirm cancelled / rejected / non-offline order | refused, nothing posted |
| OO-09 | Confirmed offline order → markShipped | ledger `order.shipped:{id}` posts balanced (proves prepayment reuse) |
| OO-10 | Confirmed offline order → admin cancel | `order.cancelled:{id}` Cr `liability.refund_payable` = total; `RefundPayable::owedOutsideGateway` = total; BV reversed |
| OO-11 | Finance rejects | payment `rejected`, order `cancelled`, reservation released, no ledger tx |
| OO-12 | RBAC: admin-operations | create 200/302 ✅; confirm 403; reject 403 |
| OO-13 | RBAC: admin-finance | create GET/POST 403; confirm ✅ |
| OO-14 | RBAC: admin-compliance | create 403, confirm 403, proof 403 |
| OO-15 | Distributor hitting admin routes | 403 |
| OO-16 | Flag off | create/store/confirm/reject/proof 404; index has no button |
| OO-17 | Buyer self-cancel pending offline | error, order still `placed` |
| OO-18 | Proof view | audited row `order.offline_proof_viewed`; 404 without file |
| OO-19 | Service re-assert | `confirmOffline()` with an ops-only user throws `AuthorizationException` |
| — | Existing `MarkPaidChokePointTest`, `AdminOrderTransitionTest`, Commerce + Payments suites | still green |

Playwright (`tests/Browser/offline-orders.spec.js`):

| ID | Role | Scenario |
|---|---|---|
| PW-01 | admin | Orders index shows "New offline order"; open form; find ADN; qty 1; Calculate total; fill UPI + ref; create → order page shows Offline card "Awaiting finance confirmation", status Placed |
| PW-02 | admin | Confirm payment (confirm modal) → status Paid, invoice number shown, card "Confirmed" |
| PW-03 | admin | Validation: submit with wrong amount → error banner, no order |
| PW-04 | admin | Reject a second offline order → Cancelled, card "Rejected" |
| PW-05 | ops (env) | sees button, can create; no Confirm/Reject buttons on the order; POST confirm → 403 |
| PW-06 | finance (env) | no "New offline order" button; GET create → 403; sees and uses Confirm |
| PW-07 | compliance (env) | no button, no confirm; create → 403 |
| PW-08 | distributor | My Orders → the offline order shows "Paid offline" note and no Cancel button while pending |

## Acceptance criteria

- [ ] `docker exec … arovolife-app php artisan migrate` (dev) applies 3 migrations; `migrate:status` clean.
- [ ] `OfflineOrderTest` all green; full `tests/Feature/Commerce`, `tests/Feature/Payments`, `tests/Feature/Compliance` green — commands with the `-e DB_DATABASE=arovolife_test …` overrides from `docs/local-dev-environment.md`.
- [ ] `vendor/bin/pint --dirty` clean; `vendor/bin/phpstan analyse` (level 7) no new errors on touched files.
- [ ] `npm run build` done; views compile (`php artisan view:cache` + `php -l` on compiled views — see blade lint memory).
- [ ] `MarkPaidChokePointTest` unchanged and green (markPaid still called only from PaymentConfirmationService).
- [ ] Browser (localhost:8084): PW-01…PW-04 and PW-08 verified by hand/Playwright as admin + distributor; flag toggled ON on dev only.
- [ ] Help docs updated; this plan gets an Outcome section.

## Deploy notes (staging, later — not part of this change)

- Code pull + `php artisan migrate` (3 module migrations under `app/Modules/Commerce/Database/Migrations/`) + `route:clear` (new routes) + `config:clear` (new disk) + Vite build on server + `queue:restart`.
- Flag stays OFF until the client signs off; developer toggles it in Feature Flags.

---

## Compliance review amendments (2026-09-23) — SUPERSEDE the sections above where they conflict

Plan reviewed by `compliance-officer`: no Critical; 3 High, 3 Major, 4 Minor. All adopted.
None reverses a user decision (D1–D4). Claims about `cancel()`, `markShipped()`,
`RazorpayRefundService::create()`, `RefundPayable`, `place()` and `transfer()` were verified.

### H1 — Income Tax Act s.269ST cash ceiling
- `StoreOfflineOrderRequest`: when `channel=cash`, `amount` max `199999.99`
  (message: "Cash of ₹2 lakh or more from one person can't be accepted (Income Tax Act s.269ST). Ask for a bank transfer or UPI instead.").
- `offline_payments` gains `distributor_id` (FK `distributors`, indexed) — set at create.
- `OfflineOrderService::create()`, inside the transaction, after `Distributor::lockForUpdate()->find($d->id)`:
  sum `amount_paise` of that distributor's `channel=cash`, `status != rejected` offline payments with the same
  `received_on`; refuse when `sum + amount ≥ 20_000_000` paise (same message).
- Test OO-20; help doc line.

### H2 — Proof files are PII (hard rule 8, DPDP §8(7))
- New `app/Modules/Commerce/Services/OfflinePaymentProofVault.php` (`final`), mirroring `KycDocumentVault`:
  `disk()` = `Storage::disk('offline-payments')`; `store(UploadedFile, string $key)` writes
  `PiiCrypter::encryptString(bytes)`; `read(OfflinePayment): ?string` decrypts; `mimeType(string $bytes)`;
  `delete(string $key)`; `retentionDays()` (setting `commerce.offline_payment_proof_retention_days`, default 2920, min 365);
  `rejectedRetentionDays()` (setting `commerce.offline_payment_rejected_proof_retention_days`, default 90, min 30).
- Proof route streams the decrypted bytes (`response($bytes, 200, ['Content-Type' => mime, 'Content-Disposition' => 'inline', 'Cache-Control' => 'no-store'])`), never a URL.
- `offline_payments` gains `proof_purged_at` (nullable timestamp).
- New command `app/Modules/Commerce/Console/Commands/PurgeOfflinePaymentProofsCommand.php`
  (`commerce:purge-offline-payment-proofs {--dry-run}`): deletes proofs of confirmed payments older than
  `retentionDays()` from `confirmed_at`, and of rejected / cancelled-unconfirmed payments older than
  `rejectedRetentionDays()` from `rejected_at` / order `cancelled_at`; nulls the key, sets `proof_purged_at`,
  audits `offline_payment.proof_purged` per row. Registered in `CommerceServiceProvider` commands list AND
  scheduled daily in `routes/console.php` next to `PurgeExpiredDocumentsCommand` (module-command-registration rule).
- Both settings added to `AdminSettingsController` registry (group `commerce`, admin-owned), copy mirrors the KYC row.
- **Deferred to the client (launch gate, recorded in R-107):** a Privacy Policy retention line for offline-payment proofs. Not edited here — published content needs the client's wording and `content:publish`.

### H3 — Same-actor create + confirm (residual of D1)
- `order.offline_created` audit details and the `markPaid` evidence (`settle()` array) both carry
  `'same_actor' => $payment->recorded_by_user_id === $actor->id`.
- New risk-register row **R-107** (`docs/compliance/risk-register.md`): "An offline payment can be recorded and
  confirmed by the same super-admin" — Statutory/Operational, Medium; accepted by the user 2026-09-23 (decision D1-B);
  mitigation: `same_actor` flag on both audit rows + a monthly admin-compliance review of same-actor confirmations;
  also lists the deferred Privacy Policy retention line and the s.269ST control.

### M1 — Rejecting money that was actually received
- Reject form requires checkbox `money_not_received` (accepted): "No money was received for this order (the deposit
  did not arrive / the entry was a mistake)." Copy: "If the money WAS received but the order must be undone, confirm
  the payment first and then cancel the order — the refund is then owed on the books and appears in Payments → Refunds."
- Remove every "return it to the payer outside the platform" string.
- `AdminOrderController::cancel()` refuses a pending offline order:
  `if ($order->isAwaitingOfflineConfirmation()) return …->withErrors(['cancel' => 'This offline order is awaiting payment confirmation. Finance confirms or rejects it from the payment card.']);`
  and `orders-show.blade.php` hides the Cancel button in that state. `OfflineOrderService::reject()` calls
  `OrderStateMachine::cancel()` directly, so it is unaffected. (Buyer self-cancel already blocked — #20.)
- Test OO-21 (ops cancel refused), OO-11 updated (reject needs the assertion).

### M2 — Staff ordering for their own ADN
- `create()` and `confirmOffline()` refuse when `$actor->id === $distributor->user_id`
  ("You cannot record or confirm an offline order for your own distributor account."). Test OO-22.

### M3 — Enum migration (replaces #1)
`payment_method` is `ENUM('online')` today (COD dropped on purpose, `database/migrations/2026_06_25_160611_drop_cod_from_payment_method_enum.php`). New migration mirrors that file:
```php
if (DB::connection()->getDriverName() === 'mysql') {
    DB::statement("ALTER TABLE `orders` MODIFY COLUMN `payment_method` ENUM('online','offline') NOT NULL DEFAULT 'online'");
} else {
    Schema::table('orders', fn (Blueprint $t) => $t->enum('payment_method', ['online', 'offline'])->default('online')->change());
}
// down(): throw if any 'offline' rows exist; else ENUM('online') by the same two branches.
```

### Minors
- m1: normalise `reference_no` = `strtoupper(preg_replace('/\s+/', '', trim($ref)))` (in the request's `prepareForValidation`); re-run the duplicate-reference check under lock inside `confirmOffline()`.
- m2: `CheckoutService::place()` — the `customer.distributor_backfilled` audit also uses `$actorUserId ?? $authUserId`.
- m3: `payments.md` notes a manual refund of a cash-channel order is recorded against the settlement bank account (NEFT out), which is correct: refunds are paid by bank transfer.
- m4: form-purpose note and help doc add "A purchase is never required to join or to stay a distributor." `order.offline_created` details carry `'within_30_days_of_joining' => $d->effective_date > now()->subDays(30)`.

### File-table additions
| # | Path | New/Mod | Summary |
|---|---|---|---|
| 32 | `app/Modules/Commerce/Services/OfflinePaymentProofVault.php` | New | Encrypted proof storage + retention |
| 33 | `app/Modules/Commerce/Console/Commands/PurgeOfflinePaymentProofsCommand.php` | New | Retention purge |
| 34 | `app/Modules/Commerce/CommerceServiceProvider.php` | Mod | Register the command |
| 35 | `routes/console.php` | Mod | Schedule it daily |
| 36 | `app/Modules/Admin/Http/Controllers/AdminSettingsController.php` | Mod | Two retention settings |
| 37 | `docs/compliance/risk-register.md` | Mod | R-107 |
(#2 gains `distributor_id`, `proof_purged_at`; #1 replaced per M3; #19 gains the cancel guard.)

### Test-plan additions
OO-20 cash ≥ ₹2 lakh same day refused · OO-21 ops cancel of pending offline refused · OO-22 actor = buyer refused on create and confirm · OO-23 proof stored as ciphertext (raw bytes on fake disk ≠ upload) and route returns plaintext · OO-24 purge command deletes expired / rejected proofs, audits, dry-run touches nothing · OO-25 `same_actor` true/false recorded.

Slices: #32 lands in S2; #33–#36 in S3; #37 in S4.

---

## Outcome (2026-09-23)

**Shipped on `feat/offline-orders`.** All 37 file-table rows landed.

Verification:
- `tests/Feature/Commerce/OfflineOrderTest.php` — 31 tests / 145 assertions green on `arovolife_test` (MySQL).
- Regression: Commerce, Compliance (incl. `MarkPaidChokePointTest`), Fulfilment, Inventory, Security, Reports, ActionCenter, scheduled-command registration, and `tests/Modules/{Commerce,Payments,Returns,Tax,Admin}` — 1,121 passed, 1 skipped (pre-existing).
- Pint clean; Larastan level 7 clean on every touched app file (the one `config/filesystems.php:44` finding is pre-existing, not on a changed line).
- Views compiled + `php -l` on compiled views; `npm run build`.
- Playwright `tests/Browser/offline-orders.spec.js` — PW-01/02, 03, 04, 08 pass against localhost:8084; PW-05/06/07 skip (no scoped staff logins on dev — `staff:create` is interactive-only). Pest OO-12…16 cover every permission-matrix row.
- Live dev check: a browser-confirmed offline order accrued personal BV, credited Genos BV to 5 upline levels via the real `compensation` queue worker, issued a GST invoice and posted `Dr asset.cash.bank.settlement / Cr liability.customer_prepayment` under `order.placed:{id}` — same effects as a shop order.

Deviations from the plan:
1. **"Calculate total" is a background JSON quote** (`GET admin.commerce.offline-orders.quote`, `commerce.order.manage`, flag-gated) instead of the planned GET re-render. Final compliance review M-1: the GET re-render put the CSRF token, buyer phone/address, payer and reference into the URL (access logs, history, Referer) and dropped an attached proof. The quote request carries only ADN, quantities and delivery type; the page never reloads. The interim `data-confirm-skip` change to the shared confirm modal was reverted — nothing shared is modified.
2. `OfflineOrderService::eligibleDistributor()` also requires `distributors.status = 'active'` (record-level flag), in addition to the user-status rule in the plan.
3. `confirmOffline()` resolves the buyer's user id with a direct query instead of the untyped `Order::distributor()` relation (static analysis).
4. Final-review minors: retention settings are floored in code at 365 / 30 days (not only in the settings registry); the purge marks + audits the row in a transaction before deleting the file; OO-28 pins offline orders out of `orders:expire-unpaid`; R-107 records the s.269ST residual (third-party payer, back-dated `received_on`).

Final verification after those fixes: OfflineOrderTest 34 passed; regression 1,124 passed / 1 skipped; Pint + Larastan clean; Playwright 4 passed / 3 skipped (scoped roles). Final compliance review: PASS, trailer approved.

Dev-only state changed: flag `commerce.offline_orders` activated on the local dev DB; four test offline orders created for ADN 360801433 by the Playwright run (one paid, one rejected, two pending).

Deferred / open:
- Privacy Policy line for proof-file retention — client wording + `content:publish` (R-107 launch gate).
- Monthly admin-compliance review of `same_actor` confirmations (R-107) — process, not code.
- Staging deploy: `git_pull` + `php artisan migrate` (3 module migrations) + `route:clear` + `config:clear` + server-side Vite build + `queue:restart`; flag stays OFF until the client signs off.
