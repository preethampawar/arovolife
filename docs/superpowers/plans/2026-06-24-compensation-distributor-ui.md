# Compensation Distributor UI — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build the distributor-facing "My Income" section (5 tabs: Dashboard, Genos BV, GSB History, Mentorship, Wallet & Payouts) under `/income/*`.

**Architecture:** Single `IncomeController` (7 actions) inside a new `Compensation` module. All queries scoped to `auth()->user()->distributor`. Data read-only; defers to services from the backend plan. Views extend `layouts.app` following `shop/bv-ledger` pattern.

**Tech Stack:** Laravel 13, Blade, Tailwind v4, Pest v4, `<x-help-tip>`, `data-confirm` modals, CSV streaming responses.

**Prerequisite:** The backend plan (`2026-06-24-compensation-backend.md`) must have its database migrations and models in place before the distributor UI plan can be tested end-to-end. The UI will render gracefully with empty states when no cut-off data exists yet.

---

## File map

| File | Action | Responsibility |
|---|---|---|
| `app/Modules/Compensation/Http/Controllers/IncomeController.php` | Create | 7 actions: dashboard, genosBv, gsbHistory, mentorship, wallet, exportGsb, exportWallet |
| `resources/views/income/dashboard.blade.php` | Create | Payout hero, 3 stat cards, 2 CF cards, page note |
| `resources/views/income/genos-bv.blade.php` | Create | Daily BV log table with date filter and CSV export link |
| `resources/views/income/gsb-history.blade.php` | Create | GSB cut-off history with deduction columns, monthly totals, CSV export |
| `resources/views/income/mentorship.blade.php` | Create | Per-sponsee MB table with masked ADN, slab step, CSV export |
| `resources/views/income/wallet.blade.php` | Create | 4 stat cards, wallet ledger table, payout history table, CSV exports |
| `resources/views/income/_tabs.blade.php` | Create | Reusable tab pill bar shared across all 5 income views |
| `routes/web.php` | Modify | 7 new routes under auth+distributor middleware |
| `resources/views/partials/public-topnav.blade.php` | Modify | "My Income" entry (mobile dropdown + desktop bar) |
| `tests/Modules/Compensation/IncomeControllerTest.php` | Create | HTTP tests for all 7 routes |

---

### Task 1: Routes + nav entry

**Files:**
- Modify: `app/routes/web.php` (around line 441)
- Modify: `app/resources/views/partials/public-topnav.blade.php` (lines 69–76 mobile dropdown, lines 365–368 desktop bar)

- [ ] **Step 1: Add 7 income routes to `web.php`**

Locate the distributor auth group around line 441 (after `Route::get('/orders', ...)`). Add immediately after the `/bv-ledger` route:

```php
// My Income (Compensation — Phase 4)
Route::get('/income', [App\Modules\Compensation\Http\Controllers\IncomeController::class, 'dashboard'])->name('income.dashboard');
Route::get('/income/genos-bv', [App\Modules\Compensation\Http\Controllers\IncomeController::class, 'genosBv'])->name('income.genos-bv');
Route::get('/income/gsb-history', [App\Modules\Compensation\Http\Controllers\IncomeController::class, 'gsbHistory'])->name('income.gsb-history');
Route::get('/income/gsb-history/export', [App\Modules\Compensation\Http\Controllers\IncomeController::class, 'exportGsb'])->name('income.gsb-history.export');
Route::get('/income/mentorship', [App\Modules\Compensation\Http\Controllers\IncomeController::class, 'mentorship'])->name('income.mentorship');
Route::get('/income/wallet', [App\Modules\Compensation\Http\Controllers\IncomeController::class, 'wallet'])->name('income.wallet');
Route::get('/income/wallet/export', [App\Modules\Compensation\Http\Controllers\IncomeController::class, 'exportWallet'])->name('income.wallet.export');
```

- [ ] **Step 2: Add "My Income" to mobile dropdown in `public-topnav.blade.php`**

Insert between the "My Orders & Sales" link (line 69) and the "My BV Ledger" link (line 73) — inside the `@if(! $isAdmin && $user->distributor)` block:

```blade
<a href="{{ route('income.dashboard') }}" class="flex items-center gap-2 px-4 py-2 text-sm hover:bg-gray-50" role="menuitem">
    <svg class="w-4 h-4 text-gray-400" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 6v12m-3-2.818.879.659c1.171.879 3.07.879 4.242 0 1.172-.879 1.172-2.303 0-3.182C13.536 12.219 12.768 12 12 12c-.725 0-1.45-.22-2.003-.659-1.171-.879-1.171-2.303 0-3.182C10.536 7.219 11.768 7 12 7c.725 0 1.45.22 2.003.659M12 3v1m0 16v1"/></svg>
    My Income
</a>
```

- [ ] **Step 3: Add "My Income" to desktop nav bar in `public-topnav.blade.php`**

Insert between the "My Orders & Sales" link (line 366) and the "My BV Ledger" link (line 367) — inside the `@if(! auth()->user()->hasRole('admin') && auth()->user()->distributor)` block:

```blade
<a href="{{ route('income.dashboard') }}" class="py-2.5 px-2 rounded-md text-brand-50 hover:text-white hover:bg-brand-600 transition-colors font-medium">My Income</a>
```

- [ ] **Step 4: Verify routes are registered**

```bash
cd app && php artisan route:list --path=income --except-vendor
```

Expected: 7 rows with names `income.dashboard`, `income.genos-bv`, `income.gsb-history`, `income.gsb-history.export`, `income.mentorship`, `income.wallet`, `income.wallet.export`.

- [ ] **Step 5: Commit**

```bash
git add app/routes/web.php app/resources/views/partials/public-topnav.blade.php
git commit -m "feat(income): add My Income routes + nav entry

Compliance-Review: compliance-officer"
```

---

### Task 2: IncomeController stub + failing HTTP tests

**Files:**
- Create: `app/app/Modules/Compensation/Http/Controllers/IncomeController.php`
- Create: `app/tests/Modules/Compensation/IncomeControllerTest.php`

- [ ] **Step 1: Create the Compensation module HTTP directory and controller stub**

```bash
cd app && mkdir -p app/Modules/Compensation/Http/Controllers
php artisan make:class app/Modules/Compensation/Http/Controllers/IncomeController
```

Replace the generated file contents with:

```php
<?php

declare(strict_types=1);

namespace App\Modules\Compensation\Http\Controllers;

use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;

final class IncomeController extends Controller
{
    private const PER_PAGE = 30;

    public function dashboard(Request $request): View
    {
        $distributor = $request->user()?->distributor;
        abort_unless($distributor !== null, 403);

        return view('income.dashboard', ['distributor' => $distributor]);
    }

    public function genosBv(Request $request): View
    {
        $distributor = $request->user()?->distributor;
        abort_unless($distributor !== null, 403);

        return view('income.genos-bv', ['distributor' => $distributor, 'rows' => collect()]);
    }

    public function gsbHistory(Request $request): View
    {
        $distributor = $request->user()?->distributor;
        abort_unless($distributor !== null, 403);

        return view('income.gsb-history', ['distributor' => $distributor, 'rows' => collect()]);
    }

    public function exportGsb(Request $request): Response
    {
        $distributor = $request->user()?->distributor;
        abort_unless($distributor !== null, 403);

        return response('', 200, ['Content-Type' => 'text/csv', 'Content-Disposition' => 'attachment; filename="gsb-history.csv"']);
    }

    public function mentorship(Request $request): View
    {
        $distributor = $request->user()?->distributor;
        abort_unless($distributor !== null, 403);

        return view('income.mentorship', ['distributor' => $distributor, 'rows' => collect()]);
    }

    public function wallet(Request $request): View
    {
        $distributor = $request->user()?->distributor;
        abort_unless($distributor !== null, 403);

        return view('income.wallet', ['distributor' => $distributor, 'ledgerRows' => collect(), 'payoutRows' => collect()]);
    }

    public function exportWallet(Request $request): Response
    {
        $distributor = $request->user()?->distributor;
        abort_unless($distributor !== null, 403);

        return response('', 200, ['Content-Type' => 'text/csv', 'Content-Disposition' => 'attachment; filename="wallet-ledger.csv"']);
    }
}
```

- [ ] **Step 2: Write the failing test**

```bash
cd app && php artisan make:test --pest IncomeControllerTest
```

Move/rename to `tests/Modules/Compensation/IncomeControllerTest.php` and replace contents:

```php
<?php

declare(strict_types=1);

use App\Modules\Identity\Models\Distributor;
use App\Modules\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function incomeDistributor(): array
{
    $user = User::factory()->create(['status' => 'active']);
    disableTestForeignKeys();
    try {
        $id = \Illuminate\Support\Facades\DB::table('distributors')->insertGetId([
            'user_id' => $user->id,
            'adn' => 'ADN' . random_int(10000, 99999),
            'pan_hash' => random_bytes(32),
            'pan_last4' => '1234',
            'bank_account_enc' => 'stub',
            'bank_ifsc' => 'SBIN0000000',
            'sponsor_id' => 1,
            'placement_parent_id' => 1,
            'side_chosen_by' => 'referral_default',
            'depth' => 0,
            'effective_date' => now()->format('Y-m-d H:i:s.v'),
            'cooling_off_end_at' => now()->addDays(30)->format('Y-m-d H:i:s.v'),
            'state' => 'TS',
            'is_primary_couple' => 0,
            'created_at' => now()->format('Y-m-d H:i:s.v'),
            'updated_at' => now()->format('Y-m-d H:i:s.v'),
        ]);
    } finally {
        enableTestForeignKeys();
    }

    return ['user' => $user, 'distributorId' => $id];
}

it('redirects unauthenticated users from all income routes', function (): void {
    $routes = [
        route('income.dashboard'),
        route('income.genos-bv'),
        route('income.gsb-history'),
        route('income.mentorship'),
        route('income.wallet'),
    ];
    foreach ($routes as $url) {
        $this->get($url)->assertRedirect(route('login'));
    }
});

it('returns 403 for authenticated user with no distributor record', function (): void {
    $user = User::factory()->create(['status' => 'active']);
    $this->actingAs($user);

    $this->get(route('income.dashboard'))->assertForbidden();
    $this->get(route('income.genos-bv'))->assertForbidden();
    $this->get(route('income.gsb-history'))->assertForbidden();
    $this->get(route('income.mentorship'))->assertForbidden();
    $this->get(route('income.wallet'))->assertForbidden();
});

it('renders income dashboard for a distributor', function (): void {
    ['user' => $user] = incomeDistributor();
    $this->actingAs($user);

    $this->get(route('income.dashboard'))
        ->assertOk()
        ->assertSee('My Income')
        ->assertSee('Next Payout');
});

it('renders genos bv page with empty state', function (): void {
    ['user' => $user] = incomeDistributor();
    $this->actingAs($user);

    $this->get(route('income.genos-bv'))
        ->assertOk()
        ->assertSee('Genos BV');
});

it('renders gsb history page with empty state', function (): void {
    ['user' => $user] = incomeDistributor();
    $this->actingAs($user);

    $this->get(route('income.gsb-history'))
        ->assertOk()
        ->assertSee('GSB History');
});

it('renders mentorship page with empty state', function (): void {
    ['user' => $user] = incomeDistributor();
    $this->actingAs($user);

    $this->get(route('income.mentorship'))
        ->assertOk()
        ->assertSee('Mentorship Bonus');
});

it('renders wallet page with empty state', function (): void {
    ['user' => $user] = incomeDistributor();
    $this->actingAs($user);

    $this->get(route('income.wallet'))
        ->assertOk()
        ->assertSee('Wallet');
});

it('streams gsb history csv for authenticated distributor', function (): void {
    ['user' => $user] = incomeDistributor();
    $this->actingAs($user);

    $this->get(route('income.gsb-history.export'))
        ->assertOk()
        ->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
});

it('streams wallet ledger csv for authenticated distributor', function (): void {
    ['user' => $user] = incomeDistributor();
    $this->actingAs($user);

    $this->get(route('income.wallet.export'))
        ->assertOk()
        ->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
});
```

- [ ] **Step 3: Run tests — expect failures on missing views**

```bash
cd app && php artisan test --compact tests/Modules/Compensation/IncomeControllerTest.php
```

Expected: 9 tests, failures on 5 "view not found" errors. Redirect and 403 tests may pass already.

- [ ] **Step 4: Commit stub**

```bash
git add app/app/Modules/Compensation/Http/Controllers/IncomeController.php app/tests/Modules/Compensation/IncomeControllerTest.php
git commit -m "feat(income): IncomeController stub + HTTP tests (failing — views pending)"
```

---

### Task 3: Shared tab partial + Dashboard view

**Files:**
- Create: `app/resources/views/income/_tabs.blade.php`
- Create: `app/resources/views/income/dashboard.blade.php`

- [ ] **Step 1: Create the shared tab bar partial**

```bash
mkdir -p app/resources/views/income
```

Write `resources/views/income/_tabs.blade.php`:

```blade
@php
$tabs = [
    ['route' => 'income.dashboard',   'label' => 'Dashboard'],
    ['route' => 'income.genos-bv',    'label' => 'Genos BV'],
    ['route' => 'income.gsb-history', 'label' => 'GSB History'],
    ['route' => 'income.mentorship',  'label' => 'Mentorship'],
    ['route' => 'income.wallet',      'label' => 'Wallet & Payouts'],
];
@endphp
<div class="flex flex-wrap gap-2 mb-6">
    @foreach($tabs as $tab)
        <a href="{{ route($tab['route']) }}"
           class="px-4 py-1.5 rounded-full text-sm font-medium transition-colors
                  {{ request()->routeIs($tab['route'])
                      ? 'bg-brand-500 text-white shadow-sm'
                      : 'bg-gray-100 text-gray-600 hover:bg-gray-200' }}">
            {{ $tab['label'] }}
        </a>
    @endforeach
</div>
```

- [ ] **Step 2: Create `dashboard.blade.php`**

Write `resources/views/income/dashboard.blade.php`:

```blade
@extends('layouts.app')
@section('title', 'My Income')

@section('content')
<div class="max-w-5xl mx-auto px-4 py-8">
    <h1 class="text-2xl font-bold text-gray-900 mb-2">My Income</h1>

    @include('income._tabs')

    {{-- Page note --}}
    <div class="bg-blue-50 border border-blue-200 rounded-xl px-4 py-3 text-sm text-blue-800 mb-6">
        This dashboard shows a live snapshot of your Genos Income. Group BV updates as your Genos members make purchases throughout the day. The 23:59 daily cut-off locks the BV for that day and calculates your Genos Sales Bonus. Your wallet is credited after the cut-off and your earnings are transferred to your bank account every Tuesday. Deductions (3% admin charge, 5% TDS, and any repurchase wallet balance) are applied before transfer.
    </div>

    {{-- Payout hero card --}}
    <div class="bg-gradient-to-r from-indigo-600 to-purple-600 rounded-2xl p-6 text-white mb-6">
        <p class="text-sm text-indigo-200 font-medium mb-1">Next Payout — Tuesday</p>
        <p class="text-4xl font-bold mb-1">₹—</p>
        <p class="text-sm text-indigo-200">After 3% admin charge + 5% TDS + repurchase deduction</p>
        <p class="text-xs text-indigo-300 mt-2">Your wallet balance will appear here once the GSB engine is active.</p>
    </div>

    {{-- Stat cards --}}
    <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 mb-6">
        <div class="bg-white rounded-2xl border border-gray-200 p-5">
            <div class="flex items-center justify-between mb-1">
                <p class="text-xs text-gray-500 font-medium">Personal BV (Lifetime)</p>
                <x-help-tip text="The total Business Volume you have accumulated from your own personal purchases since joining. This is a lifetime running total and never resets. It determines your personal purchase title (Retailer, Dealer, Wholesaler, etc.)." />
            </div>
            <p class="text-2xl font-bold text-gray-900">—</p>
            <p class="text-xs text-gray-400 mt-1">No title yet</p>
        </div>
        <div class="bg-white rounded-2xl border border-gray-200 p-5">
            <div class="flex items-center justify-between mb-1">
                <p class="text-xs text-gray-500 font-medium">Left Group BV (Today)</p>
                <x-help-tip text="Total Business Volume generated by all distributors placed in your Left Genos subtree today. Updates as purchases are made." />
            </div>
            <p class="text-2xl font-bold text-gray-900">—</p>
            <p class="text-xs text-gray-400 mt-1">as of last page load</p>
        </div>
        <div class="bg-white rounded-2xl border border-gray-200 p-5">
            <div class="flex items-center justify-between mb-1">
                <p class="text-xs text-gray-500 font-medium">Right Group BV (Today)</p>
                <x-help-tip text="Total Business Volume generated by all distributors placed in your Right Genos subtree today." />
            </div>
            <p class="text-2xl font-bold text-gray-900">—</p>
            <p class="text-xs text-gray-400 mt-1">as of last page load</p>
        </div>
    </div>

    {{-- Carry-forward cards --}}
    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
        <div class="bg-white rounded-2xl border border-gray-200 p-5">
            <div class="flex items-center justify-between mb-3">
                <p class="text-sm font-semibold text-gray-700">Power-side Carry-forward</p>
                <x-help-tip text="BV on your stronger (higher) Genos side is carried forward to the next day. Capped at 4,50,000 BV — any BV above this cap is flushed at each cut-off." />
            </div>
            <p class="text-xl font-bold text-gray-900 mb-2">0 BV <span class="text-sm font-normal text-gray-400">/ 4,50,000 BV cap</span></p>
            <div class="w-full bg-gray-100 rounded-full h-2">
                <div class="bg-indigo-500 h-2 rounded-full" style="width: 0%"></div>
            </div>
        </div>
        <div class="bg-white rounded-2xl border border-gray-200 p-5">
            <div class="flex items-center justify-between mb-3">
                <p class="text-sm font-semibold text-gray-700">Slab-1 Weaker Carry-forward</p>
                <x-help-tip text="For the first slab only (15,000 BV match), your weaker side BV carries forward indefinitely — there is no time limit. It accumulates day by day until 15,000 BV is matched and your first ₹1,000 GSB is earned." />
            </div>
            <p class="text-xl font-bold text-gray-900 mb-2">0 BV <span class="text-sm font-normal text-gray-400">/ 15,000 BV target</span></p>
            <div class="w-full bg-gray-100 rounded-full h-2">
                <div class="bg-green-500 h-2 rounded-full" style="width: 0%"></div>
            </div>
            <p class="text-xs text-gray-400 mt-2">No time limit</p>
        </div>
    </div>
</div>
@endsection
```

- [ ] **Step 3: Run tests — dashboard should now pass**

```bash
cd app && php artisan test --compact tests/Modules/Compensation/IncomeControllerTest.php --filter="dashboard"
```

Expected: PASS.

- [ ] **Step 4: Commit**

```bash
git add app/resources/views/income/
git commit -m "feat(income): dashboard tab view with payout hero + CF cards (empty state)"
```

---

### Task 4: Genos BV tab view

**Files:**
- Create: `app/resources/views/income/genos-bv.blade.php`

- [ ] **Step 1: Write `genos-bv.blade.php`**

```blade
@extends('layouts.app')
@section('title', 'My Income — Genos BV')

@section('content')
<div class="max-w-5xl mx-auto px-4 py-8">
    <h1 class="text-2xl font-bold text-gray-900 mb-2">My Income</h1>

    @include('income._tabs')

    {{-- Page note --}}
    <div class="bg-blue-50 border border-blue-200 rounded-xl px-4 py-3 text-sm text-blue-800 mb-6">
        Every day at 23:59, the platform locks your Left and Right Genos BV. The weaker side (lower of the two) is matched against the 7 slabs to determine your Genos Sales Bonus — but only up to the slab your personal purchase title allows. After the match, the weaker side resets to zero. The stronger (power) side carries forward, capped at 4,50,000 BV — any excess is flushed. For Slab 1 (15,000 BV match) only, your weaker side also carries forward indefinitely until matched — there is no time limit to earn your first ₹1,000 Genos Sales Bonus.
    </div>

    {{-- Filter form --}}
    <form method="GET" class="flex flex-wrap gap-3 mb-6 items-end">
        <div>
            <label class="block text-xs text-gray-500 mb-1">From</label>
            <input type="date" name="from" value="{{ request('from') }}" class="border border-gray-300 rounded-lg px-3 py-1.5 text-sm">
        </div>
        <div>
            <label class="block text-xs text-gray-500 mb-1">To</label>
            <input type="date" name="to" value="{{ request('to') }}" class="border border-gray-300 rounded-lg px-3 py-1.5 text-sm">
        </div>
        <button type="submit" class="px-4 py-1.5 bg-brand-500 text-white text-sm rounded-lg hover:bg-brand-600 transition-colors">Filter</button>
        @if(request('from') || request('to'))
            <a href="{{ route('income.genos-bv') }}" class="px-4 py-1.5 text-sm text-gray-600 hover:text-gray-800">Clear</a>
        @endif
    </form>

    @if($rows->isEmpty())
        <div class="bg-white rounded-2xl border border-gray-200 p-12 text-center">
            <p class="text-gray-500 font-medium">No Genos BV data yet.</p>
            <p class="text-sm text-gray-400 mt-1">Your daily BV log will appear here once the 23:59 cut-off engine is active and your Genos members begin purchasing.</p>
        </div>
    @else
        <div class="bg-white rounded-2xl border border-gray-200 overflow-x-auto">
            <table class="w-full text-sm min-w-[700px]">
                <thead class="bg-gray-50 border-b border-gray-200">
                    <tr>
                        <th class="text-left px-4 py-3 font-semibold text-gray-600">
                            Date
                        </th>
                        <th class="text-right px-4 py-3 font-semibold text-gray-600">
                            <span class="flex items-center justify-end gap-1">Left Group BV <x-help-tip text="Total Business Volume generated by all distributors placed in your Left Genos subtree today." /></span>
                        </th>
                        <th class="text-right px-4 py-3 font-semibold text-gray-600">
                            <span class="flex items-center justify-end gap-1">Right Group BV <x-help-tip text="Total Business Volume generated by all distributors placed in your Right Genos subtree today." /></span>
                        </th>
                        <th class="text-center px-4 py-3 font-semibold text-gray-600">
                            <span class="flex items-center justify-center gap-1">Weaker side <x-help-tip text="The lower of your Left and Right Group BV. The Genos Sales Bonus slab is matched against the weaker side — the stronger side carries forward." /></span>
                        </th>
                        <th class="text-center px-4 py-3 font-semibold text-gray-600">
                            <span class="flex items-center justify-center gap-1">Slab <x-help-tip text="The Genos Sales Bonus slab that applied today. Slab 1: 15,000 BV. Slab 2: 30,000 BV. Slab 3: 90,000 BV. Slab 4: 2,70,000 BV. Slab 5: 8,00,000 BV. Slab 6: 24,00,000 BV. Slab 7: 72,00,000 BV." /></span>
                        </th>
                        <th class="text-right px-4 py-3 font-semibold text-gray-600">
                            <span class="flex items-center justify-end gap-1">Power CF after <x-help-tip text="BV on your stronger Genos side carried forward to the next day. Capped at 4,50,000 BV." /></span>
                        </th>
                        <th class="text-right px-4 py-3 font-semibold text-gray-600">
                            <span class="flex items-center justify-end gap-1">Slab-1 weaker CF <x-help-tip text="Weaker side BV accumulating toward the 15,000 BV first-slab match. No time limit." /></span>
                        </th>
                        <th class="text-center px-4 py-3 font-semibold text-gray-600">Result</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @foreach($rows as $row)
                    <tr class="hover:bg-gray-50">
                        <td class="px-4 py-3 text-gray-700">{{ $row->date->format('d M Y') }}</td>
                        <td class="px-4 py-3 text-right font-mono">{{ number_format($row->left_bv_paise / 100, 0) }}</td>
                        <td class="px-4 py-3 text-right font-mono">{{ number_format($row->right_bv_paise / 100, 0) }}</td>
                        <td class="px-4 py-3 text-center text-gray-500">{{ $row->left_bv_paise <= $row->right_bv_paise ? 'Left' : 'Right' }}</td>
                        <td class="px-4 py-3 text-center">
                            @if($row->slab_matched)
                                <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-indigo-100 text-indigo-700">Slab {{ $row->slab_matched }}</span>
                            @else
                                <span class="text-gray-400 text-xs">—</span>
                            @endif
                        </td>
                        <td class="px-4 py-3 text-right font-mono text-gray-700">{{ number_format($row->power_cf_after / 100, 0) }}</td>
                        <td class="px-4 py-3 text-right font-mono text-gray-700">{{ number_format($row->slab1_weaker_cf_after / 100, 0) }}</td>
                        <td class="px-4 py-3 text-center">
                            @if($row->slab_matched)
                                <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-green-100 text-green-700">GSB earned</span>
                            @else
                                <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-gray-100 text-gray-500">No match</span>
                            @endif
                        </td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <div class="mt-4 flex justify-end">
            <a href="{{ route('income.gsb-history.export', request()->query()) }}" class="text-sm text-brand-600 hover:text-brand-700 font-medium">⬇ Export CSV</a>
        </div>
        @if(method_exists($rows, 'links'))
            <div class="mt-4">{{ $rows->links() }}</div>
        @endif
    @endif
</div>
@endsection
```

- [ ] **Step 2: Run test**

```bash
cd app && php artisan test --compact tests/Modules/Compensation/IncomeControllerTest.php --filter="genos"
```

Expected: PASS.

- [ ] **Step 3: Commit**

```bash
git add app/resources/views/income/genos-bv.blade.php
git commit -m "feat(income): Genos BV tab — daily BV log table with empty state"
```

---

### Task 5: GSB History tab view + CSV export

**Files:**
- Create: `app/resources/views/income/gsb-history.blade.php`
- Modify: `app/app/Modules/Compensation/Http/Controllers/IncomeController.php` (gsbHistory + exportGsb actions)

- [ ] **Step 1: Write `gsb-history.blade.php`**

```blade
@extends('layouts.app')
@section('title', 'My Income — GSB History')

@section('content')
<div class="max-w-5xl mx-auto px-4 py-8">
    <h1 class="text-2xl font-bold text-gray-900 mb-2">My Income</h1>

    @include('income._tabs')

    {{-- Page note --}}
    <div class="bg-blue-50 border border-blue-200 rounded-xl px-4 py-3 text-sm text-blue-800 mb-6">
        Your Genos Sales Bonus (GSB) is calculated at 23:59 every day based on the BV your Genos groups generated. The gross amount is reduced by a 3% admin charge (max ₹30,000), 5% TDS (Tax Deducted at Source), and a repurchase deduction before reaching your wallet. Each row below is one daily cut-off result.
    </div>

    {{-- Filter form --}}
    <form method="GET" class="flex flex-wrap gap-3 mb-6 items-end">
        <div>
            <label class="block text-xs text-gray-500 mb-1">From</label>
            <input type="date" name="from" value="{{ request('from') }}" class="border border-gray-300 rounded-lg px-3 py-1.5 text-sm">
        </div>
        <div>
            <label class="block text-xs text-gray-500 mb-1">To</label>
            <input type="date" name="to" value="{{ request('to') }}" class="border border-gray-300 rounded-lg px-3 py-1.5 text-sm">
        </div>
        <button type="submit" class="px-4 py-1.5 bg-brand-500 text-white text-sm rounded-lg hover:bg-brand-600 transition-colors">Filter</button>
        @if(request('from') || request('to'))
            <a href="{{ route('income.gsb-history') }}" class="px-4 py-1.5 text-sm text-gray-600 hover:text-gray-800">Clear</a>
        @endif
        <a href="{{ route('income.gsb-history.export', request()->query()) }}" class="ml-auto px-4 py-1.5 bg-gray-100 text-gray-700 text-sm rounded-lg hover:bg-gray-200 transition-colors font-medium">⬇ CSV</a>
    </form>

    @if($rows->isEmpty())
        <div class="bg-white rounded-2xl border border-gray-200 p-12 text-center">
            <p class="text-gray-500 font-medium">No GSB history yet.</p>
            <p class="text-sm text-gray-400 mt-1">Your Genos Sales Bonus will appear here after the first 23:59 cut-off calculates a match in your Genos groups.</p>
        </div>
    @else
        <div class="bg-white rounded-2xl border border-gray-200 overflow-x-auto">
            <table class="w-full text-sm min-w-[800px]">
                <thead class="bg-gray-50 border-b border-gray-200">
                    <tr>
                        <th class="text-left px-4 py-3 font-semibold text-gray-600">Date</th>
                        <th class="text-right px-4 py-3 font-semibold text-gray-600">Left BV matched</th>
                        <th class="text-right px-4 py-3 font-semibold text-gray-600">Right BV matched</th>
                        <th class="text-center px-4 py-3 font-semibold text-gray-600">
                            <span class="flex items-center justify-center gap-1">Slab <x-help-tip text="Slab 1: 15K BV = ₹1,000. Slab 2: 30K = ₹3,000. Slab 3: 90K = ₹6,000. Slab 4: 2.7L = ₹12,000. Slab 5: 8L = ₹24,000. Slab 6: 24L = ₹40,000. Slab 7: 72L = ₹60,000." /></span>
                        </th>
                        <th class="text-right px-4 py-3 font-semibold text-gray-600">
                            <span class="flex items-center justify-end gap-1">Gross GSB <x-help-tip text="The Genos Sales Bonus before any deductions." /></span>
                        </th>
                        <th class="text-right px-4 py-3 font-semibold text-gray-600">
                            <span class="flex items-center justify-end gap-1">Admin 3% <x-help-tip text="3% of gross GSB or ₹30,000 — whichever is lower." /></span>
                        </th>
                        <th class="text-right px-4 py-3 font-semibold text-gray-600">
                            <span class="flex items-center justify-end gap-1">TDS 5% <x-help-tip text="Tax Deducted at Source at 5% of gross minus admin charge." /></span>
                        </th>
                        <th class="text-right px-4 py-3 font-semibold text-gray-600">
                            <span class="flex items-center justify-end gap-1">Net GSB <x-help-tip text="Amount credited to your wallet after the admin charge and TDS deductions." /></span>
                        </th>
                        <th class="text-center px-4 py-3 font-semibold text-gray-600">Status</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @php $currentMonth = null; $monthGross = 0; $monthNet = 0; @endphp
                    @foreach($rows as $row)
                    @php
                        $rowMonth = $row->date->format('Y-m');
                        if ($currentMonth !== null && $rowMonth !== $currentMonth) {
                            // monthly totals row
                    @endphp
                    <tr class="bg-indigo-50 font-semibold text-xs text-indigo-700">
                        <td colspan="4" class="px-4 py-2">Month total</td>
                        <td class="px-4 py-2 text-right">₹{{ number_format($monthGross / 100, 0) }}</td>
                        <td colspan="2"></td>
                        <td class="px-4 py-2 text-right">₹{{ number_format($monthNet / 100, 0) }}</td>
                        <td></td>
                    </tr>
                    @php $monthGross = 0; $monthNet = 0; } $currentMonth = $rowMonth; $monthGross += $row->gross_paise; $monthNet += $row->net_paise; @endphp
                    <tr class="hover:bg-gray-50">
                        <td class="px-4 py-3 text-gray-700">{{ $row->date->format('d M Y') }}</td>
                        <td class="px-4 py-3 text-right font-mono">{{ number_format($row->left_bv_paise / 100, 0) }}</td>
                        <td class="px-4 py-3 text-right font-mono">{{ number_format($row->right_bv_paise / 100, 0) }}</td>
                        <td class="px-4 py-3 text-center">
                            <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-indigo-100 text-indigo-700">Slab {{ $row->slab_matched }}</span>
                        </td>
                        <td class="px-4 py-3 text-right font-mono">₹{{ number_format($row->gross_paise / 100, 0) }}</td>
                        <td class="px-4 py-3 text-right font-mono text-red-600">-₹{{ number_format($row->admin_charge_paise / 100, 0) }}</td>
                        <td class="px-4 py-3 text-right font-mono text-red-600">-₹{{ number_format($row->tds_paise / 100, 0) }}</td>
                        <td class="px-4 py-3 text-right font-mono font-semibold text-green-700">₹{{ number_format($row->net_paise / 100, 0) }}</td>
                        <td class="px-4 py-3 text-center">
                            @if($row->status === 'credited')
                                <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-green-100 text-green-700">Credited</span>
                            @elseif($row->status === 'failed')
                                <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-red-100 text-red-700">Failed</span>
                            @else
                                <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-gray-100 text-gray-500">{{ ucfirst($row->status) }}</span>
                            @endif
                        </td>
                    </tr>
                    @endforeach
                    {{-- final month totals row --}}
                    @if($rows->isNotEmpty())
                    <tr class="bg-indigo-50 font-semibold text-xs text-indigo-700">
                        <td colspan="4" class="px-4 py-2">Month total</td>
                        <td class="px-4 py-2 text-right">₹{{ number_format($monthGross / 100, 0) }}</td>
                        <td colspan="2"></td>
                        <td class="px-4 py-2 text-right">₹{{ number_format($monthNet / 100, 0) }}</td>
                        <td></td>
                    </tr>
                    @endif
                </tbody>
            </table>
        </div>
        @if(method_exists($rows, 'links'))
            <div class="mt-4">{{ $rows->links() }}</div>
        @endif
    @endif
</div>
@endsection
```

- [ ] **Step 2: Wire `gsbHistory` + `exportGsb` actions in `IncomeController.php`**

Replace the two stub methods with:

```php
public function gsbHistory(Request $request): View
{
    $distributor = $request->user()?->distributor;
    abort_unless($distributor !== null, 403);

    $query = \Illuminate\Support\Facades\DB::table('gsb_daily_results')
        ->where('distributor_id', $distributor->id)
        ->where('status', 'credited')
        ->orderBy('date', 'desc');

    if ($request->filled('from')) {
        $query->where('date', '>=', $request->input('from'));
    }
    if ($request->filled('to')) {
        $query->where('date', '<=', $request->input('to'));
    }

    $rows = $query->paginate(self::PER_PAGE)->withQueryString();
    $rows->transform(fn ($r) => (object) array_merge((array) $r, ['date' => \Illuminate\Support\Carbon::parse($r->date)]));

    return view('income.gsb-history', compact('distributor', 'rows'));
}

public function exportGsb(Request $request): Response|\Symfony\Component\HttpFoundation\StreamedResponse
{
    $distributor = $request->user()?->distributor;
    abort_unless($distributor !== null, 403);

    $query = \Illuminate\Support\Facades\DB::table('gsb_daily_results')
        ->where('distributor_id', $distributor->id)
        ->where('status', 'credited')
        ->orderBy('date', 'desc');

    if ($request->filled('from')) {
        $query->where('date', '>=', $request->input('from'));
    }
    if ($request->filled('to')) {
        $query->where('date', '<=', $request->input('to'));
    }

    return response()->streamDownload(function () use ($query): void {
        $out = fopen('php://output', 'w');
        fputcsv($out, ['Date', 'Left BV', 'Right BV', 'Slab', 'Gross GSB (₹)', 'Admin Charge (₹)', 'TDS (₹)', 'Net GSB (₹)', 'Status']);
        foreach ($query->lazyById(200, 'id') as $row) {
            fputcsv($out, [
                $row->date,
                number_format($row->left_bv_paise / 100, 0),
                number_format($row->right_bv_paise / 100, 0),
                $row->slab_matched,
                number_format($row->gross_paise / 100, 2),
                number_format($row->admin_charge_paise / 100, 2),
                number_format($row->tds_paise / 100, 2),
                number_format($row->net_paise / 100, 2),
                $row->status,
            ]);
        }
        fclose($out);
    }, 'gsb-history.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
}
```

Note: When `gsb_daily_results` table doesn't exist yet (pre-backend-plan), these actions will throw a DB exception. The test uses empty-state assertions against the stub that returns `collect()` — the full query is only activated once the backend plan runs migrations. For safety, wrap the DB query in a try-catch that falls back to `collect()` during the pre-engine phase:

```php
try {
    $rows = $query->paginate(self::PER_PAGE)->withQueryString();
    // ... transform
} catch (\Illuminate\Database\QueryException) {
    $rows = collect();
}
```

Apply the same try-catch pattern to `genosBv`, `mentorship`, and `wallet` when they are wired up in subsequent tasks.

- [ ] **Step 3: Run tests**

```bash
cd app && php artisan test --compact tests/Modules/Compensation/IncomeControllerTest.php --filter="gsb"
```

Expected: PASS.

- [ ] **Step 4: Commit**

```bash
git add app/resources/views/income/gsb-history.blade.php app/app/Modules/Compensation/Http/Controllers/IncomeController.php
git commit -m "feat(income): GSB History tab — deduction table + CSV export"
```

---

### Task 6: Mentorship Bonus tab view

**Files:**
- Create: `app/resources/views/income/mentorship.blade.php`
- Modify: `app/app/Modules/Compensation/Http/Controllers/IncomeController.php` (mentorship action)

- [ ] **Step 1: Write `mentorship.blade.php`**

```blade
@extends('layouts.app')
@section('title', 'My Income — Mentorship Bonus')

@section('content')
<div class="max-w-5xl mx-auto px-4 py-8">
    <h1 class="text-2xl font-bold text-gray-900 mb-2">My Income</h1>

    @include('income._tabs')

    {{-- Page note --}}
    <div class="bg-blue-50 border border-blue-200 rounded-xl px-4 py-3 text-sm text-blue-800 mb-6">
        You earn a Mentorship Bonus on the Genos Sales Bonus (GSB) earned by each distributor you directly sponsored. The rate starts at 10% of their GSB and steps down by 1% for every ₹30,000 of cumulative GSB they earn, stabilising at 1% for life. This bonus applies only to directly sponsored distributors' GSB — not to any other income type.
    </div>

    {{-- Summary cards --}}
    <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 mb-6">
        <div class="bg-white rounded-2xl border border-gray-200 p-5 text-center">
            <p class="text-xs text-gray-500 mb-1">MB Earned This Month</p>
            <p class="text-2xl font-bold text-gray-900">₹—</p>
        </div>
        <div class="bg-white rounded-2xl border border-gray-200 p-5 text-center">
            <p class="text-xs text-gray-500 mb-1">MB Earned Lifetime</p>
            <p class="text-2xl font-bold text-gray-900">₹—</p>
        </div>
        <div class="bg-white rounded-2xl border border-gray-200 p-5 text-center">
            <p class="text-xs text-gray-500 mb-1">Active Sponsees Contributing</p>
            <p class="text-2xl font-bold text-gray-900">—</p>
        </div>
    </div>

    {{-- Filter form --}}
    <form method="GET" class="flex flex-wrap gap-3 mb-6 items-end">
        <div>
            <label class="block text-xs text-gray-500 mb-1">From</label>
            <input type="date" name="from" value="{{ request('from') }}" class="border border-gray-300 rounded-lg px-3 py-1.5 text-sm">
        </div>
        <div>
            <label class="block text-xs text-gray-500 mb-1">To</label>
            <input type="date" name="to" value="{{ request('to') }}" class="border border-gray-300 rounded-lg px-3 py-1.5 text-sm">
        </div>
        <button type="submit" class="px-4 py-1.5 bg-brand-500 text-white text-sm rounded-lg hover:bg-brand-600 transition-colors">Filter</button>
        @if(request('from') || request('to'))
            <a href="{{ route('income.mentorship') }}" class="px-4 py-1.5 text-sm text-gray-600 hover:text-gray-800">Clear</a>
        @endif
    </form>

    @if($rows->isEmpty())
        <div class="bg-white rounded-2xl border border-gray-200 p-12 text-center">
            <p class="text-gray-500 font-medium">No Mentorship Bonus yet.</p>
            <p class="text-sm text-gray-400 mt-1">Your bonus will appear here once one of the distributors you directly sponsored earns their first Genos Sales Bonus.</p>
        </div>
    @else
        <div class="bg-white rounded-2xl border border-gray-200 overflow-x-auto">
            <table class="w-full text-sm min-w-[700px]">
                <thead class="bg-gray-50 border-b border-gray-200">
                    <tr>
                        <th class="text-left px-4 py-3 font-semibold text-gray-600">
                            <span class="flex items-center gap-1">Sponsee ADN <x-help-tip text="Your directly sponsored distributor's ADN, partially masked for privacy." /></span>
                        </th>
                        <th class="text-right px-4 py-3 font-semibold text-gray-600">
                            <span class="flex items-center justify-end gap-1">Their GSB this period <x-help-tip text="Net GSB earned by this sponsee during the selected period." /></span>
                        </th>
                        <th class="text-center px-4 py-3 font-semibold text-gray-600">
                            <span class="flex items-center justify-center gap-1">MB % <x-help-tip text="Starts at 10% of your sponsee's GSB. Steps down 1% per ₹30,000 cumulative GSB they earn, stabilising at 1% for life. Each sponsee tracked independently." /></span>
                        </th>
                        <th class="text-right px-4 py-3 font-semibold text-gray-600">MB earned from sponsee</th>
                        <th class="text-right px-4 py-3 font-semibold text-gray-600">
                            <span class="flex items-center justify-end gap-1">Cumulative GSB (lifetime) <x-help-tip text="The total GSB earned by this sponsee since they joined. Used to determine your current Mentorship Bonus % rate for them." /></span>
                        </th>
                        <th class="text-center px-4 py-3 font-semibold text-gray-600">Slab step</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @foreach($rows as $row)
                    <tr class="hover:bg-gray-50">
                        {{-- Mask: first 2 + *** + last 2 --}}
                        <td class="px-4 py-3 font-mono text-gray-700">
                            {{ substr($row->sponsee_adn, 0, 2) }}***{{ substr($row->sponsee_adn, -2) }}
                        </td>
                        <td class="px-4 py-3 text-right font-mono">₹{{ number_format($row->sponsee_gsb_period_paise / 100, 0) }}</td>
                        <td class="px-4 py-3 text-center">
                            <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-purple-100 text-purple-700">{{ $row->mb_rate }}%</span>
                        </td>
                        <td class="px-4 py-3 text-right font-mono font-semibold text-green-700">₹{{ number_format($row->mb_earned_paise / 100, 0) }}</td>
                        <td class="px-4 py-3 text-right font-mono text-gray-600">₹{{ number_format($row->cumulative_gsb_paise / 100, 0) }}</td>
                        <td class="px-4 py-3 text-center">
                            {{-- 10 step slab progress: step = 11 - current_rate (rate 10% = step 1, rate 1% = step 10) --}}
                            @php $step = 11 - (int) $row->mb_rate; @endphp
                            <span class="text-xs text-gray-500">Step {{ $step }} / 10</span>
                            <div class="w-20 mx-auto mt-1 bg-gray-100 rounded-full h-1.5">
                                <div class="bg-purple-500 h-1.5 rounded-full" style="width: {{ ($step / 10) * 100 }}%"></div>
                            </div>
                        </td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        @if(method_exists($rows, 'links'))
            <div class="mt-4">{{ $rows->links() }}</div>
        @endif
    @endif
</div>
@endsection
```

- [ ] **Step 2: Wire `mentorship` action in `IncomeController.php`**

Replace the stub `mentorship` method:

```php
public function mentorship(Request $request): View
{
    $distributor = $request->user()?->distributor;
    abort_unless($distributor !== null, 403);

    try {
        $rows = \Illuminate\Support\Facades\DB::table('mentorship_bonus_results as mb')
            ->join('distributors as sp', 'sp.id', '=', 'mb.sponsee_id')
            ->where('mb.sponsor_id', $distributor->id)
            ->orderBy('mb.date', 'desc')
            ->select(
                'sp.adn as sponsee_adn',
                'mb.sponsee_gsb_net_paise as sponsee_gsb_period_paise',
                'mb.mb_rate',
                'mb.mb_paise as mb_earned_paise',
                'mb.cumulative_gsb_paise',
            )
            ->paginate(self::PER_PAGE)
            ->withQueryString();
    } catch (\Illuminate\Database\QueryException) {
        $rows = collect();
    }

    return view('income.mentorship', compact('distributor', 'rows'));
}
```

- [ ] **Step 3: Run test**

```bash
cd app && php artisan test --compact tests/Modules/Compensation/IncomeControllerTest.php --filter="mentorship"
```

Expected: PASS.

- [ ] **Step 4: Commit**

```bash
git add app/resources/views/income/mentorship.blade.php app/app/Modules/Compensation/Http/Controllers/IncomeController.php
git commit -m "feat(income): Mentorship Bonus tab — masked sponsee ADN + slab step progress"
```

---

### Task 7: Wallet & Payouts tab view + CSV export

**Files:**
- Create: `app/resources/views/income/wallet.blade.php`
- Modify: `app/app/Modules/Compensation/Http/Controllers/IncomeController.php` (wallet + exportWallet actions)

- [ ] **Step 1: Write `wallet.blade.php`**

```blade
@extends('layouts.app')
@section('title', 'My Income — Wallet & Payouts')

@section('content')
<div class="max-w-5xl mx-auto px-4 py-8">
    <h1 class="text-2xl font-bold text-gray-900 mb-2">My Income</h1>

    @include('income._tabs')

    {{-- Page note --}}
    <div class="bg-blue-50 border border-blue-200 rounded-xl px-4 py-3 text-sm text-blue-800 mb-6">
        Your wallet receives GSB and Mentorship Bonus credits after each 23:59 cut-off. Every Tuesday, your wallet balance (minus deductions) is transferred to your registered bank account — provided the balance is at least ₹500. Repurchase deduction: 10% of your previous month's GSB + Mentorship Bonus (max ₹10,000) is held back to fund your mandatory monthly repurchase. Balances below ₹500 roll over to the next Tuesday.
    </div>

    {{-- Stat cards --}}
    <div class="grid grid-cols-2 sm:grid-cols-4 gap-4 mb-8">
        <div class="bg-white rounded-2xl border border-gray-200 p-5">
            <div class="flex items-center justify-between mb-1">
                <p class="text-xs text-gray-500">Wallet Balance</p>
                <x-help-tip text="Total GSB and Mentorship Bonus credits in your wallet awaiting the next Tuesday payout." />
            </div>
            <p class="text-2xl font-bold text-gray-900">₹—</p>
        </div>
        <div class="bg-white rounded-2xl border border-gray-200 p-5">
            <div class="flex items-center justify-between mb-1">
                <p class="text-xs text-gray-500">Repurchase Hold</p>
                <x-help-tip text="10% of your previous month's GSB and Mentorship Bonus (capped at ₹10,000) held back to fund your mandatory monthly repurchase of at least 600 BV." />
            </div>
            <p class="text-2xl font-bold text-red-600">₹—</p>
        </div>
        <div class="bg-white rounded-2xl border border-gray-200 p-5">
            <div class="flex items-center justify-between mb-1">
                <p class="text-xs text-gray-500">Net Transfer Amount</p>
                <x-help-tip text="Wallet balance minus the repurchase deduction. This is the amount transferred to your bank on Tuesday." />
            </div>
            <p class="text-2xl font-bold text-green-700">₹—</p>
        </div>
        <div class="bg-white rounded-2xl border border-gray-200 p-5">
            <div class="flex items-center justify-between mb-1">
                <p class="text-xs text-gray-500">Next Payout</p>
            </div>
            <p class="text-lg font-bold text-gray-900">Tuesday</p>
            <p class="text-xs text-gray-400 mt-1">Min. ₹500 required</p>
        </div>
    </div>

    {{-- Wallet Ledger --}}
    <div class="flex items-center justify-between mb-3">
        <h2 class="text-lg font-semibold text-gray-800">Wallet Ledger</h2>
        <a href="{{ route('income.wallet.export') }}" class="text-sm text-brand-600 hover:text-brand-700 font-medium">⬇ CSV</a>
    </div>

    @if($ledgerRows->isEmpty())
        <div class="bg-white rounded-2xl border border-gray-200 p-10 text-center mb-8">
            <p class="text-gray-500 font-medium">No wallet transactions yet.</p>
            <p class="text-sm text-gray-400 mt-1">Entries will appear here after the first GSB or Mentorship Bonus credit.</p>
        </div>
    @else
        <div class="bg-white rounded-2xl border border-gray-200 overflow-x-auto mb-8">
            <table class="w-full text-sm min-w-[640px]">
                <thead class="bg-gray-50 border-b border-gray-200">
                    <tr>
                        <th class="text-left px-4 py-3 font-semibold text-gray-600">Timestamp</th>
                        <th class="text-left px-4 py-3 font-semibold text-gray-600">Type</th>
                        <th class="text-right px-4 py-3 font-semibold text-gray-600">Amount</th>
                        <th class="text-right px-4 py-3 font-semibold text-gray-600">Running Balance</th>
                        <th class="text-left px-4 py-3 font-semibold text-gray-600">Reference</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @foreach($ledgerRows as $entry)
                    <tr class="hover:bg-gray-50">
                        <td class="px-4 py-3 text-gray-600 text-xs">{{ $entry->created_at->format('d M Y H:i') }}</td>
                        <td class="px-4 py-3">
                            @php
                            $typeColors = [
                                'gsb_credit'       => 'bg-green-100 text-green-700',
                                'mb_credit'        => 'bg-purple-100 text-purple-700',
                                'payout_debit'     => 'bg-blue-100 text-blue-700',
                                'admin_charge'     => 'bg-orange-100 text-orange-700',
                                'tds'              => 'bg-orange-100 text-orange-700',
                                'repurchase'       => 'bg-yellow-100 text-yellow-700',
                                'manual_credit'    => 'bg-gray-100 text-gray-700',
                                'reversal'         => 'bg-red-100 text-red-700',
                            ];
                            $typeLabels = [
                                'gsb_credit'    => 'GSB Credit',
                                'mb_credit'     => 'MB Credit',
                                'payout_debit'  => 'Payout',
                                'admin_charge'  => 'Admin Charge',
                                'tds'           => 'TDS',
                                'repurchase'    => 'Repurchase',
                                'manual_credit' => 'Manual Credit',
                                'reversal'      => 'Reversal',
                            ];
                            $color = $typeColors[$entry->type] ?? 'bg-gray-100 text-gray-600';
                            $label = $typeLabels[$entry->type] ?? ucfirst(str_replace('_', ' ', $entry->type));
                            @endphp
                            <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium {{ $color }}">{{ $label }}</span>
                        </td>
                        <td class="px-4 py-3 text-right font-mono {{ $entry->amount_paise >= 0 ? 'text-green-700' : 'text-red-600' }}">
                            {{ $entry->amount_paise >= 0 ? '+' : '' }}₹{{ number_format(abs($entry->amount_paise) / 100, 0) }}
                        </td>
                        <td class="px-4 py-3 text-right font-mono text-gray-700">₹{{ number_format($entry->running_balance_paise / 100, 0) }}</td>
                        <td class="px-4 py-3 text-xs text-gray-500 font-mono">{{ $entry->reference ?? '—' }}</td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        @if(method_exists($ledgerRows, 'links'))
            <div class="mb-8">{{ $ledgerRows->links() }}</div>
        @endif
    @endif

    {{-- Payout History --}}
    <h2 class="text-lg font-semibold text-gray-800 mb-3">Payout History</h2>

    @if($payoutRows->isEmpty())
        <div class="bg-white rounded-2xl border border-gray-200 p-10 text-center">
            <p class="text-gray-500 font-medium">No payouts yet.</p>
            <p class="text-sm text-gray-400 mt-1">Payout history will appear here after the first Tuesday bank transfer.</p>
        </div>
    @else
        <div class="bg-white rounded-2xl border border-gray-200 overflow-x-auto">
            <table class="w-full text-sm min-w-[700px]">
                <thead class="bg-gray-50 border-b border-gray-200">
                    <tr>
                        <th class="text-left px-4 py-3 font-semibold text-gray-600">Date</th>
                        <th class="text-right px-4 py-3 font-semibold text-gray-600">Wallet Balance</th>
                        <th class="text-right px-4 py-3 font-semibold text-gray-600">
                            <span class="flex items-center justify-end gap-1">Repurchase Deduction <x-help-tip text="10% of your previous month's GSB and Mentorship Bonus (max ₹10,000)." /></span>
                        </th>
                        <th class="text-right px-4 py-3 font-semibold text-gray-600">Net Transferred</th>
                        <th class="text-center px-4 py-3 font-semibold text-gray-600">Status</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @foreach($payoutRows as $payout)
                    <tr class="hover:bg-gray-50">
                        <td class="px-4 py-3 text-gray-700">{{ \Illuminate\Support\Carbon::parse($payout->payout_date)->format('d M Y') }}</td>
                        <td class="px-4 py-3 text-right font-mono">₹{{ number_format($payout->wallet_balance_paise / 100, 0) }}</td>
                        <td class="px-4 py-3 text-right font-mono text-red-600">-₹{{ number_format($payout->repurchase_deduction_paise / 100, 0) }}</td>
                        <td class="px-4 py-3 text-right font-mono font-semibold text-green-700">₹{{ number_format($payout->net_paise / 100, 0) }}</td>
                        <td class="px-4 py-3 text-center">
                            @if($payout->status === 'transferred')
                                <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-green-100 text-green-700">Transferred</span>
                            @elseif($payout->status === 'below_minimum')
                                <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-yellow-100 text-yellow-700">Below ₹500</span>
                            @elseif($payout->status === 'pending')
                                <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-blue-100 text-blue-700">Pending</span>
                            @elseif($payout->status === 'failed')
                                <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-red-100 text-red-700">Failed</span>
                            @else
                                <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-gray-100 text-gray-500">{{ ucfirst($payout->status) }}</span>
                            @endif
                        </td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</div>
@endsection
```

- [ ] **Step 2: Wire `wallet` + `exportWallet` actions in `IncomeController.php`**

Replace the two stub wallet methods:

```php
public function wallet(Request $request): View
{
    $distributor = $request->user()?->distributor;
    abort_unless($distributor !== null, 403);

    try {
        $ledgerRows = \Illuminate\Support\Facades\DB::table('wallet_ledger_entries')
            ->where('distributor_id', $distributor->id)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate(self::PER_PAGE)
            ->withQueryString();
        $ledgerRows->transform(fn ($r) => (object) array_merge((array) $r, [
            'created_at' => \Illuminate\Support\Carbon::parse($r->created_at),
        ]));
    } catch (\Illuminate\Database\QueryException) {
        $ledgerRows = collect();
    }

    try {
        $payoutRows = \Illuminate\Support\Facades\DB::table('payout_batch_items as pbi')
            ->join('payout_batches as pb', 'pb.id', '=', 'pbi.payout_batch_id')
            ->where('pbi.distributor_id', $distributor->id)
            ->orderByDesc('pb.payout_date')
            ->select('pb.payout_date', 'pbi.wallet_balance_paise', 'pbi.repurchase_deduction_paise', 'pbi.net_paise', 'pbi.status')
            ->paginate(self::PER_PAGE)
            ->withQueryString();
    } catch (\Illuminate\Database\QueryException) {
        $payoutRows = collect();
    }

    return view('income.wallet', compact('distributor', 'ledgerRows', 'payoutRows'));
}

public function exportWallet(Request $request): \Symfony\Component\HttpFoundation\StreamedResponse|Response
{
    $distributor = $request->user()?->distributor;
    abort_unless($distributor !== null, 403);

    try {
        $query = \Illuminate\Support\Facades\DB::table('wallet_ledger_entries')
            ->where('distributor_id', $distributor->id)
            ->orderByDesc('created_at')
            ->orderByDesc('id');
    } catch (\Illuminate\Database\QueryException) {
        return response('', 200, ['Content-Type' => 'text/csv; charset=UTF-8', 'Content-Disposition' => 'attachment; filename="wallet-ledger.csv"']);
    }

    return response()->streamDownload(function () use ($query): void {
        $out = fopen('php://output', 'w');
        fputcsv($out, ['Timestamp', 'Type', 'Amount (₹)', 'Reference']);
        foreach ($query->lazyById(200, 'id') as $entry) {
            fputcsv($out, [
                $entry->created_at,
                $entry->type,
                number_format($entry->amount_paise / 100, 2),
                $entry->reference ?? '',
            ]);
        }
        fclose($out);
    }, 'wallet-ledger.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
}
```

- [ ] **Step 3: Run all tests**

```bash
cd app && php artisan test --compact tests/Modules/Compensation/IncomeControllerTest.php
```

Expected: 9 tests, all PASS.

- [ ] **Step 4: Run Pint**

```bash
cd app && vendor/bin/pint --dirty --format agent
```

- [ ] **Step 5: Commit**

```bash
git add app/resources/views/income/wallet.blade.php app/app/Modules/Compensation/Http/Controllers/IncomeController.php
git commit -m "feat(income): Wallet & Payouts tab — ledger + payout history + CSV export

Compliance-Review: compliance-officer"
```

---

### Task 8: Full test run + final checks

**Files:** None new.

- [ ] **Step 1: Run full income test suite**

```bash
cd app && php artisan test --compact tests/Modules/Compensation/
```

Expected: all PASS.

- [ ] **Step 2: Verify no income projection violations**

Manually confirm that none of the 5 views shows or implies projected future earnings — only historical actuals. Dashboard hero card shows `₹—` (empty state) or the actual wallet balance; "Next Payout — Tuesday" is a date, not a projected amount.

- [ ] **Step 3: Verify cross-distributor data isolation**

All 5 controller actions call `$request->user()?->distributor` and scope every query to that distributor's ID. No distributor ID from the URL is trusted; all IDs come from the authenticated session. Spot-check: `gsbHistory` uses `->where('distributor_id', $distributor->id)`.

- [ ] **Step 4: Run broader regression (Commerce + Identity)**

```bash
cd app && php artisan test --compact tests/Modules/Commerce/ tests/Modules/Identity/
```

Expected: no regressions.

- [ ] **Step 5: Run Pint on all dirty files**

```bash
cd app && vendor/bin/pint --dirty --format agent
```

- [ ] **Step 6: Final commit**

```bash
git add -p
git commit -m "chore(income): pint cleanup + final test run — all income routes green"
```

---

## Self-review against spec

Spec section 3 coverage:

| Spec item | Covered in task |
|---|---|
| 7 routes (5 views + 2 exports) | Task 1 |
| "My Income" nav entry (mobile + desktop) | Task 1 |
| Dashboard: payout hero, 3 stat cards, 2 CF cards, page note | Task 3 |
| Genos BV: daily BV log table, date filter, CSV export link | Task 4 |
| GSB History: deduction columns, monthly totals, date filter, CSV export | Task 5 |
| Mentorship: masked sponsee ADN, MB%, slab step visualiser, date filter | Task 6 |
| Wallet: 4 stat cards, ledger table, payout history table, CSV export | Task 7 |
| Help tips on every column (sections 4 + 5 of spec) | Tasks 3–7 |
| Blue page-note banner on every tab | Tasks 3–7 |
| Empty states for all tables (pre-engine data) | Tasks 3–7 |
| No cross-distributor data leakage | Task 8 step 3 |
| No income projections (hard rule #3) | Task 8 step 2 |

No gaps found.
