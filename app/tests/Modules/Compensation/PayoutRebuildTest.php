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
use App\Modules\Shared\Features\RankBonusFeature;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\WithConsoleEvents;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Laravel\Pennant\Feature;

uses(RefreshDatabase::class, WithConsoleEvents::class);

/** The CREDITING month; its batch is dated the 1st of the month after it. */
const PAYOUT_REBUILD_MONTH = '2026-08';

const PAYOUT_REBUILD_BATCH_DATE = '2026-09-01';

beforeEach(function (): void {
    disableTestForeignKeys();
    app(RolesAndPermissionsSeeder::class)->run();
    Carbon::setTestNow(Carbon::parse('2026-09-15 04:00:00', 'Asia/Kolkata'));
    // The monthly payout batch no-ops with the compensation flag off.
    Feature::for(null)->activate(GenosSalesBonusFeature::class);
});

afterEach(function (): void {
    Carbon::setTestNow();
});

function payoutRebuildDeveloper(): User
{
    $user = User::create([
        'full_name' => 'Platform Developer',
        'email' => 'payout-rebuild-'.uniqid().'@test.com',
        'phone_e164' => '+91'.str_pad((string) random_int(7000000000, 9999999999), 10, '0'),
        'password_hash' => bcrypt('x'),
        'status' => 'active',
        'email_verified_at' => now(),
    ]);
    $user->assignRole('developer');

    return $user;
}

/** One distributor with a Group-B credit earned for the crediting month. */
function seedMonthlyCredit(): Distributor
{
    $distributor = Distributor::factory()->create();

    BvLedgerEntry::create([
        'distributor_id' => $distributor->id,
        'order_id' => 600_000 + $distributor->id,
        'bv_paise' => 300_000,
        'type' => 'accrual',
        'effective_at' => Carbon::parse(PAYOUT_REBUILD_MONTH.'-15'),
    ]);

    app(WalletService::class)->credit(
        distributorId: $distributor->id,
        amountPaise: 100_000,
        type: 'rank_credit',
        referenceId: walletRef(),
        referenceType: 'rank_bonus_result',
        bonusMonth: Carbon::parse(PAYOUT_REBUILD_MONTH.'-01'),
        earnedOn: Carbon::parse(PAYOUT_REBUILD_MONTH.'-31'),
    );

    return $distributor;
}

it('refuses a crediting month with no batch to remove', function (): void {
    seedMonthlyCredit();

    $plan = app(RebuildPlanner::class)->plan(RebuildKind::Payout, Carbon::parse(PAYOUT_REBUILD_MONTH.'-01'));

    expect($plan->isRefused())->toBeTrue();
    expect(implode("\n", $plan->refusals))->toContain('No monthly batch is dated '.PAYOUT_REBUILD_BATCH_DATE);
});

it('un-builds the batch and re-freezes every amount through the payout close', function (): void {
    seedMonthlyCredit();
    $developer = payoutRebuildDeveloper();

    expect(Artisan::call('compensation:monthly-payout-close', ['--month' => PAYOUT_REBUILD_MONTH]))->toBe(0);

    $batch = PayoutBatch::where('batch_type', PayoutBatch::TYPE_MONTHLY)
        ->whereDate('batch_date', PAYOUT_REBUILD_BATCH_DATE)
        ->firstOrFail();

    $before = PayoutLineItem::where('payout_batch_id', $batch->id)
        ->get(['distributor_id', 'gross_paise', 'admin_charge_paise', 'tds_paise', 'net_transferred_paise'])
        ->toArray();

    expect($before)->not->toBeEmpty();

    $plan = app(RebuildPlanner::class)->plan(RebuildKind::Payout, Carbon::parse(PAYOUT_REBUILD_MONTH.'-01'));

    expect($plan->refusals)->toBe([]);
    expect($plan->rerunCommand())->toBe('compensation:monthly-payout-close --month='.PAYOUT_REBUILD_MONTH);
    expect($plan->unsweeps)->toBeGreaterThan(0);

    expect(Artisan::call('compensation:rebuild-payout', [
        '--month' => PAYOUT_REBUILD_MONTH,
        '--actor' => $developer->id,
        '--yes' => true,
    ]))->toBe(0);

    $rebuilt = PayoutBatch::where('batch_type', PayoutBatch::TYPE_MONTHLY)
        ->whereDate('batch_date', PAYOUT_REBUILD_BATCH_DATE)
        ->firstOrFail();

    expect($rebuilt->id)->not->toBe($batch->id);
    expect($rebuilt->created_by)->toBe($developer->id);
    expect(PayoutLineItem::where('payout_batch_id', $rebuilt->id)
        ->get(['distributor_id', 'gross_paise', 'admin_charge_paise', 'tds_paise', 'net_transferred_paise'])
        ->toArray())->toBe($before);

    expect(EngineRun::where('engine_key', 'compensation.rebuild-payout')->value('status'))
        ->toBe(EngineRun::STATUS_SUCCEEDED);
    expect(AuditLog::where('action', 'payout.batch.unbuilt')->value('actor_id'))->toBe($developer->id);
});

it('warns about later batches whose cap headroom this one changes', function (): void {
    seedMonthlyCredit();

    expect(Artisan::call('compensation:monthly-payout-close', ['--month' => PAYOUT_REBUILD_MONTH]))->toBe(0);

    $later = PayoutBatch::create([
        'batch_type' => PayoutBatch::TYPE_WEEKLY,
        'batch_date' => '2026-09-08',
        'status' => PayoutBatch::STATUS_PENDING,
    ]);

    $warnings = implode("\n", app(RebuildPlanner::class)
        ->plan(RebuildKind::Payout, Carbon::parse(PAYOUT_REBUILD_MONTH.'-01'))
        ->warnings);

    expect($warnings)->toContain('Rebuild these too, oldest first: #'.$later->id);
    expect($warnings)->toContain('compensation:rebuild-week --date=2026-09-08');
    expect($warnings)->toContain('records you as its maker');
});

it('removes the batch and records a failed rebuild when the crediting gate is shut', function (): void {
    $distributor = seedMonthlyCredit();
    $developer = payoutRebuildDeveloper();

    // Built by hand while the gate was open…
    $batch = app(PayoutService::class)->runMonthlyBatch(Carbon::parse(PAYOUT_REBUILD_BATCH_DATE));

    expect(WalletLedgerEntry::where('swept_by_payout_batch_id', $batch->id)->count())->toBeGreaterThan(0);

    // …and now Rank Bonus is on with no succeeded run for the month, so the
    // payout close refuses and names it.
    Feature::for(null)->activate(RankBonusFeature::class);

    $plan = app(RebuildPlanner::class)->plan(RebuildKind::Payout, Carbon::parse(PAYOUT_REBUILD_MONTH.'-01'));

    expect($plan->refusals)->toBe([]);
    expect(implode("\n", $plan->warnings))->toContain('The payout close will refuse (rank.');

    expect(Artisan::call('compensation:rebuild-payout', [
        '--month' => PAYOUT_REBUILD_MONTH,
        '--actor' => $developer->id,
        '--yes' => true,
    ]))->toBe(1);

    // The stale batch is gone and the credits are back in the pool, waiting for
    // the monthly run to build a correct batch once the crediting is green.
    expect(PayoutBatch::find($batch->id))->toBeNull();
    expect(WalletLedgerEntry::where('distributor_id', $distributor->id)
        ->where('type', 'rank_credit')
        ->whereNotNull('swept_by_payout_batch_id')
        ->count())->toBe(0);

    expect(EngineRun::where('engine_key', 'compensation.rebuild-payout')->value('status'))
        ->toBe(EngineRun::STATUS_FAILED);
    expect(AuditLog::where('action', 'compensation.rebuild.rerun_failed')->exists())->toBeTrue();
});

it('refuses a batch finance has approved', function (): void {
    seedMonthlyCredit();

    PayoutBatch::create([
        'batch_type' => PayoutBatch::TYPE_MONTHLY,
        'batch_date' => PAYOUT_REBUILD_BATCH_DATE,
        'status' => PayoutBatch::STATUS_APPROVED,
        'approved_at' => Carbon::parse('2026-09-08 10:00:00'),
    ]);

    $plan = app(RebuildPlanner::class)->plan(RebuildKind::Payout, Carbon::parse(PAYOUT_REBUILD_MONTH.'-01'));

    expect($plan->isRefused())->toBeTrue();
    expect(implode("\n", $plan->refusals))->toContain('finance has signed off is not rebuilt');
});

it('refuses a batch stuck in processing and names the reopen command', function (): void {
    seedMonthlyCredit();

    PayoutBatch::create([
        'batch_type' => PayoutBatch::TYPE_MONTHLY,
        'batch_date' => PAYOUT_REBUILD_BATCH_DATE,
        'status' => PayoutBatch::STATUS_PROCESSING,
    ]);

    $plan = app(RebuildPlanner::class)->plan(RebuildKind::Payout, Carbon::parse(PAYOUT_REBUILD_MONTH.'-01'));

    expect($plan->isRefused())->toBeTrue();
    expect(implode("\n", $plan->refusals))->toContain('payout:reopen-stuck-batch --type=monthly --date='.PAYOUT_REBUILD_BATCH_DATE);
});
