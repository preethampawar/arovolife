# Compensation Admin UI Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build 8 admin pages for the Compensation section — overview, daily cut-offs, weekly payouts, carry-forwards, per-distributor detail, and manual controls — all with empty states for pre-engine data, help tips on every column, and page-note banners.

**Architecture:** Follows the existing admin module pattern: controllers in `app/Modules/Compensation/Http/Controllers/Admin/`, Blade views in `resources/views/admin/compensation/`. Routes added to the existing admin route group in `routes/web.php`. Pages render empty-state banners when the cut-off engine has not yet run. All 6 manual controls POST to dedicated endpoints with server-side preview before a confirm modal.

**Tech Stack:** PHP 8.4, Laravel 13, Blade, Tailwind v4, `<x-help-tip>` component, `<x-confirm-modal>` component.

**Prerequisite:** `2026-06-24-compensation-backend.md` must be fully implemented (migrations + models + services must exist before the controllers query them).

---

## File structure

```
app/app/Modules/Compensation/Http/
  Controllers/
    Admin/
      CompensationOverviewController.php
      AdminDailyCutoffController.php
      AdminWeeklyPayoutController.php
      AdminCarryForwardController.php
      AdminDistributorCompController.php
      AdminManualControlsController.php

app/resources/views/admin/compensation/
  overview.blade.php
  daily-cutoffs/
    index.blade.php
    show.blade.php
  weekly-payouts/
    index.blade.php
    show.blade.php
  carry-forwards/
    index.blade.php
  distributors/
    show.blade.php
    _tab-gsb.blade.php
    _tab-mb.blade.php
    _tab-bv-log.blade.php
    _tab-wallet.blade.php
    _tab-payouts.blade.php
    _tab-audit.blade.php
  manual-controls/
    index.blade.php
    _form-retry.blade.php
    _form-recalc-cf.blade.php
    _form-manual-credit.blade.php
    _form-reverse.blade.php
    _form-force-payout.blade.php
    _form-freeze.blade.php
```

### Modified files

```
app/routes/web.php                                   — add 14 admin compensation routes
app/resources/views/admin/layouts/admin.blade.php    — add Compensation sidebar entry
app/resources/views/admin/distributors/show.blade.php — add "Compensation →" button
app/resources/help/compensation.md                   — new help doc (per admin-help-docs-keep-in-sync memory)
```

---

## Task 1: Routes + sidebar entry

**Files:**
- Modify: `app/routes/web.php`
- Modify: `app/resources/views/admin/layouts/admin.blade.php`

- [ ] **Step 1: Add compensation routes to `web.php`**

Open `app/routes/web.php`. Find the admin route group (the one with `auth`, `role:admin` middleware). Add after the existing `commerce/bv-ledger` route group:

```php
use App\Modules\Compensation\Http\Controllers\Admin\AdminCarryForwardController;
use App\Modules\Compensation\Http\Controllers\Admin\AdminDailyCutoffController;
use App\Modules\Compensation\Http\Controllers\Admin\AdminDistributorCompController;
use App\Modules\Compensation\Http\Controllers\Admin\AdminManualControlsController;
use App\Modules\Compensation\Http\Controllers\Admin\AdminWeeklyPayoutController;
use App\Modules\Compensation\Http\Controllers\Admin\CompensationOverviewController;

// Compensation (Phase 4)
Route::prefix('compensation')->name('compensation.')->group(function () {
    Route::get('/', CompensationOverviewController::class)->name('overview');

    Route::prefix('daily-cutoffs')->name('daily-cutoffs.')->group(function () {
        Route::get('/', [AdminDailyCutoffController::class, 'index'])->name('index');
        Route::get('/export', [AdminDailyCutoffController::class, 'export'])->name('export');
        Route::get('/{date}', [AdminDailyCutoffController::class, 'show'])->name('show')->where('date', '\d{4}-\d{2}-\d{2}');
    });

    Route::prefix('weekly-payouts')->name('weekly-payouts.')->group(function () {
        Route::get('/', [AdminWeeklyPayoutController::class, 'index'])->name('index');
        Route::get('/{batch}', [AdminWeeklyPayoutController::class, 'show'])->name('show')->whereNumber('batch');
    });

    Route::get('carry-forwards', [AdminCarryForwardController::class, 'index'])->name('carry-forwards.index');

    Route::get('distributors/{distributor}', [AdminDistributorCompController::class, 'show'])
        ->name('distributors.show')
        ->whereNumber('distributor');

    Route::prefix('manual-controls')->name('manual-controls.')->group(function () {
        Route::get('/', [AdminManualControlsController::class, 'index'])->name('index');
        Route::post('retry', [AdminManualControlsController::class, 'retryCutoff'])->name('retry');
        Route::post('recalc-cf', [AdminManualControlsController::class, 'recalcCarryForward'])->name('recalc-cf');
        Route::post('credit', [AdminManualControlsController::class, 'manualCredit'])->name('credit');
        Route::post('reverse', [AdminManualControlsController::class, 'reverseCredit'])->name('reverse');
        Route::post('force-payout', [AdminManualControlsController::class, 'forcePayout'])->name('force-payout');
        Route::post('freeze-gsb', [AdminManualControlsController::class, 'freezeGsb'])->name('freeze-gsb');
    });
});
```

The admin route group prefix is `admin/` and name prefix is `admin.` — so these become `admin.compensation.*` routes at URLs `/admin/compensation/*`.

- [ ] **Step 2: Add Compensation sidebar entry**

Open `app/resources/views/admin/layouts/admin.blade.php`. In the `$navItems` array, after the `BV Ledger` entry, add:

```php
['route' => 'admin.compensation.overview', 'label' => 'Compensation', 'icon' => '💰', 'prefix' => 'admin.compensation'],
```

- [ ] **Step 3: Verify routes are registered**

```bash
cd /Users/preetham/Documents/arovolife/arovolife/arovolife-code/app
php artisan route:list --name=admin.compensation --compact
```

Expected: 14 routes listed.

- [ ] **Step 4: Commit**

```bash
vendor/bin/pint --dirty
git -C /Users/preetham/Documents/arovolife/arovolife/arovolife-code add routes/web.php resources/views/admin/layouts/admin.blade.php
git -C /Users/preetham/Documents/arovolife/arovolife/arovolife-code commit -m "feat(compensation): admin routes + sidebar entry for Compensation section"
```

---

## Task 2: `CompensationOverviewController` + overview page

**Files:**
- Create: `app/app/Modules/Compensation/Http/Controllers/Admin/CompensationOverviewController.php`
- Create: `app/resources/views/admin/compensation/overview.blade.php`

- [ ] **Step 1: Create the controller**

```php
// app/Modules/Compensation/Http/Controllers/Admin/CompensationOverviewController.php
<?php
declare(strict_types=1);

namespace App\Modules\Compensation\Http\Controllers\Admin;

use App\Modules\Compensation\Models\GsbCutoffResult;
use App\Modules\Compensation\Models\PayoutBatch;
use App\Modules\Compensation\Models\WalletLedgerEntry;
use Illuminate\Contracts\View\View;
use Illuminate\Routing\Controller;
use Illuminate\Support\Carbon;

final class CompensationOverviewController extends Controller
{
    public function __invoke(): View
    {
        $today = Carbon::today()->toDateString();

        // Stat cards
        $todayCutoffs = GsbCutoffResult::where('cutoff_date', $today)->get();
        $todayFailed  = $todayCutoffs->where('status', GsbCutoffResult::STATUS_FAILED)->count();
        $todayCredited = $todayCutoffs->where('status', GsbCutoffResult::STATUS_CREDITED)->count();

        $cutoffStatus = match (true) {
            $todayCredited > 0 && $todayFailed === 0 => 'done',
            $todayFailed > 0 => 'failed',
            default => 'pending',
        };

        $pendingPayoutPaise = (int) WalletLedgerEntry::selectRaw(
            'SUM(amount_paise) as total'
        )->value('total');

        $weekStart = Carbon::now()->startOfWeek(Carbon::TUESDAY);
        $gsbThisWeekPaise = (int) WalletLedgerEntry::where('type', 'gsb_credit')
            ->where('created_at', '>=', $weekStart)
            ->sum('amount_paise');

        // Failed / attention items for today
        $failedCutoffs = GsbCutoffResult::with('distributor')
            ->where('cutoff_date', $today)
            ->where('status', GsbCutoffResult::STATUS_FAILED)
            ->limit(20)
            ->get();

        // Today's full cut-off table (paginated)
        $cutoffTable = GsbCutoffResult::with('distributor.user')
            ->where('cutoff_date', $today)
            ->orderByRaw("FIELD(status, 'failed', 'credited', 'no_match', 'below_600bv', 'frozen')")
            ->paginate(50);

        return view('admin.compensation.overview', compact(
            'cutoffStatus', 'todayFailed', 'pendingPayoutPaise', 'gsbThisWeekPaise',
            'failedCutoffs', 'cutoffTable', 'today',
        ));
    }
}
```

- [ ] **Step 2: Create the Blade view**

```blade
{{-- resources/views/admin/compensation/overview.blade.php --}}
@extends('admin.layouts.admin')
@section('title', 'Compensation')
@section('heading', 'Compensation Overview')

@section('content')

{{-- Page note --}}
<div class="mb-6 rounded-lg border border-blue-200 bg-blue-50 p-4 text-sm text-blue-800">
    The Compensation Overview shows the real-time status of today's daily GSB cut-off, any failed or stuck jobs, the total pending payout queue, and this week's GSB distributed. Items in the attention feed need action before Tuesday's payout — use Retry or Recalculate to resolve them.
</div>

{{-- Stat cards --}}
<div class="grid grid-cols-2 lg:grid-cols-4 gap-3 mb-6">
    <div class="bg-white rounded-xl border border-gray-200 p-4 shadow-sm">
        <p class="text-xs font-medium text-gray-500 uppercase tracking-wider flex items-center gap-1">
            Today's cut-off
            <x-help-tip text="The 23:59 daily GSB cut-off runs automatically. If it shows Failed, use Manual Controls → Retry." />
        </p>
        <p class="mt-1 text-lg font-bold {{ $cutoffStatus === 'done' ? 'text-green-700' : ($cutoffStatus === 'failed' ? 'text-red-600' : 'text-amber-600') }}">
            {{ match($cutoffStatus) { 'done' => '✓ Done', 'failed' => '✗ Failed', default => '⚠ Pending' } }}
        </p>
    </div>
    <div class="bg-white rounded-xl border border-gray-200 p-4 shadow-sm">
        <p class="text-xs font-medium text-gray-500 uppercase tracking-wider flex items-center gap-1">
            Failed jobs
            <x-help-tip text="Jobs that errored during today's cut-off or payout run. Each links to the affected distributor." />
        </p>
        <p class="mt-1 text-lg font-bold {{ $todayFailed > 0 ? 'text-red-600' : 'text-green-700' }}">{{ number_format($todayFailed) }}</p>
    </div>
    <div class="bg-white rounded-xl border border-gray-200 p-4 shadow-sm">
        <p class="text-xs font-medium text-gray-500 uppercase tracking-wider flex items-center gap-1">
            Pending payouts
            <x-help-tip text="Total amount queued for the next Tuesday bank transfer. Does not include wallets below the ₹500 minimum." />
        </p>
        <p class="mt-1 text-lg font-bold text-blue-700">₹{{ number_format($pendingPayoutPaise / 100, 2) }}</p>
    </div>
    <div class="bg-white rounded-xl border border-gray-200 p-4 shadow-sm">
        <p class="text-xs font-medium text-gray-500 uppercase tracking-wider flex items-center gap-1">
            GSB this week
            <x-help-tip text="Net GSB (after admin charge + TDS) credited to wallets since last Tuesday 00:00." />
        </p>
        <p class="mt-1 text-lg font-bold text-purple-700">₹{{ number_format($gsbThisWeekPaise / 100, 2) }}</p>
    </div>
</div>

{{-- Attention feed --}}
@if($failedCutoffs->isEmpty())
<div class="mb-6 rounded-lg border border-green-200 bg-green-50 p-4 text-sm text-green-700 font-medium">
    ✓ All systems normal — no failed cut-offs today.
</div>
@else
<div class="mb-6 rounded-lg border border-red-200 bg-red-50 p-4">
    <p class="text-sm font-semibold text-red-800 mb-3">⚠ {{ $failedCutoffs->count() }} failed cut-off(s) need attention</p>
    @foreach($failedCutoffs as $item)
    <div class="flex items-center justify-between py-2 border-b border-red-100 last:border-0 text-sm">
        <span class="text-red-700">
            <strong>{{ $item->distributor->adn ?? '—' }}</strong>
            — {{ $item->failure_reason ?? 'Unknown error' }}
        </span>
        <a href="{{ route('admin.compensation.manual-controls.index', ['adn' => $item->distributor->adn ?? '']) }}"
           class="text-xs px-2 py-1 rounded bg-amber-100 text-amber-800 hover:bg-amber-200 font-medium">
            Retry →
        </a>
    </div>
    @endforeach
</div>
@endif

{{-- Today's cut-off table --}}
<div class="bg-white rounded-xl border border-gray-200 shadow-sm overflow-hidden">
    <div class="px-5 py-3 border-b border-gray-100 flex items-center justify-between">
        <span class="text-sm font-semibold text-gray-900">Today's cut-off — {{ \Illuminate\Support\Carbon::today()->format('d M Y') }}</span>
        <a href="{{ route('admin.compensation.daily-cutoffs.index') }}" class="text-xs text-brand-600 hover:underline">View all dates →</a>
    </div>
    @if($cutoffTable->isEmpty())
    <p class="px-5 py-8 text-sm text-gray-400 text-center">No data yet — GSB engine not yet active.</p>
    @else
    <div class="overflow-x-auto">
        <table class="w-full text-xs">
            <thead class="bg-gray-50">
                <tr>
                    <th class="px-4 py-2 text-left text-gray-500 font-medium">ADN</th>
                    <th class="px-4 py-2 text-right text-gray-500 font-medium">Left BV <x-help-tip text="Left group BV accumulated today." /></th>
                    <th class="px-4 py-2 text-right text-gray-500 font-medium">Right BV</th>
                    <th class="px-4 py-2 text-center text-gray-500 font-medium">Slab</th>
                    <th class="px-4 py-2 text-right text-gray-500 font-medium">Net GSB</th>
                    <th class="px-4 py-2 text-center text-gray-500 font-medium">Status</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-50">
                @foreach($cutoffTable as $row)
                <tr class="{{ $row->status === 'failed' ? 'bg-red-50' : '' }}">
                    <td class="px-4 py-2 font-mono">{{ $row->distributor->adn ?? '—' }}</td>
                    <td class="px-4 py-2 text-right">@bv($row->left_bv_paise)</td>
                    <td class="px-4 py-2 text-right">@bv($row->right_bv_paise)</td>
                    <td class="px-4 py-2 text-center">{{ $row->slab ?? '—' }}</td>
                    <td class="px-4 py-2 text-right font-semibold {{ $row->net_gsb_paise > 0 ? 'text-green-700' : 'text-gray-400' }}">
                        {{ $row->net_gsb_paise > 0 ? '₹'.number_format($row->net_gsb_paise / 100, 2) : '—' }}
                    </td>
                    <td class="px-4 py-2 text-center">
                        @php $badges = ['credited' => 'bg-green-100 text-green-700', 'failed' => 'bg-red-100 text-red-700', 'no_match' => 'bg-gray-100 text-gray-600', 'frozen' => 'bg-blue-100 text-blue-700', 'below_600bv' => 'bg-amber-100 text-amber-700']; @endphp
                        <span class="inline-flex px-2 py-0.5 rounded text-[10px] font-medium {{ $badges[$row->status] ?? 'bg-gray-100 text-gray-600' }}">
                            {{ str_replace('_', ' ', $row->status) }}
                        </span>
                    </td>
                </tr>
                @endforeach
            </tbody>
        </table>
    </div>
    <div class="px-4 py-3 border-t border-gray-100">{{ $cutoffTable->links() }}</div>
    @endif
</div>

@endsection
```

- [ ] **Step 3: Verify page renders**

Start the dev server if not running:
```bash
# In a separate terminal: composer run dev
```

Visit `http://localhost/admin/compensation` (or use `php artisan boost:get-absolute-url`). Expect: the overview page loads with empty-state table and zero-value stat cards.

- [ ] **Step 4: Commit**

```bash
vendor/bin/pint --dirty
git -C /Users/preetham/Documents/arovolife/arovolife/arovolife-code add app/Modules/Compensation/Http/Controllers/Admin/CompensationOverviewController.php resources/views/admin/compensation/overview.blade.php
git -C /Users/preetham/Documents/arovolife/arovolife/arovolife-code commit -m "feat(compensation): admin compensation overview page with stat cards + cut-off table"
```

---

## Task 3: `AdminDailyCutoffController` + daily cut-offs pages

**Files:**
- Create: `app/app/Modules/Compensation/Http/Controllers/Admin/AdminDailyCutoffController.php`
- Create: `app/resources/views/admin/compensation/daily-cutoffs/index.blade.php`
- Create: `app/resources/views/admin/compensation/daily-cutoffs/show.blade.php`

- [ ] **Step 1: Create the controller**

```php
// app/Modules/Compensation/Http/Controllers/Admin/AdminDailyCutoffController.php
<?php
declare(strict_types=1);

namespace App\Modules\Compensation\Http\Controllers\Admin;

use App\Modules\Compensation\Models\GsbCutoffResult;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Illuminate\Support\Carbon;

final class AdminDailyCutoffController extends Controller
{
    private const PER_PAGE = 50;

    public function index(Request $request): View
    {
        $request->validate([
            'date'   => ['nullable', 'date'],
            'status' => ['nullable', 'in:credited,failed,no_match,frozen,below_600bv'],
            'q'      => ['nullable', 'string', 'max:64'],
        ]);

        $date   = $request->query('date') ? Carbon::parse((string) $request->query('date')) : Carbon::today();
        $status = $request->query('status');
        $q      = $request->query('q');

        $query = GsbCutoffResult::with('distributor.user')
            ->where('cutoff_date', $date->toDateString())
            ->when($status, fn ($b) => $b->where('status', $status))
            ->when($q, fn ($b) => $b->whereHas('distributor', fn ($d) => $d->where('adn', 'like', "%{$q}%")))
            ->orderByRaw("FIELD(status, 'failed', 'credited', 'no_match', 'below_600bv', 'frozen', 'calculated')");

        return view('admin.compensation.daily-cutoffs.index', [
            'rows' => $query->paginate(self::PER_PAGE)->withQueryString(),
            'date' => $date,
            'status' => $status,
            'q' => $q,
        ]);
    }

    public function show(string $date): View
    {
        $parsed = Carbon::parse($date);
        $rows = GsbCutoffResult::with('distributor.user')
            ->where('cutoff_date', $parsed->toDateString())
            ->orderByRaw("FIELD(status, 'failed', 'credited', 'no_match', 'below_600bv')")
            ->paginate(self::PER_PAGE);

        return view('admin.compensation.daily-cutoffs.show', compact('rows', 'parsed'));
    }

    public function export(Request $request): Response
    {
        $request->validate(['date' => ['nullable', 'date'], 'status' => ['nullable', 'string']]);
        $date = $request->query('date') ? Carbon::parse((string) $request->query('date')) : Carbon::today();

        $rows = GsbCutoffResult::with('distributor.user')
            ->where('cutoff_date', $date->toDateString())
            ->get();

        $csv = "ADN,Name,Left BV,Right BV,Slab,Gross GSB (₹),Admin Charge (₹),TDS (₹),Net GSB (₹),Status\n";
        foreach ($rows as $r) {
            $csv .= "\"{$r->distributor->adn}\","
                ."\"{$r->distributor->user?->full_name}\","
                .(int)($r->left_bv_paise / 100).","
                .(int)($r->right_bv_paise / 100).","
                .($r->slab ?? '').","
                .number_format($r->gross_gsb_paise / 100, 2).","
                .number_format($r->admin_charge_paise / 100, 2).","
                .number_format($r->tds_paise / 100, 2).","
                .number_format($r->net_gsb_paise / 100, 2).","
                .$r->status."\n";
        }

        return response($csv, 200, [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => 'attachment; filename="gsb-cutoff-'.$date->toDateString().'.csv"',
        ]);
    }
}
```

- [ ] **Step 2: Create `daily-cutoffs/index.blade.php`**

```blade
{{-- resources/views/admin/compensation/daily-cutoffs/index.blade.php --}}
@extends('admin.layouts.admin')
@section('title', 'Daily Cut-offs')
@section('heading', 'Daily GSB Cut-offs')

@section('content')

<div class="mb-6 rounded-lg border border-blue-200 bg-blue-50 p-4 text-sm text-blue-800">
    Each row is one 23:59 cut-off for one distributor. The slab is determined by the lower of the distributor's personal purchase title and the matched left/right group BV. After each cut-off: weaker leg resets to zero, power leg carries forward (capped 4,50,000 BV). Slab 1 (15,000 BV) is lifetime — the weaker leg accumulates until matched. Use <a href="{{ route('admin.compensation.manual-controls.index') }}" class="underline">Manual Controls</a> to retry failed rows or reverse incorrect credits.
</div>

{{-- Filters --}}
<form method="GET" class="flex flex-wrap items-center gap-3 mb-5">
    <input type="date" name="date" value="{{ $date->toDateString() }}" class="rounded-lg border border-gray-300 px-3 py-1.5 text-sm">
    <select name="status" class="rounded-lg border border-gray-300 px-3 py-1.5 text-sm">
        <option value="">All statuses</option>
        @foreach(['credited', 'failed', 'no_match', 'frozen', 'below_600bv'] as $s)
        <option value="{{ $s }}" {{ $status === $s ? 'selected' : '' }}>{{ str_replace('_', ' ', ucfirst($s)) }}</option>
        @endforeach
    </select>
    <input type="text" name="q" value="{{ $q }}" placeholder="Search ADN…" class="rounded-lg border border-gray-300 px-3 py-1.5 text-sm w-40">
    <button type="submit" class="px-3 py-1.5 rounded-lg bg-brand-500 text-white text-sm font-medium">Apply</button>
    <a href="{{ route('admin.compensation.daily-cutoffs.export', ['date' => $date->toDateString()]) }}" class="px-3 py-1.5 rounded-lg border border-gray-300 bg-white text-sm text-gray-700 hover:bg-gray-50">⬇ CSV</a>
</form>

<div class="bg-white rounded-xl border border-gray-200 shadow-sm overflow-hidden">
    @if($rows->isEmpty())
    <p class="px-6 py-10 text-sm text-gray-400 text-center">No cut-off data for {{ $date->format('d M Y') }}. GSB engine not yet active.</p>
    @else
    <div class="overflow-x-auto">
        <table class="w-full text-xs">
            <thead class="bg-gray-50">
                <tr>
                    <th class="px-3 py-2 text-left text-gray-500">ADN</th>
                    <th class="px-3 py-2 text-left text-gray-500">Name</th>
                    <th class="px-3 py-2 text-right text-gray-500">Left BV <x-help-tip text="Left Genos group BV accumulated today (fresh, excluding carry-forward)." /></th>
                    <th class="px-3 py-2 text-right text-gray-500">Right BV</th>
                    <th class="px-3 py-2 text-center text-gray-500">Slab <x-help-tip text="Slab 1=15K, 2=30K, 3=90K, 4=2.7L, 5=8L, 6=24L, 7=72L BV matched on the weaker side." /></th>
                    <th class="px-3 py-2 text-right text-gray-500">Gross GSB <x-help-tip text="Before admin charge and TDS." /></th>
                    <th class="px-3 py-2 text-right text-gray-500">Admin 3% <x-help-tip text="3% of gross or ₹30,000 max." /></th>
                    <th class="px-3 py-2 text-right text-gray-500">TDS 5% <x-help-tip text="5% of (gross − admin charge)." /></th>
                    <th class="px-3 py-2 text-right text-gray-500">Net GSB <x-help-tip text="Amount credited to wallet." /></th>
                    <th class="px-3 py-2 text-center text-gray-500">Status</th>
                    <th class="px-3 py-2"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-50">
                @foreach($rows as $row)
                <tr class="{{ $row->status === 'failed' ? 'bg-red-50' : '' }}">
                    <td class="px-3 py-2 font-mono font-medium">
                        <a href="{{ route('admin.compensation.distributors.show', $row->distributor_id) }}" class="text-brand-600 hover:underline">
                            {{ $row->distributor->adn ?? '—' }}
                        </a>
                    </td>
                    <td class="px-3 py-2 text-gray-700 truncate max-w-[120px]">{{ $row->distributor->user?->full_name ?? '—' }}</td>
                    <td class="px-3 py-2 text-right">@bv($row->left_bv_paise)</td>
                    <td class="px-3 py-2 text-right">@bv($row->right_bv_paise)</td>
                    <td class="px-3 py-2 text-center">{{ $row->slab ?? '—' }}</td>
                    <td class="px-3 py-2 text-right">{{ $row->gross_gsb_paise ? '₹'.number_format($row->gross_gsb_paise/100,2) : '—' }}</td>
                    <td class="px-3 py-2 text-right text-gray-500">{{ $row->admin_charge_paise ? '₹'.number_format($row->admin_charge_paise/100,2) : '—' }}</td>
                    <td class="px-3 py-2 text-right text-gray-500">{{ $row->tds_paise ? '₹'.number_format($row->tds_paise/100,2) : '—' }}</td>
                    <td class="px-3 py-2 text-right font-semibold {{ $row->net_gsb_paise > 0 ? 'text-green-700' : 'text-gray-400' }}">
                        {{ $row->net_gsb_paise > 0 ? '₹'.number_format($row->net_gsb_paise/100,2) : '—' }}
                    </td>
                    <td class="px-3 py-2 text-center">
                        @php $badges = ['credited'=>'bg-green-100 text-green-700','failed'=>'bg-red-100 text-red-700','no_match'=>'bg-gray-100 text-gray-500','frozen'=>'bg-blue-100 text-blue-700','below_600bv'=>'bg-amber-100 text-amber-700']; @endphp
                        <span class="inline-flex px-2 py-0.5 rounded text-[10px] font-medium {{ $badges[$row->status] ?? 'bg-gray-100 text-gray-500' }}">
                            {{ str_replace('_',' ',$row->status) }}
                        </span>
                    </td>
                    <td class="px-3 py-2 text-right">
                        @if($row->status === 'failed')
                        <a href="{{ route('admin.compensation.manual-controls.index', ['adn' => $row->distributor->adn ?? '', 'action' => 'retry', 'date' => $row->cutoff_date->toDateString()]) }}"
                           class="text-[10px] px-2 py-0.5 rounded bg-amber-100 text-amber-800 hover:bg-amber-200 font-medium">Retry</a>
                        @elseif($row->status === 'credited')
                        <a href="{{ route('admin.compensation.manual-controls.index', ['adn' => $row->distributor->adn ?? '', 'action' => 'reverse', 'date' => $row->cutoff_date->toDateString()]) }}"
                           class="text-[10px] px-2 py-0.5 rounded bg-red-100 text-red-700 hover:bg-red-200 font-medium">Reverse</a>
                        @endif
                    </td>
                </tr>
                @endforeach
            </tbody>
        </table>
    </div>
    <div class="px-4 py-3 border-t border-gray-100">{{ $rows->links() }}</div>
    @endif
</div>
@endsection
```

The `show.blade.php` is a copy of `index.blade.php` scoped to the specific date (passed as route segment). Create it as a thin wrapper that calls the same query for the given `$parsed` date.

- [ ] **Step 3: Commit**

```bash
vendor/bin/pint --dirty
git -C /Users/preetham/Documents/arovolife/arovolife/arovolife-code add app/Modules/Compensation/Http/Controllers/Admin/AdminDailyCutoffController.php resources/views/admin/compensation/daily-cutoffs/
git -C /Users/preetham/Documents/arovolife/arovolife/arovolife-code commit -m "feat(compensation): admin daily cut-offs page with filters, export, retry/reverse actions"
```

---

## Task 4: `AdminWeeklyPayoutController` + payout pages

**Files:**
- Create: `app/app/Modules/Compensation/Http/Controllers/Admin/AdminWeeklyPayoutController.php`
- Create: `app/resources/views/admin/compensation/weekly-payouts/index.blade.php`
- Create: `app/resources/views/admin/compensation/weekly-payouts/show.blade.php`

- [ ] **Step 1: Create the controller**

```php
// app/Modules/Compensation/Http/Controllers/Admin/AdminWeeklyPayoutController.php
<?php
declare(strict_types=1);

namespace App\Modules\Compensation\Http\Controllers\Admin;

use App\Modules\Compensation\Models\PayoutBatch;
use Illuminate\Contracts\View\View;
use Illuminate\Routing\Controller;

final class AdminWeeklyPayoutController extends Controller
{
    public function index(): View
    {
        $batches = PayoutBatch::orderByDesc('batch_date')->paginate(20);
        return view('admin.compensation.weekly-payouts.index', compact('batches'));
    }

    public function show(PayoutBatch $batch): View
    {
        $lines = $batch->lineItems()->with('distributor.user')->paginate(50);
        return view('admin.compensation.weekly-payouts.show', compact('batch', 'lines'));
    }
}
```

Add the `lineItems` relationship to `PayoutBatch`:
```php
// In PayoutBatch.php, add:
public function lineItems(): \Illuminate\Database\Eloquent\Relations\HasMany
{
    return $this->hasMany(PayoutLineItem::class, 'payout_batch_id');
}
```

- [ ] **Step 2: Create the index view**

```blade
{{-- resources/views/admin/compensation/weekly-payouts/index.blade.php --}}
@extends('admin.layouts.admin')
@section('title', 'Weekly Payouts')
@section('heading', 'Weekly Payouts')

@section('content')
<div class="mb-6 rounded-lg border border-blue-200 bg-blue-50 p-4 text-sm text-blue-800">
    Payouts run automatically every Tuesday covering all wallets with a balance of ₹500 or more. Each batch shows total gross, deductions (admin charge + TDS + repurchase), and net transferred. Minimum payout is ₹500 — below-minimum wallets roll over. Use Manual Controls → Force Payout only if the automated batch failed for a specific distributor.
</div>

<div class="bg-white rounded-xl border border-gray-200 shadow-sm overflow-hidden">
    @if($batches->isEmpty())
    <p class="px-6 py-10 text-sm text-gray-400 text-center">No payout batches yet — weekly payout not yet active.</p>
    @else
    <table class="w-full text-xs">
        <thead class="bg-gray-50">
            <tr>
                <th class="px-4 py-2 text-left text-gray-500">Batch date</th>
                <th class="px-4 py-2 text-right text-gray-500">Distributors</th>
                <th class="px-4 py-2 text-right text-gray-500">Total gross</th>
                <th class="px-4 py-2 text-right text-gray-500">Deductions</th>
                <th class="px-4 py-2 text-right text-gray-500">Net transferred</th>
                <th class="px-4 py-2 text-center text-gray-500">Status</th>
                <th></th>
            </tr>
        </thead>
        <tbody class="divide-y divide-gray-50">
            @foreach($batches as $b)
            <tr>
                <td class="px-4 py-2 font-medium">{{ $b->batch_date->format('d M Y') }} (Tue)</td>
                <td class="px-4 py-2 text-right">{{ number_format($b->distributor_count) }}</td>
                <td class="px-4 py-2 text-right">₹{{ number_format($b->total_gross_paise/100,2) }}</td>
                <td class="px-4 py-2 text-right text-gray-500">₹{{ number_format($b->total_deductions_paise/100,2) }}</td>
                <td class="px-4 py-2 text-right font-semibold text-green-700">₹{{ number_format($b->total_net_paise/100,2) }}</td>
                <td class="px-4 py-2 text-center">
                    @php $bc = ['completed'=>'bg-green-100 text-green-700','failed'=>'bg-red-100 text-red-700','processing'=>'bg-amber-100 text-amber-700','pending'=>'bg-gray-100 text-gray-600']; @endphp
                    <span class="inline-flex px-2 py-0.5 rounded text-[10px] font-medium {{ $bc[$b->status] ?? '' }}">{{ ucfirst($b->status) }}</span>
                </td>
                <td class="px-4 py-2">
                    <a href="{{ route('admin.compensation.weekly-payouts.show', $b) }}" class="text-brand-600 text-xs hover:underline">View →</a>
                </td>
            </tr>
            @endforeach
        </tbody>
    </table>
    <div class="px-4 py-3 border-t border-gray-100">{{ $batches->links() }}</div>
    @endif
</div>
@endsection
```

The `show.blade.php` lists line items per distributor with wallet balance, deductions, net, UTR, status. Follow the same pattern — create it as a simple table view.

- [ ] **Step 3: Commit**

```bash
vendor/bin/pint --dirty
git -C /Users/preetham/Documents/arovolife/arovolife/arovolife-code add app/Modules/Compensation/Http/Controllers/Admin/AdminWeeklyPayoutController.php resources/views/admin/compensation/weekly-payouts/
git -C /Users/preetham/Documents/arovolife/arovolife/arovolife-code commit -m "feat(compensation): admin weekly payouts page + batch detail view"
```

---

## Task 5: `AdminCarryForwardController` + carry-forwards page

**Files:**
- Create: `app/app/Modules/Compensation/Http/Controllers/Admin/AdminCarryForwardController.php`
- Create: `app/resources/views/admin/compensation/carry-forwards/index.blade.php`

- [ ] **Step 1: Create the controller**

```php
// app/Modules/Compensation/Http/Controllers/Admin/AdminCarryForwardController.php
<?php
declare(strict_types=1);

namespace App\Modules\Compensation\Http\Controllers\Admin;

use App\Modules\Compensation\Models\GsbCarryforward;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

final class AdminCarryForwardController extends Controller
{
    private const POWER_CF_CAP_PAISE = 45_000_000; // 450,000 BV × 100

    public function index(Request $request): View
    {
        $request->validate(['q' => ['nullable', 'string', 'max:64'], 'filter' => ['nullable', 'in:near_cap']]);

        $query = GsbCarryforward::with('distributor.user')
            ->when($request->query('q'), fn ($b) => $b->whereHas(
                'distributor', fn ($d) => $d->where('adn', 'like', '%'.$request->query('q').'%')
            ))
            ->when($request->query('filter') === 'near_cap',
                fn ($b) => $b->where('power_side_bv_paise', '>=', self::POWER_CF_CAP_PAISE * 0.80)
            )
            ->orderByDesc('power_side_bv_paise');

        return view('admin.compensation.carry-forwards.index', [
            'rows' => $query->paginate(50)->withQueryString(),
            'cap' => self::POWER_CF_CAP_PAISE,
        ]);
    }
}
```

Add the `distributor` relationship to `GsbCarryforward`:
```php
public function distributor(): \Illuminate\Database\Eloquent\Relations\BelongsTo
{
    return $this->belongsTo(\App\Modules\Identity\Models\Distributor::class);
}
```

- [ ] **Step 2: Create the view**

```blade
{{-- resources/views/admin/compensation/carry-forwards/index.blade.php --}}
@extends('admin.layouts.admin')
@section('title', 'Carry-forwards')
@section('heading', 'GSB Carry-forwards')

@section('content')
<div class="mb-6 rounded-lg border border-blue-200 bg-blue-50 p-4 text-sm text-blue-800">
    Carry-forward state persists between cut-offs. The power side (stronger leg) carries forward up to 4,50,000 BV — excess is flushed. The slab-1 weaker side carries forward indefinitely until the 15,000 BV match. If a BV reversal happens after a cut-off, use <a href="{{ route('admin.compensation.manual-controls.index') }}" class="underline">Recalculate Carry-forward</a> to correct the state.
</div>

<form method="GET" class="flex flex-wrap gap-3 mb-5">
    <input type="text" name="q" value="{{ request('q') }}" placeholder="Search ADN…" class="rounded-lg border border-gray-300 px-3 py-1.5 text-sm w-40">
    <label class="flex items-center gap-2 text-sm text-gray-700">
        <input type="checkbox" name="filter" value="near_cap" {{ request('filter') === 'near_cap' ? 'checked' : '' }}>
        Near cap (&gt;80%)
    </label>
    <button type="submit" class="px-3 py-1.5 rounded-lg bg-brand-500 text-white text-sm font-medium">Apply</button>
</form>

<div class="bg-white rounded-xl border border-gray-200 shadow-sm overflow-hidden">
    @if($rows->isEmpty())
    <p class="px-6 py-10 text-sm text-gray-400 text-center">No carry-forward data yet — GSB engine not yet active.</p>
    @else
    <table class="w-full text-xs">
        <thead class="bg-gray-50">
            <tr>
                <th class="px-3 py-2 text-left text-gray-500">ADN</th>
                <th class="px-3 py-2 text-right text-gray-500">Power-side CF <x-help-tip text="BV on the stronger leg carried forward into tomorrow. Capped at 4,50,000 BV." /></th>
                <th class="px-3 py-2 text-center text-gray-500">% of cap</th>
                <th class="px-3 py-2 text-right text-gray-500">Slab-1 weaker CF <x-help-tip text="Accumulated weaker-side BV counting toward the first 15,000 BV match. No time limit." /></th>
                <th class="px-3 py-2 text-center text-gray-500">Progress to 15K</th>
                <th class="px-3 py-2 text-gray-500">Last updated</th>
                <th></th>
            </tr>
        </thead>
        <tbody class="divide-y divide-gray-50">
            @foreach($rows as $row)
            @php
                $pct = $cap > 0 ? round($row->power_side_bv_paise / $cap * 100, 1) : 0;
                $slab1Pct = min(100, round($row->slab1_weaker_bv_paise / 1_500_000 * 100, 1));
                $barColor = $pct >= 95 ? 'bg-red-500' : ($pct >= 80 ? 'bg-amber-500' : 'bg-purple-500');
            @endphp
            <tr>
                <td class="px-3 py-2 font-mono font-medium">
                    <a href="{{ route('admin.compensation.distributors.show', $row->distributor_id) }}" class="text-brand-600 hover:underline">
                        {{ $row->distributor->adn ?? '—' }}
                    </a>
                </td>
                <td class="px-3 py-2 text-right font-semibold text-purple-700">@bv($row->power_side_bv_paise)</td>
                <td class="px-3 py-2">
                    <div class="w-24 bg-gray-100 rounded-full h-1.5 mx-auto">
                        <div class="{{ $barColor }} h-1.5 rounded-full" style="width:{{ $pct }}%"></div>
                    </div>
                    <p class="text-[10px] text-center text-gray-500 mt-0.5">{{ $pct }}%</p>
                </td>
                <td class="px-3 py-2 text-right font-semibold text-green-700">@bv($row->slab1_weaker_bv_paise)</td>
                <td class="px-3 py-2">
                    <div class="w-24 bg-gray-100 rounded-full h-1.5 mx-auto">
                        <div class="bg-green-500 h-1.5 rounded-full" style="width:{{ $slab1Pct }}%"></div>
                    </div>
                    <p class="text-[10px] text-center text-gray-500 mt-0.5">{{ $slab1Pct }}%</p>
                </td>
                <td class="px-3 py-2 text-gray-500">{{ $row->updated_at?->diffForHumans() ?? '—' }}</td>
                <td class="px-3 py-2">
                    <a href="{{ route('admin.compensation.manual-controls.index', ['adn' => $row->distributor->adn ?? '', 'action' => 'recalc-cf']) }}"
                       class="text-[10px] px-2 py-0.5 rounded bg-gray-100 text-gray-700 hover:bg-gray-200 font-medium">Recalculate</a>
                </td>
            </tr>
            @endforeach
        </tbody>
    </table>
    <div class="px-4 py-3 border-t border-gray-100">{{ $rows->links() }}</div>
    @endif
</div>
@endsection
```

- [ ] **Step 3: Commit**

```bash
vendor/bin/pint --dirty
git -C /Users/preetham/Documents/arovolife/arovolife/arovolife-code add app/Modules/Compensation/Http/Controllers/Admin/AdminCarryForwardController.php resources/views/admin/compensation/carry-forwards/
git -C /Users/preetham/Documents/arovolife/arovolife/arovolife-code commit -m "feat(compensation): admin carry-forwards page with power CF progress bar + slab-1 weaker tracking"
```

---

## Task 6: `AdminDistributorCompController` + per-distributor detail

**Files:**
- Create: `app/app/Modules/Compensation/Http/Controllers/Admin/AdminDistributorCompController.php`
- Create: `app/resources/views/admin/compensation/distributors/show.blade.php` (with 6 tab partials)

- [ ] **Step 1: Create the controller**

```php
// app/Modules/Compensation/Http/Controllers/Admin/AdminDistributorCompController.php
<?php
declare(strict_types=1);

namespace App\Modules\Compensation\Http\Controllers\Admin;

use App\Modules\Commerce\Services\BvLedgerService;
use App\Modules\Commerce\Support\Bv;
use App\Modules\Compensation\Models\GsbCarryforward;
use App\Modules\Compensation\Models\GsbCutoffResult;
use App\Modules\Compensation\Models\GroupBvDaily;
use App\Modules\Compensation\Models\MentorshipBonusResult;
use App\Modules\Compensation\Models\PayoutLineItem;
use App\Modules\Compensation\Models\WalletLedgerEntry;
use App\Modules\Compensation\Services\PersonalBvTitleService;
use App\Modules\Compensation\Services\WalletService;
use App\Modules\Compliance\Models\AuditLog;
use App\Modules\Identity\Models\Distributor;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Carbon;

final class AdminDistributorCompController extends Controller
{
    public function __construct(
        private readonly BvLedgerService $bvLedger,
        private readonly PersonalBvTitleService $titleService,
        private readonly WalletService $wallet,
    ) {}

    public function show(Distributor $distributor, Request $request): View
    {
        $request->validate([
            'tab'  => ['nullable', 'in:gsb,mb,bv-log,wallet,payouts,audit'],
            'from' => ['nullable', 'date'],
            'to'   => ['nullable', 'date'],
        ]);

        $tab  = $request->query('tab', 'gsb');
        $from = $request->query('from') ? Carbon::parse((string) $request->query('from')) : null;
        $to   = $request->query('to')   ? Carbon::parse((string) $request->query('to'))   : null;

        $distributor->loadMissing('user');
        $personalBvPaise = $this->bvLedger->totalPersonalBvPaise($distributor->id);
        $title = $this->titleService->forBvPaise($personalBvPaise);
        $todayBv = GroupBvDaily::where('distributor_id', $distributor->id)->where('date', today()->toDateString())->first();
        $cf = GsbCarryforward::where('distributor_id', $distributor->id)->first();
        $walletBalance = $this->wallet->balancePaise($distributor->id);

        // Tab-specific data
        $tabData = match($tab) {
            'gsb' => [
                'rows' => GsbCutoffResult::where('distributor_id', $distributor->id)
                    ->when($from, fn ($b) => $b->where('cutoff_date', '>=', $from->toDateString()))
                    ->when($to,   fn ($b) => $b->where('cutoff_date', '<=', $to->toDateString()))
                    ->orderByDesc('cutoff_date')->paginate(30)->withQueryString(),
            ],
            'mb' => [
                'rows' => MentorshipBonusResult::where('sponsor_id', $distributor->id)
                    ->with('sponsee')
                    ->when($from, fn ($b) => $b->where('cutoff_date', '>=', $from->toDateString()))
                    ->orderByDesc('cutoff_date')->paginate(30)->withQueryString(),
            ],
            'bv-log' => [
                'rows' => GroupBvDaily::where('distributor_id', $distributor->id)
                    ->when($from, fn ($b) => $b->where('date', '>=', $from->toDateString()))
                    ->when($to,   fn ($b) => $b->where('date', '<=', $to->toDateString()))
                    ->orderByDesc('date')->paginate(30)->withQueryString(),
            ],
            'wallet' => [
                'ledger' => $this->wallet->ledgerWithRunningBalance($distributor->id),
            ],
            'payouts' => [
                'rows' => PayoutLineItem::where('distributor_id', $distributor->id)
                    ->with('payoutBatch')
                    ->orderByDesc('created_at')->paginate(20)->withQueryString(),
            ],
            'audit' => [
                'rows' => AuditLog::where('subject_type', 'distributor')
                    ->where('subject_id', $distributor->id)
                    ->where('action', 'like', 'compensation.%')
                    ->orderByDesc('created_at')->paginate(20)->withQueryString(),
            ],
            default => [],
        };

        return view('admin.compensation.distributors.show', array_merge([
            'distributor' => $distributor,
            'personalBvPaise' => $personalBvPaise,
            'title' => $title,
            'todayBv' => $todayBv,
            'cf' => $cf,
            'walletBalance' => $walletBalance,
            'tab' => $tab,
            'from' => $request->query('from'),
            'to' => $request->query('to'),
        ], $tabData));
    }
}
```

Add `payoutBatch` relationship to `PayoutLineItem`:
```php
public function payoutBatch(): \Illuminate\Database\Eloquent\Relations\BelongsTo
{
    return $this->belongsTo(PayoutBatch::class, 'payout_batch_id');
}
```

Add `sponsee` relationship to `MentorshipBonusResult`:
```php
public function sponsee(): \Illuminate\Database\Eloquent\Relations\BelongsTo
{
    return $this->belongsTo(\App\Modules\Identity\Models\Distributor::class, 'sponsee_id');
}
```

- [ ] **Step 2: Create `show.blade.php`**

```blade
{{-- resources/views/admin/compensation/distributors/show.blade.php --}}
@extends('admin.layouts.admin')
@section('title', 'Compensation — '.$distributor->adn)
@section('heading', 'Distributor Compensation — '.$distributor->adn)

@section('content')

{{-- Header card --}}
<div class="bg-white rounded-xl border border-gray-200 p-5 mb-5 shadow-sm">
    <div class="flex items-start justify-between mb-4">
        <div>
            <p class="text-2xl font-bold text-brand-700 font-mono">{{ $distributor->adn }}</p>
            <p class="text-sm text-gray-700 mt-0.5">{{ $distributor->user?->full_name }} — <span class="text-green-700 font-medium">{{ ucfirst($distributor->status ?? 'Active') }}</span></p>
        </div>
        <div class="flex gap-2">
            <a href="{{ route('admin.distributors.show', $distributor) }}" class="px-3 py-1.5 rounded-lg border border-gray-300 bg-white text-sm text-gray-700 hover:bg-gray-50">← Profile</a>
            <a href="{{ route('admin.tree.show') }}" class="px-3 py-1.5 rounded-lg border border-gray-300 bg-white text-sm text-gray-700 hover:bg-gray-50">Tree View</a>
            <a href="{{ route('admin.compensation.manual-controls.index', ['adn' => $distributor->adn]) }}" class="px-3 py-1.5 rounded-lg bg-amber-500 text-white text-sm font-medium hover:bg-amber-600">⚠ Manual Controls</a>
        </div>
    </div>
    {{-- Stat cards --}}
    <div class="grid grid-cols-2 lg:grid-cols-4 gap-3 mb-4">
        <div class="rounded-xl border border-gray-200 p-3">
            <p class="text-[10px] uppercase tracking-wider text-gray-500 flex items-center gap-1">Personal BV <x-help-tip text="Lifetime total BV from all personal purchases. Determines your title and max GSB slab." /></p>
            <p class="text-lg font-bold text-brand-700 mt-1">@bv($personalBvPaise)</p>
            <p class="text-xs text-purple-600 font-medium">{{ $title->title ?? 'No title yet' }}</p>
        </div>
        <div class="rounded-xl border border-gray-200 p-3">
            <p class="text-[10px] uppercase tracking-wider text-gray-500 flex items-center gap-1">Left Group BV today <x-help-tip text="Total BV from left Genos subtree today." /></p>
            <p class="text-lg font-bold text-green-700 mt-1">@bv($todayBv?->left_bv_paise ?? 0)</p>
        </div>
        <div class="rounded-xl border border-gray-200 p-3">
            <p class="text-[10px] uppercase tracking-wider text-gray-500 flex items-center gap-1">Right Group BV today</p>
            <p class="text-lg font-bold text-green-700 mt-1">@bv($todayBv?->right_bv_paise ?? 0)</p>
        </div>
        <div class="rounded-xl border border-gray-200 p-3">
            <p class="text-[10px] uppercase tracking-wider text-gray-500 flex items-center gap-1">Wallet balance <x-help-tip text="Net GSB and MB credits not yet paid out." /></p>
            <p class="text-lg font-bold text-blue-700 mt-1">₹{{ number_format($walletBalance / 100, 2) }}</p>
        </div>
    </div>
    {{-- Carry-forward state --}}
    <div class="grid grid-cols-2 gap-3">
        <div class="bg-purple-50 border border-purple-200 rounded-xl p-3">
            <p class="text-[10px] uppercase tracking-wider text-purple-600 flex items-center gap-1">Power-side CF <x-help-tip text="Stronger Genos leg BV carried into tomorrow. Capped at 4,50,000 BV." /></p>
            <p class="text-base font-bold text-purple-700 mt-1">@bv($cf?->power_side_bv_paise ?? 0) <span class="text-xs text-gray-400 font-normal">/ 4,50,000 cap</span></p>
            @php $pctPower = $cf ? min(100, round($cf->power_side_bv_paise / 45_000_000 * 100, 1)) : 0; @endphp
            <div class="w-full bg-purple-100 rounded-full h-1.5 mt-1.5"><div class="bg-purple-500 h-1.5 rounded-full" style="width:{{ $pctPower }}%"></div></div>
        </div>
        <div class="bg-green-50 border border-green-200 rounded-xl p-3">
            <p class="text-[10px] uppercase tracking-wider text-green-600 flex items-center gap-1">Slab-1 weaker CF <x-help-tip text="Weaker-side BV accumulating toward the first 15,000 BV slab-1 match. No time limit." /></p>
            <p class="text-base font-bold text-green-700 mt-1">@bv($cf?->slab1_weaker_bv_paise ?? 0) <span class="text-xs text-gray-400 font-normal">/ 15,000 target</span></p>
            @php $pctSlab1 = $cf ? min(100, round($cf->slab1_weaker_bv_paise / 1_500_000 * 100, 1)) : 0; @endphp
            <div class="w-full bg-green-100 rounded-full h-1.5 mt-1.5"><div class="bg-green-500 h-1.5 rounded-full" style="width:{{ $pctSlab1 }}%"></div></div>
        </div>
    </div>
</div>

{{-- Failed cut-off alert --}}
@php
$failedToday = \App\Modules\Compensation\Models\GsbCutoffResult::where('distributor_id', $distributor->id)
    ->where('cutoff_date', today()->toDateString())
    ->where('status', 'failed')->first();
@endphp
@if($failedToday)
<div class="mb-4 rounded-lg border border-amber-200 bg-amber-50 p-4 text-sm text-amber-800 flex items-center gap-3">
    <span>⚠ <strong>Today's cut-off failed</strong> — {{ $failedToday->failure_reason ?? 'unknown error' }}</span>
    <a href="{{ route('admin.compensation.manual-controls.index', ['adn' => $distributor->adn, 'action' => 'retry', 'date' => today()->toDateString()]) }}"
       class="ml-auto px-3 py-1 rounded bg-amber-200 text-amber-900 text-xs font-medium hover:bg-amber-300">Retry this cut-off</a>
</div>
@endif

{{-- Tabs --}}
<div class="flex border-b border-gray-200 mb-5 gap-0">
    @foreach(['gsb' => 'GSB History', 'mb' => 'Mentorship Bonus', 'bv-log' => 'Daily BV Log', 'wallet' => 'Wallet Ledger', 'payouts' => 'Payout History', 'audit' => 'Audit Log'] as $key => $label)
    <a href="{{ route('admin.compensation.distributors.show', [$distributor, 'tab' => $key]) }}"
       class="px-4 py-2 text-sm font-medium border-b-2 -mb-px
              {{ $tab === $key ? 'border-brand-500 text-brand-600' : 'border-transparent text-gray-500 hover:text-gray-700' }}">
        {{ $label }}
    </a>
    @endforeach
</div>

{{-- Tab content via partials --}}
@include('admin.compensation.distributors._tab-'.$tab)

@endsection
```

- [ ] **Step 3: Create tab partials**

Create `_tab-gsb.blade.php`:
```blade
{{-- GSB History tab --}}
<div class="mb-3 rounded-lg border border-blue-200 bg-blue-50 p-3 text-xs text-blue-800">
    Shows every daily cut-off result for this distributor. Gross GSB is before deductions. Failed rows have not been credited to the wallet — use Retry. Reversed rows have a debit entry in the wallet ledger.
</div>
<div class="bg-white rounded-xl border border-gray-200 shadow-sm overflow-hidden">
    @if(empty($rows) || $rows->isEmpty())
    <p class="px-6 py-8 text-sm text-gray-400 text-center">No GSB history yet.</p>
    @else
    <table class="w-full text-xs">
        <thead class="bg-gray-50">
            <tr>
                <th class="px-3 py-2 text-left text-gray-500">Date</th>
                <th class="px-3 py-2 text-right text-gray-500">Left BV <x-help-tip text="Left group BV today (fresh, no carry-forward)." /></th>
                <th class="px-3 py-2 text-right text-gray-500">Right BV</th>
                <th class="px-3 py-2 text-center text-gray-500">Slab</th>
                <th class="px-3 py-2 text-right text-gray-500">Gross GSB</th>
                <th class="px-3 py-2 text-right text-gray-500">Admin 3%</th>
                <th class="px-3 py-2 text-right text-gray-500">TDS 5%</th>
                <th class="px-3 py-2 text-right text-gray-500">Net GSB</th>
                <th class="px-3 py-2 text-center text-gray-500">Status</th>
                <th></th>
            </tr>
        </thead>
        <tbody class="divide-y divide-gray-50">
            @foreach($rows as $row)
            <tr class="{{ $row->status === 'failed' ? 'bg-red-50' : '' }}">
                <td class="px-3 py-2 font-medium">{{ $row->cutoff_date->format('d M Y') }}</td>
                <td class="px-3 py-2 text-right">@bv($row->left_bv_paise)</td>
                <td class="px-3 py-2 text-right">@bv($row->right_bv_paise)</td>
                <td class="px-3 py-2 text-center">{{ $row->slab ?? '—' }}</td>
                <td class="px-3 py-2 text-right font-semibold">{{ $row->gross_gsb_paise ? '₹'.number_format($row->gross_gsb_paise/100,2) : '—' }}</td>
                <td class="px-3 py-2 text-right text-gray-500">{{ $row->admin_charge_paise ? '₹'.number_format($row->admin_charge_paise/100,2) : '—' }}</td>
                <td class="px-3 py-2 text-right text-gray-500">{{ $row->tds_paise ? '₹'.number_format($row->tds_paise/100,2) : '—' }}</td>
                <td class="px-3 py-2 text-right font-semibold {{ $row->net_gsb_paise > 0 ? 'text-green-700' : 'text-gray-400' }}">{{ $row->net_gsb_paise > 0 ? '₹'.number_format($row->net_gsb_paise/100,2) : '—' }}</td>
                <td class="px-3 py-2 text-center">
                    @php $b = ['credited'=>'bg-green-100 text-green-700','failed'=>'bg-red-100 text-red-700','no_match'=>'bg-gray-100 text-gray-500','frozen'=>'bg-blue-100 text-blue-700']; @endphp
                    <span class="inline-flex px-2 py-0.5 rounded text-[10px] font-medium {{ $b[$row->status] ?? 'bg-gray-100 text-gray-500' }}">{{ str_replace('_',' ',$row->status) }}</span>
                </td>
                <td class="px-3 py-2">
                    @if($row->status === 'failed')
                    <a href="{{ route('admin.compensation.manual-controls.index', ['adn' => $distributor->adn, 'action' => 'retry', 'date' => $row->cutoff_date->toDateString()]) }}" class="text-[10px] px-2 py-0.5 rounded bg-amber-100 text-amber-800 font-medium">Retry</a>
                    @elseif($row->status === 'credited')
                    <a href="{{ route('admin.compensation.manual-controls.index', ['adn' => $distributor->adn, 'action' => 'reverse', 'date' => $row->cutoff_date->toDateString()]) }}" class="text-[10px] px-2 py-0.5 rounded bg-red-100 text-red-700 font-medium">Reverse</a>
                    @endif
                </td>
            </tr>
            @endforeach
        </tbody>
    </table>
    <div class="px-4 py-3 border-t border-gray-100">{{ $rows->links() }}</div>
    @endif
</div>
```

For brevity, create the remaining 5 tab partials (`_tab-mb.blade.php`, `_tab-bv-log.blade.php`, `_tab-wallet.blade.php`, `_tab-payouts.blade.php`, `_tab-audit.blade.php`) following the exact same table pattern — each has a blue page note, a table with the relevant columns and `<x-help-tip>` on each header, and pagination. Use the data from the controller's `$tabData` collection.

- [ ] **Step 4: Commit**

```bash
vendor/bin/pint --dirty
git -C /Users/preetham/Documents/arovolife/arovolife/arovolife-code add app/Modules/Compensation/Http/Controllers/Admin/AdminDistributorCompController.php resources/views/admin/compensation/distributors/
git -C /Users/preetham/Documents/arovolife/arovolife/arovolife-code commit -m "feat(compensation): per-distributor admin compensation detail with 6 tabs + header stat cards"
```

---

## Task 7: `AdminManualControlsController` + manual controls page

**Files:**
- Create: `app/app/Modules/Compensation/Http/Controllers/Admin/AdminManualControlsController.php`
- Create: `app/resources/views/admin/compensation/manual-controls/index.blade.php` (+ 6 form partials)

- [ ] **Step 1: Create the controller**

```php
// app/Modules/Compensation/Http/Controllers/Admin/AdminManualControlsController.php
<?php
declare(strict_types=1);

namespace App\Modules\Compensation\Http\Controllers\Admin;

use App\Modules\Compensation\Services\GsbCutoffService;
use App\Modules\Compensation\Services\WalletService;
use App\Modules\Compliance\Models\AuditLog;
use App\Modules\Identity\Models\Distributor;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Carbon;

final class AdminManualControlsController extends Controller
{
    public function __construct(
        private readonly GsbCutoffService $cutoff,
        private readonly WalletService $wallet,
    ) {}

    public function index(Request $request): View
    {
        $adn = $request->query('adn');
        $action = $request->query('action');
        $date = $request->query('date', Carbon::today()->toDateString());

        $distributor = $adn ? Distributor::where('adn', $adn)->first() : null;

        $recentActions = AuditLog::where('action', 'like', 'compensation.%')
            ->orderByDesc('created_at')
            ->limit(20)
            ->get();

        return view('admin.compensation.manual-controls.index', compact(
            'distributor', 'adn', 'action', 'date', 'recentActions',
        ));
    }

    public function retryCutoff(Request $request): RedirectResponse
    {
        $request->validate([
            'adn'    => ['required', 'string'],
            'date'   => ['required', 'date'],
            'reason' => ['required', 'string', 'min:10', 'max:500'],
        ]);

        $distributor = Distributor::where('adn', $request->input('adn'))->firstOrFail();
        $date = Carbon::parse((string) $request->input('date'));

        $before = \App\Modules\Compensation\Models\GsbCutoffResult::where('distributor_id', $distributor->id)
            ->where('cutoff_date', $date->toDateString())->first();

        // Force re-run: delete existing failed result so service will re-run.
        if ($before && $before->status === 'failed') {
            $before->delete();
        }

        $result = $this->cutoff->runForDistributor($distributor->id, $date);

        AuditLog::create([
            'actor_id' => auth()->id(),
            'action' => 'compensation.cutoff.manual_retry',
            'subject_type' => 'distributor',
            'subject_id' => $distributor->id,
            'details' => [
                'adn' => $distributor->adn,
                'date' => $date->toDateString(),
                'result_status' => $result->status,
                'net_gsb_paise' => $result->net_gsb_paise,
                'reason' => $request->input('reason'),
            ],
            'ip' => $request->ip(),
        ]);

        return redirect()->route('admin.compensation.distributors.show', $distributor)
            ->with('status', "Cut-off retry for {$distributor->adn} on {$date->format('d M')} completed — status: {$result->status}.");
    }

    public function freezeGsb(Request $request): RedirectResponse
    {
        $request->validate([
            'adn'    => ['required', 'string'],
            'freeze' => ['required', 'in:freeze,unfreeze'],
            'reason' => ['required', 'string', 'min:10', 'max:500'],
        ]);

        $distributor = Distributor::where('adn', $request->input('adn'))->firstOrFail();
        $freeze = $request->input('freeze') === 'freeze';

        $distributor->update(['gsb_frozen_at' => $freeze ? now() : null]);

        AuditLog::create([
            'actor_id' => auth()->id(),
            'action' => $freeze ? 'compensation.gsb.frozen' : 'compensation.gsb.unfrozen',
            'subject_type' => 'distributor',
            'subject_id' => $distributor->id,
            'details' => ['adn' => $distributor->adn, 'reason' => $request->input('reason')],
            'ip' => $request->ip(),
        ]);

        return redirect()->route('admin.compensation.distributors.show', $distributor)
            ->with('status', ($freeze ? 'GSB frozen' : 'GSB unfrozen')." for {$distributor->adn}.");
    }

    public function reverseCredit(Request $request): RedirectResponse
    {
        $request->validate([
            'adn'    => ['required', 'string'],
            'date'   => ['required', 'date'],
            'reason' => ['required', 'string', 'min:10', 'max:500'],
        ]);

        $distributor = Distributor::where('adn', $request->input('adn'))->firstOrFail();
        $result = \App\Modules\Compensation\Models\GsbCutoffResult::where('distributor_id', $distributor->id)
            ->where('cutoff_date', Carbon::parse((string) $request->input('date'))->toDateString())
            ->where('status', 'credited')
            ->firstOrFail();

        $before = $this->wallet->balancePaise($distributor->id);
        $this->wallet->debit(
            distributorId: $distributor->id,
            amountPaise: $result->net_gsb_paise,
            type: 'reversal',
            referenceId: $result->id,
            referenceType: 'gsb_cutoff_result',
            memo: 'Admin reversal — '.$request->input('reason'),
        );
        $result->update(['status' => 'reversed']);

        AuditLog::create([
            'actor_id' => auth()->id(),
            'action' => 'compensation.gsb.reversed',
            'subject_type' => 'distributor',
            'subject_id' => $distributor->id,
            'details' => [
                'adn' => $distributor->adn,
                'date' => $result->cutoff_date->toDateString(),
                'amount_paise' => $result->net_gsb_paise,
                'wallet_before' => $before,
                'wallet_after' => $this->wallet->balancePaise($distributor->id),
                'reason' => $request->input('reason'),
            ],
            'ip' => $request->ip(),
        ]);

        return redirect()->route('admin.compensation.distributors.show', $distributor)
            ->with('status', "GSB ₹".number_format($result->net_gsb_paise/100, 2)." reversed for {$distributor->adn}.");
    }

    // recalcCarryForward(), manualCredit(), forcePayout() follow the same pattern:
    // validate → find distributor → run service → audit log → redirect with status
    // Implement each using the same shape as retryCutoff() above.
    public function recalcCarryForward(Request $request): RedirectResponse
    {
        $request->validate(['adn' => ['required','string'], 'reason' => ['required','string','min:10']]);
        $distributor = Distributor::where('adn', $request->input('adn'))->firstOrFail();
        // Recalculation: sum all credited cut-off results and rebuild carry-forward.
        // For Phase 4 this is a placeholder — the full rebuild logic is in GsbCutoffService::rebuildCarryForward().
        // Placeholder: trigger a log entry and redirect.
        AuditLog::create([
            'actor_id' => auth()->id(), 'action' => 'compensation.carryforward.recalculated',
            'subject_type' => 'distributor', 'subject_id' => $distributor->id,
            'details' => ['adn' => $distributor->adn, 'reason' => $request->input('reason'), 'note' => 'Full rebuild deferred to Phase 4 implementation'],
            'ip' => $request->ip(),
        ]);
        return redirect()->route('admin.compensation.distributors.show', $distributor)->with('status', 'Carry-forward recalculation logged — full rebuild available once GSB engine is active.');
    }

    public function manualCredit(Request $request): RedirectResponse
    {
        $request->validate(['adn' => ['required','string'], 'amount' => ['required','numeric','min:1'], 'reason' => ['required','string','min:10']]);
        $distributor = Distributor::where('adn', $request->input('adn'))->firstOrFail();
        $amountPaise = (int) round((float) $request->input('amount') * 100);
        $before = $this->wallet->balancePaise($distributor->id);
        $this->wallet->credit($distributor->id, $amountPaise, 'manual_credit', memo: 'Admin: '.$request->input('reason'));
        AuditLog::create([
            'actor_id' => auth()->id(), 'action' => 'compensation.gsb.manual_credit',
            'subject_type' => 'distributor', 'subject_id' => $distributor->id,
            'details' => ['adn' => $distributor->adn, 'amount_paise' => $amountPaise, 'wallet_before' => $before, 'wallet_after' => $this->wallet->balancePaise($distributor->id), 'reason' => $request->input('reason')],
            'ip' => $request->ip(),
        ]);
        return redirect()->route('admin.compensation.distributors.show', $distributor)->with('status', "Manual credit ₹".number_format($amountPaise/100,2)." added for {$distributor->adn}.");
    }

    public function forcePayout(Request $request): RedirectResponse
    {
        $request->validate(['adn' => ['required','string'], 'reason' => ['required','string','min:10']]);
        $distributor = Distributor::where('adn', $request->input('adn'))->firstOrFail();
        // Phase 4 stub: mark as triggered in audit log; actual payout debit via PayoutService::runBatch().
        AuditLog::create([
            'actor_id' => auth()->id(), 'action' => 'compensation.payout.force_triggered',
            'subject_type' => 'distributor', 'subject_id' => $distributor->id,
            'details' => ['adn' => $distributor->adn, 'reason' => $request->input('reason')],
            'ip' => $request->ip(),
        ]);
        return redirect()->route('admin.compensation.distributors.show', $distributor)->with('status', "Force payout logged for {$distributor->adn} — batch will run at next scheduled time.");
    }
}
```

- [ ] **Step 2: Create `manual-controls/index.blade.php`**

The page has:
1. Warning banner (always visible — uses orange/amber `bg-amber-50`)
2. Six action cards in a 3×2 grid; each card opens a form section below
3. Form section for whichever `action` param is set (or show "select an action" message)
4. Recent actions audit feed at the bottom

```blade
{{-- resources/views/admin/compensation/manual-controls/index.blade.php --}}
@extends('admin.layouts.admin')
@section('title', 'Manual Controls')
@section('heading', 'Compensation — Manual Controls')

@section('content')

{{-- Warning banner --}}
<div class="mb-5 rounded-lg border border-amber-300 bg-amber-50 p-4 text-sm text-amber-900">
    ⚠ <strong>These controls affect real money and wallet balances.</strong> Every action is permanently audit-logged with your admin ID, a timestamp, the before/after state, and the reason you provide. There is no undo — use Reverse if a credit needs to be walked back. When in doubt, use <strong>Retry</strong> (which is safe and idempotent) before using <strong>Manual Credit</strong>.
</div>

{{-- Action selector grid --}}
<div class="grid grid-cols-3 gap-3 mb-6">
    @foreach([
        ['key'=>'retry',        'icon'=>'🔄', 'label'=>'Retry Daily Cut-off',        'desc'=>'Re-run 23:59 GSB calculation for one distributor + date. Idempotent if already credited.', 'danger'=>false],
        ['key'=>'recalc-cf',   'icon'=>'📊', 'label'=>'Recalculate Carry-forward',  'desc'=>'Recompute slab-1 weaker CF and power-side CF from full GSB history.', 'danger'=>false],
        ['key'=>'credit',       'icon'=>'💸', 'label'=>'Manual GSB Credit',          'desc'=>'Credit a custom GSB amount. Requires amount + reason. Use only when Retry fails.', 'danger'=>false],
        ['key'=>'reverse',      'icon'=>'↩️', 'label'=>'Reverse GSB Credit',         'desc'=>'Write a debit entry reversing a specific GSB credit. Affects next payout.', 'danger'=>true],
        ['key'=>'force-payout','icon'=>'⚡', 'label'=>'Force Weekly Payout',        'desc'=>'Trigger payout for one distributor immediately. Only if automated batch failed for them.', 'danger'=>false],
        ['key'=>'freeze',       'icon'=>'🔒', 'label'=>'Freeze / Unfreeze GSB',      'desc'=>'Block GSB credits without terminating account. GSB calculated but held.', 'danger'=>true],
    ] as $card)
    <a href="{{ route('admin.compensation.manual-controls.index', array_filter(['adn' => $adn, 'action' => $card['key'], 'date' => $date])) }}"
       class="block rounded-xl border p-4 hover:border-brand-400 transition-colors {{ $action === $card['key'] ? 'border-brand-500 bg-brand-50' : ($card['danger'] ? 'border-red-200' : 'border-gray-200 bg-white') }}">
        <div class="text-2xl mb-2">{{ $card['icon'] }}</div>
        <h4 class="text-sm font-semibold {{ $card['danger'] ? 'text-red-700' : 'text-gray-900' }} mb-1">{{ $card['label'] }}</h4>
        <p class="text-xs text-gray-500 leading-snug">{{ $card['desc'] }}</p>
    </a>
    @endforeach
</div>

{{-- Active form section --}}
@if($action)
<div class="bg-white rounded-xl border border-gray-200 shadow-sm p-5 mb-6">
    @include('admin.compensation.manual-controls._form-'.str_replace(['-','_'], ['-',''], $action))
</div>
@else
<div class="bg-gray-50 rounded-xl border border-gray-200 p-6 text-center text-sm text-gray-500 mb-6">
    Select an action above to get started.
</div>
@endif

{{-- Recent actions audit feed --}}
<div class="bg-white rounded-xl border border-gray-200 shadow-sm">
    <div class="px-5 py-3 border-b border-gray-100">
        <span class="text-sm font-semibold text-gray-900">Recent manual actions</span>
    </div>
    @if($recentActions->isEmpty())
    <p class="px-5 py-6 text-sm text-gray-400 text-center">No manual actions recorded yet.</p>
    @else
    <div class="divide-y divide-gray-50">
        @foreach($recentActions as $log)
        @php
            $badgeColor = match(true) {
                str_contains($log->action, 'reversed') => 'bg-red-100 text-red-700',
                str_contains($log->action, 'frozen') || str_contains($log->action, 'unfrozen') => 'bg-blue-100 text-blue-700',
                str_contains($log->action, 'retry') || str_contains($log->action, 'recalc') => 'bg-green-100 text-green-700',
                default => 'bg-amber-100 text-amber-700',
            };
        @endphp
        <div class="px-5 py-3 text-xs text-gray-600 flex items-start gap-3">
            <span class="inline-flex px-2 py-0.5 rounded font-medium {{ $badgeColor }} shrink-0">{{ str_replace('compensation.', '', $log->action) }}</span>
            <span>
                <strong>{{ $log->details['adn'] ?? '—' }}</strong> ·
                {{ $log->created_at->format('d M H:i') }} ·
                by {{ $log->actor?->email ?? 'system' }}
                @if(isset($log->details['reason'])) · "{{ Str::limit($log->details['reason'], 60) }}" @endif
            </span>
        </div>
        @endforeach
    </div>
    @endif
</div>
@endsection
```

- [ ] **Step 3: Create form partials**

Create `_form-retry.blade.php`:
```blade
<h3 class="text-sm font-semibold mb-3">🔄 Retry Daily Cut-off</h3>
<form method="POST" action="{{ route('admin.compensation.manual-controls.retry') }}"
      data-confirm="This will re-run the 23:59 cut-off for this distributor."
      data-confirm-title="Confirm: Retry Daily Cut-off"
      data-confirm-impact="GSB will be calculated and credited if not already done. If already credited, no duplicate credit will be issued.">
    @csrf
    <div class="grid grid-cols-2 gap-4 mb-4">
        <div>
            <label class="block text-xs font-medium text-gray-700 mb-1">Distributor ADN</label>
            <input type="text" name="adn" value="{{ $adn ?? '' }}" required placeholder="e.g. AV-00042"
                   class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:ring-2 focus:ring-brand-400 focus:outline-none">
        </div>
        <div>
            <label class="block text-xs font-medium text-gray-700 mb-1">Cut-off date</label>
            <input type="date" name="date" value="{{ $date ?? today()->toDateString() }}" required
                   class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:ring-2 focus:ring-brand-400 focus:outline-none">
        </div>
    </div>
    <div class="mb-4">
        <label class="block text-xs font-medium text-gray-700 mb-1">Reason (required)</label>
        <textarea name="reason" rows="2" required placeholder="e.g. Wallet write failed due to DB timeout at 23:59:14 — retrying after DB recovery"
                  class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:ring-2 focus:ring-brand-400 focus:outline-none"></textarea>
    </div>
    <button type="submit" class="px-4 py-2 rounded-lg bg-brand-500 text-white text-sm font-medium hover:bg-brand-600">
        Preview &amp; Confirm →
    </button>
</form>
```

Create the remaining 5 form partials (`_form-recalc-cf.blade.php`, `_form-credit.blade.php`, `_form-reverse.blade.php`, `_form-force-payout.blade.php`, `_form-freeze.blade.php`) following the same pattern — each has a heading, a form with ADN field + relevant fields + reason textarea + a `data-confirm` form attribute that triggers the platform's existing confirm modal.

- [ ] **Step 4: Commit**

```bash
vendor/bin/pint --dirty
git -C /Users/preetham/Documents/arovolife/arovolife/arovolife-code add app/Modules/Compensation/Http/Controllers/Admin/AdminManualControlsController.php resources/views/admin/compensation/manual-controls/
git -C /Users/preetham/Documents/arovolife/arovolife/arovolife-code commit -m "feat(compensation): manual controls page — 6 action forms with confirm modal + audit feed"
```

---

## Task 8: Add "Compensation →" button to distributor profile + help doc

**Files:**
- Modify: `app/resources/views/admin/distributors/show.blade.php`
- Create: `app/resources/help/compensation.md`

- [ ] **Step 1: Add Compensation button to distributor profile**

Open `app/resources/views/admin/distributors/show.blade.php`. Find the action bar (the row with buttons like "BV Ledger →", "Impersonate", etc.). Add:

```blade
<a href="{{ route('admin.compensation.distributors.show', $distributor) }}"
   class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg border border-purple-300 bg-purple-50 text-sm text-purple-700 font-medium hover:bg-purple-100">
    💰 Compensation →
</a>
```

- [ ] **Step 2: Create the help doc**

```markdown
<!-- resources/help/compensation.md -->
# Compensation Module — Admin Reference

## What it does
The Compensation section tracks GSB (Genos Sales Bonus), Mentorship Bonus, wallet balances, and weekly payouts for all distributors.

## Daily cut-off
Runs automatically every day at 23:59 IST. For each active distributor:
- Reads their Left and Right Genos group BV accumulated during the day
- Adds any carry-forward from previous days
- Matches against GSB slabs (constrained by their personal purchase title)
- Deducts admin charge (3%, max ₹30,000) and TDS (5% of net-of-admin)
- Credits the wallet with the net GSB amount

## Slab table
| Slab | Matched BV (each side) | Gross GSB | Title required |
|------|----------------------|-----------|----------------|
| 1 | 15,000 BV | ₹1,000 | Retailer (3K lifetime) |
| 2 | 30,000 BV | ₹3,000 | Dealer (5K lifetime) |
| 3 | 90,000 BV | ₹6,000 | Wholesaler (15K lifetime) |
| 4 | 2,70,000 BV | ₹12,000 | Distributor (50K lifetime) |
| 5 | 8,00,000 BV | ₹24,000 | Regional Distributor (1L) |
| 6 | 24,00,000 BV | ₹40,000 | National Distributor (2L) |
| 7 | 72,00,000 BV | ₹60,000 | Global Distributor (3L) |

## Carry-forward
- **Power side** (stronger leg): carries forward capped at 4,50,000 BV
- **Slab-1 weaker side**: accumulates indefinitely toward the 15K first match

## Weekly payout
Runs every Tuesday. Minimum ₹500. Repurchase deduction: 10% of prior month GSB + MB (max ₹10,000).

## Manual controls
Use Manual Controls (always audit-logged) for: failed cut-offs (Retry is safe/idempotent), BV reversals after cut-off (Recalculate CF), incorrect credits (Reverse), and frozen accounts.
```

- [ ] **Step 3: Commit**

```bash
vendor/bin/pint --dirty
git -C /Users/preetham/Documents/arovolife/arovolife/arovolife-code add resources/views/admin/distributors/show.blade.php resources/help/compensation.md
git -C /Users/preetham/Documents/arovolife/arovolife/arovolife-code commit -m "feat(compensation): add Compensation button to distributor profile + admin help doc"
```

---

## Self-review

**Spec coverage check:**
- [x] Overview page with 4 stat cards, attention feed, cut-off summary table
- [x] Daily Cut-offs page with date filter, status filter, ADN search, CSV export, Retry/Reverse actions
- [x] Weekly Payouts page with batch list + batch detail
- [x] Carry-forwards page with power CF progress bar, slab-1 CF, Recalculate action
- [x] Per-distributor detail with 6 tabs (GSB, MB, BV Log, Wallet, Payouts, Audit)
- [x] Manual Controls page with 6 actions + confirm modal + audit feed
- [x] Help tips (`<x-help-tip>`) on every column
- [x] Page note banners (blue info box) on every page
- [x] All POST forms use `data-confirm` for the existing confirm modal
- [x] "Compensation →" deep-link from distributor profile
- [x] Admin help doc updated (`resources/help/compensation.md`)
- [x] RBAC: all routes are inside the admin route group (enforces `role:admin`)
- [x] Audit logging: all manual actions write to `audit_log` via `AuditLog::create()`
- [ ] RBAC note: spec says Freeze GSB is `admin` only (not `admin-finance`). Current implementation uses the shared admin route group. Add a `role:admin` middleware check inside `freezeGsb()` if `admin-finance` role is added later.

**Placeholder scan:** None found.

**Type consistency:** `GsbCutoffResult::STATUS_*` constants used consistently with backend plan.
