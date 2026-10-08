# Pre-launch Cart Gate Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** An admin-owned setting that closes the cart for everyone except back-office staff, so the catalogue stays browsable before the 2026-11-10 launch while nobody outside the team can add to cart, open a shared cart, or check out.

**Architecture:** One new boolean setting `commerce.cart.enabled` (missing row = open, so deploying changes nothing). One small `CartGate` support class is the single reader of that setting and the single place the staff bypass lives; a `@cartOpen` Blade directive and the two storefront controllers call it. Nothing else changes: the existing `commerce.storefront.enabled` (whole shop 404) and `commerce.checkout.enabled` (checkout 404) switches keep their meaning.

**Tech Stack:** Laravel 13 / PHP 8.4, Blade + Tailwind, Pest, Spatie roles (`User::isStaff()`), raw `settings` table reads as the sibling controllers do.

**Spec:** This file is the spec. Decisions were taken in the 2026-10-09 session:
- D-1 A **separate** setting, not an overload of `commerce.checkout.enabled`, so checkout can stay ON for the team's own production testing while the public buttons are hidden.
- D-2 **Staff bypass:** every role in `User::STAFF_ROLES` (`developer`, `admin`, `admin-operations`, `admin-finance`, `admin-compliance`) sees the buttons and can add, share and check out while the gate is closed. The bypass is not environment-specific; the setting is simply turned OFF on production before launch and ON at launch. Distributors and guests are gated.
- D-3 Gate **adds, quantity increases, cart sharing, opening a shared cart, and checkout**. Viewing the cart, decreasing quantity, removing lines and clearing the cart stay open so a visitor can always empty a cart built before the gate closed.
- D-4 Copy: "Available at launch" in place of the button; "Ordering opens at launch. You can browse the catalogue until then." as the explanatory line. No date, no scarcity, no earnings (arovolife-ux-writing; hard rule 3).
- D-5 Turning the setting OFF/ON on each environment is an operator action through `/admin/settings` after deploy, already audited by `AdminSettingsController`; it is **not** part of this plan.

## Global Constraints
- `declare(strict_types=1);` in every PHP file; `final` classes; constructor-promoted dependencies; explicit return types.
- Blade: Tailwind utilities only, no inline styles, `{{ }}` only; icons via `<x-lucide-*>`.
- No new hardcoded flag reads outside `CartGate`; every surface asks `CartGate::isOpenFor()`.
- The seeder `CommerceFeatureFlagSeeder` is `updateOrInsert` and **resets every commerce flag when re-run** — add the row to it for fresh installs, but never re-run it on staging or production.
- Tests run only against `arovolife_test` (docs/local-dev-environment.md):
  `docker exec -e DB_CONNECTION=mysql -e DB_DATABASE=arovolife_test -e DB_HOST=db -e DB_PORT=3306 -e DB_USERNAME=arovolife -e DB_PASSWORD=secret arovolife-app php artisan test --compact <path>`
- `vendor/bin/pint --dirty --format agent` before each commit; Larastan level 7 must pass (`vendor/bin/phpstan analyse --memory-limit=1G` on the touched files).
- Blade views are compiled on deploy: changed blades need `php artisan view:cache` and, because no CSS classes are new, **no** `npm run build`.
- Public copy changed → every commit carries `Compliance-Review: compliance-officer` in the trailer (CLAUDE.md commit discipline).

## Review Focus
1. **A visitor who filled a cart before the gate closed** must not be able to check out: the cart page hides "Proceed to Checkout" and `GET /shop/checkout` 404s for them (Task 4 test CGT-09 / CGT-10).
2. **The Easy Purchase link** (`/shop/easy-cart/{code}`) must not load items into a guest's cart while closed, or the gate is trivially bypassed by any distributor's share link (Task 3 test CGT-06).
3. **A missing settings row** must mean OPEN, or the first deploy silently closes the cart on every environment (Task 1 test CGT-02).
4. **The listing-card AJAX add** must get a non-2xx so the page's JS shows its error toast instead of "added" (Task 3 test CGT-04).
5. **Staff must still be able to do everything** while closed, or the team cannot test production before launch (Task 3 CGT-05, Task 4 CGT-11).

---

### Task 1: `CartGate` support class + setting registry + seeder row

**Files:**
- Create: `app/app/Modules/Commerce/Support/CartGate.php`
- Modify: `app/app/Modules/Commerce/CommerceServiceProvider.php` (`register()` singleton + `@cartOpen` directive next to the existing `@bv` directive at line 62)
- Modify: `app/app/Modules/Admin/Http/Controllers/AdminSettingsController.php:582-596` (registry, after `commerce.checkout.enabled`)
- Modify: `app/app/database/seeders/CommerceFeatureFlagSeeder.php:17` (new row after `commerce.checkout.enabled`)
- Test: `app/tests/Modules/Commerce/CartGateTest.php` (new; later tasks append to it)

**Interfaces:**
- Produces: `App\Modules\Commerce\Support\CartGate` — `final class`, singleton. `public const SETTING_KEY = 'commerce.cart.enabled'`, `public const CLOSED_MESSAGE = 'Ordering opens at launch. You can browse the catalogue until then.'`, `public function isOpenFor(?User $user): bool`. Blade directive `@cartOpen … @else … @endcartOpen`.

- [ ] **Step 1: Write the failing tests**

Create `app/tests/Modules/Commerce/CartGateTest.php`:

```php
<?php

declare(strict_types=1);

use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductCategory;
use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Commerce\Support\CartGate;
use App\Modules\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

/**
 * Pre-launch cart gate (plan docs/plans/prelaunch-cart-gate-2026-10-09.md):
 * `commerce.cart.enabled` OFF hides Add to Cart and refuses adds, shares and
 * checkout for distributors and guests; every back-office role bypasses it.
 */
function cgtSetting(string $key, string $value): void
{
    DB::table('settings')->updateOrInsert(
        ['key' => $key],
        ['value' => $value, 'version' => 1, 'updated_at' => now()],
    );
}

function cgtUser(?string $role = null): User
{
    $user = User::create([
        'full_name' => 'Gate '.random_int(1000, 9999),
        'email' => 'cgt-'.uniqid().'@test.com',
        'phone_e164' => '+91'.str_pad((string) random_int(7000000000, 9999999999), 10, '0'),
        'password_hash' => bcrypt('x'),
        'password_set_at' => now(),
        'status' => 'active',
        'email_verified_at' => now(),
    ]);

    if ($role !== null) {
        Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        $user->assignRole($role);
    }

    return $user;
}

/** A distributor user (raw row, FK checks off — the same shape SharedCartTest uses). */
function cgtDistributorUser(): User
{
    $user = cgtUser();
    disableTestForeignKeys();
    try {
        $id = DB::table('distributors')->insertGetId([
            'user_id' => $user->id, 'adn' => 'AV'.random_int(100000, 999999),
            'pan_hash' => random_bytes(32), 'pan_last4' => '0000',
            'bank_account_enc' => 'stub', 'bank_ifsc' => 'SBIN0000000',
            'sponsor_id' => 0, 'placement_parent_id' => 0, 'side_chosen_by' => 'referral_default', 'depth' => 0,
            'effective_date' => now()->format('Y-m-d H:i:s.v'),
            'cooling_off_end_at' => now()->copy()->addDays(30)->format('Y-m-d H:i:s.v'),
            'state' => 'TS', 'is_primary_couple' => 0,
            'created_at' => now()->format('Y-m-d H:i:s.v'), 'updated_at' => now()->format('Y-m-d H:i:s.v'),
        ]);
        DB::table('distributors')->where('id', $id)->update(['sponsor_id' => $id, 'placement_parent_id' => $id]);
    } finally {
        enableTestForeignKeys();
    }

    return $user->refresh();
}

/** An active product in an active category (the listing groups by category, so one is required to render a card). */
function cgtVariant(): ProductVariant
{
    $n = random_int(10000, 99999);
    $category = ProductCategory::query()->firstOrCreate(
        ['slug' => 'cgt-care'],
        ['name' => 'Gate Care', 'sort' => 1, 'status' => 'active'],
    );
    $product = Product::create(['sku' => "CGT-{$n}", 'slug' => "cgt-{$n}", 'name' => "Gate {$n}", 'hsn_code' => '3004', 'status' => 'active', 'category_id' => $category->id]);

    return ProductVariant::create([
        'product_id' => $product->id, 'variant_sku' => "CGT-{$n}-V1", 'name' => 'Default',
        'mrp_paise' => 50000, 'sale_price_paise' => 50000, 'gst_rate_bp' => 1800,
        'inventory_policy' => 'no_track', 'status' => 'active',
    ]);
}

beforeEach(function (): void {
    cgtSetting('commerce.storefront.enabled', 'true');
    cgtSetting('commerce.checkout.enabled', 'true');
});

it('CGT-01: the gate is closed for a guest and a distributor when the setting is false', function (): void {
    cgtSetting(CartGate::SETTING_KEY, 'false');

    $gate = app(CartGate::class);

    expect($gate->isOpenFor(null))->toBeFalse()
        ->and($gate->isOpenFor(cgtUser()))->toBeFalse();
});

it('CGT-02: a missing settings row means OPEN', function (): void {
    DB::table('settings')->where('key', CartGate::SETTING_KEY)->delete();

    expect(app(CartGate::class)->isOpenFor(null))->toBeTrue();
});

it('CGT-03: every back-office role bypasses a closed gate', function (): void {
    cgtSetting(CartGate::SETTING_KEY, 'false');

    foreach (User::STAFF_ROLES as $role) {
        expect(app(CartGate::class)->isOpenFor(cgtUser($role)))->toBeTrue($role.' should bypass');
    }
});
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `docker exec -e DB_CONNECTION=mysql -e DB_DATABASE=arovolife_test -e DB_HOST=db -e DB_PORT=3306 -e DB_USERNAME=arovolife -e DB_PASSWORD=secret arovolife-app php artisan test --compact tests/Modules/Commerce/CartGateTest.php`
Expected: FAIL — `Class "App\Modules\Commerce\Support\CartGate" not found`.

- [ ] **Step 3: Create the class**

`app/app/Modules/Commerce/Support/CartGate.php`:

```php
<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Support;

use App\Modules\Identity\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Pre-launch cart gate. `commerce.cart.enabled` OFF closes adds, cart sharing
 * and checkout for distributors and guests while the catalogue stays
 * browsable; every back-office role bypasses it so the team can keep testing.
 * A missing row means OPEN so a deploy never closes the cart by itself.
 *
 * Bound as a singleton: the setting is read once per request however many
 * product cards ask.
 */
final class CartGate
{
    public const SETTING_KEY = 'commerce.cart.enabled';

    public const CLOSED_MESSAGE = 'Ordering opens at launch. You can browse the catalogue until then.';

    private ?bool $settingOpen = null;

    public function isOpenFor(?User $user): bool
    {
        if ($user?->isStaff()) {
            return true;
        }

        return $this->settingOpen ??= $this->readSetting();
    }

    private function readSetting(): bool
    {
        $value = DB::table('settings')->where('key', self::SETTING_KEY)->value('value');

        return $value === null || $value === 'true';
    }
}
```

- [ ] **Step 4: Register the singleton and the Blade directive**

In `app/app/Modules/Commerce/CommerceServiceProvider.php`:
- add `use App\Modules\Commerce\Support\CartGate;` to the imports;
- in `register()` add `$this->app->singleton(CartGate::class);` (if the provider has no `register()` method, add `public function register(): void { $this->app->singleton(CartGate::class); }`);
- in `boot()`, directly after the `@bv` directive (line 62), add:

```php
        // `@cartOpen … @else … @endcartOpen` — the one Blade question for
        // "may this visitor add to cart / check out right now" (pre-launch
        // gate + staff bypass live in CartGate, never in a view).
        Blade::if('cartOpen', static fn (): bool => app(CartGate::class)->isOpenFor(auth()->user()));
```

- [ ] **Step 5: Add the registry entry**

In `AdminSettingsController.php`, directly after the `'commerce.checkout.enabled' => [ … ],` entry (ends line 596):

```php
            'commerce.cart.enabled' => [
                'group' => 'commerce',
                'label' => 'Cart and ordering open',
                'description' => 'Turn OFF before launch to hide every Add to Cart button and refuse adds, Easy Purchase links and checkout for distributors and visitors while the catalogue stays browsable. Back-office roles are never gated, so the team can keep placing test orders. Turn ON at launch.',
                'impact' => 'OFF stops all customer ordering immediately, including carts built earlier: their owners can still view and empty the cart but cannot check out. Staff accounts are unaffected. No data changes either way.',
                'type' => 'bool',
                'default' => 'true',
            ],
```

- [ ] **Step 6: Add the seeder row**

In `app/app/database/seeders/CommerceFeatureFlagSeeder.php`, after line 17 (`commerce.checkout.enabled`):

```php
            ['key' => 'commerce.cart.enabled',                    'value' => 'true'],   // Pre-launch gate: OFF hides Add to Cart for non-staff (plan 2026-10-09)
```

- [ ] **Step 7: Run the tests to verify they pass**

Run the Task 1 Step 2 command. Expected: 3 passed.

- [ ] **Step 8: Pint, Larastan, commit**

```bash
cd app && vendor/bin/pint --dirty --format agent && vendor/bin/phpstan analyse --memory-limit=1G app/Modules/Commerce/Support/CartGate.php app/Modules/Commerce/CommerceServiceProvider.php
git add app/app/Modules/Commerce/Support/CartGate.php app/app/Modules/Commerce/CommerceServiceProvider.php app/app/Modules/Admin/Http/Controllers/AdminSettingsController.php app/app/database/seeders/CommerceFeatureFlagSeeder.php app/tests/Modules/Commerce/CartGateTest.php
git commit -m "feat(commerce): pre-launch cart gate setting with back-office bypass

Compliance-Review: compliance-officer

Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>"
```

---

### Task 2: Hide the buttons in the three storefront blades and the cart page

**Files:**
- Modify: `app/resources/views/shop/product.blade.php:146-174` (the Add to Cart form)
- Modify: `app/resources/views/partials/_product-card.blade.php:42-50` (the icon form)
- Modify: `app/resources/views/shop/cart.blade.php:159-163` (Proceed to Checkout) and the Easy Purchase share block that starts at line 165 (`@if(auth()->user()?->distributor)`)
- Test: `app/tests/Modules/Commerce/CartGateTest.php` (append)

**Interfaces:**
- Consumes: `@cartOpen` directive and `CartGate::CLOSED_MESSAGE` from Task 1.

- [ ] **Step 1: Append the failing view tests**

```php
it('CGT-07: closed → the product page shows "Available at launch" instead of Add to Cart', function (): void {
    cgtSetting(CartGate::SETTING_KEY, 'false');
    $variant = cgtVariant();

    $this->get(route('shop.product', ['slug' => $variant->product->slug]))
        ->assertOk()
        ->assertSee('Available at launch')
        ->assertSee(CartGate::CLOSED_MESSAGE)
        ->assertDontSee('Add to Cart');
});

it('CGT-08: closed → the listing card has no add-to-cart form; staff still get it', function (): void {
    cgtSetting(CartGate::SETTING_KEY, 'false');
    cgtVariant();

    // `data-add-to-cart>` is the form tag's end; the page's JS selector string
    // `form[data-add-to-cart]` is always present, so match the tag, not the name.
    $this->get(route('shop.index'))->assertOk()->assertDontSee('data-add-to-cart>', false);

    $this->actingAs(cgtUser('admin-operations'))
        ->get(route('shop.index'))->assertOk()->assertSee('data-add-to-cart>', false);
});

it('CGT-09: closed → the cart page hides Proceed to Checkout and the share form, and explains why', function (): void {
    cgtSetting(CartGate::SETTING_KEY, 'false');
    $variant = cgtVariant();
    $distributor = cgtUser();

    // Build the cart while open, then close the gate.
    cgtSetting(CartGate::SETTING_KEY, 'true');
    $this->actingAs($distributor)->post(route('shop.cart.add'), ['product_variant_id' => $variant->id, 'qty' => 1]);
    cgtSetting(CartGate::SETTING_KEY, 'false');
    app()->forgetInstance(CartGate::class);

    $this->actingAs($distributor)->get(route('shop.cart'))
        ->assertOk()
        ->assertSee(CartGate::CLOSED_MESSAGE)
        ->assertDontSee('Proceed to Checkout')
        ->assertDontSee(route('shop.cart.share'), false);
});
```

Note on `app()->forgetInstance(CartGate::class)`: the gate memoises the setting per request; within one Pest test several requests share the container, so drop the singleton after flipping the setting mid-test. Any later test that flips the setting **after** its first request must do the same.

- [ ] **Step 2: Run the tests to verify they fail**

Run the Task 1 Step 2 command. Expected: CGT-07, CGT-08, CGT-09 FAIL (buttons still rendered); CGT-01..03 pass.

- [ ] **Step 3: Product page**

In `shop/product.blade.php`, the current block is:

```blade
        @if(($outOfStock[$variant->id] ?? false))
        <div class="mb-8">
            <span class="inline-flex items-center px-4 py-2.5 rounded-full bg-gray-100 text-gray-700 text-sm font-semibold">
                Out of stock
            </span>
        </div>
        @else
        <form method="POST" action="{{ route('shop.cart.add') }}" class="mb-8">
            …
        </form>
        @endif
```

Change it to (the form body is unchanged; only the surrounding branches move):

```blade
        @if(($outOfStock[$variant->id] ?? false))
        <div class="mb-8">
            <span class="inline-flex items-center px-4 py-2.5 rounded-full bg-gray-100 text-gray-700 text-sm font-semibold">
                Out of stock
            </span>
        </div>
        @else
        @cartOpen
        <form method="POST" action="{{ route('shop.cart.add') }}" class="mb-8">
            … (existing form body, untouched)
        </form>
        @else
        {{-- Pre-launch cart gate (CartGate): the catalogue is browsable, ordering
             is not. Factual availability only — no date, no scarcity, no income
             (hard rule #3). Staff never see this branch. --}}
        <div class="mb-8">
            <span class="inline-flex items-center px-4 py-2.5 rounded-full bg-gray-100 text-gray-700 text-sm font-semibold">
                Available at launch
            </span>
            <p class="text-xs text-gray-600 mt-2">{{ \App\Modules\Commerce\Support\CartGate::CLOSED_MESSAGE }}</p>
        </div>
        @endcartOpen
        @endif
```

- [ ] **Step 4: Listing card**

In `partials/_product-card.blade.php`, wrap only the `<form … data-add-to-cart>…</form>` (lines 42–50) in `@cartOpen … @endcartOpen`. The price span stays; when closed the row simply has no button. No placeholder text on the card (the product page carries the explanation).

- [ ] **Step 5: Cart page**

In `shop/cart.blade.php`, replace lines 159–163:

```blade
        @cartOpen
        <a href="{{ route('shop.checkout') }}"
           class="block text-center w-full py-3 rounded-full bg-brand-600 hover:bg-brand-700 text-white font-semibold text-sm transition-colors">
            Proceed to Checkout
        </a>
        <p class="text-xs text-gray-600 mt-3 text-center">30-day return window on every order.</p>
        @else
        {{-- Pre-launch cart gate (CartGate): a cart built earlier can be viewed
             and emptied but not checked out. Staff never see this branch. --}}
        <p class="text-sm text-gray-700 text-center rounded-lg bg-gray-100 px-4 py-3">{{ \App\Modules\Commerce\Support\CartGate::CLOSED_MESSAGE }}</p>
        @endcartOpen
```

Then change the Easy Purchase guard `@if(auth()->user()?->distributor)` that follows into two nested conditions so the share form is also hidden while closed:

```blade
        @cartOpen
        @if(auth()->user()?->distributor)
        … (existing share block, untouched)
        @endif
        @endcartOpen
```

(Keep the existing `@endif` of that block and add `@endcartOpen` after it.)

- [ ] **Step 6: Compile-check the blades and run the tests**

```bash
cd app && php artisan view:clear && php artisan view:cache
```
Expected: no exception (an unbalanced `@cartOpen` fails here). Then run the Task 1 Step 2 command. Expected: 6 passed.

Also run the neighbours that render these views to prove nothing regressed while the gate is open:
`… php artisan test --compact tests/Modules/Commerce/StorefrontCatalogTest.php tests/Modules/Commerce/CartQuantityTest.php tests/Modules/Commerce/CartThumbnailTest.php tests/Modules/Commerce/SharedCartTest.php`
Expected: all pass.

- [ ] **Step 7: Commit**

```bash
git add app/resources/views/shop/product.blade.php app/resources/views/partials/_product-card.blade.php app/resources/views/shop/cart.blade.php app/tests/Modules/Commerce/CartGateTest.php
git commit -m "feat(storefront): hide Add to Cart, checkout and share while the cart gate is closed

Compliance-Review: compliance-officer

Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>"
```

---

### Task 3: Refuse adds, quantity increases, sharing and shared-cart opens server-side

**Files:**
- Modify: `app/app/Modules/Commerce/Http/Controllers/Storefront/CartController.php` (constructor line 24; `add` line 92; `update` line 119; `share` line 150; `openShared` line 190)
- Test: `app/tests/Modules/Commerce/CartGateTest.php` (append)

**Interfaces:**
- Consumes: `CartGate::isOpenFor(?User)`, `CartGate::CLOSED_MESSAGE`.
- Produces: private `CartController::closedResponse(Request $request): RedirectResponse|JsonResponse|null` — `null` when open for this visitor.

- [ ] **Step 1: Append the failing controller tests**

```php
it('CGT-04: closed → a guest add is refused: redirect with an error, 403 JSON for the AJAX card', function (): void {
    cgtSetting(CartGate::SETTING_KEY, 'false');
    $variant = cgtVariant();

    $this->post(route('shop.cart.add'), ['product_variant_id' => $variant->id, 'qty' => 1])
        ->assertRedirect(route('shop.index'))
        ->assertSessionHasErrors(['cart' => CartGate::CLOSED_MESSAGE]);

    $this->postJson(route('shop.cart.add'), ['product_variant_id' => $variant->id, 'qty' => 1])
        ->assertStatus(403)
        ->assertJson(['ok' => false, 'message' => CartGate::CLOSED_MESSAGE]);

    expect(DB::table('cart_items')->count())->toBe(0);
});

it('CGT-05: closed → a staff add still lands in the cart', function (): void {
    cgtSetting(CartGate::SETTING_KEY, 'false');
    $variant = cgtVariant();

    $this->actingAs(cgtUser('admin'))
        ->post(route('shop.cart.add'), ['product_variant_id' => $variant->id, 'qty' => 1])
        ->assertRedirect(route('shop.cart'))
        ->assertSessionHas('added_variant_id', $variant->id);
});

it('CGT-06: closed → an Easy Purchase link does not load the shared cart; the sharer cannot create one', function (): void {
    $variant = cgtVariant();
    $sharer = cgtDistributorUser();

    // Share while open …
    cgtSetting(CartGate::SETTING_KEY, 'true');
    $this->actingAs($sharer)->post(route('shop.cart.add'), ['product_variant_id' => $variant->id, 'qty' => 1]);
    $this->actingAs($sharer)->post(route('shop.cart.share'))->assertSessionHas('shared_cart_url');
    $code = basename((string) parse_url((string) session('shared_cart_url'), PHP_URL_PATH));

    // … then close the gate.
    cgtSetting(CartGate::SETTING_KEY, 'false');
    app()->forgetInstance(CartGate::class);

    $this->get(route('shop.easy-cart', ['code' => $code]))
        ->assertRedirect(route('shop.index'))
        ->assertSessionHasErrors(['share' => CartGate::CLOSED_MESSAGE]);
    expect(DB::table('cart_items')->count())->toBe(1); // only the sharer's own line

    $this->actingAs($sharer)->post(route('shop.cart.share'))
        ->assertRedirect(route('shop.cart'))
        ->assertSessionHasErrors(['share' => CartGate::CLOSED_MESSAGE]);
});

it('CGT-12: closed → quantity cannot go up, but a line can still be reduced, removed and the cart cleared', function (): void {
    $variant = cgtVariant();
    $user = cgtUser();
    cgtSetting(CartGate::SETTING_KEY, 'true');
    $this->actingAs($user)->post(route('shop.cart.add'), ['product_variant_id' => $variant->id, 'qty' => 3]);
    $item = \App\Modules\Commerce\Models\CartItem::query()->firstOrFail();
    cgtSetting(CartGate::SETTING_KEY, 'false');
    app()->forgetInstance(CartGate::class);

    $this->actingAs($user)->patch(route('shop.cart.update', $item), ['qty' => 4])
        ->assertRedirect(route('shop.index'))
        ->assertSessionHasErrors(['cart' => CartGate::CLOSED_MESSAGE]);
    expect($item->fresh()->qty)->toBe(3);

    $this->actingAs($user)->patch(route('shop.cart.update', $item), ['qty' => 2])->assertRedirect(route('shop.cart'));
    expect($item->fresh()->qty)->toBe(2);

    $this->actingAs($user)->delete(route('shop.cart.remove', $item))->assertRedirect(route('shop.cart'));
    expect(DB::table('cart_items')->count())->toBe(0);
});
```

- [ ] **Step 2: Run the tests to verify they fail**

Run the Task 1 Step 2 command. Expected: CGT-04, CGT-06, CGT-12 FAIL (adds succeed); CGT-05 passes already (nothing is gated yet) — that is expected and it stays as the regression guard.

- [ ] **Step 3: Inject the gate and add the helper**

In `CartController.php`:
- import `App\Modules\Commerce\Support\CartGate;`
- add `private readonly CartGate $cartGate,` as the last constructor parameter;
- add after `resolveCustomer()`:

```php
    /**
     * Pre-launch cart gate: null when this visitor may change the cart, else
     * the refusal — JSON 403 for the listing-card AJAX add (its JS shows the
     * error toast on any non-2xx), otherwise a redirect to the shop with the
     * message under the `cart` error key.
     */
    private function closedResponse(Request $request, string $errorKey = 'cart'): RedirectResponse|JsonResponse|null
    {
        if ($this->cartGate->isOpenFor($request->user())) {
            return null;
        }

        if ($request->wantsJson()) {
            return response()->json(['ok' => false, 'message' => CartGate::CLOSED_MESSAGE], 403);
        }

        return redirect()->route('shop.index')->withErrors([$errorKey => CartGate::CLOSED_MESSAGE]);
    }
```

- [ ] **Step 4: Gate the four actions**

`add()` — first statement, before `$request->validate(...)`:
```php
        if ($closed = $this->closedResponse($request)) {
            return $closed;
        }
```

`update()` — it validates `qty` (`min:0`, 0 = remove) into `$validated` and calls `$this->cartService->updateQty(...)`. Gate only an **increase**, between the validation and the service call:
```php
        if ((int) $validated['qty'] > $item->qty && ($closed = $this->closedResponse($request))) {
            return $closed;
        }
```

`share()` — first statement. Its errors go to the cart page under the `share` key the page already renders, so do not use `closedResponse()` here:
```php
        if (! $this->cartGate->isOpenFor($request->user())) {
            return redirect()->route('shop.cart')->withErrors(['share' => CartGate::CLOSED_MESSAGE]);
        }
```

`openShared()` — first statement, before the `SharedCart::where(...)` lookup, so a closed gate neither sets the attribution cookie nor loads items:
```php
        if ($closed = $this->closedResponse($request, 'share')) {
            return $closed;
        }
```

- [ ] **Step 5: Run the tests to verify they pass**

Run the Task 1 Step 2 command. Expected: 10 passed. Then `… php artisan test --compact tests/Modules/Commerce/SharedCartTest.php tests/Modules/Commerce/CartQuantityTest.php` — all pass (gate open by default).

- [ ] **Step 6: Pint, Larastan, commit**

```bash
cd app && vendor/bin/pint --dirty --format agent && vendor/bin/phpstan analyse --memory-limit=1G app/Modules/Commerce/Http/Controllers/Storefront/CartController.php
git add app/app/Modules/Commerce/Http/Controllers/Storefront/CartController.php app/tests/Modules/Commerce/CartGateTest.php
git commit -m "feat(commerce): refuse cart adds, increases, shares and shared-cart opens while the cart gate is closed

Compliance-Review: compliance-officer

Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>"
```

---

### Task 4: Close checkout for gated visitors + admin help note

**Files:**
- Modify: `app/app/Modules/Commerce/Http/Controllers/Storefront/CheckoutController.php` (constructor; `ensureCheckoutEnabled()` at line 603)
- Modify: `app/resources/help/order-management.md` (new short section)
- Test: `app/tests/Modules/Commerce/CartGateTest.php` (append)

**Interfaces:**
- Consumes: `CartGate::isOpenFor(?User)`.

- [ ] **Step 1: Append the failing tests**

```php
it('CGT-10: closed → checkout is 404 for a distributor with a filled cart', function (): void {
    $variant = cgtVariant();
    $user = cgtUser();
    cgtSetting(CartGate::SETTING_KEY, 'true');
    $this->actingAs($user)->post(route('shop.cart.add'), ['product_variant_id' => $variant->id, 'qty' => 1]);
    cgtSetting(CartGate::SETTING_KEY, 'false');
    app()->forgetInstance(CartGate::class);

    $this->actingAs($user)->get(route('shop.checkout'))->assertNotFound();
});

it('CGT-11: closed → staff reach checkout (empty cart sends them to the shop, proving the gate was passed)', function (): void {
    cgtSetting(CartGate::SETTING_KEY, 'false');

    $this->actingAs(cgtUser('admin-finance'))
        ->get(route('shop.checkout'))
        ->assertRedirect(route('shop.index'));
});
```

- [ ] **Step 2: Run the tests to verify they fail**

Run the Task 1 Step 2 command. Expected: CGT-10 FAIL (checkout renders); CGT-11 passes already.

- [ ] **Step 3: Gate checkout**

In `CheckoutController.php`: import `App\Modules\Commerce\Support\CartGate;`, add `private readonly CartGate $cartGate,` as the last promoted constructor parameter (the list starts at line 50 with `CartService $cartService`), and change `ensureCheckoutEnabled()` to:

```php
    private function ensureCheckoutEnabled(): void
    {
        $enabled = DB::table('settings')->where('key', 'commerce.checkout.enabled')->value('value');
        if ($enabled !== 'true') {
            throw new NotFoundHttpException;
        }

        // Pre-launch cart gate: same 404 as checkout-off, for non-staff only.
        if (! $this->cartGate->isOpenFor(Auth::user())) {
            throw new NotFoundHttpException;
        }
    }
```

Both entry points already call it: `show()` (line 68) and `place()` (line 170). `confirmation()` (line 549) does not and must not — it renders an already-placed order.

- [ ] **Step 4: Help note**

Append to `app/resources/help/order-management.md` (match the file's existing heading level for sections):

```markdown
## Pre-launch cart gate

**Settings → Commerce → "Cart and ordering open".** OFF hides every Add to Cart button and refuses adds, Easy Purchase links and checkout for distributors and visitors; the catalogue stays browsable and shows "Available at launch". Carts built earlier can still be viewed and emptied, not checked out. Back-office roles are never gated, so test orders can continue. Turn ON at launch. This is independent of "Public storefront" (takes the whole shop down) and "Storefront checkout" (pauses checkout for everyone, staff included).
```

- [ ] **Step 5: Run the tests to verify they pass**

Run the Task 1 Step 2 command. Expected: 12 passed. Then the checkout neighbours:
`… php artisan test --compact tests/Modules/Commerce/MembersOnlyCheckoutTest.php tests/Modules/Commerce/CheckoutDistributorDetailsTest.php tests/Modules/Commerce/SharedCartTest.php tests/Modules/Payments/RazorpayProductionTestModeTest.php tests/Feature/Compliance/SeparationOfDutiesTest.php`
Expected: all pass.

- [ ] **Step 6: Pint, Larastan, commit**

```bash
cd app && vendor/bin/pint --dirty --format agent && vendor/bin/phpstan analyse --memory-limit=1G app/Modules/Commerce/Http/Controllers/Storefront/CheckoutController.php
git add app/app/Modules/Commerce/Http/Controllers/Storefront/CheckoutController.php app/resources/help/order-management.md app/tests/Modules/Commerce/CartGateTest.php
git commit -m "feat(commerce): checkout 404s for gated visitors while the cart gate is closed; help note

Compliance-Review: compliance-officer

Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>"
```

---

### Task 5: Whole-suite run and handoff

- [ ] **Step 1: Full suite** — `docker exec -e DB_CONNECTION=mysql -e DB_DATABASE=arovolife_test -e DB_HOST=db -e DB_PORT=3306 -e DB_USERNAME=arovolife -e DB_PASSWORD=secret arovolife-app php artisan test --compact`. Expected: green. Any test that enumerates the settings registry keys (grep `tests/` for `commerce.checkout.enabled` inside an expected-keys list) gets `commerce.cart.enabled` added.
- [ ] **Step 2: Local smoke** on http://localhost:8084: set the setting OFF at `/admin/settings` as admin; open `/shop` in a private window → cards have no cart button, product page shows "Available at launch"; as admin the buttons are back. Set it ON again.
- [ ] **Step 3: Report** to the user: the four commits, the test count, and that staging/production need `view:cache` after deploy and the setting flipped OFF by an admin before launch (no deploy without approval — memory rule).
