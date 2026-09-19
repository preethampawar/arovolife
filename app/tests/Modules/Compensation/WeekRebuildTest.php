<?php

declare(strict_types=1);

use App\Modules\Commerce\Models\BvLedgerEntry;
use App\Modules\Compensation\Models\EngineRun;
use App\Modules\Compensation\Models\PayoutBatch;
use App\Modules\Compensation\Models\PayoutLineItem;
use App\Modules\Compensation\Models\WalletLedgerEntry;
use App\Modules\Compensation\Services\PayoutService;
use App\Modules\Compensation\Services\Rebuild\RebuildKind;
use App\Modules\Compensation\Services\Rebuild\RebuildPlanner;
use App\Modules\Compensation\Services\WalletService;
use App\Modules\Compliance\Models\AuditLog;
use App\Modules\Identity\Models\Distributor;
use App\Modules\Identity\Models\User;
use App\Modules\Shared\Features\GenosSalesBonusFeature;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\WithConsoleEvents;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Laravel\Pennant\Feature;

uses(RefreshDatabase::class, WithConsoleEvents::class);

/** The Tuesday under test. */
const WEEK_REBUILD_TUESDAY = '2026-09-22';

beforeEach(function (): void {
    disableTestForeignKeys();
    app(RolesAndPermissionsSeeder::class)->run();
    Carbon::setTestNow(Carbon::parse(WEEK_REBUILD_TUESDAY.' 03:00:00', 'Asia/Kolkata'));
    Feature::for(null)->activate(GenosSalesBonusFeature::class);
});

afterEach(function (): void {
    Carbon::setTestNow();
});

function weekRebuildDeveloper(): User
{
    $user = User::create([
        'full_name' => 'Platform Developer',
        'email' => 'week-rebuild-'.uniqid().'@test.com',
        'phone_e164' => '+91'.str_pad((string) random_int(7000000000, 9999999999), 10, '0'),
        'password_hash' => bcrypt('x'),
        'status' => 'active',
        'email_verified_at' => now(),
    ]);
    $user->assignRole('developer');

    return $user;
}

/** A pending weekly batch dated $date with one paid distributor. */
function weeklyBatchWithOneLine(string $date): PayoutBatch
{
    $batchDate = Carbon::parse($date);
    $distributor = Distributor::factory()->create();

    BvLedgerEntry::create([
        'distributor_id' => $distributor->id,
        'order_id' => 700_000 + $distributor->id,
        'bv_paise' => 300_000,
        'type' => 'accrual',
        'effective_at' => now(),
    ]);

    app(WalletService::class)->credit(
        distributorId: $distributor->id,
        amountPaise: 100_000,
        type: 'gsb_credit',
        referenceId: walletRef(),
        referenceType: 'gsb_cutoff_result',
        earnedOn: PayoutBatch::weeklyEarningWindow($batchDate)['end'],
    );

    return app(PayoutService::class)->runWeeklyBatch($batchDate);
}

it('refuses a Tuesday with no batch to remove', function (): void {
    $plan = app(RebuildPlanner::class)->plan(RebuildKind::Week, Carbon::parse(WEEK_REBUILD_TUESDAY));

    expect($plan->isRefused())->toBeTrue();
    expect(implode("\n", $plan->refusals))->toContain('No weekly batch is dated '.WEEK_REBUILD_TUESDAY);
    expect(implode("\n", $plan->refusals))->toContain('compensation:weekly-run --date='.WEEK_REBUILD_TUESDAY);
});

it('counts what it would remove and un-sweep', function (): void {
    $batch = weeklyBatchWithOneLine(WEEK_REBUILD_TUESDAY);

    $plan = app(RebuildPlanner::class)->plan(RebuildKind::Week, Carbon::parse(WEEK_REBUILD_TUESDAY));

    expect($plan->refusals)->toBe([]);
    expect($plan->rowsToRemove['payout_batches'])->toBe(1);
    expect($plan->rowsToRemove['payout_line_items'])->toBe(1);
    expect($plan->rowsToRemove['wallet_ledger_entries'])->toBe(3);
    expect($plan->unsweeps)->toBe(WalletLedgerEntry::where('swept_by_payout_batch_id', $batch->id)->count());
    expect($plan->rerunCommand())->toBe('gsb:weekly-payout --date='.WEEK_REBUILD_TUESDAY);
    expect(implode("\n", $plan->warnings))->toContain('records you as its maker');
});

it('warns about later batches — rebuild the unfrozen ones, the approved ones stand', function (): void {
    weeklyBatchWithOneLine(WEEK_REBUILD_TUESDAY);

    $pendingLater = PayoutBatch::create([
        'batch_type' => PayoutBatch::TYPE_WEEKLY,
        'batch_date' => '2026-09-29',
        'status' => PayoutBatch::STATUS_PENDING,
    ]);
    $approvedLater = PayoutBatch::create([
        'batch_type' => PayoutBatch::TYPE_MONTHLY,
        'batch_date' => '2026-10-01',
        'status' => PayoutBatch::STATUS_APPROVED,
        'approved_at' => Carbon::parse('2026-10-01 10:00:00'),
    ]);

    $warnings = implode("\n", app(RebuildPlanner::class)
        ->plan(RebuildKind::Week, Carbon::parse(WEEK_REBUILD_TUESDAY))
        ->warnings);

    expect($warnings)->toContain('Rebuild these too, oldest first: #'.$pendingLater->id);
    expect($warnings)->toContain('compensation:rebuild-week --date=2026-09-29');
    expect($warnings)->toContain('Batch #'.$approvedLater->id);
    expect($warnings)->toContain('its cap allocation stands');
});

it('refuses a batch finance has approved', function (): void {
    $batch = weeklyBatchWithOneLine(WEEK_REBUILD_TUESDAY);
    $batch->update(['status' => PayoutBatch::STATUS_APPROVED, 'approved_at' => now()]);

    $plan = app(RebuildPlanner::class)->plan(RebuildKind::Week, Carbon::parse(WEEK_REBUILD_TUESDAY));

    expect($plan->isRefused())->toBeTrue();
    expect(implode("\n", $plan->refusals))->toContain('finance has signed off is not rebuilt');
});

it('rebuilds the batch end to end and records the developer as its maker', function (): void {
    $batch = weeklyBatchWithOneLine(WEEK_REBUILD_TUESDAY);
    $developer = weekRebuildDeveloper();

    $before = PayoutLineItem::where('payout_batch_id', $batch->id)
        ->get(['distributor_id', 'gross_paise', 'net_transferred_paise'])
        ->toArray();

    expect(Artisan::call('compensation:rebuild-week', [
        '--date' => WEEK_REBUILD_TUESDAY,
        '--actor' => $developer->id,
        '--yes' => true,
    ]))->toBe(0);

    $rebuilt = PayoutBatch::where('batch_type', PayoutBatch::TYPE_WEEKLY)
        ->whereDate('batch_date', WEEK_REBUILD_TUESDAY)
        ->firstOrFail();

    expect($rebuilt->id)->not->toBe($batch->id);
    // The maker bar: HandlesPayoutBatchActions refuses an approval from the same
    // user id, so the developer who rebuilt it cannot also sign it off (R-81).
    expect($rebuilt->created_by)->toBe($developer->id);
    expect(PayoutLineItem::where('payout_batch_id', $rebuilt->id)
        ->get(['distributor_id', 'gross_paise', 'net_transferred_paise'])
        ->toArray())->toBe($before);

    expect(EngineRun::where('engine_key', 'compensation.rebuild-week')->value('status'))
        ->toBe(EngineRun::STATUS_SUCCEEDED);
    expect(AuditLog::where('action', 'payout.batch.unbuilt')->value('actor_id'))->toBe($developer->id);
    expect(AuditLog::where('action', 'compensation.rebuild.completed')->exists())->toBeTrue();
});
