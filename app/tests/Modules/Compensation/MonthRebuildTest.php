<?php

declare(strict_types=1);

use App\Modules\Commerce\Models\PurchaseOfferGrant;
use App\Modules\Commerce\Models\RedeemPointEntry;
use App\Modules\Compensation\Models\AdcBonusResult;
use App\Modules\Compensation\Models\EngineRun;
use App\Modules\Compensation\Models\FortuneBonusParticipant;
use App\Modules\Compensation\Models\FortuneBonusResult;
use App\Modules\Compensation\Models\FortuneMonthlyPool;
use App\Modules\Compensation\Models\FortuneMonthlyPoolLevel;
use App\Modules\Compensation\Models\GbbMonthlyPool;
use App\Modules\Compensation\Models\GbbMonthlyResult;
use App\Modules\Compensation\Models\LifetimeAwardMilestone;
use App\Modules\Compensation\Models\PayoutBatch;
use App\Modules\Compensation\Models\PayoutLineItem;
use App\Modules\Compensation\Models\RankAogoGrant;
use App\Modules\Compensation\Models\RankBonusResult;
use App\Modules\Compensation\Models\RankMonthlyPool;
use App\Modules\Compensation\Models\RankQualification;
use App\Modules\Compensation\Models\WalletLedgerEntry;
use App\Modules\Compensation\Services\EngineStatusService;
use App\Modules\Compensation\Services\Rebuild\RebuildKind;
use App\Modules\Compensation\Services\Rebuild\RebuildPlanner;
use App\Modules\Compensation\Services\WalletService;
use App\Modules\Compliance\Models\AuditLog;
use App\Modules\Identity\Models\Distributor;
use App\Modules\Identity\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Console\Command;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\WithConsoleEvents;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class, WithConsoleEvents::class);

/** The crediting month under test, and a night inside the month after it. */
const REBUILD_MONTH = '2026-08';

const REBUILD_MONTH_START = '2026-08-01';

/**
 * A stand-in for the monthly close: the same artisan name and the same two
 * options, so RecordEngineRun still writes its row and the rebuild's `--restart`
 * is observable, but it runs none of the seven engines.
 */
final class StubMonthlyClose extends Command
{
    /** @var list<array{month: string|null, restart: bool}> */
    public static array $calls = [];

    public static int $exitCode = 0;

    protected $signature = 'compensation:monthly-close {--month=} {--force} {--restart}';

    protected $description = 'Test stub for the monthly close';

    public function handle(): int
    {
        $month = $this->option('month');

        self::$calls[] = [
            'month' => is_string($month) ? $month : null,
            'restart' => (bool) $this->option('restart'),
        ];

        return self::$exitCode;
    }
}

beforeEach(function (): void {
    disableTestForeignKeys();
    app(RolesAndPermissionsSeeder::class)->run();
    Carbon::setTestNow(Carbon::parse('2026-09-15 04:00:00', 'Asia/Kolkata'));

    StubMonthlyClose::$calls = [];
    StubMonthlyClose::$exitCode = 0;
    app(Kernel::class)->registerCommand(new StubMonthlyClose);
});

afterEach(function (): void {
    Carbon::setTestNow();
});

function monthRebuildDeveloper(): User
{
    $user = User::create([
        'full_name' => 'Platform Developer',
        'email' => 'month-rebuild-'.uniqid().'@test.com',
        'phone_e164' => '+91'.str_pad((string) random_int(7000000000, 9999999999), 10, '0'),
        'password_hash' => bcrypt('x'),
        'status' => 'active',
        'email_verified_at' => now(),
    ]);
    $user->assignRole('developer');

    return $user;
}

/**
 * One row in every table the month wipe touches, plus one row of each kind that
 * must SURVIVE it.
 *
 * @return array{0: Distributor, 1: RankBonusResult, 2: GbbMonthlyResult}
 */
function seedCreditingMonth(): array
{
    $distributor = Distributor::factory()->create();
    $month = REBUILD_MONTH_START;

    $rank = RankBonusResult::create([
        'distributor_id' => $distributor->id, 'month_start' => $month, 'rank_number' => 1,
        'company_turnover_paise' => 0, 'pool_paise' => 0, 'qualifier_count' => 1,
        'gross_paise' => 100_000, 'admin_charge_paise' => 0, 'tds_paise' => 0, 'net_paise' => 100_000,
        'status' => 'credited',
    ]);

    $gbb = GbbMonthlyResult::create([
        'distributor_id' => $distributor->id, 'year_month' => $month, 'status' => 'credited',
    ]);

    RankAogoGrant::create([
        'distributor_id' => $distributor->id, 'month_start' => $month,
        'grant_number' => 1, 'points' => 5, 'previous_rank_number' => 1,
    ]);
    RankMonthlyPool::create([
        'month_start' => $month, 'rank_number' => 1, 'company_turnover_paise' => 0, 'envelope_bp' => 0,
        'pool_pct' => 0, 'pool_paise' => 0, 'payable_count' => 0, 'aogo_points' => 0,
        'gross_per_qualifier_paise' => 0, 'payout_paise' => 0, 'leftover_paise' => 0,
    ]);
    RankQualification::create([
        'distributor_id' => $distributor->id, 'rank_number' => 1, 'month_start' => $month,
        'is_carry_forward' => false,
    ]);
    // Written by an EARLIER month's check for this month — that month's output,
    // not this one's, so the wipe must leave it.
    RankQualification::create([
        'distributor_id' => $distributor->id, 'rank_number' => 2, 'month_start' => $month,
        'is_carry_forward' => true,
    ]);
    LifetimeAwardMilestone::create([
        'distributor_id' => $distributor->id, 'rank_number' => 1, 'triggered_month' => $month,
        'award_description' => 'Pending award', 'status' => LifetimeAwardMilestone::STATUS_PENDING,
    ]);
    // Already handed over: a decision outside the engines, kept.
    LifetimeAwardMilestone::create([
        'distributor_id' => $distributor->id, 'rank_number' => 2, 'triggered_month' => $month,
        'award_description' => 'Delivered award', 'status' => LifetimeAwardMilestone::STATUS_DELIVERED,
    ]);
    GbbMonthlyPool::create([
        'month_start' => $month, 'company_bv_paise' => 0, 'pool_rate_bp' => 0, 'pool_paise' => 0,
        'total_agp' => 0, 'point_value_paise' => 0, 'payout_paise' => 0, 'leftover_paise' => 0,
    ]);
    FortuneBonusResult::create([
        'distributor_id' => $distributor->id, 'month_start' => $month, 'position' => 1, 'matrix_level' => 1,
    ]);
    $pool = FortuneMonthlyPool::create([
        'month_start' => $month, 'company_bv_paise' => 0, 'pool_rate_bp' => 0, 'pool_paise' => 0,
        'total_points' => 0, 'payout_paise' => 0, 'leftover_paise' => 0,
    ]);
    FortuneMonthlyPoolLevel::create([
        'fortune_monthly_pool_id' => $pool->id, 'matrix_level' => 1, 'payout_mode' => 'points',
        'participants' => 0, 'points' => 0, 'point_value_paise' => 0, 'paid_paise' => 0,
    ]);
    FortuneBonusParticipant::create([
        'distributor_id' => $distributor->id, 'month_start' => $month, 'position' => 1,
        'matrix_level' => 1, 'eligibility_tier' => 'standard',
    ]);
    AdcBonusResult::create([
        'center_id' => 1, 'distributor_id' => $distributor->id, 'month_start' => $month,
    ]);

    $grant = PurchaseOfferGrant::create([
        'distributor_id' => $distributor->id, 'offer_type' => 'redeem_points', 'month_start' => $month,
    ]);
    RedeemPointEntry::create([
        'distributor_id' => $distributor->id, 'points' => 500, 'type' => 'accrual',
        'reference_type' => 'purchase_offer_grant', 'reference_id' => $grant->id,
    ]);
    // Already spent on an order: re-deriving it would let that discount be taken
    // twice (F124), so it stays and its points stay with it.
    $consumed = PurchaseOfferGrant::create([
        // A different offer_type: one grant per distributor per offer per month.
        'distributor_id' => $distributor->id, 'offer_type' => 'half_price_product', 'month_start' => $month,
        'consumed_order_id' => 12_345,
    ]);
    RedeemPointEntry::create([
        'distributor_id' => $distributor->id, 'points' => 500, 'type' => 'accrual',
        'reference_type' => 'purchase_offer_grant', 'reference_id' => $consumed->id,
    ]);

    $wallet = app(WalletService::class);
    $wallet->creditWithRepurchaseDeduction(
        distributorId: $distributor->id,
        grossPaise: 100_000,
        bonusType: 'rank_credit',
        referenceId: $rank->id,
        referenceType: 'rank_bonus_result',
        bonusMonth: Carbon::parse($month),
    );
    $wallet->credit(
        distributorId: $distributor->id,
        amountPaise: 50_000,
        type: 'gbb_credit',
        referenceId: $gbb->id,
        referenceType: 'gbb_monthly_result',
        bonusMonth: Carbon::parse($month),
    );
    // A lifetime award is released by hand, never by an engine — a rebuild does
    // not take one back.
    $wallet->credit(
        distributorId: $distributor->id,
        amountPaise: 25_000,
        type: 'awards_credit',
        referenceId: walletRef(),
        referenceType: 'lifetime_award_milestone',
        bonusMonth: Carbon::parse($month),
    );

    return [$distributor, $rank, $gbb];
}

it('wipes every one of the month\'s tables and leaves what the engines did not write', function (): void {
    seedCreditingMonth();
    $developer = monthRebuildDeveloper();

    $plan = app(RebuildPlanner::class)->plan(RebuildKind::Month, Carbon::parse(REBUILD_MONTH_START));

    expect($plan->refusals)->toBe([]);
    expect($plan->rowsToRemove)->toHaveKeys([
        'wallet_ledger_entries', 'rank_aogo_grants', 'rank_bonus_results', 'rank_monthly_pools',
        'rank_qualifications', 'lifetime_award_milestones', 'gbb_monthly_results', 'gbb_monthly_pools',
        'fortune_bonus_results', 'fortune_monthly_pool_levels', 'fortune_monthly_pools',
        'fortune_bonus_participants', 'adc_bonus_results', 'redeem_point_entries', 'purchase_offer_grants',
    ]);

    expect(Artisan::call('compensation:rebuild-month', [
        '--month' => REBUILD_MONTH,
        '--actor' => $developer->id,
        '--yes' => true,
    ]))->toBe(0);

    expect(RankAogoGrant::count())->toBe(0);
    expect(RankBonusResult::count())->toBe(0);
    expect(RankMonthlyPool::count())->toBe(0);
    expect(LifetimeAwardMilestone::where('status', LifetimeAwardMilestone::STATUS_PENDING)->count())->toBe(0);
    expect(GbbMonthlyResult::count())->toBe(0);
    expect(GbbMonthlyPool::count())->toBe(0);
    expect(FortuneBonusResult::count())->toBe(0);
    expect(FortuneMonthlyPoolLevel::count())->toBe(0);
    expect(FortuneMonthlyPool::count())->toBe(0);
    expect(FortuneBonusParticipant::count())->toBe(0);
    expect(AdcBonusResult::count())->toBe(0);

    // What the engines did not write survives.
    expect(RankQualification::count())->toBe(1);
    expect(RankQualification::first()->is_carry_forward)->toBeTrue();
    expect(LifetimeAwardMilestone::count())->toBe(1);
    expect(LifetimeAwardMilestone::first()->status)->toBe(LifetimeAwardMilestone::STATUS_DELIVERED);
    expect(PurchaseOfferGrant::count())->toBe(1);
    expect(PurchaseOfferGrant::first()->consumed_order_id)->toBe(12_345);
    expect(RedeemPointEntry::count())->toBe(1);

    // The month's credits and their repurchase halves are gone; the hand-released
    // award credit is not.
    expect(WalletLedgerEntry::whereIn('type', ['rank_credit', 'gbb_credit', 'repurchase_transfer', 'repurchase_deduction'])->count())->toBe(0);
    expect(WalletLedgerEntry::where('type', 'awards_credit')->count())->toBe(1);

    // The close was re-run for the month, with --restart.
    expect(StubMonthlyClose::$calls)->toBe([['month' => REBUILD_MONTH, 'restart' => true]]);
});

it('un-builds the month\'s pending payout batch first and says the batch has to come back', function (): void {
    [$distributor] = seedCreditingMonth();
    $developer = monthRebuildDeveloper();

    $batch = PayoutBatch::create([
        'batch_type' => PayoutBatch::TYPE_MONTHLY,
        'batch_date' => '2026-09-01',
        'status' => PayoutBatch::STATUS_PENDING,
        'processed_at' => Carbon::parse('2026-09-08 04:00:00'),
    ]);

    WalletLedgerEntry::where('distributor_id', $distributor->id)
        ->where('type', 'rank_credit')
        ->update(['swept_by_payout_batch_id' => $batch->id]);

    $creditsOnly = app(RebuildPlanner::class)
        ->plan(RebuildKind::Month, Carbon::parse(REBUILD_MONTH_START))
        ->rowsToRemove['wallet_ledger_entries'];

    // One frozen line and the payout debit written with it. Both go when the
    // batch is un-built, so the preview has to count them: a preview that says
    // "1,200 wallet rows" and removes 2,400 is a confirm nobody gave.
    $line = PayoutLineItem::create([
        'payout_batch_id' => $batch->id,
        'distributor_id' => $distributor->id,
        'wallet_balance_paise' => 100_000,
        'repurchase_deduction_paise' => 0,
        'net_transferred_paise' => 100_000,
        'status' => PayoutLineItem::STATUS_PENDING,
    ]);
    app(WalletService::class)->debit(
        distributorId: $distributor->id,
        amountPaise: 100_000,
        type: 'payout_debit',
        referenceId: $line->id,
        referenceType: 'payout_line_item',
    );

    $plan = app(RebuildPlanner::class)->plan(RebuildKind::Month, Carbon::parse(REBUILD_MONTH_START));

    expect($plan->refusals)->toBe([]);
    expect($plan->unsweeps)->toBe(1);
    expect($plan->rowsToRemove['wallet_ledger_entries'])->toBe($creditsOnly + 1);
    expect($plan->rowsToRemove['payout_line_items'])->toBe(1);
    expect(implode("\n", $plan->warnings))->toContain('compensation:rebuild-payout --month='.REBUILD_MONTH);
    expect(implode("\n", $plan->warnings))->toContain('1 purchase-offer grant(s) already used on an order are kept');
    expect(implode("\n", $plan->warnings))->toContain('Repurchase cycle verdicts');

    expect(Artisan::call('compensation:rebuild-month', [
        '--month' => REBUILD_MONTH,
        '--actor' => $developer->id,
        '--yes' => true,
    ]))->toBe(0);

    expect(PayoutBatch::find($batch->id))->toBeNull();
    expect(PayoutLineItem::count())->toBe(0);
    expect(WalletLedgerEntry::where('type', 'payout_debit')->count())->toBe(0);
    expect(AuditLog::where('action', 'payout.batch.unbuilt')->exists())->toBeTrue();

    // And what the wipe reported is what it removed, the batch's debit included.
    $wiped = AuditLog::where('action', 'compensation.rebuild.wiped')->firstOrFail();
    expect($wiped->details['removed']['wallet_ledger_entries'])->toBe($creditsOnly + 1);
});

it('refuses a frozen month', function (): void {
    seedCreditingMonth();

    PayoutBatch::create([
        'batch_type' => PayoutBatch::TYPE_MONTHLY,
        'batch_date' => '2026-09-01',
        'status' => PayoutBatch::STATUS_APPROVED,
        'approved_at' => Carbon::parse('2026-09-08 10:00:00'),
    ]);

    $plan = app(RebuildPlanner::class)->plan(RebuildKind::Month, Carbon::parse(REBUILD_MONTH_START));

    expect($plan->isRefused())->toBeTrue();
    expect(implode("\n", $plan->refusals))->toContain('August 2026 is frozen');
});

it('refuses once the next month has been closed on it', function (): void {
    seedCreditingMonth();

    EngineRun::create([
        'engine_key' => 'compensation.monthly-close',
        'period_start' => '2026-09-01',
        'status' => EngineRun::STATUS_SUCCEEDED,
        'trigger' => EngineRun::TRIGGER_CONSOLE,
        'started_at' => Carbon::parse('2026-10-01 00:20:00'),
        'finished_at' => Carbon::parse('2026-10-01 00:40:00'),
    ]);

    $plan = app(RebuildPlanner::class)->plan(RebuildKind::Month, Carbon::parse(REBUILD_MONTH_START));

    expect($plan->isRefused())->toBeTrue();
    expect(implode("\n", $plan->refusals))->toContain('DN-5');
});

it('refuses when one of the month\'s credits was paid by a frozen batch', function (): void {
    [$distributor] = seedCreditingMonth();

    $frozen = PayoutBatch::create([
        'batch_type' => PayoutBatch::TYPE_WEEKLY,
        'batch_date' => '2026-09-08',
        'status' => PayoutBatch::STATUS_COMPLETED,
    ]);

    WalletLedgerEntry::where('distributor_id', $distributor->id)
        ->where('type', 'rank_credit')
        ->update(['swept_by_payout_batch_id' => $frozen->id]);

    $plan = app(RebuildPlanner::class)->plan(RebuildKind::Month, Carbon::parse(REBUILD_MONTH_START));

    expect($plan->isRefused())->toBeTrue();
    expect(implode("\n", $plan->refusals))->toContain('money that left cannot be recomputed');
});

it('refuses when one of the month\'s credits was reversed', function (): void {
    [$distributor, $rank] = seedCreditingMonth();

    DB::table('wallet_ledger_entries')->insert([
        'distributor_id' => $distributor->id,
        'type' => 'reversal',
        'amount_paise' => -100_000,
        'reference_id' => $rank->id,
        'reference_type' => 'rank_bonus_result',
        'bonus_month' => REBUILD_MONTH_START,
        'created_at' => now(),
    ]);

    $plan = app(RebuildPlanner::class)->plan(RebuildKind::Month, Carbon::parse(REBUILD_MONTH_START));

    expect($plan->isRefused())->toBeTrue();
    expect(implode("\n", $plan->refusals))->toContain('reversed by an admin decision');
});

it('records the rebuild as failed and leaves the month unclosed when the close fails', function (): void {
    seedCreditingMonth();
    $developer = monthRebuildDeveloper();
    StubMonthlyClose::$exitCode = 1;

    expect(Artisan::call('compensation:rebuild-month', [
        '--month' => REBUILD_MONTH,
        '--actor' => $developer->id,
        '--yes' => true,
    ]))->toBe(1);

    expect(RankBonusResult::count())->toBe(0);
    expect(app(EngineStatusService::class)->hasSucceededRun('compensation.monthly-close', Carbon::parse(REBUILD_MONTH_START)))
        ->toBeFalse();
    expect(EngineRun::where('engine_key', 'compensation.rebuild-month')->value('status'))
        ->toBe(EngineRun::STATUS_FAILED);
    expect(AuditLog::where('action', 'compensation.rebuild.rerun_failed')->exists())->toBeTrue();
});

it('refuses when one of the month\'s credits was swept by a batch this rebuild does not un-build', function (): void {
    [$distributor] = seedCreditingMonth();

    // The monthly sweep window is "earned in this month or before", so a batch
    // for a LATER crediting month legitimately collects August's leftover
    // credits — and a sales-free September can build one with no engine run at
    // all (D1/R-101), so the "M+1 has a succeeded run" refusal never fires. It
    // is pending, not frozen, and it is dated the 1st of October, so
    // FrozenPayoutGuard::batchFor(August) never finds it either.
    $later = PayoutBatch::create([
        'batch_type' => PayoutBatch::TYPE_MONTHLY,
        'batch_date' => '2026-10-01',
        'status' => PayoutBatch::STATUS_PENDING,
        'processed_at' => Carbon::parse('2026-10-08 04:00:00'),
    ]);

    WalletLedgerEntry::where('distributor_id', $distributor->id)
        ->where('type', 'rank_credit')
        ->update(['swept_by_payout_batch_id' => $later->id]);

    $plan = app(RebuildPlanner::class)->plan(RebuildKind::Month, Carbon::parse(REBUILD_MONTH_START));

    expect($plan->isRefused())->toBeTrue();
    expect(implode("\n", $plan->refusals))->toContain('money that left cannot be recomputed');
    expect(implode("\n", $plan->refusals))->toContain('#'.$later->id);
});

it('refuses when the month\'s repurchase-wallet credits have since been spent', function (): void {
    [$distributor] = seedCreditingMonth();

    expect(WalletLedgerEntry::where('type', 'repurchase_deduction')->count())->toBeGreaterThan(0);

    // The credit funded a discount on a repurchase order. That money left.
    app(WalletService::class)->debit(
        distributorId: $distributor->id,
        amountPaise: 1_000,
        type: 'repurchase_wallet_used',
        referenceId: 4_242,
        referenceType: 'order',
    );

    $plan = app(RebuildPlanner::class)->plan(RebuildKind::Month, Carbon::parse(REBUILD_MONTH_START));

    expect($plan->isRefused())->toBeTrue();
    expect(implode("\n", $plan->refusals))->toContain('repurchase wallet has been drawn on');
});

it('records how much credited income the wipe removed', function (): void {
    seedCreditingMonth();
    $developer = monthRebuildDeveloper();

    expect(Artisan::call('compensation:rebuild-month', [
        '--month' => REBUILD_MONTH,
        '--actor' => $developer->id,
        '--yes' => true,
    ]))->toBe(0);

    $wiped = AuditLog::where('action', 'compensation.rebuild.wiped')->firstOrFail();

    expect($wiped->details['wallet_totals']['rank_credit']['count'])->toBe(1);
    expect($wiped->details['wallet_totals']['rank_credit']['paise'])->toBe(100_000);
    expect($wiped->details['wallet_totals']['gbb_credit']['paise'])->toBe(50_000);
});
