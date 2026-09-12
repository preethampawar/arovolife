<?php

declare(strict_types=1);

/**
 * F52 — the regression net for the CSV → XLSX sweep
 * (docs/plans/csv-to-xlsx-exports-2026-09-12.md).
 *
 * One dataset over every converted export endpoint, asserting four things per
 * endpoint — `?format=xlsx`, `?format=csv`, the bare URL (which now defaults to
 * XLSX, decision D2's single deliberate behaviour change) and the legacy
 * `?export=csv` — plus the negatives that the sweep could plausibly have
 * broken: the permission gate, the compensation feature flags, and the two
 * NEFT bank files that must stay CSV.
 *
 * Nothing here asserts row content. Column headers are pinned by the
 * acceptance criteria against the previous commit; this file pins the
 * *envelope* — status, content type, filename extension and who may reach it.
 */

use App\Modules\Compensation\Models\PayoutBatch;
use App\Modules\Identity\Models\Distributor;
use App\Modules\Identity\Models\User;
use App\Modules\Shared\Features\AreteDevelopmentCenterBonusFeature;
use App\Modules\Shared\Features\FortuneBonusFeature;
use App\Modules\Shared\Features\GenosSalesBonusFeature;
use App\Modules\Shared\Features\GrowthBoosterBonusFeature;
use App\Modules\Shared\Features\LifetimeAwardsFeature;
use App\Modules\Shared\Features\MentorshipBonusFeature;
use App\Modules\Shared\Features\RankBonusFeature;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Laravel\Pennant\Feature;

uses(RefreshDatabase::class);

const EET_XLSX_TYPE = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';

/**
 * Every compensation feature flag an export sits behind. Activated for the
 * whole file so the happy path reaches the report; the flag-off test
 * deactivates the one it is testing.
 *
 * @var array<int, class-string>
 */
const EET_COMPENSATION_FEATURES = [
    GenosSalesBonusFeature::class,
    MentorshipBonusFeature::class,
    GrowthBoosterBonusFeature::class,
    RankBonusFeature::class,
    FortuneBonusFeature::class,
    LifetimeAwardsFeature::class,
    AreteDevelopmentCenterBonusFeature::class,
];

beforeEach(function (): void {
    disableTestForeignKeys();
    $this->seed(RolesAndPermissionsSeeder::class);

    // The compensation reports read their slab/rank/fortune ladders from DB
    // tables. tests/Pest.php seeds these automatically only under
    // tests/Modules/Compensation, so this file seeds them itself.
    seedCompensationPlanTables();

    foreach (EET_COMPENSATION_FEATURES as $feature) {
        Feature::for(null)->activate($feature);
    }
});

/**
 * An actor by the role name used in the plan's permission matrix.
 *
 * `guest` returns null — used as the "may not reach it" side of the three
 * distributor-facing exports, which 404 rather than 403 for a signed-in admin
 * (they resolve the caller's own distributor record and abort when there is
 * none), so an unauthenticated request is the honest negative there.
 */
function eetActor(string $role): ?User
{
    if ($role === 'guest') {
        return null;
    }

    if ($role === 'distributor') {
        return Distributor::factory()->create()->user;
    }

    $user = User::factory()->create(['status' => 'active']);
    $user->assignRole($role);

    return $user;
}

function eetGet(?User $actor, string $url): TestResponse
{
    return $actor === null
        ? test()->get($url)
        : test()->actingAs($actor)->get($url);
}

/** The filename the browser will save, out of the Content-Disposition header. */
function eetFilename(TestResponse $response): string
{
    $disposition = (string) $response->headers->get('Content-Disposition');

    preg_match('/filename\*?=(?:UTF-8\'\')?"?([^";]+)"?/', $disposition, $m);

    return $m[1] ?? '';
}

function eetAssertXlsx(TestResponse $response): void
{
    $response->assertOk();
    expect((string) $response->headers->get('Content-Type'))->toContain(EET_XLSX_TYPE);
    expect(eetFilename($response))->toEndWith('.xlsx');
}

function eetAssertCsv(TestResponse $response): void
{
    $response->assertOk();
    expect((string) $response->headers->get('Content-Type'))->toContain('text/csv');
    expect(eetFilename($response))->toEndWith('.csv');
}

/** Append a query string to a URL that may already carry one (route params). */
function eetUrl(string $route, array $params, string $query): string
{
    $url = route($route, $params);

    if ($query === '') {
        return $url;
    }

    return $url.(str_contains($url, '?') ? '&' : '?').$query;
}

/**
 * The 30 endpoints S2–S7 converted to `ReportExport::respond()`.
 *
 * Columns: label => [route name, route params, role that may reach it,
 * role that may not, the query string that stands in for "bare URL"].
 *
 * The last column is empty for every dedicated export endpoint — a bare URL
 * there IS the export. The ten inventory reports are the exception: the same
 * route renders the HTML report and only exports when an export parameter is
 * present, so `export=1` is their "no format asked for" case. It exercises the
 * same default branch in ReportExport::formatFor().
 *
 * NOTE: the plan's file table counts 32 converted endpoints — all 32 are
 * here. AdminBvLedgerController's two exports (P3, `can:audit.read`) were the
 * last to convert, gated on the S6b decision about their unbounded query
 * (resolved: lazy/cursor iteration — see AdminBvLedgerController's row
 * generators).
 */
dataset('converted exports', [
    // P1 — inventory reports, `can:inventory.view` (admin-operations, admin-finance).
    'inventory stock-on-hand' => ['admin.inventory.reports.stock-on-hand', [], 'admin-operations', 'admin-compliance', 'export=1'],
    'inventory movements' => ['admin.inventory.reports.movements', [], 'admin-operations', 'admin-compliance', 'export=1'],
    'inventory batch-expiry' => ['admin.inventory.reports.batch-expiry', [], 'admin-operations', 'admin-compliance', 'export=1'],
    'inventory low-stock' => ['admin.inventory.reports.low-stock', [], 'admin-operations', 'admin-compliance', 'export=1'],
    'inventory valuation' => ['admin.inventory.reports.valuation', [], 'admin-operations', 'admin-compliance', 'export=1'],
    'inventory purchase-register' => ['admin.inventory.reports.purchase-register', [], 'admin-operations', 'admin-compliance', 'export=1'],
    'inventory transfer-register' => ['admin.inventory.reports.transfer-register', [], 'admin-operations', 'admin-compliance', 'export=1'],
    'inventory order-fulfilment' => ['admin.inventory.reports.order-fulfilment', [], 'admin-operations', 'admin-compliance', 'export=1'],
    'inventory returns-restock' => ['admin.inventory.reports.returns-restock', [], 'admin-operations', 'admin-compliance', 'export=1'],
    'inventory stock-in-out' => ['admin.inventory.reports.stock-in-out', [], 'admin-operations', 'admin-compliance', 'export=1'],

    // P6 — the 15 compensation exports. The admin route group admits the whole
    // admin family; a distributor is the role that may not reach them.
    'comp carry-forwards' => ['admin.compensation.carry-forwards.export', [], 'developer', 'distributor', ''],
    'comp genos-transactions' => ['admin.compensation.genos-transactions.export', [], 'developer', 'distributor', ''],
    'comp gsb-calculation' => ['admin.compensation.gsb-calculation.export', [], 'developer', 'distributor', ''],
    'comp gsb-input-output' => ['admin.compensation.gsb-input-output.export', [], 'developer', 'distributor', ''],
    'comp msb-calculation' => ['admin.compensation.msb-calculation.export', [], 'developer', 'distributor', ''],
    'comp msb-input-output' => ['admin.compensation.msb-input-output.export', [], 'developer', 'distributor', ''],
    'comp gbb-calculation' => ['admin.compensation.gbb-calculation.export', [], 'developer', 'distributor', ''],
    'comp gbb-input-output' => ['admin.compensation.gbb-input-output.export', [], 'developer', 'distributor', ''],
    'comp rb-calculation' => ['admin.compensation.rb-calculation.export', [], 'developer', 'distributor', ''],
    'comp rb-input-output' => ['admin.compensation.rb-input-output.export', [], 'developer', 'distributor', ''],
    'comp fb-calculation' => ['admin.compensation.fb-calculation.export', [], 'developer', 'distributor', ''],
    'comp aw-rw-calculation' => ['admin.compensation.aw-rw-calculation.export', [], 'developer', 'distributor', ''],
    'comp adc-calculation' => ['admin.compensation.adc-calculation.export', [], 'developer', 'distributor', ''],
    'comp daily-cutoffs' => ['admin.compensation.daily-cutoffs.export', [], 'developer', 'distributor', ''],
    'comp personal-bv-topups' => ['admin.compensation.personal-bv-topups.export', [], 'developer', 'distributor', ''],

    // P2 — the statutory DSR register. Admin-family only, no extra `can:`.
    'distributors register' => ['admin.distributors.export', [], 'developer', 'distributor', ''],

    // P5 — `can:grievance.handle`, which admin-finance deliberately lacks.
    'grievance report' => ['admin.grievances.report.export', [], 'admin-compliance', 'admin-finance', ''],

    // P3 — `can:audit.read`. S6b: converted last, after the lazy/cursor
    // iteration decision for its unbounded queries.
    'bv-ledger summary' => ['admin.commerce.bv-ledger.export', [], 'admin-finance', 'distributor', ''],

    // P7, P8, P9 — distributor-facing.
    'income gsb-history' => ['income.gsb-history.export', [], 'distributor', 'guest', ''],
    'income wallet' => ['income.wallet.export', [], 'distributor', 'guest', ''],
    'team roster' => ['dashboard.team-roster.download', ['scope' => 'total'], 'distributor', 'guest', ''],
]);

it('serves XLSX when asked for it', function (string $route, array $params, string $allowed, string $denied, string $bare): void {
    eetAssertXlsx(eetGet(eetActor($allowed), eetUrl($route, $params, 'format=xlsx')));
})->with('converted exports');

it('serves CSV when asked for it', function (string $route, array $params, string $allowed, string $denied, string $bare): void {
    eetAssertCsv(eetGet(eetActor($allowed), eetUrl($route, $params, 'format=csv')));
})->with('converted exports');

// D2: the one deliberate behaviour change in the whole plan. A URL that asks
// for no format now hands back a workbook, not a CSV. If this test ever starts
// failing because someone flipped the default back, that is a decision to make
// on purpose — not a test to update.
it('defaults to XLSX when no format is requested', function (string $route, array $params, string $allowed, string $denied, string $bare): void {
    eetAssertXlsx(eetGet(eetActor($allowed), eetUrl($route, $params, $bare)));
})->with('converted exports');

// The legacy parameter the inventory reports shipped with. Somebody has these
// bookmarked, and D2 promises they keep working everywhere, not just where they
// originated.
it('still honours the legacy ?export=csv parameter', function (string $route, array $params, string $allowed, string $denied, string $bare): void {
    eetAssertCsv(eetGet(eetActor($allowed), eetUrl($route, $params, 'export=csv')));
})->with('converted exports');

it('keeps its permission gate in both formats', function (string $route, array $params, string $allowed, string $denied, string $bare): void {
    foreach (['format=xlsx', 'format=csv'] as $query) {
        $status = eetGet(eetActor($denied), eetUrl($route, $params, $query))->getStatusCode();

        expect($status)->toBeIn([302, 403]);
    }
})->with('converted exports');

/**
 * P6's feature-flag half. Fourteen of the fifteen compensation exports abort
 * 404 when their bonus flag is off.
 *
 * DISCREPANCY against the plan, which says all 15:
 * AdminGenosTransactionsController has no `abort_unless(Feature::...)` on
 * either its index or its export — the Genos transaction ledger is not behind
 * a bonus flag. That is pre-existing behaviour, unchanged by this sweep, so it
 * is excluded here rather than "fixed".
 */
dataset('flagged compensation exports', [
    'carry-forwards' => ['admin.compensation.carry-forwards.export', GenosSalesBonusFeature::class],
    'gsb-calculation' => ['admin.compensation.gsb-calculation.export', GenosSalesBonusFeature::class],
    'gsb-input-output' => ['admin.compensation.gsb-input-output.export', GenosSalesBonusFeature::class],
    'daily-cutoffs' => ['admin.compensation.daily-cutoffs.export', GenosSalesBonusFeature::class],
    'personal-bv-topups' => ['admin.compensation.personal-bv-topups.export', GenosSalesBonusFeature::class],
    'msb-calculation' => ['admin.compensation.msb-calculation.export', MentorshipBonusFeature::class],
    'msb-input-output' => ['admin.compensation.msb-input-output.export', MentorshipBonusFeature::class],
    'gbb-calculation' => ['admin.compensation.gbb-calculation.export', GrowthBoosterBonusFeature::class],
    'gbb-input-output' => ['admin.compensation.gbb-input-output.export', GrowthBoosterBonusFeature::class],
    'rb-calculation' => ['admin.compensation.rb-calculation.export', RankBonusFeature::class],
    'rb-input-output' => ['admin.compensation.rb-input-output.export', RankBonusFeature::class],
    'fb-calculation' => ['admin.compensation.fb-calculation.export', FortuneBonusFeature::class],
    'aw-rw-calculation' => ['admin.compensation.aw-rw-calculation.export', LifetimeAwardsFeature::class],
    'adc-calculation' => ['admin.compensation.adc-calculation.export', AreteDevelopmentCenterBonusFeature::class],
]);

it('404s in both formats when its bonus feature flag is off', function (string $route, string $feature): void {
    Feature::for(null)->deactivate($feature);

    $admin = eetActor('developer');

    foreach (['format=xlsx', 'format=csv'] as $query) {
        eetGet($admin, eetUrl($route, [], $query))->assertNotFound();
    }
})->with('flagged compensation exports');

// P10 — the negative test that keeps the NEFT exemption honest. The bank's
// portal parses CSV and nothing else, so these two must NOT have been swept up
// with the rest. If someone "finishes the job" in a later pass, this fails.
it('keeps the NEFT bank file as CSV, whatever format is asked for', function (string $route): void {
    $batch = PayoutBatch::create([
        'batch_type' => str_contains($route, 'monthly') ? PayoutBatch::TYPE_MONTHLY : PayoutBatch::TYPE_WEEKLY,
        'batch_date' => now()->toDateString(),
        'status' => PayoutBatch::STATUS_APPROVED,
    ]);

    // `can:finance.record` — the gate the matrix says must survive.
    $finance = eetActor('admin-finance');

    foreach (['', 'format=xlsx', 'format=csv'] as $query) {
        $response = eetGet($finance, eetUrl($route, ['batch' => $batch->id], $query));

        $response->assertOk();
        expect((string) $response->headers->get('Content-Type'))->toContain('text/csv');
        expect(eetFilename($response))->toEndWith('.csv');
    }
})->with([
    'monthly NEFT' => ['admin.compensation.monthly-payouts.neft'],
    'weekly NEFT' => ['admin.compensation.weekly-payouts.neft'],
]);

it('keeps the NEFT bank file out of reach of a role without finance.record', function (string $route): void {
    $batch = PayoutBatch::create([
        'batch_type' => PayoutBatch::TYPE_WEEKLY,
        'batch_date' => now()->toDateString(),
        'status' => PayoutBatch::STATUS_APPROVED,
    ]);

    expect(eetGet(eetActor('admin-compliance'), route($route, ['batch' => $batch->id]))->getStatusCode())
        ->toBeIn([302, 403]);
})->with([
    'monthly NEFT' => ['admin.compensation.monthly-payouts.neft'],
    'weekly NEFT' => ['admin.compensation.weekly-payouts.neft'],
]);

/**
 * S6b — AdminBvLedgerController::exportShow() (per-distributor). It needs a
 * Distributor row, so it can't sit in the static 'converted exports' dataset
 * (whose params are fixed at file-load time); the summary export above does
 * cover the shared xlsx/csv/bare/legacy/permission behaviour, this covers the
 * per-distributor route specifically.
 */
it('serves the per-distributor BV ledger export as XLSX or CSV', function (): void {
    $distributor = Distributor::factory()->create();
    $admin = eetActor('admin-finance'); // `can:audit.read`

    eetAssertXlsx(eetGet($admin, route('admin.commerce.bv-ledger.show.export', ['distributor' => $distributor->id, 'format' => 'xlsx'])));
    eetAssertCsv(eetGet($admin, route('admin.commerce.bv-ledger.show.export', ['distributor' => $distributor->id, 'format' => 'csv'])));
    eetAssertXlsx(eetGet($admin, route('admin.commerce.bv-ledger.show.export', ['distributor' => $distributor->id])));
});

it('keeps the BV ledger behind audit.read', function (): void {
    $distributor = Distributor::factory()->create();
    $distributorRole = eetActor('distributor');

    expect(eetGet($distributorRole, route('admin.commerce.bv-ledger.export'))->getStatusCode())
        ->toBeIn([302, 403]);
    expect(eetGet($distributorRole, route('admin.commerce.bv-ledger.show.export', ['distributor' => $distributor->id]))->getStatusCode())
        ->toBeIn([302, 403]);
});
