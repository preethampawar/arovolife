# Distributor UI Changes Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Ship the 30 approved distributor-facing UI changes (labels, navigation, colours, My Business, My Income, repurchase date/time and popup) without changing any compensation engine result.

**Architecture:** Mostly Blade and Tailwind edits in existing views. New PHP is limited to:
- one shared palette class
- one carry-over display helper
- one team-stats method
- one income-filter defaults helper
- one read-only repurchase notice service
- one HTML brand-wordmark transformer, applied in a response middleware and a mail listener

Engine code is only read, never changed. The one exception is an additive `startedAt` field on the repurchase card DTO.

**Tech Stack:** Laravel 13, PHP 8.4 (`Dom\HTMLDocument`), Blade, Tailwind v4 (CSS-first `@theme` in `resources/css/app.css`), Pest on MySQL `arovolife_test`, Lucide icons via `blade-lucide-icons`.

**Spec:** `docs/plans/distributor-ui-changes-2026-09-28.md`. Read it first. Every decision table there binds this plan.

## Global Constraints

- Paths are relative to `app/` unless they start with `docs/`.
- **Never run a bare `php artisan test`.** Every test run uses the isolated DB:
  ```
  docker exec -e DB_CONNECTION=mysql -e DB_DATABASE=arovolife_test -e DB_HOST=db -e DB_PORT=3306 -e DB_USERNAME=arovolife -e DB_PASSWORD=secret arovolife-app php artisan test --compact <test path>
  ```
  Below this is written as `RUN_TEST <path>`.
- After any Tailwind class change, run `cd app && npm run build`. `view:clear` is not enough.
- **Deploy:** commits stay local. **No push to staging or production without the user's explicit approval for that deploy.**
- `declare(strict_types=1);` in every new PHP file. Services are `final` with constructor promotion.
- Icons: Lucide only (`<x-lucide-*>` / `svg('lucide-…')`).
- Display numbers: `App\Modules\Shared\Support\IndianNumber::format()` or `@bv`, never `Number::format`.
- Theme: light/dark comes from the `html.dark` remap in `resources/css/app.css`. Never add `dark:` variants.
- Brand name is always lowercase: "arovolife".
- User-facing side names: "Left Genos" / "Right Genos". Internal code keeps `binary`, `L`/`R`, `left`/`right`.
- Left = sky (blue). Right = emerald (green). Every side colour comes from `GenosSideColors` (Task 8).
- Copy: no income projections, no rupee figures in repurchase eligibility messages (hard rule 3).
- **Commits:**
  - Conventional Commits.
  - Every commit ends with `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`.
  - Tasks 14–16 also carry `Compliance-Review: compliance-officer`.
- **Help docs:** any label or behaviour change updates the matching `resources/help/*.md` in the same commit.
- **Blade lint:** after a view change, run `docker exec arovolife-app php artisan view:cache` and then `php -l` on the compiled views of touched templates.

## Review Focus

1. **"arovolife" inside an attribute, URL, email address or `<script>`** must stay untouched by the wordmark transformer, or links, meta tags and JSON break. Tested in Task 7.
2. **An explicitly cleared income filter** (`?f=1` with blank dates) must show all rows, not snap back to yesterday. Tested in Task 12.
3. **Midnight IST boundary** for "orders today": an order paid at 23:59 IST yesterday is not today's, even though it's the same UTC date. Tested in Task 11.
4. **Repurchase popup on a second visit, a COD order, or a guest/customer buyer** must not appear. Tested in Task 16.
5. **A distributor with no child on one side** (empty Left or Right Genos) must render 0 members and 0 orders, not an error. Tested in Task 11.

---

### Task 0: Shared test fixture helper

**Files:**
- Modify: `tests/Pest.php` (append at the end)

**Interfaces:**
- Produces:
  - `uiDistributor(array $attrs = []): array{user: \App\Modules\Identity\Models\User, id: int}`
  - `uiPlaceUnder(int $parentId, string $side, int $childId): void`
  - `uiPaidSelfOrder(int $distributorId, int $bvPaise, \Illuminate\Support\Carbon $paidAt, string $status = 'paid'): int`
  - `uiRepurchaseWallet(int $distributorId, int $paise): void`
  - `uiCustomerFor(\App\Modules\Identity\Models\User $user, int $distributorId): int`

- [ ] **Step 1: Append the helpers**

```php
/*
| Distributor UI changes (2026-09-28) — shared fixtures. Prefixed `ui` so they
| never collide with per-file helpers.
*/
function uiDistributor(array $attrs = []): array
{
    $user = \App\Modules\Identity\Models\User::create([
        'full_name' => $attrs['full_name'] ?? 'Ui Tester',
        'email' => 'ui-'.uniqid().'@example.com',
        'phone_e164' => '+91944'.str_pad((string) random_int(0, 9999999), 7, '0', STR_PAD_LEFT),
        'password_hash' => \Illuminate\Support\Facades\Hash::make('ui-test-pwd-2026'),
        'password_set_at' => now(),
        'status' => 'active',
        'email_verified_at' => now(),
    ]);

    disableTestForeignKeys();
    try {
        $now = now()->format('Y-m-d H:i:s.v');
        $id = \Illuminate\Support\Facades\DB::table('distributors')->insertGetId([
            'user_id' => $user->id,
            'adn' => (string) random_int(100000000, 999999999),
            'pan_hash' => random_bytes(32),
            'pan_last4' => '0000',
            'bank_account_enc' => 'stub',
            'bank_ifsc' => 'SBIN0000000',
            'sponsor_id' => 0,
            'placement_parent_id' => 0,
            'placement_side' => null,
            'side_chosen_by' => 'referral_default',
            'depth' => 0,
            'effective_date' => $now,
            'cooling_off_end_at' => now()->addDays(30)->format('Y-m-d H:i:s.v'),
            'state' => 'TS',
            'is_primary_couple' => 0,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        \Illuminate\Support\Facades\DB::table('distributors')->where('id', $id)
            ->update(['sponsor_id' => $id, 'placement_parent_id' => $id]);
    } finally {
        enableTestForeignKeys();
    }
    \Illuminate\Support\Facades\DB::table('genealogy_closure')
        ->insert(['ancestor_id' => $id, 'descendant_id' => $id, 'depth' => 0]);

    return ['user' => $user, 'id' => $id];
}

function uiPlaceUnder(int $parentId, string $side, int $childId): void
{
    \Illuminate\Support\Facades\DB::table('distributors')->where('id', $childId)
        ->update(['placement_parent_id' => $parentId, 'placement_side' => $side, 'sponsor_id' => $parentId]);

    $rows = \Illuminate\Support\Facades\DB::table('genealogy_closure')
        ->where('descendant_id', $parentId)->get(['ancestor_id', 'depth']);
    foreach ($rows as $row) {
        \Illuminate\Support\Facades\DB::table('genealogy_closure')->insert([
            'ancestor_id' => $row->ancestor_id, 'descendant_id' => $childId, 'depth' => $row->depth + 1,
        ]);
    }
}

function uiPaidSelfOrder(int $distributorId, int $bvPaise, \Illuminate\Support\Carbon $paidAt, string $status = 'paid'): int
{
    disableTestForeignKeys();
    try {
        $orderId = \Illuminate\Support\Facades\DB::table('orders')->insertGetId([
            'order_no' => 'UI'.random_int(10000000, 99999999),
            'customer_id' => 0,
            'attributed_distributor_id' => $distributorId,
            'attribution_source' => 'self',
            'payment_method' => 'online',
            'status' => $status,
            'self_consumption' => true,
            'subtotal_paise' => 100000,
            'total_paise' => 100000,
            'placed_at' => $paidAt,
            'paid_at' => $paidAt,
            'created_at' => $paidAt,
            'updated_at' => $paidAt,
        ]);
    } finally {
        enableTestForeignKeys();
    }
    \Illuminate\Support\Facades\DB::table('bv_ledger_entries')->insert([
        'distributor_id' => $distributorId,
        'order_id' => $orderId,
        'type' => 'accrual',
        'bv_paise' => $bvPaise,
        'effective_at' => $paidAt,
        'created_at' => $paidAt,
        'updated_at' => $paidAt,
    ]);

    return $orderId;
}

/** Put $paise into a distributor's repurchase wallet (balance = deductions − usages). */
function uiRepurchaseWallet(int $distributorId, int $paise): void
{
    \App\Modules\Compensation\Models\WalletLedgerEntry::create([
        'distributor_id' => $distributorId,
        'type' => 'repurchase_deduction',
        'amount_paise' => $paise,
        'reference_id' => walletRef(),
        'reference_type' => 'test',
        'memo' => 'UI test repurchase credit',
    ]);
}

/** A Customer row owned by $user, so CheckoutController::ownsOrder() is true for orders pointing at it. */
function uiCustomerFor(\App\Modules\Identity\Models\User $user, int $distributorId): int
{
    return \App\Modules\Commerce\Models\Customer::create([
        'user_id' => $user->id,
        'distributor_id' => $distributorId,
        'display_name' => $user->full_name,
    ])->id;
}
```

Check the `WalletLedgerEntry` namespace with `grep -rn "class WalletLedgerEntry" app/Modules`. Check the `Customer` required columns: `email_hash`/`phone_hash` may be NOT NULL, in which case pass `random_bytes(32)`.

- [ ] **Step 2: Check the column lists against the real schema.** Use the `mcp__laravel-boost__database-schema` tool for `orders` and `bv_ledger_entries`, or `SHOW COLUMNS` on `arovolife_test`.
  - Add any NOT NULL column without a default, using a neutral value.
  - Drop any column that doesn't exist.
  - Do not guess: the insert must succeed.

- [ ] **Step 3: Smoke-test.** Create `tests/Feature/UiFixtureSmokeTest.php`:

```php
<?php
declare(strict_types=1);
use Illuminate\Foundation\Testing\RefreshDatabase;
uses(RefreshDatabase::class);

it('builds a placed pair with a paid self order', function () {
    $root = uiDistributor();
    $child = uiDistributor();
    uiPlaceUnder($root['id'], 'L', $child['id']);
    $orderId = uiPaidSelfOrder($child['id'], 60000, now());
    expect(\DB::table('genealogy_closure')->where('ancestor_id', $root['id'])->where('descendant_id', $child['id'])->value('depth'))->toBe(1)
        ->and($orderId)->toBeGreaterThan(0);
});
```

Run: `RUN_TEST tests/Feature/UiFixtureSmokeTest.php`. Expected: PASS.

- [ ] **Step 4: Commit**

```bash
git add tests/Pest.php tests/Feature/UiFixtureSmokeTest.php
git commit -m "test: shared distributor/placement/order fixtures for UI changes"
```

---

### Task 1: Sign-in page, step 9 title, checkout Total BV (items 28, 29, 4, 6)

**Files:**
- Modify: `resources/views/auth/login.blade.php` (l.60 link text; remove the `#coupleRoleRow` block, ~l.74–90, and its reveal `<script>`; remove the Remember-me label ~l.93–96)
- Modify: `resources/views/registration/step3-personal.blade.php:2,7`
- Modify: `resources/views/shop/checkout.blade.php` (move the `@php $bvTotal …` block and its `@if … @endif` from ~l.459 to directly after the `<h2>Order Summary</h2>` at ~l.408)
- Test: `tests/Modules/Identity/SignInCopyTest.php`, `tests/Modules/Commerce/CheckoutBvPositionTest.php`

- [ ] **Step 1: Write the failing tests**

```php
<?php
// tests/Modules/Identity/SignInCopyTest.php
declare(strict_types=1);
use Illuminate\Foundation\Testing\RefreshDatabase;
uses(RefreshDatabase::class);

it('shows Find my ADN and no primary-holder or remember-me controls', function () {
    $html = $this->get(route('login'))->assertOk()->getContent();
    expect($html)->toContain('Find my ADN')
        ->not->toContain('Forgot your ADN')
        ->not->toContain('Primary account holder')
        ->not->toContain('name="remember"')
        ->not->toContain('coupleRoleRow');
});

it('a couple ADN without the primary field signs in the primary holder', function () {
    $primary = uiDistributor();
    $spouse = uiDistributor();
    $adn = \DB::table('distributors')->where('id', $primary['id'])->value('adn');
    \DB::table('distributors')->where('id', $primary['id'])->update(['is_primary_couple' => 1]);
    \DB::table('distributors')->where('id', $spouse['id'])->update(['adn' => $adn, 'is_primary_couple' => 0]);

    $this->post(route('login'), ['identifier' => $adn, 'password' => 'ui-test-pwd-2026']);

    expect(auth()->id())->toBe($primary['user']->id);
});

it('titles registration step 9 My Personal Details', function () {
    $blade = file_get_contents(resource_path('views/registration/step3-personal.blade.php'));
    expect($blade)->toContain("'Step 9 — My Personal Details'")->toContain('>My Personal Details</h2>');
});
```

Before running, check the login field name in `LoginController`: it may be `identifier`, `login` or `email`. Use the real one.

```php
<?php
// tests/Modules/Commerce/CheckoutBvPositionTest.php
declare(strict_types=1);

it('renders Total BV directly under the Order Summary heading', function () {
    $blade = file_get_contents(resource_path('views/shop/checkout.blade.php'));
    $heading = strpos($blade, 'Order Summary</h2>');
    $bv = strpos($blade, '$bvTotal = auth()->user()->distributor');
    $firstLine = strpos($blade, '@foreach($cart->', $heading);
    expect($heading)->not->toBeFalse()
        ->and($bv)->toBeGreaterThan($heading)
        ->and($bv)->toBeLessThan($firstLine);
});
```

`$firstLine` assumes the item loop starts with `@foreach($cart->`. Read the file and use the real loop opener after the heading.

- [ ] **Step 2: Run both files and confirm they fail.** Run: `RUN_TEST tests/Modules/Identity/SignInCopyTest.php` and `RUN_TEST tests/Modules/Commerce/CheckoutBvPositionTest.php`. Expected: FAIL on the copy assertions. The couple test may already pass; that's fine, it pins behaviour.

- [ ] **Step 3: Implement**
  - login: change link text to `Find my ADN`. Delete the whole `{{-- Couple (joint) ADN disambiguation … --}}` comment and `<div id="coupleRoleRow">…</div>`. Delete the `<script>` block that toggles `coupleRoleRow`. Search `coupleRoleRow` and remove every reference. Delete the `<label>` wrapping `name="remember"`. If the wrapping flex row is left holding only the "Find my ADN" link, change `justify-between` to `justify-end`.
  - step 9: `@section('title', 'Step 9 — My Personal Details')` and `<h2 class="text-2xl font-bold mb-2">My Personal Details</h2>`.
  - checkout: cut the Total BV `@php … @endphp @if($bvTotal > 0) … @endif` block, including its comment, and paste it immediately after the `Order Summary` `<h2>`. Keep the markup unchanged and add `mb-4` to its wrapper so it separates from the items.

- [ ] **Step 4: Run the tests.** Expected: PASS. Then run `RUN_TEST tests/Modules/Identity` to catch login regressions. Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add resources/views/auth/login.blade.php resources/views/registration/step3-personal.blade.php resources/views/shop/checkout.blade.php tests/Modules/Identity/SignInCopyTest.php tests/Modules/Commerce/CheckoutBvPositionTest.php
git commit -m "feat(ui): sign-in copy, My Personal Details title, Total BV under Order Summary"
```

---

### Task 2: Step 10 upload progress message (item 5)

**Files:**
- Modify: `resources/views/registration/step7-documents.blade.php` (the upload `<form>` and its submit button)
- Test: `tests/Modules/Identity/DocumentsUploadProgressTest.php`

- [ ] **Step 1: Failing test**

```php
<?php
declare(strict_types=1);

it('step 10 carries an upload-progress status region and a submit guard', function () {
    $blade = file_get_contents(resource_path('views/registration/step7-documents.blade.php'));
    expect($blade)->toContain('data-upload-progress')
        ->toContain('role="status"')
        ->toContain('Uploading your documents. Please keep this page open.')
        ->toContain('data-upload-form');
});
```

- [ ] **Step 2: Run it.** Expected: FAIL.

- [ ] **Step 3: Implement.**
  - Add `data-upload-form` to the upload `<form>` and `data-upload-submit` to its submit button.
  - Directly above the button, add:

```blade
<div data-upload-progress hidden role="status" aria-live="polite"
     class="flex items-center gap-2 rounded-lg border border-brand-200 bg-brand-50 px-3 py-2 text-sm text-brand-800">
    <x-lucide-loader-circle class="w-4 h-4 animate-spin" />
    Uploading your documents. Please keep this page open.
</div>
```

  - At the end of the view's content section, add:

```blade
<script>
(function () {
    const form = document.querySelector('[data-upload-form]');
    if (! form) return;
    form.addEventListener('submit', function () {
        if (! form.checkValidity()) return;
        const note = form.querySelector('[data-upload-progress]');
        const btn = form.querySelector('[data-upload-submit]');
        if (note) note.hidden = false;
        if (btn) { btn.disabled = true; btn.classList.add('opacity-60', 'cursor-wait'); }
    });
    // Back/forward cache restores a disabled button; undo it.
    window.addEventListener('pageshow', function () {
        const note = form.querySelector('[data-upload-progress]');
        const btn = form.querySelector('[data-upload-submit]');
        if (note) note.hidden = true;
        if (btn) { btn.disabled = false; btn.classList.remove('opacity-60', 'cursor-wait'); }
    });
})();
</script>
```

  - If the view already uses `@push('scripts')`, push the script there instead.

- [ ] **Step 4: Run the test.** Expected: PASS. Run `npm run build`.

- [ ] **Step 5: Commit**

```bash
git add resources/views/registration/step7-documents.blade.php tests/Modules/Identity/DocumentsUploadProgressTest.php
git commit -m "feat(registration): show upload-in-progress message on the documents step"
```

---

### Task 3: "My Personal World" / "My Business World" (items 8, 9)

**Files** (distributor-visible strings only; leave code comments alone):
- `resources/views/partials/public-topnav.blade.php` (~l.83, 89, 183, 186, 454, 457)
- `resources/views/partials/distributor-sidenav.blade.php` (Overview group labels)
- `resources/views/dashboard/index.blade.php` (title/heading if it says Dashboard/My Dashboard)
- `resources/views/my-business.blade.php:2,93` (title and h1)
- `resources/views/income/_tabs.blade.php`, `resources/views/dashboard/_quick-actions.blade.php`, `resources/views/dashboard/_kpi-strip.blade.php`, `resources/views/dashboard/_genos-balance.blade.php`
- `app/Modules/Compensation/Support/IncomeNavLinks.php`, `app/Modules/Identity/Http/Controllers/DashboardController.php`, `app/Modules/Identity/Services/DistributorIdCardStats.php`, `app/Modules/Compensation/Http/Controllers/MyBusinessController.php`, `app/Modules/Compensation/Services/GsbCutoffService.php`: change only user-visible string literals. Open each and decide per hit.
- `resources/help/compensation.md`
- Test: `tests/Modules/Identity/WorldLabelsTest.php`

- [ ] **Step 1: Failing test**

```php
<?php
declare(strict_types=1);
use Illuminate\Foundation\Testing\RefreshDatabase;
uses(RefreshDatabase::class);

it('distributor chrome says My Personal World and My Business World', function () {
    $d = uiDistributor();
    $html = $this->actingAs($d['user'])->get(route('my-business'))->assertOk()->getContent();
    expect($html)->toContain('My Personal World')->toContain('My Business World')
        ->not->toMatch('/>\s*My Dashboard\s*</')
        ->not->toMatch('/>\s*My Business\s*</');
});
```

- [ ] **Step 2: Run it.** `RUN_TEST tests/Modules/Identity/WorldLabelsTest.php`. Expected: FAIL.

- [ ] **Step 3: Implement.**
  - Replace the visible label "My Dashboard" with "My Personal World". Rename the sidebar item `'Dashboard'` to `'My Personal World'`.
  - Replace the visible label "My Business" with "My Business World". This covers the sidebar, quick-action tile label, KPI strip, tabs, page `<title>` and `<h1>`, and `IncomeNavLinks` labels.
  - Admin-only text ("Admin Console") is unchanged.
  - Run `grep -rn "My Dashboard\|My Business\b" resources app/Modules` afterwards. Every remaining hit must be a comment, a route name, or a non-distributor surface.

- [ ] **Step 4: Run the test,** then `RUN_TEST tests/Modules/Identity/DashboardRenderTest.php`. Expected: PASS for both. If older tests assert the old label, update those assertions.

- [ ] **Step 5: Commit** with message `feat(ui): rename My Dashboard/My Business to My Personal World/My Business World`.

---

### Task 4: "Left group" → "Left Genos" (item 15)

**Files:**
- Views: `tree/_binary-node`, `tree/binary`, `registration/step10-complete`, `emails/new-placement-under-you`, `income/genos-bv`, `income/genos-ledger`, `admin/compensation/distributors/_tab-genos-ledger`, `admin/compensation/genos-transactions/index`, `dashboard/_genos-balance`, `dashboard/_my-team`, `membership/direct-seller-application`, `partials/_distributor-sidenav-groups`. The chip text changes from `{{ $navIsLeft ? '← Left' : '→ Right' }} group` to `… Genos`.
- Help: `resources/help/glossary.md`, plus any `resources/help/*.md` hit.
- PHP label strings only: `app/Modules/Compensation/Services/IncomeOverviewService.php`, `GenosBvLedgerService.php`, `DTOs/GenosLedgerDay.php`, `DTOs/GsbSlabRow.php`, `DTOs/GsbSlabProgress.php`. Skip `ScaleSeedCommand.php`.
- Memory: `/Users/preetham/.claude/projects/-Users-preetham-Documents-arovolife-arovolife-arovolife-code/memory/terminology_genos_bv_group.md`
- Test: `tests/Feature/GenosSideTerminologyTest.php`

- [ ] **Step 1: Failing test.** It scans the user-facing sources for the old term.

```php
<?php
declare(strict_types=1);

it('no user-facing view, help page or label says Left/Right group', function () {
    $files = array_merge(
        glob(resource_path('views/**/*.blade.php')) ?: [],
        iterator_to_array(new RegexIterator(new RecursiveIteratorIterator(new RecursiveDirectoryIterator(resource_path('views'))), '/\.blade\.php$/'), false),
        glob(resource_path('help/*.md')) ?: [],
        [app_path('Modules/Compensation/Services/IncomeOverviewService.php'),
         app_path('Modules/Compensation/Services/GenosBvLedgerService.php'),
         app_path('Modules/Compensation/Services/DTOs/GenosLedgerDay.php'),
         app_path('Modules/Compensation/Services/DTOs/GsbSlabRow.php'),
         app_path('Modules/Compensation/Services/DTOs/GsbSlabProgress.php')],
    );
    $offenders = [];
    foreach (array_unique(array_map('strval', $files)) as $f) {
        // Strip Blade and PHP comments so developer notes don't count.
        $src = preg_replace(['/\{\{--.*?--\}\}/s', '#/\*.*?\*/#s', '#^\s*//.*$#m'], '', (string) file_get_contents($f));
        if (preg_match('/\b(Left|Right) group\b/i', $src)) {
            $offenders[] = str_replace(base_path().'/', '', $f);
        }
    }
    expect($offenders)->toBe([]);
});
```

- [ ] **Step 2: Run it.** Expected: FAIL, listing the files above.

- [ ] **Step 3: Implement.** Change "Left group" to "Left Genos" and "Right group" to "Right Genos", matching capitalisation (for example "left group" becomes "left Genos"). Genos is always capitalised. Change user-visible strings only. Then update the terminology memory body: user-facing leg name is "Genos" ("Left Genos" / "Right Genos") since 2026-09-28, replacing "group".

- [ ] **Step 4: Run the test,** then `RUN_TEST tests/Modules/Compensation`. Expected: PASS. Fix any older assertion that expected "Left group".

- [ ] **Step 5: Commit** with message `feat(ui): Left/Right group becomes Left/Right Genos in all user-facing copy`.

---

### Task 5: Header colour, sign-out pill, dropdown removal, My Profile group (items 23, 7, 10)

**Files:**
- Modify: `resources/views/partials/public-topnav.blade.php`
- Modify: `resources/views/partials/distributor-sidenav.blade.php` (groups array and header comment)
- Modify: `resources/views/partials/_distributor-sidenav-groups.blade.php` (active matching supports `exclude`)
- Test: `tests/Modules/Identity/DistributorNavChromeTest.php`

**Interfaces:**
- Produces: sidebar item array key `exclude` (string route prefix), reused by any later item.

- [ ] **Step 1: Failing test**

```php
<?php
declare(strict_types=1);
use Illuminate\Foundation\Testing\RefreshDatabase;
uses(RefreshDatabase::class);

it('distributor top bar has a red sign-out pill and no profile dropdown', function () {
    $d = uiDistributor();
    $html = $this->actingAs($d['user'])->get(route('my-business'))->assertOk()->getContent();
    expect($html)->not->toContain('data-profile-menu')
        ->toContain('data-signout-pill')
        ->toContain('bg-brand-600');
    // The pill sits after the country marker.
    expect(strpos($html, 'data-signout-pill'))->toBeGreaterThan(strpos($html, 'India'));
});

it('sidebar has a My Profile group with Edit Profile, Change Password, My Addresses', function () {
    $d = uiDistributor();
    $html = $this->actingAs($d['user'])->get(route('profile.password.show'))->assertOk()->getContent();
    expect($html)->toContain('>My Profile<')
        ->toContain('Edit Profile')->toContain('Change Password')->toContain('My Addresses');
    // On the password page only Change Password is current.
    preg_match_all('/aria-current="page"[^>]*>.*?<span class="flex-1 truncate">([^<]+)</s', $html, $m);
    expect(array_values(array_unique($m[1])))->toBe(['Change Password']);
});

it('admins keep the profile dropdown and get no sign-out pill', function () {
    \Spatie\Permission\Models\Role::findOrCreate('admin', 'web');
    $admin = \App\Modules\Identity\Models\User::create([
        'email' => 'ui-admin-'.uniqid().'@test.com',
        'phone_e164' => '+91'.str_pad((string) random_int(7000000000, 9999999999), 10, '0'),
        'password_hash' => bcrypt('x'),
        'status' => 'active',
    ]);
    $admin->assignRole('admin');

    $html = $this->actingAs($admin)->get(route('about'))->assertOk()->getContent();
    expect($html)->toContain('data-profile-menu')->not->toContain('data-signout-pill');
});
```

This is the same admin fixture as `tests/Modules/Admin/AdminLineChangeControllerTest.php`. `about` is a public page that renders the top nav for any signed-in user.

- [ ] **Step 2: Run it.** Expected: FAIL.

- [ ] **Step 3: Implement the header colour.** In `public-topnav.blade.php`:
  - The utility strip `bg-brand-700` becomes `bg-brand-600`, and `<nav class="bg-brand-700 border-b border-brand-600">` becomes `bg-brand-600 border-b border-brand-500`.
  - In the strip and nav, `hover:bg-brand-800` becomes `hover:bg-brand-700`, and separators `text-brand-400` become `text-brand-200`.
  - Apply the same to the mobile menu container if it uses `bg-brand-700` or `bg-brand-800`.

- [ ] **Step 4: Implement the dropdown split.** Wrap the existing `<div class="relative" data-profile-menu>…</div>` in `@if($isAdmin || ! $user->distributor) … @else … @endif`. The `@else` branch is a plain label:

```blade
<span class="inline-flex items-center gap-2 px-2 py-0.5">
    <span class="inline-flex items-center justify-center w-6 h-6 rounded-full bg-white text-brand-700 text-[10px] font-bold leading-none">{{ $initials }}</span>
    <span class="font-medium">{{ $name }}</span>
</span>
```

  Check that the profile-dropdown JS (~l.347) returns early when `[data-profile-trigger]` is absent. Add `if (! trigger) return;` if it doesn't.

- [ ] **Step 5: Implement the sign-out pill.** After `<span>India 🇮🇳</span>`, add:

```blade
@auth
    @if(auth()->user()->distributor && ! auth()->user()->isSuperStaff())
        <span class="text-brand-200">|</span>
        <form method="POST" action="{{ route('logout') }}" class="inline">
            @csrf
            <button type="submit" data-signout-pill
                    class="inline-flex items-center gap-1.5 rounded-full bg-white px-2.5 py-0.5 text-xs font-semibold text-red-600 shadow-sm hover:bg-red-50 transition-colors">
                <x-lucide-log-out class="w-3.5 h-3.5" />
                Sign out
            </button>
        </form>
    @endif
@endauth
```

  In the mobile menu (~l.475–477), make the Sign out button red: `text-red-100 hover:text-white hover:bg-red-600`, and prepend `<x-lucide-log-out class="inline w-4 h-4 mr-1.5" />`.

- [ ] **Step 6: Implement the My Profile group.** In `distributor-sidenav.blade.php`:
  - Remove `My Addresses` from `'Shopping'` and `My Profile` from `'My Account'`.
  - Insert a new group between `'Shopping'` and `'My Account'`:

```php
'My Profile' => [
    ['label' => 'Edit Profile',    'route' => 'profile.show',          'icon' => 'user-pen', 'prefix' => 'profile.', 'exclude' => 'profile.password.'],
    ['label' => 'Change Password', 'route' => 'profile.password.show', 'icon' => 'lock',     'prefix' => 'profile.password.'],
    ['label' => 'My Addresses',    'route' => 'addresses.index',       'icon' => 'map-pin',  'prefix' => 'addresses.'],
],
```

  - In `_distributor-sidenav-groups.blade.php`, change `$active` to:

```php
$active = (request()->routeIs($item['route'])
        || (isset($item['prefix']) && request()->routeIs($item['prefix'].'*')))
    && ! (isset($item['exclude']) && request()->routeIs($item['exclude'].'*'));
```

  - Replace the header comment's "Phones keep the top-nav profile menu" sentence with: "Phones use the drawer below. The top-bar profile dropdown is admin/customer-only."

- [ ] **Step 7: Run the tests.** Run `npm run build`, then `RUN_TEST tests/Modules/Identity/DistributorNavChromeTest.php`. Expected: PASS.

- [ ] **Step 8: Commit** with message `feat(ui): logo-blue header, red sign-out pill, profile links move to sidebar`.

---

### Task 6: Sidebar scroll area and hover colour (items 26, 27)

**Files:**
- Modify: `resources/views/partials/distributor-sidenav.blade.php` (`#distributorSidenavSticky` classes, drawer classes; delete the sticky-offset `<script>` IIFE that starts `const el = document.getElementById('distributorSidenavSticky')`)
- Modify: `resources/views/partials/_distributor-sidenav-groups.blade.php` (hover classes)
- Modify: `resources/css/app.css` (append the `.nav-scroll` utility)
- Test: `tests/Modules/Identity/DistributorNavChromeTest.php` (add a case)

- [ ] **Step 1: Failing test.** Append to the Task 5 file:

```php
it('sidebar column scrolls on its own and items hover light blue', function () {
    $d = uiDistributor();
    $html = $this->actingAs($d['user'])->get(route('my-business'))->getContent();
    expect($html)->toContain('lg:max-h-[calc(100vh-8rem)]')->toContain('nav-scroll')
        ->toContain('hover:bg-brand-100')
        ->not->toContain('hover:bg-white/80');
});
```

- [ ] **Step 2: Run it.** Expected: FAIL.

- [ ] **Step 3: Implement.**
  - `#distributorSidenavSticky` class string becomes: `lg:sticky lg:top-28 lg:max-h-[calc(100vh-8rem)] lg:overflow-y-auto nav-scroll space-y-5 rounded-2xl border …`, keeping the rest. Delete the offset-fitting script and the comment above `<aside class="hidden lg:block">` that describes "no inner scrollbar". Replace that comment with "Desktop: sticky column with its own thin scrollbar when the list is taller than the viewport."
  - Add `nav-scroll` to `#distributorNavDrawer`.
  - In groups, the non-active class `'text-gray-700 hover:bg-white/80 hover:text-gray-900 font-medium'` becomes `'text-gray-700 hover:bg-brand-100 hover:text-brand-800 font-medium'`.
  - In `app.css` (outside `@theme`), add:

```css
.nav-scroll { scrollbar-width: thin; scrollbar-color: var(--color-brand-300) transparent; }
.nav-scroll::-webkit-scrollbar { width: 6px; }
.nav-scroll::-webkit-scrollbar-thumb { background: var(--color-brand-300); border-radius: 9999px; }
.nav-scroll::-webkit-scrollbar-track { background: transparent; }
```

- [ ] **Step 4: Run the test.** Run `npm run build` and the test. Expected: PASS.

- [ ] **Step 5: Commit** with message `feat(ui): distributor sidebar scrolls independently with a visible hover state`.

---

### Task 7: Brand wordmark font on every "arovolife" (item 30)

**Implementation note (differs from spec §4.5, same outcome).** The spec proposed a Blade component plus a CommonMark extension. This plan instead transforms the final HTML once, in two places:
- a web middleware for pages
- a `MessageSending` listener for HTML mail

It uses PHP 8.4's HTML5 parser (`Dom\HTMLDocument`). That covers Blade, Markdown help, DB content pages and emails with one tested function, and it never touches attributes, because it only visits text nodes. Tell the user about this change when reporting the task.

**Files:**
- Create: `app/Modules/Shared/Support/BrandWordmark.php`
- Create: `app/Modules/Shared/Http/Middleware/ApplyBrandWordmark.php`
- Create: `app/Modules/Shared/Listeners/ApplyBrandWordmarkToMail.php`
- Modify: `bootstrap/app.php` (append the middleware to the `web` group). Register the listener where the project registers listeners: `grep -rn "Event::listen" app/Providers app/Modules/*/Providers | head`.
- Create: `resources/fonts/urw-gothic/` (woff2 files and LICENSE)
- Modify: `resources/css/app.css` (`@font-face`, `--font-brand`, `.font-brand`)
- Test: `tests/Modules/Shared/BrandWordmarkTest.php`

**Interfaces:**
- Produces: `BrandWordmark::apply(string $html): string` (pure; returns input unchanged when there is no match). Also `BrandWordmark::CLASS_NAME = 'font-brand'`.

- [ ] **Step 1: Failing unit tests**

```php
<?php
declare(strict_types=1);
use App\Modules\Shared\Support\BrandWordmark;

it('wraps the word in text nodes', function () {
    $out = BrandWordmark::apply('<!doctype html><html><body><p>Welcome to arovolife today</p></body></html>');
    expect($out)->toContain('<span class="font-brand">arovolife</span> today');
});

it('never touches attributes, urls, emails, scripts, styles, titles or form fields', function () {
    $in = '<!doctype html><html><head><title>arovolife</title><meta name="description" content="arovolife"><script>var a="arovolife";</script><style>.arovolife{}</style></head>'
        .'<body><a href="https://arovolife.com/x" title="arovolife">site</a><img alt="arovolife"><input value="arovolife"><textarea>arovolife</textarea>'
        .'<p>Mail care@arovolife.com or visit www.arovolife.com</p><code>arovolife</code></body></html>';
    $out = BrandWordmark::apply($in);
    expect(substr_count($out, 'font-brand'))->toBe(0);
});

it('handles several mentions and keeps surrounding text', function () {
    $out = BrandWordmark::apply('<!doctype html><html><body><p>arovolife, and arovolife.</p></body></html>');
    expect(substr_count($out, '<span class="font-brand">arovolife</span>'))->toBe(2);
});

it('returns fragments without a match byte-for-byte', function () {
    $html = '<!doctype html><html><body><p>nothing here</p></body></html>';
    expect(BrandWordmark::apply($html))->toBe($html);
});

it('is idempotent', function () {
    $once = BrandWordmark::apply('<!doctype html><html><body><p>arovolife</p></body></html>');
    expect(BrandWordmark::apply($once))->toBe($once);
});
```

  "arovolife." at the end of a sentence must be wrapped. `www.arovolife.com` must not. The regex below separates them with `(?!\.\w)`.

- [ ] **Step 2: Run the test.** `RUN_TEST tests/Modules/Shared/BrandWordmarkTest.php`. Expected: FAIL, class not found.

- [ ] **Step 3: Implement `BrandWordmark`**

```php
<?php

declare(strict_types=1);

namespace App\Modules\Shared\Support;

use Dom\HTMLDocument;
use Dom\Text;
use Dom\XPath;

/**
 * Renders the brand name "arovolife" in the brand face wherever it is visible
 * text. Text nodes only — attributes, URLs, e-mail addresses, scripts, styles,
 * titles and form fields are never touched. Pure and idempotent.
 */
final class BrandWordmark
{
    public const CLASS_NAME = 'font-brand';

    private const PATTERN = '/(?<![@\w.\/-])arovolife(?![\w@]|\.\w)/';

    private const SKIP = ['script', 'style', 'title', 'textarea', 'code', 'pre', 'option', 'noscript', 'svg'];

    public static function apply(string $html): string
    {
        if (! str_contains($html, 'arovolife')) {
            return $html;
        }

        $doc = HTMLDocument::createFromString($html, LIBXML_NOERROR | \Dom\HTML_NO_DEFAULT_NS);
        $xpath = new XPath($doc);
        $changed = false;

        /** @var list<Text> $nodes */
        $nodes = iterator_to_array($xpath->query('//body//text()[contains(., "arovolife")]'));
        foreach ($nodes as $node) {
            if (self::insideSkipped($node)) {
                continue;
            }
            $parts = preg_split(self::PATTERN, $node->data, -1);
            if ($parts === false || count($parts) === 1) {
                continue;
            }
            $fragment = $doc->createDocumentFragment();
            foreach ($parts as $i => $part) {
                if ($i > 0) {
                    $span = $doc->createElement('span');
                    $span->setAttribute('class', self::CLASS_NAME);
                    $span->textContent = 'arovolife';
                    $fragment->appendChild($span);
                }
                if ($part !== '') {
                    $fragment->appendChild($doc->createTextNode($part));
                }
            }
            $node->parentNode?->replaceChild($fragment, $node);
            $changed = true;
        }

        return $changed ? $doc->saveHtml() : $html;
    }

    private static function insideSkipped(Text $node): bool
    {
        for ($el = $node->parentElement; $el !== null; $el = $el->parentElement) {
            $name = strtolower($el->localName);
            if (in_array($name, self::SKIP, true)) {
                return true;
            }
            if ($name === 'span' && $el->getAttribute('class') === self::CLASS_NAME) {
                return true; // already wrapped: idempotent
            }
        }

        return false;
    }
}
```

  If `Dom\HTML_NO_DEFAULT_NS` isn't a constant in the container's PHP 8.4.23, drop that flag. Check with `docker exec arovolife-app php -r 'var_dump(defined("Dom\\HTML_NO_DEFAULT_NS"));'`.

- [ ] **Step 4: Run the unit tests.** Expected: PASS. If the serializer rewrites the doctype or attribute quoting in a way that breaks "byte-for-byte", that assertion only covers the no-match early return, which is guaranteed.

- [ ] **Step 5: Middleware and mail listener**

```php
<?php

declare(strict_types=1);

namespace App\Modules\Shared\Http\Middleware;

use App\Modules\Shared\Support\BrandWordmark;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

final class ApplyBrandWordmark
{
    public function handle(Request $request, Closure $next): SymfonyResponse
    {
        $response = $next($request);

        if ($response instanceof Response
            && $response->getStatusCode() === 200
            && str_starts_with((string) $response->headers->get('Content-Type', 'text/html'), 'text/html')) {
            $content = (string) $response->getContent();
            $response->setContent(BrandWordmark::apply($content));
        }

        return $response;
    }
}
```

  Stream, binary-file, JSON and redirect responses aren't `Illuminate\Http\Response` with `text/html`, so they pass through untouched.

```php
<?php

declare(strict_types=1);

namespace App\Modules\Shared\Listeners;

use App\Modules\Shared\Support\BrandWordmark;
use Illuminate\Mail\Events\MessageSending;

final class ApplyBrandWordmarkToMail
{
    public function handle(MessageSending $event): void
    {
        $html = $event->message->getHtmlBody();
        if (is_string($html) && $html !== '') {
            $event->message->html(BrandWordmark::apply($html));
        }
    }
}
```

  - Register the middleware: in `bootstrap/app.php`, inside `->withMiddleware(function (Middleware $middleware) { … })`, add `$middleware->web(append: [\App\Modules\Shared\Http\Middleware\ApplyBrandWordmark::class]);`. If a `web(append: [...])` call already exists, add to its array instead of adding a second call.
  - Register the listener with `Event::listen(MessageSending::class, ApplyBrandWordmarkToMail::class)` in the provider found above.
  - Plain-text mail parts and SMS are untouched by design.
  - An HTML email can't load a self-hosted webfont reliably. The span carries an inline fallback so clients without the font still use a Century-Gothic-like system face. In the listener, pass the result through `str_replace('<span class="font-brand">', '<span class="font-brand" style="font-family:\'URW Gothic\',\'Century Gothic\',\'Avant Garde\',sans-serif">', …)`. This is the only inline style, and it's email-only.

- [ ] **Step 6: Font files (licence gate).**
  - Download `URWGothic-Book.otf` and `URWGothic-Demi.otf` from the ArtifexSoftware `urw-base35-fonts` repository on GitHub, into the session scratchpad.
  - **Read its LICENSE.** If it doesn't clearly allow serving the font files on a public website, **stop and ask the user.** The fallback is an OFL font such as Didact Gothic from Google Fonts.
  - Convert the files in a scratchpad venv:
    ```bash
    python3 -m venv "$SCRATCH/fontenv" && "$SCRATCH/fontenv/bin/pip" install -q fonttools brotli
    "$SCRATCH/fontenv/bin/pyftsubset" URWGothic-Book.otf --unicodes='U+0000-00FF,U+20B9' --flavor=woff2 --output-file=urw-gothic-book.woff2
    "$SCRATCH/fontenv/bin/pyftsubset" URWGothic-Demi.otf --unicodes='U+0000-00FF,U+20B9' --flavor=woff2 --output-file=urw-gothic-demi.woff2
    ```
  - Copy both woff2 files and the LICENSE into `resources/fonts/urw-gothic/`.
  - In `app.css`, next to the existing `@font-face` blocks, use the same URL style the Outfit faces use (relative path through Vite):

```css
@font-face { font-family: 'URW Gothic'; src: url('../fonts/urw-gothic/urw-gothic-book.woff2') format('woff2'); font-weight: 400; font-style: normal; font-display: swap; }
@font-face { font-family: 'URW Gothic'; src: url('../fonts/urw-gothic/urw-gothic-demi.woff2') format('woff2'); font-weight: 600 700; font-style: normal; font-display: swap; }
```

  - Inside `@theme`, add `--font-brand: 'URW Gothic', 'Century Gothic', 'Avant Garde', ui-sans-serif, sans-serif;`.
  - Outside `@theme`, add `.font-brand { font-family: var(--font-brand); letter-spacing: 0; }`. Size and weight are inherited from context.

- [ ] **Step 7: Integration test**

```php
it('pages and help render arovolife in the brand face without touching links', function () {
    $html = $this->get(route('login'))->assertOk()->getContent();
    expect($html)->toContain('<span class="font-brand">arovolife</span>')
        ->not->toMatch('/href="[^"]*<span/')
        ->not->toMatch('/content="[^"]*<span/');
});
```

  Run the whole file, then `RUN_TEST tests/Modules/Identity` and `RUN_TEST tests/Modules/Commerce`. Expected: PASS. Any existing test that asserts `toContain('… arovolife …')` inside visible text may now fail. Update those assertions to `strip_tags`-based checks, never by weakening the transformer.

- [ ] **Step 8: Performance check.** In the browser, compare the Server-Timing or response time of `/dashboard` before and after. If the transformer adds more than 25 ms on a typical page, report it to the user before continuing.

- [ ] **Step 9: Commit** with message `feat(ui): render every visible arovolife in the brand face (URW Gothic)`.

---

### Task 8: Left blue / Right green palette (item 14)

**Files:**
- Create: `app/Modules/Genealogy/Support/GenosSideColors.php`
- Modify: `resources/views/dashboard/_genos-balance.blade.php`, `resources/views/dashboard/_my-team.blade.php`, `resources/views/partials/_distributor-sidenav-groups.blade.php` (position chip), `resources/views/income/genos-bv.blade.php`, `resources/views/income/genos-ledger.blade.php`
- Test: `tests/Modules/Genealogy/GenosSideColorsTest.php`

**Interfaces:**
- Produces: `GenosSideColors::for(string $side): array{bar: string, text: string, chip: string, card: string, dot: string}`, where `$side` is `'L'|'R'|'left'|'right'`. Used by Tasks 10, 11 and 13.

- [ ] **Step 1: Failing test**

```php
<?php
declare(strict_types=1);
use App\Modules\Genealogy\Support\GenosSideColors;

it('left is blue and right is green', function () {
    expect(GenosSideColors::for('L')['bar'])->toContain('sky')
        ->and(GenosSideColors::for('right')['bar'])->toContain('emerald')
        ->and(GenosSideColors::for('R')['text'])->toBe('text-emerald-700');
});

it('no side-coloured dashboard or income view still uses indigo for Right', function () {
    foreach (['dashboard/_genos-balance', 'dashboard/_my-team', 'income/genos-bv', 'income/genos-ledger', 'partials/_distributor-sidenav-groups'] as $v) {
        expect(file_get_contents(resource_path("views/$v.blade.php")))->not->toContain('indigo');
    }
});
```

  Before relying on the second test, check each file's `indigo` hits. Any `indigo` that isn't a Right-side cue (for example a power badge) must move to a non-side colour such as `violet`, so the assertion stays meaningful.

- [ ] **Step 2: Run it.** Expected: FAIL.

- [ ] **Step 3: Implement**

```php
<?php

declare(strict_types=1);

namespace App\Modules\Genealogy\Support;

/**
 * The one place that decides how a Genos side is coloured: Left blue, Right
 * green (client, 2026-09-28). Literal class strings so Tailwind keeps them.
 */
final class GenosSideColors
{
    private const LEFT = [
        'bar' => 'bg-gradient-to-r from-sky-400 to-sky-600',
        'text' => 'text-sky-700',
        'chip' => 'border-sky-200 bg-sky-50 text-sky-700',
        'card' => 'border-sky-200 bg-gradient-to-br from-sky-50 to-white',
        'dot' => 'bg-sky-500',
    ];

    private const RIGHT = [
        'bar' => 'bg-gradient-to-r from-emerald-400 to-emerald-600',
        'text' => 'text-emerald-700',
        'chip' => 'border-emerald-200 bg-emerald-50 text-emerald-700',
        'card' => 'border-emerald-200 bg-gradient-to-br from-emerald-50 to-white',
        'dot' => 'bg-emerald-500',
    ];

    /** @return array{bar: string, text: string, chip: string, card: string, dot: string} */
    public static function for(string $side): array
    {
        return in_array(strtolower($side), ['l', 'left'], true) ? self::LEFT : self::RIGHT;
    }
}
```

  - In each listed view, replace the hard-coded sky/indigo classes for a side with `GenosSideColors::for('L')[…]` / `::for('R')[…]`, via an `@php $gl = …; $gr = …; @endphp` at the top.
  - The sidebar chip becomes `{{ \App\Modules\Genealogy\Support\GenosSideColors::for($navSide)['chip'] }}`.
  - Tailwind v4 scans PHP files under `app/` only if `@source` covers them. Check `resources/css/app.css` for `@source`. If `app/Modules` isn't listed, add `@source '../../app/Modules/Genealogy/Support';`.

- [ ] **Step 4: Run the test.** Run `npm run build` and the test, then `RUN_TEST tests/Modules/Identity/DashboardRenderTest.php`. Expected: PASS. Check the dark theme in the browser: sky and emerald must be covered by the `html.dark` remap. Extend the remap if not.

- [ ] **Step 5: Commit** with message `feat(ui): Left Genos blue, Right Genos green on every side-coloured surface`.

---

### Task 9: Dashboard quick actions, income cards, bonus table, Fortune chip (items 13, 16, 17, 18)

**Files:**
- Modify: `resources/views/dashboard/_quick-actions.blade.php`, `resources/views/dashboard/_income-snapshot.blade.php`, `resources/views/dashboard/_fortune-bonus.blade.php`
- Test: `tests/Modules/Identity/DashboardColoursTest.php`

- [ ] **Step 1: Failing test**

```php
<?php
declare(strict_types=1);

it('every quick action has its own colour family', function () {
    $src = file_get_contents(resource_path('views/dashboard/_quick-actions.blade.php'));
    preg_match_all("/'tone' => 'bg-([a-z]+)-/", $src, $m);
    expect(count($m[1]))->toBe(count(array_unique($m[1])));
    expect($src)->toContain("'teal'")->toContain('bg-teal-100');
});

it('income snapshot cards carry distinct tones', function () {
    $src = file_get_contents(resource_path('views/dashboard/_income-snapshot.blade.php'));
    foreach (['this-month', 'lifetime', 'next-weekly', 'next-monthly', 'repurchase-alert'] as $tone) {
        expect($src)->toContain('data-card="'.$tone.'"');
    }
    expect($src)->toContain('from-emerald-50')->toContain('from-violet-50')->not->toContain('border-gray-200 bg-gray-50 p-4');
});

it('fortune bonus not-qualified chip is red', function () {
    $src = file_get_contents(resource_path('views/dashboard/_fortune-bonus.blade.php'));
    expect($src)->toMatch('/bg-red-50[^"]*"[^>]*>\s*<x-lucide-circle-x[^>]*\/>\s*Not qualified yet/s');
});
```

- [ ] **Step 2: Run it.** Expected: FAIL.

- [ ] **Step 3: Quick actions.**
  - Change Wallet's tone to `'bg-teal-50 text-teal-700'`.
  - Add `'teal'` to `$quickTones`.
  - In every `$quickTones` entry, the `card` string moves from `border-{c}-200 bg-{c}-50 hover:border-{c}-400 hover:bg-{c}-100` to `border-{c}-300 bg-{c}-100 hover:border-{c}-400 hover:bg-{c}-200`. Write each literally, as today.

```php
'teal' => ['card' => 'border-teal-300 bg-teal-100 hover:border-teal-400 hover:bg-teal-200', 'icon' => 'bg-teal-500 text-white', 'label' => 'text-teal-900'],
```

- [ ] **Step 4: Income snapshot cards.** Replace each card's `border border-gray-200 bg-gray-50` and add `data-card` markers:

| Card | Classes and marker |
|---|---|
| This month | `border border-emerald-200 bg-gradient-to-br from-emerald-50 to-white` `data-card="this-month"`; label `text-emerald-800` |
| Lifetime | `border border-brand-200 bg-gradient-to-br from-brand-50 to-white` `data-card="lifetime"`; label `text-brand-800` |
| Next weekly | `border border-amber-200 bg-gradient-to-br from-amber-50 to-white` `data-card="next-weekly"`; label `text-amber-800` |
| Next monthly | `border border-violet-200 bg-gradient-to-br from-violet-50 to-white` `data-card="next-monthly"`; label `text-violet-800` |
| Repurchase alert | `data-card="repurchase-alert"`; classes below |

  Repurchase alert classes:

```blade
@php
    $alertUrgent = isset($repurchaseCard) && ($repurchaseCard?->state === \App\Modules\Compensation\Services\DTOs\RepurchaseCycleCard::STATE_SUSPENDED || $repurchaseCard?->urgencyTier() === 'late');
@endphp
class="rounded-xl border p-4 col-span-2 bg-gradient-to-br to-white {{ $alertUrgent ? 'border-red-300 from-red-50' : 'border-amber-300 from-amber-50' }}"
```

  Find the variable name that holds the cycle card in the dashboard view data: `grep -n "RepurchaseCycleCard\|cardFor" app/Modules/Identity/Http/Controllers/DashboardController.php resources/views/dashboard/index.blade.php`. Use it instead of `$repurchaseCard`. If `_income-snapshot` doesn't receive it, pass it in the existing `@include` call.

- [ ] **Step 5: Bonus table (item 17).**
  - The table container `divide-y divide-gray-100 border border-gray-100 rounded-xl overflow-hidden` becomes `relative divide-y divide-brand-100 rounded-2xl border border-brand-200 bg-gradient-to-br from-brand-50 via-white to-leaf-50/60 overflow-hidden shadow-sm`.
  - Add as its first child `<span class="absolute inset-x-0 top-0 h-1.5 bg-gradient-to-r from-brand-500 via-leaf-500 to-sunrise-500" aria-hidden="true"></span>`, and give the header row `pt-4`.
  - Header and Total rows: `bg-gray-50` becomes `bg-brand-50/70`. Row hover `hover:bg-gray-50` becomes `hover:bg-white/70`.

- [ ] **Step 6: Fortune chip (item 18).** In the `@else` branch:

```blade
<span class="inline-flex items-center gap-1 rounded-full bg-red-50 border border-red-200 px-2 py-1 text-[11px] font-medium text-red-800">
    <x-lucide-circle-x class="w-3.5 h-3.5" />
    Not qualified yet
</span>
```

- [ ] **Step 7: Run the tests.** Run `npm run build`, the new test, and `RUN_TEST tests/Feature/DashboardFortuneCardTest.php tests/Modules/Identity/DashboardRenderTest.php`. Expected: PASS.

- [ ] **Step 8: Commit** with message `feat(dashboard): distinct quick-action colours, tinted income cards, bonus table and red Fortune status`.

---

### Task 10: My Business payout card and carry cards (items 19, 20, 21)

**Files:**
- Create: `app/Modules/Compensation/Support/CarryOverDisplay.php`
- Modify: `resources/views/my-business.blade.php` (Next payout card ~l.110–125; carry row ~l.142–205; delete the F54 block ~l.76–81)
- Test: `tests/Modules/Compensation/CarryOverDisplayTest.php`

**Interfaces:**
- Produces: `CarryOverDisplay::sinceLastMatch(int $carriedBv, int $carryForwardBv): int`

- [ ] **Step 1: Failing tests**

```php
<?php
declare(strict_types=1);
use App\Modules\Compensation\Support\CarryOverDisplay;

it('shows only business since the last match', function (int $carried, int $cf, int $expected) {
    expect(CarryOverDisplay::sinceLastMatch($carried, $cf))->toBe($expected);
})->with([
    'before any match' => [4200, 0, 4200],
    'right after a match' => [5000, 5000, 0],
    'new business since' => [6500, 5000, 1500],
    'never negative' => [100, 300, 0],
]);

it('carry cards show numbers only and keep both sides', function () {
    $src = file_get_contents(resource_path('views/my-business.blade.php'));
    expect($src)->toContain('Carried-over Left Genos BV')->toContain('Carried-over Right Genos BV')
        ->toContain('CarryOverDisplay::sinceLastMatch')
        ->not->toContain('Remaining after your last slab match')
        ->not->toContain('No new business on this side since your last match')
        ->not->toContain('UnchangedSinceMatch')
        ->toContain('from-emerald-500 to-green-700');
});
```

- [ ] **Step 2: Run it.** Expected: FAIL.

- [ ] **Step 3: Implement**

```php
<?php

declare(strict_types=1);

namespace App\Modules\Compensation\Support;

/**
 * My Business "Carried-over" card (client, 2026-09-28): business added on a
 * side since the last slab match. The carry forward already sits on its own
 * card, so it is not repeated here — right after a match this reads 0.
 * Display only; the engine's carried totals are unchanged.
 */
final class CarryOverDisplay
{
    public static function sinceLastMatch(int $carriedBv, int $carryForwardBv): int
    {
        return max(0, $carriedBv - $carryForwardBv);
    }
}
```

- [ ] **Step 4: View changes**
  - Next payout card: replace its background classes with `bg-gradient-to-br from-emerald-500 to-green-700`. Keep the white text.
  - Delete the F54 comment and the `$leftUnchangedSinceMatch` / `$rightUnchangedSinceMatch` lines.
  - After `$rightCarriedBv`, add:

```php
$leftSinceMatchBv = \App\Modules\Compensation\Support\CarryOverDisplay::sinceLastMatch($leftCarriedBv, $leftCarryForwardBv);
$rightSinceMatchBv = \App\Modules\Compensation\Support\CarryOverDisplay::sinceLastMatch($rightCarriedBv, $rightCarryForwardBv);
$gl = \App\Modules\Genealogy\Support\GenosSideColors::for('L');
$gr = \App\Modules\Genealogy\Support\GenosSideColors::for('R');
```

  - Each of the four carry cards:
    - Replace `$cardClasses` with `rounded-2xl border p-5 shadow-sm {{ $gl['card'] }}` for the Left cards and `{{ $gr['card'] }}` for the Right cards.
    - Show the value with `\App\Modules\Shared\Support\IndianNumber::format(...)`: the carry-forward cards use `$leftCarryForwardBv` / `$rightCarryForwardBv`, and the carried-over cards use `$leftSinceMatchBv` / `$rightSinceMatchBv`.
    - **Delete every `<p class="text-xs …">` sub-line under the value.** Keep the label, the badge and `<x-help-tip>`.
  - Carried-over help-tip text becomes: `Business added on your {Left|Right} side since your last slab match, as it stood after the last 23:59 cut-off. Together with your carry forward, it counts toward your next slab match.` For the side matching `$settledWeakerSide`, when `$slabProgress?->slab1WeakerCfPaise > 0`, append ` It includes {N} BV of slab-1 weaker carry over.` Then append the existing `{{ $badgeTipSuffix }}{{ $eligibilityTipSuffix }}`.
  - Keep the pending-tonight info icon exactly as it is.
  - Update `resources/help/compensation.md` where it describes the Carried-over cards.

- [ ] **Step 5: Run the tests.** Run `npm run build`, the test, and `RUN_TEST tests/Modules/Compensation`. Expected: PASS. Update old assertions on removed sub-lines.

- [ ] **Step 6: Commit** with message `feat(my-business): green payout card, numbers-only carry cards, carried-over excludes carry forward`.

---

### Task 11: Team cards with today's orders (item 22)

**Files:**
- Modify: `app/Modules/Identity/Services/TeamStatsService.php` (add a public method)
- Modify: `app/Modules/Compensation/Http/Controllers/MyBusinessController.php` (~l.90 and the catch fallback ~l.100; add to `compact`)
- Modify: `resources/views/my-business.blade.php` (team cards ~l.212–243)
- Test: `tests/Modules/Identity/TeamOrdersTodayTest.php`

**Interfaces:**
- Consumes: `uiDistributor`, `uiPlaceUnder`, `uiPaidSelfOrder` (Task 0); `GenosSideColors` (Task 8)
- Produces: `TeamStatsService::ordersTodayBySide(Distributor $distributor, ?Carbon $now = null): array{left: int, right: int}`

- [ ] **Step 1: Failing tests**

```php
<?php
declare(strict_types=1);
use App\Modules\Identity\Models\Distributor;
use App\Modules\Identity\Services\TeamStatsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
uses(RefreshDatabase::class);

beforeEach(function () {
    $this->root = uiDistributor();
    $this->l1 = uiDistributor(); uiPlaceUnder($this->root['id'], 'L', $this->l1['id']);
    $this->l2 = uiDistributor(); uiPlaceUnder($this->l1['id'], 'R', $this->l2['id']); // deeper, still Left Genos
    $this->r1 = uiDistributor(); uiPlaceUnder($this->root['id'], 'R', $this->r1['id']);
    $this->now = Carbon::parse('2026-09-28 15:00', 'Asia/Kolkata');
});

it('counts paid self orders placed today per side', function () {
    uiPaidSelfOrder($this->l1['id'], 60000, $this->now->copy()->setTime(9, 0));
    uiPaidSelfOrder($this->l2['id'], 60000, $this->now->copy()->setTime(10, 0));
    uiPaidSelfOrder($this->r1['id'], 60000, $this->now->copy()->setTime(11, 0));

    $counts = app(TeamStatsService::class)->ordersTodayBySide(Distributor::find($this->root['id']), $this->now);
    expect($counts)->toBe(['left' => 2, 'right' => 1]);
});

it('excludes yesterday (IST boundary), cancelled, refunded and non-self orders', function () {
    uiPaidSelfOrder($this->l1['id'], 60000, Carbon::parse('2026-09-27 23:59', 'Asia/Kolkata'));
    uiPaidSelfOrder($this->l1['id'], 60000, $this->now->copy()->setTime(9, 0), 'cancelled');
    uiPaidSelfOrder($this->l1['id'], 60000, $this->now->copy()->setTime(9, 0), 'refunded');
    $customer = uiPaidSelfOrder($this->l1['id'], 60000, $this->now->copy()->setTime(9, 0));
    \DB::table('orders')->where('id', $customer)->update(['self_consumption' => false]);

    expect(app(TeamStatsService::class)->ordersTodayBySide(Distributor::find($this->root['id']), $this->now))
        ->toBe(['left' => 0, 'right' => 0]);
});

it('an empty side is zero, not an error', function () {
    $solo = uiDistributor();
    expect(app(TeamStatsService::class)->ordersTodayBySide(Distributor::find($solo['id']), $this->now))
        ->toBe(['left' => 0, 'right' => 0]);
});

it('My Business shows members and orders today, Right card mirrored and right-aligned', function () {
    uiPaidSelfOrder($this->r1['id'], 60000, now());
    $html = $this->actingAs($this->root['user'])->get(route('my-business'))->assertOk()->getContent();
    expect($html)->toContain('data-team-card="left"')->toContain('data-team-card="right"')
        ->toMatch('/data-team-card="right"[^>]*text-right/');
});
```

  `paid_at` storage timezone: check `config('app.timezone')`. If it is `Asia/Kolkata`, store and compare in IST. If it is UTC, convert the day bounds to UTC in the method. The yesterday-23:59 case pins the answer either way.

- [ ] **Step 2: Run it.** Expected: FAIL, method missing.

- [ ] **Step 3: Implement.** Add to `TeamStatsService`:

```php
/**
 * Paid self-consumption orders placed TODAY (Asia/Kolkata) by team members on
 * each side of the Genos. Aggregate counts only — no money, no individual
 * rows (client, 2026-09-28; hard rule 3 allows own-subtree aggregates).
 *
 * @return array{left: int, right: int}
 */
public function ordersTodayBySide(Distributor $distributor, ?\Illuminate\Support\Carbon $now = null): array
{
    $now ??= now();
    $ist = $now->copy()->setTimezone('Asia/Kolkata');
    $from = $ist->copy()->startOfDay()->setTimezone(config('app.timezone'));
    $to = $ist->copy()->endOfDay()->setTimezone(config('app.timezone'));

    $count = function (string $scope) use ($distributor, $from, $to): int {
        return (int) $this->db->table('orders as o')
            ->whereIn('o.attributed_distributor_id', $this->scopedIdQuery($distributor, $scope))
            ->where('o.self_consumption', true)
            ->whereNotIn('o.status', [
                \App\Modules\Commerce\Models\Order::STATUS_DRAFT,
                \App\Modules\Commerce\Models\Order::STATUS_PLACED,
                \App\Modules\Commerce\Models\Order::STATUS_CANCELLED,
                \App\Modules\Commerce\Models\Order::STATUS_REFUND_APPROVED,
                \App\Modules\Commerce\Models\Order::STATUS_REFUNDED,
            ])
            ->whereBetween('o.paid_at', [$from, $to])
            ->count();
    };

    return ['left' => $count('left'), 'right' => $count('right')];
}
```

  - `scopedIdQuery` selects `d.id` from a builder that joins `users as u`. Inside `whereIn` it becomes a subquery, which is fine.
  - Refund-requested and refund-inspection orders still count, because they were paid and aren't refunded yet. Mention this in the commit body.
  - In `MyBusinessController`, next to `$teamCounts`, add `$ordersToday = app(TeamStatsService::class)->ordersTodayBySide($distributor);`. In the catch block, add `$ordersToday = ['left' => 0, 'right' => 0];`, and add `'ordersToday'` to `compact(...)`.

- [ ] **Step 4: View.** The Left team card becomes:

```blade
<div data-team-card="left" class="rounded-2xl border p-5 shadow-sm {{ $gl['card'] }}">
    <div class="flex items-center justify-between mb-1">
        <p class="{{ $statLabelClasses }}">Left Genos total team</p>
        <x-help-tip text="Everyone placed anywhere in the Left side of your Genos, at any depth below you, and how many paid orders they placed for themselves today." />
    </div>
    <p class="flex items-center gap-2 text-2xl font-bold text-gray-900"
       aria-label="{{ $teamCounts['left_team'] ?? 0 }} members, {{ $ordersToday['left'] }} orders today">
        <span>{{ \App\Modules\Shared\Support\IndianNumber::format($teamCounts['left_team'] ?? 0, 0) }}</span>
        <x-lucide-arrow-right class="w-5 h-5 {{ $gl['text'] }}" aria-hidden="true" />
        <span>{{ \App\Modules\Shared\Support\IndianNumber::format($ordersToday['left'], 0) }}</span>
    </p>
    <p class="text-xs text-gray-600 mt-1">members → orders today</p>
</div>
```

  The Right card mirrors it and is fully right-aligned:

```blade
<div data-team-card="right" class="rounded-2xl border p-5 shadow-sm text-right {{ $gr['card'] }}">
    <div class="flex items-center justify-between flex-row-reverse mb-1">
        <p class="{{ $statLabelClasses }}">Right Genos total team</p>
        <x-help-tip text="Everyone placed anywhere in the Right side of your Genos, at any depth below you, and how many paid orders they placed for themselves today." />
    </div>
    <p class="flex items-center justify-end gap-2 text-2xl font-bold text-gray-900"
       aria-label="{{ $teamCounts['right_team'] ?? 0 }} members, {{ $ordersToday['right'] }} orders today">
        <span>{{ \App\Modules\Shared\Support\IndianNumber::format($ordersToday['right'], 0) }}</span>
        <x-lucide-arrow-left class="w-5 h-5 {{ $gr['text'] }}" aria-hidden="true" />
        <span>{{ \App\Modules\Shared\Support\IndianNumber::format($teamCounts['right_team'] ?? 0, 0) }}</span>
    </p>
    <p class="text-xs text-gray-600 mt-1">orders today ← members</p>
</div>
```

- [ ] **Step 5: Run the tests.** Run `npm run build` and the test file. Expected: PASS. Update `resources/help/compensation.md` or the My Business help page with one sentence on the order count.

- [ ] **Step 6: Commit** with message `feat(my-business): team cards show today's paid self orders per Genos side`.

---

### Task 12: Income filter defaults (item 24)

**Files:**
- Create: `app/Modules/Compensation/Support/IncomeFilterDefaults.php`
- Modify: `app/Modules/Compensation/Http/Controllers/IncomeController.php`. Call it first thing in `gsbHistory` (l.191) and `mentorship` (l.266) with `daily`, and in `growthBooster` (l.307), `rankBonus` (l.344), `fortuneBonus` (l.382) and `adcBonus` (l.417) with `monthly`. Also do this in their `…Export` actions, if they read `from`/`to`, so the export matches the screen.
- Modify: the six views `resources/views/income/{gsb-history,mentorship,growth-booster,rank-bonus,fortune-bonus,adc-bonus}.blade.php`. In each GET form, add `<input type="hidden" name="f" value="1">`. The Clear link becomes `route('…', ['f' => 1])` and is always visible. Remove the `@if(request('from') || request('to'))` guard around it.
- Test: `tests/Modules/Compensation/IncomeFilterDefaultsTest.php`

**Interfaces:**
- Produces: `IncomeFilterDefaults::apply(Request $request, string $grain, ?Carbon $today = null): void`, where `$grain` is `'daily'|'monthly'`.

- [ ] **Step 1: Failing tests**

```php
<?php
declare(strict_types=1);
use App\Modules\Compensation\Support\IncomeFilterDefaults;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

it('daily defaults to yesterday for both dates', function () {
    $r = Request::create('/x', 'GET');
    IncomeFilterDefaults::apply($r, 'daily', Carbon::parse('2026-09-28'));
    expect($r->query('from'))->toBe('2026-09-27')->and($r->query('to'))->toBe('2026-09-27');
});

it('monthly defaults to the previous month, across January', function () {
    $r = Request::create('/x', 'GET');
    IncomeFilterDefaults::apply($r, 'monthly', Carbon::parse('2027-01-05'));
    expect($r->query('from'))->toBe('2026-12')->and($r->query('to'))->toBe('2026-12');
});

it('an explicitly cleared form keeps blank dates', function () {
    $r = Request::create('/x', 'GET', ['f' => '1']);
    IncomeFilterDefaults::apply($r, 'daily', Carbon::parse('2026-09-28'));
    expect($r->filled('from'))->toBeFalse()->and($r->filled('to'))->toBeFalse();
});

it('an explicit range is respected', function () {
    $r = Request::create('/x', 'GET', ['from' => '2026-09-01', 'to' => '2026-09-10', 'f' => '1']);
    IncomeFilterDefaults::apply($r, 'daily', Carbon::parse('2026-09-28'));
    expect($r->query('from'))->toBe('2026-09-01');
});

it('a page link (?page=2) without f still gets defaults only when no dates present', function () {
    $r = Request::create('/x', 'GET', ['page' => '2']);
    IncomeFilterDefaults::apply($r, 'daily', Carbon::parse('2026-09-28'));
    expect($r->query('from'))->toBe('2026-09-27');
});
```

  Pagination uses `withQueryString()`, so once defaults are merged the page links carry `from`/`to` and stay consistent.

- [ ] **Step 2: Run it.** Expected: FAIL.

- [ ] **Step 3: Implement**

```php
<?php

declare(strict_types=1);

namespace App\Modules\Compensation\Support;

use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Default date range for the My Income bonus pages (client, 2026-09-28):
 * daily bonuses open on yesterday, monthly bonuses on last month. Applied only
 * to a first visit — a submitted or cleared filter form carries `f=1` and is
 * left exactly as the distributor set it.
 */
final class IncomeFilterDefaults
{
    public static function apply(Request $request, string $grain, ?Carbon $today = null): void
    {
        if ($request->has('f') || $request->filled('from') || $request->filled('to')) {
            return;
        }

        $today ??= Carbon::today('Asia/Kolkata');

        $value = $grain === 'monthly'
            ? $today->copy()->startOfMonth()->subMonth()->format('Y-m')
            : $today->copy()->subDay()->toDateString();

        $request->query->set('from', $value);
        $request->query->set('to', $value);
    }
}
```

  `$request->query->set()` updates the query bag, which `request('from')`, `->filled()` and `withQueryString()` all read. Verify the views display the value through `request('from')`.

- [ ] **Step 4: Controller and views.**
  - Add `IncomeFilterDefaults::apply($request, 'daily');` or `'monthly'` as the first line after the `abort_unless` checks in each of the six actions and their exports.
  - Add the hidden `f` input and the always-visible Clear link to each of the six forms.
  - Add a feature test: `$this->actingAs($distributor)->get(route('income.gsb-history'))` with GSB enabled via `Feature::activate(GenosSalesBonusFeature::class)` renders `value="{yesterday}"`.

- [ ] **Step 5: Run the tests.** Run the file and `RUN_TEST tests/Modules/Compensation`. Expected: PASS.

- [ ] **Step 6: Commit** with message `feat(income): bonus pages open on yesterday (daily) or last month (monthly)`.

---

### Task 13: Rank Bonus page bars and tiles (item 25)

**Files:**
- Modify: `resources/views/income/rank-bonus.blade.php` ("My Rank Status" Left/Right bars; stat cards ~l.130–145). If the bars are in `resources/views/income/_rank-conditions.blade.php` or `_rank-progress-note.blade.php`, edit them there.
- Test: `tests/Modules/Compensation/RankBonusStylingTest.php`

- [ ] **Step 1: Failing test**

```php
<?php
declare(strict_types=1);

it('rank status bars use the side palette and totals are tiles', function () {
    $src = file_get_contents(resource_path('views/income/rank-bonus.blade.php'))
        .@file_get_contents(resource_path('views/income/_rank-conditions.blade.php'));
    expect($src)->toContain("GenosSideColors::for('L')")
        ->toContain('data-tile="credited"')->toContain('data-tile="months"')
        ->toContain('bg-emerald-100')->toContain('bg-brand-100');
});
```

- [ ] **Step 2: Run it.** Expected: FAIL.

- [ ] **Step 3: Implement.**
  - Locate the "Left Genos BV this month" bar fill. Replace its fill classes with `{{ \App\Modules\Genealogy\Support\GenosSideColors::for('L')['bar'] }}`, and the Right bar with `::for('R')['bar']`.
  - Replace the two stat cards with:

```blade
<div class="grid grid-cols-2 gap-3 mb-6">
    <div data-tile="credited" class="flex items-center gap-3 rounded-2xl border border-emerald-300 bg-emerald-100 p-4 shadow-sm">
        <span class="inline-flex h-10 w-10 items-center justify-center rounded-xl bg-emerald-500 text-white shadow-md"><x-lucide-wallet class="w-5 h-5" /></span>
        <div>
            <p class="text-xs font-semibold text-emerald-900">Rank Bonus credited to wallet (page)</p>
            <p class="text-xl font-bold text-gray-900">{{-- keep the existing value expression --}}</p>
        </div>
    </div>
    <div data-tile="months" class="flex items-center gap-3 rounded-2xl border border-brand-300 bg-brand-100 p-4 shadow-sm">
        <span class="inline-flex h-10 w-10 items-center justify-center rounded-xl bg-brand-500 text-white shadow-md"><x-lucide-calendar-check class="w-5 h-5" /></span>
        <div>
            <p class="text-xs font-semibold text-brand-900">Months credited</p>
            <p class="text-xl font-bold text-gray-900">{{-- keep the existing value expression --}}</p>
        </div>
    </div>
</div>
```

  Move the two existing value expressions into the marked `<p>`s unchanged. The comments above mark where they go; don't leave them in.

- [ ] **Step 4: Run the tests.** Run `npm run build` and the test. Expected: PASS.

- [ ] **Step 5: Commit** with message `feat(income): Rank Bonus bars use Left blue/Right green; totals as tiles`.

---

### Task 14: Item 1: reproduce the shared-cart wallet report (compliance)

**Files:**
- Test: `tests/Modules/Commerce/SharedCartTest.php`. Append two cases so they reuse that file's `sctVariant`, `sctDistributorUser`, `sctCartFor`, `sctSetting` and `sctGuestCart`. Redefining those helpers in a new file would collide.
- Possibly modify: `app/Modules/Commerce/Http/Controllers/Storefront/CheckoutController.php`, `app/Modules/Commerce/Services/CheckoutService.php`. **Only if a test fails.**

- [ ] **Step 1: Write the reproduction tests.** Append to `tests/Modules/Commerce/SharedCartTest.php`. The imports `LedgerAccountSeeder`, `PreventRequestForgery`, `AttributionService`, `SharedCart` and `Order` are already at the top of that file.

```php
function sctPlaceForm(): array
{
    return [
        'buyer_name' => 'Guest Customer',
        'buyer_email' => 'guest-'.uniqid().'@test.com',
        'buyer_phone' => '9800000000',
        'ship_line1' => '1 Test St',
        'ship_city' => 'Pune',
        'ship_state' => 'Maharashtra',
        'ship_pincode' => '411001',
        'delivery_type' => 'ship',
        'payment_method' => 'online',
        'billing_same' => '1',
        'accept_terms' => '1',
    ];
}

it('SCT-10: a guest paying through KP\'s Easy Purchase link pays in full and KP\'s wallet is untouched', function (): void {
    $this->seed(LedgerAccountSeeder::class);
    sctSetting('commerce.checkout.enabled', 'true');
    sctSetting('commerce.guest_checkout.enabled', 'false');

    $adn = 'ADN'.random_int(10000, 99999);
    $kp = sctDistributorUser($adn);
    DB::table('distributors')->where('id', $kp->distributor->id)->update(['status' => 'active']);
    uiRepurchaseWallet($kp->distributor->id, 500_000);           // ₹5,000
    $cart = sctGuestCart(sctVariant(400_000));                     // ₹4,000

    $page = $this->withCookie(AttributionService::ANON_COOKIE, $cart->anonymous_key)
        ->withSession([SharedCart::SESSION_DISTRIBUTOR_KEY => $kp->distributor->id])
        ->get(route('shop.checkout'));
    $page->assertOk()->assertDontSee('Repurchase Credit');

    $this->withCookie(AttributionService::ANON_COOKIE, $cart->anonymous_key)
        ->withCookie(AttributionService::COOKIE_NAME, $adn)
        ->withSession([SharedCart::SESSION_DISTRIBUTOR_KEY => $kp->distributor->id])
        ->withoutMiddleware(PreventRequestForgery::class)
        ->post(route('shop.checkout.place'), sctPlaceForm())
        ->assertRedirect();

    $order = Order::latest('id')->first();
    $wallet = app(\App\Modules\Compensation\Services\WalletService::class);
    expect($order->total_paise)->toBeGreaterThanOrEqual(400_000)
        ->and($wallet->repurchaseCreditAppliedToOrder($order->id))->toBe(0)
        ->and($wallet->repurchaseWalletBalancePaise($kp->distributor->id))->toBe(500_000)
        ->and(DB::table('wallet_ledger_entries')->where('distributor_id', $kp->distributor->id)->where('type', 'repurchase_wallet_used')->count())->toBe(0);
});

it('SCT-11: a different signed-in distributor on KP\'s link only ever spends their own wallet', function (): void {
    $this->seed(LedgerAccountSeeder::class);
    sctSetting('commerce.checkout.enabled', 'true');

    $kp = sctDistributorUser('ADN'.random_int(10000, 99999));
    uiRepurchaseWallet($kp->distributor->id, 500_000);
    $buyer = sctDistributorUser('ADN'.random_int(10000, 99999));
    uiRepurchaseWallet($buyer->distributor->id, 100_000);           // ₹1,000
    sctCartFor($buyer, [sctVariant(400_000)]);

    $this->actingAs($buyer)
        ->withSession([SharedCart::SESSION_DISTRIBUTOR_KEY => $kp->distributor->id])
        ->withoutMiddleware(PreventRequestForgery::class)
        ->post(route('shop.checkout.place'), array_merge(sctPlaceForm(), ['buyer_email' => $buyer->email]))
        ->assertRedirect();

    $wallet = app(\App\Modules\Compensation\Services\WalletService::class);
    expect($wallet->repurchaseWalletBalancePaise($kp->distributor->id))->toBe(500_000)
        ->and($wallet->repurchaseWalletBalancePaise($buyer->distributor->id))->toBe(0);
});
```

  Read `sctCartFor`'s signature before using it. It takes `(User $user, array $variants)` per the file index. If the place request fails validation, dump `session('errors')` and add the missing field. Don't loosen the wallet assertions.

- [ ] **Step 2: Run the tests.** `RUN_TEST tests/Modules/Commerce/SharedCartTest.php`.
  - **Both pass:** commit the tests (Step 4). Stop the item there and report to the user: "The code never applies the sharer's wallet. Please send exact steps: who was signed in, which browser, and the URL used." Do not change production code.
  - **One fails:** switch to `superpowers:systematic-debugging`, find the root cause, fix it with the smallest change, and re-run until green.

- [ ] **Step 3: Compliance review.** Dispatch the `compliance-officer` subagent on the diff. Money is involved: repurchase wallet, hard rule 2 area.

- [ ] **Step 4: Commit**

```bash
git commit -m "test(commerce): shared-cart checkout never spends the sharer's repurchase wallet

Compliance-Review: compliance-officer
Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 15: Repurchase cycle date and time (item 11)

**Files:**
- Modify: `app/Modules/Compensation/Services/DTOs/RepurchaseCycleCard.php` (add `startedAt`; `fromCycle` takes it)
- Modify: `app/Modules/Compensation/Services/RepurchaseCycleService.php` (`cardFor` resolves `startedAt`; new public `cycleStartedAt()`)
- Modify: `resources/views/dashboard/_repurchase-cycle.blade.php` (dates line)
- Test: `tests/Modules/Compensation/RepurchaseCycleStartedAtTest.php`

**Interfaces:**
- Consumes: `BvLedgerService::firstReachedBvPaiseAt(int, int): ?Carbon`, `CompensationPlanSettingsService::gsbMinBvPaise()` (already `$this->plan` in the service)
- Produces:
  - `RepurchaseCycleCard::$startedAt` (`?Carbon`)
  - `RepurchaseCycleCard::fromCycle(RepurchaseCycle $cycle, Carbon $today, int $personalBvPaise, int $qualifyBvPaise, ?int $liveWalletBalancePaise = null, ?Carbon $startedAt = null): self`
  - `RepurchaseCycleService::cycleStartedAt(RepurchaseCycle $cycle): Carbon`

- [ ] **Step 1: Failing tests**

```php
<?php
declare(strict_types=1);
use App\Modules\Compensation\Models\RepurchaseCycle;
use App\Modules\Compensation\Services\RepurchaseCycleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
uses(RefreshDatabase::class);

beforeEach(fn () => seedCompensationPlanTables());

it('first cycle starts at the time the qualifying order was paid', function () {
    $d = uiDistributor();
    uiPaidSelfOrder($d['id'], 60000, Carbon::parse('2026-07-07 14:32:00'));
    $cycle = RepurchaseCycle::create([
        'distributor_id' => $d['id'], 'cycle_start_date' => '2026-07-07', 'due_date' => '2026-08-06',
        'required_bv_paise' => 60000, 'completed_bv_paise' => 0, 'status' => RepurchaseCycle::STATUS_ACTIVE,
    ]);
    expect(app(RepurchaseCycleService::class)->cycleStartedAt($cycle)->format('Y-m-d H:i'))->toBe('2026-07-07 14:32');
});

it('a rolled-over cycle starts at midnight', function () {
    $d = uiDistributor();
    uiPaidSelfOrder($d['id'], 60000, Carbon::parse('2026-07-07 14:32:00'));
    $cycle = RepurchaseCycle::create([
        'distributor_id' => $d['id'], 'cycle_start_date' => '2026-08-07', 'due_date' => '2026-09-06',
        'required_bv_paise' => 60000, 'completed_bv_paise' => 0, 'status' => RepurchaseCycle::STATUS_ACTIVE,
    ]);
    expect(app(RepurchaseCycleService::class)->cycleStartedAt($cycle)->format('Y-m-d H:i'))->toBe('2026-08-07 00:00');
});

it('the card line shows start and end with times and the window length', function () {
    $blade = file_get_contents(resource_path('views/dashboard/_repurchase-cycle.blade.php'));
    expect($blade)->toContain("startedAt?->format('j M Y, g:i A')")
        ->toContain("endDate?->format('j M Y')")->toContain('11:59 PM')
        ->not->toContain('last day counts');
});
```

  Check the `RepurchaseCycle` `$fillable` and required columns first, and match the `create` arrays to them. The status constant for a running cycle may be named differently; check the model.

- [ ] **Step 2: Run it.** Expected: FAIL.

- [ ] **Step 3: Implement `cycleStartedAt`.** Add to `RepurchaseCycleService`, near `cardFor`:

```php
/**
 * When this cycle began, to the minute, for the dashboard card (client,
 * 2026-09-28). Display only — eligibility stays by whole day.
 *
 *  - First cycle: the moment personal BV first reached the qualifying
 *    minimum (the paid time of that order).
 *  - A cycle opened on a late-fulfilment day: the time of that day's last
 *    self-purchase accrual (the purchase that completed it is at or before
 *    it; a documented approximation when two purchases land the same day).
 *  - Any other cycle rolls straight on from the last one: 00:00 on its start.
 */
public function cycleStartedAt(RepurchaseCycle $cycle): Carbon
{
    $start = $cycle->cycle_start_date->copy()->startOfDay();

    $firstReach = $this->bvLedger->firstReachedBvPaiseAt($cycle->distributor_id, $this->plan->gsbMinBvPaise());
    if ($firstReach !== null && $firstReach->isSameDay($start)) {
        return $firstReach;
    }

    $reactivated = RepurchaseCycle::query()
        ->where('distributor_id', $cycle->distributor_id)
        ->where('id', '!=', $cycle->id)
        ->whereDate('fulfilled_on', $start->toDateString())
        ->whereColumn('fulfilled_on', '>', 'due_date')
        ->exists();

    if ($reactivated) {
        $last = DB::table('bv_ledger_entries')
            ->where('distributor_id', $cycle->distributor_id)
            ->where('type', 'accrual')
            ->whereBetween('effective_at', [$start, $start->copy()->endOfDay()])
            ->whereExists(fn ($q) => $q->selectRaw('1')->from('orders')
                ->whereColumn('orders.id', 'bv_ledger_entries.order_id')
                ->where('orders.self_consumption', true))
            ->max('effective_at');

        if ($last !== null) {
            return Carbon::parse($last);
        }
    }

    return $start;
}
```

  - In `cardFor`, pass `startedAt: $this->cycleStartedAt($cycle)` to `fromCycle` as a named argument.
  - In the DTO, add `public ?Carbon $startedAt = null,` as the **last** constructor parameter, so `notQualified()` and other callers still compile. Add `?Carbon $startedAt = null` as the last `fromCycle` parameter and pass `startedAt: $startedAt ?? $start`.

- [ ] **Step 4: View.** Replace the dates `<p>` inside `@if($card->qualified())` with:

```blade
<p class="ms-auto text-xs text-gray-500 flex flex-col sm:flex-row sm:gap-1 sm:items-center">
    <span>Started <span class="font-semibold text-gray-900">{{ $card->startedAt?->format('j M Y, g:i A') }}</span></span>
    <span class="hidden sm:inline">&middot;</span>
    <span>Ends <span class="font-semibold text-gray-900">{{ $card->endDate?->format('j M Y') }}, 11:59 PM</span></span>
    <span class="hidden sm:inline">&middot; {{ $card->daysTotal - 1 }}-day window</span>
</p>
```

  Update the Blade comment above it: the 11:59 PM end replaces "last day counts".

- [ ] **Step 5: Run the tests.** Run the file and `RUN_TEST tests/Modules/Compensation --filter=Repurchase`. Expected: PASS.

- [ ] **Step 6: Commit** with message `feat(repurchase): cycle card shows start and end date with time`, including the `Compliance-Review: compliance-officer` trailer after Task 16's review. Alternatively, commit now and include both tasks in the Task 16 review.

---

### Task 16: Repurchase popup on confirmation and green check (item 12)

**Files:**
- Create: `app/Modules/Compensation/Services/RepurchaseOrderNotice.php`
- Modify: `app/Modules/Commerce/Http/Controllers/Storefront/CheckoutController.php` (`confirmation()`, ~l.547)
- Modify: `resources/views/shop/confirmation.blade.php` (modal)
- Modify: `resources/views/dashboard/_repurchase-cycle.blade.php` (green check line)
- Test: `tests/Modules/Compensation/RepurchaseOrderNoticeTest.php`

**Interfaces:**
- Consumes: `RepurchaseCycleService::currentCycle(int): ?RepurchaseCycle`, `BvLedgerService::selfPurchaseBvPaise(int, ?Carbon, ?Carbon): int`, `WalletService::repurchaseWalletBalancePaise(int): int`
- Produces:
  - `RepurchaseOrderNotice::for(Order $order, ?Carbon $today = null): ?array{kind: 'restored'|'bv_met', message: string}`
  - constants `RepurchaseOrderNotice::RESTORED`, `RepurchaseOrderNotice::BV_MET`

- [ ] **Step 1: Failing tests.** Create `tests/Modules/Compensation/RepurchaseOrderNoticeTest.php`:

```php
<?php
declare(strict_types=1);

use App\Modules\Commerce\Models\Order;
use App\Modules\Compensation\Models\RepurchaseCycle;
use App\Modules\Compensation\Services\RepurchaseCycleService;
use App\Modules\Compensation\Services\RepurchaseOrderNotice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
uses(RefreshDatabase::class);

beforeEach(function () {
    seedCompensationPlanTables();
    $this->today = Carbon::parse('2026-09-28', 'Asia/Kolkata');
    $this->d = uiDistributor();
    // Anchor: first 600 BV reached on 10 Aug, so an obligation exists.
    uiPaidSelfOrder($this->d['id'], 60_000, Carbon::parse('2026-08-10 11:00'));
});

function rnCycle(int $distributorId, string $start, string $due, string $status, int $required = 60_000): RepurchaseCycle
{
    return RepurchaseCycle::create([
        'distributor_id' => $distributorId, 'cycle_start_date' => $start, 'due_date' => $due,
        'required_bv_paise' => $required, 'completed_bv_paise' => 0, 'status' => $status,
    ]);
}

it('bv_met when this order takes an active cycle over the requirement', function () {
    rnCycle($this->d['id'], '2026-09-10', '2026-10-09', RepurchaseCycle::STATUS_ACTIVE);
    uiPaidSelfOrder($this->d['id'], 30_000, Carbon::parse('2026-09-15 10:00'));
    $order = Order::find(uiPaidSelfOrder($this->d['id'], 30_000, Carbon::parse('2026-09-28 12:00')));

    $notice = app(RepurchaseOrderNotice::class)->for($order, $this->today);

    expect($notice['kind'])->toBe(RepurchaseOrderNotice::BV_MET)
        ->and($notice['message'])->toContain('Keep your repurchase wallet at ₹0 on 9 Oct 2026');
});

it('restored when a suspended cycle is met with the wallet at zero', function () {
    rnCycle($this->d['id'], '2026-08-21', '2026-09-20', RepurchaseCycle::STATUS_SUSPENDED);
    uiPaidSelfOrder($this->d['id'], 20_000, Carbon::parse('2026-09-01 10:00'));
    $order = Order::find(uiPaidSelfOrder($this->d['id'], 40_000, Carbon::parse('2026-09-28 12:00')));

    expect(app(RepurchaseOrderNotice::class)->for($order, $this->today))->toBe([
        'kind' => RepurchaseOrderNotice::RESTORED,
        'message' => 'Your repurchase requirement is met. Your bonus eligibility is restored from today, 28 Sep 2026.',
    ]);
});

it('gives no notice when the wallet is not zero on a suspended cycle', function () {
    rnCycle($this->d['id'], '2026-08-21', '2026-09-20', RepurchaseCycle::STATUS_SUSPENDED);
    uiRepurchaseWallet($this->d['id'], 5_000);
    $order = Order::find(uiPaidSelfOrder($this->d['id'], 60_000, Carbon::parse('2026-09-28 12:00')));
    expect(app(RepurchaseOrderNotice::class)->for($order, $this->today))->toBeNull();
});

it('gives no notice for an unpaid order, a customer order, or a cycle already met before this order', function () {
    rnCycle($this->d['id'], '2026-09-10', '2026-10-09', RepurchaseCycle::STATUS_ACTIVE);
    $notice = app(RepurchaseOrderNotice::class);

    $unpaid = Order::find(uiPaidSelfOrder($this->d['id'], 60_000, Carbon::parse('2026-09-28 12:00')));
    DB::table('orders')->where('id', $unpaid->id)->update(['paid_at' => null, 'status' => 'placed']);
    expect($notice->for($unpaid->fresh(), $this->today))->toBeNull();
    DB::table('bv_ledger_entries')->where('order_id', $unpaid->id)->delete();

    $customer = Order::find(uiPaidSelfOrder($this->d['id'], 60_000, Carbon::parse('2026-09-28 12:00')));
    DB::table('orders')->where('id', $customer->id)->update(['self_consumption' => false]);
    expect($notice->for($customer->fresh(), $this->today))->toBeNull();
    DB::table('bv_ledger_entries')->where('order_id', $customer->id)->delete();

    uiPaidSelfOrder($this->d['id'], 60_000, Carbon::parse('2026-09-20 10:00'));        // already met
    $extra = Order::find(uiPaidSelfOrder($this->d['id'], 10_000, Carbon::parse('2026-09-28 12:00')));
    expect($notice->for($extra, $this->today))->toBeNull();
});

it('a restored cycle really dates fulfilment today, so today is not forfeited', function () {
    $cycle = rnCycle($this->d['id'], '2026-08-21', '2026-09-20', RepurchaseCycle::STATUS_SUSPENDED);
    uiPaidSelfOrder($this->d['id'], 60_000, Carbon::parse('2026-09-28 12:00'));

    app(RepurchaseCycleService::class)->evaluate($this->d['id'], Carbon::parse('2026-09-28'));

    $resolved = RepurchaseCycle::where('distributor_id', $this->d['id'])->whereNotNull('fulfilled_on')->orderBy('id')->first();
    expect($resolved?->fulfilled_on?->toDateString())->toBe('2026-09-28');
    expect($resolved->forfeitedWindow()[1]->toDateString())->toBe('2026-09-27');
});

it('the confirmation popup shows once per order, for its owner', function () {
    DB::table('settings')->updateOrInsert(['key' => 'commerce.checkout.enabled'], ['value' => 'true', 'version' => 1, 'updated_at' => now()]);
    \Laravel\Pennant\Feature::activate(\App\Modules\Shared\Features\RepurchaseEngineFeature::class);
    rnCycle($this->d['id'], now()->subDays(5)->toDateString(), now()->addDays(25)->toDateString(), RepurchaseCycle::STATUS_ACTIVE);
    $orderId = uiPaidSelfOrder($this->d['id'], 60_000, now());
    DB::table('orders')->where('id', $orderId)->update(['customer_id' => uiCustomerFor($this->d['user'], $this->d['id'])]);
    $orderNo = DB::table('orders')->where('id', $orderId)->value('order_no');

    $first = $this->actingAs($this->d['user'])->get(route('shop.confirmation', $orderNo))->assertOk()->getContent();
    $second = $this->actingAs($this->d['user'])->get(route('shop.confirmation', $orderNo))->assertOk()->getContent();

    expect($first)->toContain('data-repurchase-notice')
        ->and($second)->not->toContain('data-repurchase-notice');
});
```

  - **If `evaluate()` needs more state** (a rank row, or a prior completed cycle), add the minimum fixture it asks for. Never change the engine.
  - **If the restored-cycle test fails** because fulfilment lands tomorrow, change the RESTORED copy to "from tomorrow" and the assertion to match (spec §8.3).
  - **If `confirmation.blade.php` needs order items to render,** insert one `order_items` row for the order. Check its columns with the schema tool.

- [ ] **Step 2: Run it.** Expected: FAIL.

- [ ] **Step 3: Implement the service**

```php
<?php

declare(strict_types=1);

namespace App\Modules\Compensation\Services;

use App\Modules\Commerce\Models\Order;
use App\Modules\Commerce\Services\BvLedgerService;
use App\Modules\Compensation\Models\RepurchaseCycle;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The one-time message on an order confirmation when THIS order changed the
 * buyer's repurchase standing (client, 2026-09-28). Read-only: it never opens,
 * advances or resolves a cycle — the nightly evaluation does that. Eligibility
 * wording only; no rupee income figures (hard rule 3).
 */
final class RepurchaseOrderNotice
{
    public const RESTORED = 'restored';

    public const BV_MET = 'bv_met';

    public function __construct(
        private readonly RepurchaseCycleService $cycles,
        private readonly BvLedgerService $bvLedger,
        private readonly WalletService $wallet,
    ) {}

    /** @return array{kind: string, message: string}|null */
    public function for(Order $order, ?Carbon $today = null): ?array
    {
        $today ??= Carbon::today('Asia/Kolkata');
        $distributorId = $order->attributed_distributor_id;

        if (! $order->self_consumption || $order->paid_at === null || $distributorId === null) {
            return null;
        }

        $cycle = $this->cycles->currentCycle((int) $distributorId);
        if ($cycle === null) {
            return null;
        }

        $orderBv = (int) DB::table('bv_ledger_entries')
            ->where('order_id', $order->id)->where('type', 'accrual')->sum('bv_paise');
        if ($orderBv <= 0) {
            return null;
        }

        $after = $this->bvLedger->selfPurchaseBvPaise(
            (int) $distributorId,
            $cycle->cycle_start_date->copy()->startOfDay(),
            $today->copy()->endOfDay(),
        );
        $before = $after - $orderBv;
        $required = (int) $cycle->required_bv_paise;

        if (! ($before < $required && $after >= $required)) {
            return null;
        }

        if ($cycle->status === RepurchaseCycle::STATUS_SUSPENDED) {
            if ($this->wallet->repurchaseWalletBalancePaise((int) $distributorId) > 0) {
                return null;
            }

            return [
                'kind' => self::RESTORED,
                'message' => 'Your repurchase requirement is met. Your bonus eligibility is restored from today, '.$today->format('j M Y').'.',
            ];
        }

        if ($cycle->status === RepurchaseCycle::STATUS_COMPLETED) {
            return null;
        }

        return [
            'kind' => self::BV_MET,
            'message' => 'Your repurchase BV for this cycle is met. Keep your repurchase wallet at ₹0 on '
                .$cycle->due_date->format('j M Y').' to complete the cycle.',
        ];
    }
}
```

  Check that `selfPurchaseBvPaise` counts only self-consumption accruals net of reversals, as its docblock says. If the running-cycle status constant isn't `STATUS_ACTIVE`, the `default` path above still handles it.

- [ ] **Step 4: Controller.** In `confirmation()`, before `return view(...)`:

```php
$repurchaseNotice = null;
$seenKey = 'repurchase_notice_shown.'.$order->id;
if ($this->ownsOrder($request, $order) && $request->user()?->distributor !== null && ! $request->session()->has($seenKey)) {
    $repurchaseNotice = app(\App\Modules\Compensation\Services\RepurchaseOrderNotice::class)->for($order);
    if ($repurchaseNotice !== null) {
        $request->session()->put($seenKey, true);
    }
}
```

  Pass `'repurchaseNotice' => $repurchaseNotice` to the view. Guard with the repurchase engine feature flag the dashboard card uses: `grep -n "RepurchaseEngineFeature" app/Modules/Identity/Http/Controllers/DashboardController.php`. When the flag is off, the notice is null (feature-flag zero-trace rule).

- [ ] **Step 5: Confirmation view.** At the end of the content section:

```blade
@if(! empty($repurchaseNotice))
<div data-repurchase-notice class="fixed inset-0 z-[60] flex items-center justify-center bg-gray-900/40 p-4" role="dialog" aria-modal="true" aria-labelledby="repurchase-notice-title">
    <div class="w-full max-w-md rounded-2xl bg-white p-6 shadow-xl text-center">
        <span class="mx-auto mb-3 inline-flex h-12 w-12 items-center justify-center rounded-full bg-green-100 text-green-700">
            <x-lucide-circle-check class="w-7 h-7" />
        </span>
        <h2 id="repurchase-notice-title" class="text-lg font-bold text-gray-900 mb-2">Repurchase update</h2>
        <p class="text-sm text-gray-700">{{ $repurchaseNotice['message'] }}</p>
        <button type="button" data-repurchase-notice-close autofocus
                class="mt-5 inline-flex items-center justify-center rounded-lg bg-green-600 px-5 py-2 text-sm font-semibold text-white hover:bg-green-700">OK</button>
    </div>
</div>
<script>
(function () {
    const box = document.querySelector('[data-repurchase-notice]');
    if (! box) return;
    const close = () => box.remove();
    box.querySelector('[data-repurchase-notice-close]').addEventListener('click', close);
    document.addEventListener('keydown', (e) => { if (e.key === 'Escape') close(); });
    box.addEventListener('keydown', (e) => { if (e.key === 'Tab') { e.preventDefault(); box.querySelector('[data-repurchase-notice-close]').focus(); } });
})();
</script>
@endif
```

- [ ] **Step 6: Green check in the dashboard card.** In `_repurchase-cycle.blade.php`, inside the qualified branch, after the wallet line:

```blade
@if($card->bvMet() && in_array($card->state, [\App\Modules\Compensation\Services\DTOs\RepurchaseCycleCard::STATE_ACTIVE, \App\Modules\Compensation\Services\DTOs\RepurchaseCycleCard::STATE_COMPLETED], true))
    <p data-repurchase-met class="flex items-start gap-2 text-xs font-medium text-green-800">
        <x-lucide-circle-check class="w-4 h-4 text-green-700 mt-px shrink-0" />
        <span>Repurchase eligibility met. Today's business counts toward your bonuses.</span>
    </p>
@endif
```

  Add a render assertion for `data-repurchase-met` in the test file: present for active + BV met, absent for suspended and not-qualified.

- [ ] **Step 7: Run the tests.** Run the file and `RUN_TEST tests/Modules/Commerce tests/Modules/Compensation --filter="Repurchase|Confirmation"`. Expected: PASS.

- [ ] **Step 8: Compliance and copy review.** Dispatch `compliance-officer` on the Task 15 + 16 diff. Check the two popup messages and the green-check line against the `arovolife-ux-writing` skill. Apply any Critical finding before committing.

- [ ] **Step 9: Commit** with message `feat(repurchase): one-time confirmation notice and green eligibility check`, plus the `Compliance-Review: compliance-officer` trailer.

---

### Task 17: Whole-branch verification

- [ ] **Step 1:** Run `docker exec arovolife-app ./vendor/bin/pint --dirty` and `docker exec arovolife-app ./vendor/bin/phpstan analyse --memory-limit=1G`. Expected: clean, at Larastan level 7.
- [ ] **Step 2:** Run the full suite on the test DB: `RUN_TEST` with no path. Expected: all PASS. Report any pre-existing failure separately; don't mask it.
- [ ] **Step 3:** Run `cd app && npm run build`, `docker exec arovolife-app php artisan view:cache`, then `php -l` over the compiled views of every touched template.
- [ ] **Step 4:** Local browser check on http://localhost:8084 as a distributor, at desktop width and at 390px, in light and dark theme. Cover:
  - sign-in
  - registration steps 9 and 10
  - checkout (Total BV position)
  - the dashboard (quick actions, Genos colours, income cards, bonus table, Fortune chip, repurchase card with times)
  - My Business
  - GSB, MSB and Rank Bonus pages (default dates, Clear)
  - the top bar (sign-out pill, header colour)
  - sidebar scroll and hover
  - the arovolife font
  - the confirmation popup, by placing a paid self order that meets the BV

  Record a GIF with `gif_creator` named `distributor_ui_changes_2026_09_28.gif`. Afterwards, recommend `/compact`.
- [ ] **Step 5:** Update the memory index: the terminology memory (done in Task 4), plus a short project memory `distributor_ui_changes_2026_09_28.md` recording what shipped and that the font is URW Gothic pending a Century Gothic licence.
- [ ] **Step 6:** Report to the user with the task list and statuses only (execution-reporting memory). **Do not push or deploy.** Ask for approval before any staging or production deploy.
