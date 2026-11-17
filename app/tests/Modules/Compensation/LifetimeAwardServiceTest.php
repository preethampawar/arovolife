<?php

declare(strict_types=1);

use App\Modules\Admin\Events\DistributorTerminated;
use App\Modules\Admin\Services\TerminateDistributor;
use App\Modules\Commerce\Models\BvLedgerEntry;
use App\Modules\Compensation\Models\LifetimeAwardMilestone;
use App\Modules\Compensation\Models\PayoutBatch;
use App\Modules\Compensation\Models\RankQualification;
use App\Modules\Compensation\Models\WalletLedgerEntry;
use App\Modules\Compensation\Notifications\LifetimeAwardWithdrawnNotification;
use App\Modules\Compensation\Services\CompensationPlanSettingsService;
use App\Modules\Compensation\Services\LifetimeAwardService;
use App\Modules\Compensation\Services\WalletService;
use App\Modules\Compliance\Events\CoolingOffCancelled;
use App\Modules\Compliance\Models\AuditLog;
use App\Modules\Compliance\Services\CancelCoolingOff;
use App\Modules\Identity\Models\Distributor;
use App\Modules\Identity\Models\User;
use App\Modules\Shared\Features\LifetimeAwardsFeature;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Laravel\Pennant\Feature;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    disableTestForeignKeys();
    Feature::for(null)->activate(LifetimeAwardsFeature::class);
    awardSetting('comp.awards.cash_effective_month', '2026-01');
});

function awardSetting(string $key, string $value): void
{
    DB::table('settings')->updateOrInsert(['key' => $key], ['value' => $value, 'version' => 1, 'updated_at' => now()]);
    app()->forgetInstance(CompensationPlanSettingsService::class);
    app()->forgetInstance(LifetimeAwardService::class);
}

function awardQualify(int $distributorId, int $rank, string $monthStart, ?int $leftBv = null, ?int $rightBv = null): RankQualification
{
    return RankQualification::create([
        'distributor_id' => $distributorId,
        'rank_number' => $rank,
        'month_start' => $monthStart,
        'left_genos_bv_paise' => $leftBv,
        'right_genos_bv_paise' => $rightBv,
        'occurrence_in_month' => 1,
        'is_carry_forward' => false,
        'status' => RankQualification::STATUS_QUALIFIED,
    ]);
}

/**
 * Close one month for a set of roster rows, as the Rank Bonus credit step
 * does: plan read-only, then credit inside a transaction.
 *
 * @param  array<int, list<int>>  $roster  distributor id → payable roster ranks
 */
function awardClose(string $monthStart, array $roster): int
{
    $service = app(LifetimeAwardService::class);
    $plan = $service->planForMonth(Carbon::parse($monthStart), $roster);

    return DB::transaction(fn (): int => $service->creditPlan($plan)->credited);
}

/** @return list<int> */
function awardedRanks(int $distributorId): array
{
    return array_values(LifetimeAwardMilestone::where('distributor_id', $distributorId)->orderBy('rank_number')->pluck('rank_number')->map(fn ($rank): int => (int) $rank)->all());
}

it('credits a first-time Rank 1 award at the rank amount with its evidence snapshot', function (): void {
    $dist = Distributor::factory()->create();
    BvLedgerEntry::create(['distributor_id' => $dist->id, 'order_id' => 880_001, 'bv_paise' => 450_000, 'type' => 'accrual', 'effective_at' => '2026-03-10 10:00:00']);
    $qualification = awardQualify($dist->id, 1, '2026-03-01', leftBv: 1_500_000, rightBv: 1_600_000);

    expect(awardClose('2026-03-01', [$dist->id => [1]]))->toBe(1);

    $milestone = LifetimeAwardMilestone::where('distributor_id', $dist->id)->sole();
    expect($milestone->status)->toBe(LifetimeAwardMilestone::STATUS_CREDITED)
        ->and($milestone->amount_paise)->toBe(app(CompensationPlanSettingsService::class)->lifetimeAwardBudgetPaise(1))
        ->and($milestone->achieved_month->toDateString())->toBe('2026-03-01')
        ->and($milestone->rank_qualification_id)->toBe($qualification->id)
        ->and($milestone->personal_bv_paise)->toBe(450_000)
        ->and($milestone->left_genos_bv_paise)->toBe(1_500_000)
        ->and($milestone->right_genos_bv_paise)->toBe(1_600_000)
        ->and($milestone->credited_at)->not->toBeNull();

    expect(WalletLedgerEntry::where('type', 'awards_credit')->where('reference_id', $milestone->id)->value('amount_paise'))->toBe($milestone->amount_paise)
        ->and(AuditLog::where('action', 'awards.milestone.credited')->where('subject_id', $milestone->id)->count())->toBe(1);
});

it('records the rank award name when the award is earned, and keeps it after a rename', function (): void {
    DB::table('rank_tiers')->where('rank_number', 1)->update(['lifetime_award_name' => 'iPhone 17']);
    app()->forgetInstance(CompensationPlanSettingsService::class);
    app()->forgetInstance(LifetimeAwardService::class);
    $dist = Distributor::factory()->create();
    awardQualify($dist->id, 1, '2026-03-01', leftBv: 1_500_000, rightBv: 1_600_000);

    awardClose('2026-03-01', [$dist->id => [1]]);
    DB::table('rank_tiers')->where('rank_number', 1)->update(['lifetime_award_name' => 'Goa trip']);

    $rankName = app(CompensationPlanSettingsService::class)->rankName(1);
    expect(LifetimeAwardMilestone::where('distributor_id', $dist->id)->sole()->award_description)
        ->toBe($rankName.' Lifetime Award — iPhone 17');
});

it('uses the default award text while no award name is set', function (): void {
    $dist = Distributor::factory()->create();
    awardQualify($dist->id, 1, '2026-03-01', leftBv: 1_500_000, rightBv: 1_600_000);

    awardClose('2026-03-01', [$dist->id => [1]]);

    $rankName = app(CompensationPlanSettingsService::class)->rankName(1);
    expect(LifetimeAwardMilestone::where('distributor_id', $dist->id)->sole()->award_description)
        ->toBe($rankName.' Lifetime Award');
});

it('pays only the rank reached on a jump — skipped ranks are never paid', function (): void {
    $dist = Distributor::factory()->create();
    awardQualify($dist->id, 2, '2026-03-01');

    awardClose('2026-03-01', [$dist->id => [2]]);

    expect(awardedRanks($dist->id))->toBe([2]);

    // Rank 1 later is below an award already paid: nothing, ever.
    awardQualify($dist->id, 1, '2026-06-01');
    awardClose('2026-06-01', [$dist->id => [1]]);

    expect(awardedRanks($dist->id))->toBe([2]);
});

it('pays Rank 1 in one month and Rank 2 in a later one', function (): void {
    $dist = Distributor::factory()->create();
    awardQualify($dist->id, 1, '2026-01-01');
    awardQualify($dist->id, 2, '2026-03-01');

    awardClose('2026-01-01', [$dist->id => [1]]);
    awardClose('2026-03-01', [$dist->id => [2]]);

    expect(awardedRanks($dist->id))->toBe([1, 2])
        ->and(WalletLedgerEntry::where('type', 'awards_credit')->where('distributor_id', $dist->id)->count())->toBe(2);
});

it('takes the highest of several roster ranks in one month', function (): void {
    $dist = Distributor::factory()->create();
    awardQualify($dist->id, 1, '2026-03-01');
    awardQualify($dist->id, 2, '2026-03-01');

    awardClose('2026-03-01', [$dist->id => [1, 2]]);

    expect(awardedRanks($dist->id))->toBe([2]);
});

it('writes nothing when the same month is closed twice', function (): void {
    $dist = Distributor::factory()->create();
    awardQualify($dist->id, 1, '2026-03-01');

    expect(awardClose('2026-03-01', [$dist->id => [1]]))->toBe(1)
        ->and(awardClose('2026-03-01', [$dist->id => [1]]))->toBe(0)
        ->and(WalletLedgerEntry::where('type', 'awards_credit')->count())->toBe(1);
});

it('writes nothing while the flag is off, and nothing for a month before the cash effective month', function (): void {
    $dist = Distributor::factory()->create();
    awardQualify($dist->id, 1, '2026-03-01');

    Feature::for(null)->deactivate(LifetimeAwardsFeature::class);
    expect(awardClose('2026-03-01', [$dist->id => [1]]))->toBe(0);

    Feature::for(null)->activate(LifetimeAwardsFeature::class);
    awardSetting('comp.awards.cash_effective_month', '2026-04');
    expect(awardClose('2026-03-01', [$dist->id => [1]]))->toBe(0);

    awardSetting('comp.awards.cash_effective_month', '');
    expect(awardClose('2026-03-01', [$dist->id => [1]]))->toBe(0)
        ->and(LifetimeAwardMilestone::count())->toBe(0);
});

it('skips a rank whose award amount is ₹0', function (): void {
    DB::table('rank_tiers')->where('rank_number', 1)->update(['lifetime_award_budget_paise' => 0]);
    app()->forgetInstance(CompensationPlanSettingsService::class);
    app()->forgetInstance(LifetimeAwardService::class);
    $dist = Distributor::factory()->create();
    awardQualify($dist->id, 1, '2026-03-01');

    expect(awardClose('2026-03-01', [$dist->id => [1]]))->toBe(0)
        ->and(LifetimeAwardMilestone::count())->toBe(0);
});

it('never re-creates a withdrawn award', function (): void {
    $dist = Distributor::factory()->create();
    awardQualify($dist->id, 1, '2026-03-01');
    awardClose('2026-03-01', [$dist->id => [1]]);

    $milestone = LifetimeAwardMilestone::where('distributor_id', $dist->id)->sole();
    app(LifetimeAwardService::class)->withdraw($milestone, 'Terminated', null, 'admin.lifetime_award.withdrawn');

    awardQualify($dist->id, 1, '2026-04-01');
    awardClose('2026-04-01', [$dist->id => [1]]);

    expect(LifetimeAwardMilestone::where('distributor_id', $dist->id)->sole()->status)->toBe(LifetimeAwardMilestone::STATUS_CANCELLED);
});

it('keeps the amount frozen when the rank amount changes afterwards', function (): void {
    $dist = Distributor::factory()->create();
    awardQualify($dist->id, 1, '2026-03-01');
    awardClose('2026-03-01', [$dist->id => [1]]);
    $amount = LifetimeAwardMilestone::sole()->amount_paise;

    DB::table('rank_tiers')->where('rank_number', 1)->update(['lifetime_award_budget_paise' => $amount + 100_000]);
    app()->forgetInstance(CompensationPlanSettingsService::class);
    app()->forgetInstance(LifetimeAwardService::class);
    awardClose('2026-03-01', [$dist->id => [1]]);

    expect(LifetimeAwardMilestone::sole()->amount_paise)->toBe($amount);
});

it('lets a rebuilt earlier month re-create its award beside a later month\'s higher one', function (): void {
    // A later month's milestone never blocks an earlier month: a rebuild
    // replays months out of order.
    $dist = Distributor::factory()->create();
    awardQualify($dist->id, 1, '2026-01-01');
    awardQualify($dist->id, 2, '2026-03-01');
    awardClose('2026-03-01', [$dist->id => [2]]);

    awardClose('2026-01-01', [$dist->id => [1]]);

    expect(awardedRanks($dist->id))->toBe([1, 2]);
});

it('names the awards whose rank qualification no longer stands', function (): void {
    $kept = Distributor::factory()->create();
    $voided = Distributor::factory()->create();
    awardQualify($kept->id, 1, '2026-03-01');
    $qualification = awardQualify($voided->id, 1, '2026-03-01');
    awardClose('2026-03-01', [$kept->id => [1], $voided->id => [1]]);

    $qualification->update(['status' => RankQualification::STATUS_VOIDED]);

    $ids = LifetimeAwardMilestone::orderBy('id')->pluck('id')->all();
    expect(app(LifetimeAwardService::class)->milestonesNoLongerQualified($ids))
        ->toBe([LifetimeAwardMilestone::where('distributor_id', $voided->id)->value('id')]);
});

it('withdraws an unpaid credited award by reversing its wallet credit, with a reason and an audit row', function (): void {
    $dist = Distributor::factory()->create();
    awardQualify($dist->id, 1, '2026-03-01');
    awardClose('2026-03-01', [$dist->id => [1]]);
    $milestone = LifetimeAwardMilestone::sole();

    $outcome = app(LifetimeAwardService::class)->withdraw($milestone, 'Cancelled within the cooling-off period.', null, 'awards.milestone.withdrawn_cooling_off');

    expect($outcome)->toBe(LifetimeAwardService::WITHDRAWN)
        ->and($milestone->fresh()->status)->toBe(LifetimeAwardMilestone::STATUS_CANCELLED)
        ->and($milestone->fresh()->notes)->toBe('Cancelled within the cooling-off period.')
        ->and(app(WalletService::class)->balancePaise($dist->id))->toBe(0)
        ->and(WalletLedgerEntry::where('type', 'awards_credit')->notReversed()->count())->toBe(0);

    $audit = AuditLog::where('action', 'awards.milestone.withdrawn_cooling_off')->sole();
    expect($audit->before_hash)->not->toBeNull()
        ->and($audit->after_hash)->not->toBeNull()
        ->and($audit->details['reason'])->toBe('Cancelled within the cooling-off period.');
});

it('refuses to withdraw an award the payout has already swept', function (): void {
    $dist = Distributor::factory()->create();
    awardQualify($dist->id, 1, '2026-03-01');
    awardClose('2026-03-01', [$dist->id => [1]]);
    $milestone = LifetimeAwardMilestone::sole();

    $batch = PayoutBatch::create(['batch_type' => PayoutBatch::TYPE_MONTHLY, 'batch_date' => '2026-03-01', 'status' => PayoutBatch::STATUS_PENDING]);
    WalletLedgerEntry::where('type', 'awards_credit')->update(['swept_by_payout_batch_id' => $batch->id]);

    expect(app(LifetimeAwardService::class)->withdraw($milestone, 'x', null, 'admin.lifetime_award.withdrawn'))->toBe(LifetimeAwardService::ALREADY_PAID)
        ->and($milestone->fresh()->status)->toBe(LifetimeAwardMilestone::STATUS_CREDITED);
});

it('withdraws every unpaid award on a cooling-off cancellation and leaves a paid one paid', function (): void {
    $dist = Distributor::factory()->create();
    awardQualify($dist->id, 1, '2026-01-01');
    awardQualify($dist->id, 2, '2026-03-01');
    awardClose('2026-01-01', [$dist->id => [1]]);
    awardClose('2026-03-01', [$dist->id => [2]]);

    $paid = LifetimeAwardMilestone::where('rank_number', 1)->sole();
    $batch = PayoutBatch::create(['batch_type' => PayoutBatch::TYPE_MONTHLY, 'batch_date' => '2026-01-01', 'status' => PayoutBatch::STATUS_COMPLETED]);
    WalletLedgerEntry::where('type', 'awards_credit')->where('reference_id', $paid->id)->update(['swept_by_payout_batch_id' => $batch->id]);

    CoolingOffCancelled::dispatch($dist->id, (int) $dist->user_id, Carbon::now());

    expect($paid->fresh()->status)->toBe(LifetimeAwardMilestone::STATUS_CREDITED)
        ->and(LifetimeAwardMilestone::where('rank_number', 2)->sole()->status)->toBe(LifetimeAwardMilestone::STATUS_CANCELLED)
        ->and(LifetimeAwardMilestone::where('rank_number', 2)->sole()->notes)->toBe('Cooling-off cancellation.')
        ->and(AuditLog::where('action', 'awards.milestone.withdrawn_cooling_off')->count())->toBe(1);
});

it('withdraws an unpaid award when the account is terminated', function (): void {
    $dist = Distributor::factory()->create();
    awardQualify($dist->id, 1, '2026-03-01');
    awardClose('2026-03-01', [$dist->id => [1]]);

    DistributorTerminated::dispatch($dist->id, null, 'Breach of the Code of Ethics', Carbon::now());

    // The partner sees a fixed text; the admin's reason stays in the audit row.
    $milestone = LifetimeAwardMilestone::sole();
    expect($milestone->status)->toBe(LifetimeAwardMilestone::STATUS_CANCELLED)
        ->and($milestone->notes)->toBe('Account terminated.')
        ->and(app(WalletService::class)->balancePaise($dist->id))->toBe(0)
        ->and(AuditLog::where('action', 'awards.milestone.withdrawn_termination')->sole()->details['reason'])->toBe('Account terminated: Breach of the Code of Ethics');
});

it('pays a rank only the first time it is achieved, even when the first time was before the cash effective month', function (): void {
    // User decision 2026-11-11 (a).
    awardSetting('comp.awards.cash_effective_month', '2026-04');
    $dist = Distributor::factory()->create();
    awardQualify($dist->id, 1, '2026-02-01');
    awardQualify($dist->id, 1, '2026-05-01');

    expect(awardClose('2026-05-01', [$dist->id => [1]]))->toBe(0);

    // A rank not reached before is still paid.
    awardQualify($dist->id, 2, '2026-06-01');
    expect(awardClose('2026-06-01', [$dist->id => [2]]))->toBe(1)
        ->and(awardedRanks($dist->id))->toBe([2]);
});

it('pays no lower-rank award after a higher rank was reached before the cash effective month', function (): void {
    // User decision 2026-11-17: Rank 3 reached in an unpaid month, no milestone.
    awardSetting('comp.awards.cash_effective_month', '2026-04');
    $dist = Distributor::factory()->create();
    awardQualify($dist->id, 3, '2026-02-01');

    awardQualify($dist->id, 2, '2026-05-01');
    expect(awardClose('2026-05-01', [$dist->id => [2]]))->toBe(0)
        ->and(awardedRanks($dist->id))->toBe([]);

    // A rank above the earlier one is still paid.
    awardQualify($dist->id, 4, '2026-06-01');
    expect(awardClose('2026-06-01', [$dist->id => [4]]))->toBe(1)
        ->and(awardedRanks($dist->id))->toBe([4]);
});

it('writes no award for a month that has not closed (an --in-flight run)', function (): void {
    $dist = Distributor::factory()->create();
    $thisMonth = Carbon::now('Asia/Kolkata')->startOfMonth()->toDateString();
    awardQualify($dist->id, 1, $thisMonth);

    expect(awardClose($thisMonth, [$dist->id => [1]]))->toBe(0)
        ->and(LifetimeAwardMilestone::count())->toBe(0);
});

it('treats a withdrawn award as unpayable even when its wallet reversal is missing', function (): void {
    $dist = Distributor::factory()->create();
    awardQualify($dist->id, 1, '2026-03-01');
    awardClose('2026-03-01', [$dist->id => [1]]);
    $milestone = LifetimeAwardMilestone::sole();
    $milestone->update(['status' => LifetimeAwardMilestone::STATUS_CANCELLED]);

    expect(app(LifetimeAwardService::class)->milestonesNoLongerQualified([$milestone->id]))->toBe([$milestone->id]);
});

it('withdraws a credited award through the real cooling-off cancellation, reverses the wallet and tells the partner', function (): void {
    Notification::fake();
    $dist = Distributor::factory()->create();
    awardQualify($dist->id, 1, '2026-03-01');
    awardClose('2026-03-01', [$dist->id => [1]]);

    app(CancelCoolingOff::class)($dist->id, (int) $dist->user_id);

    $milestone = LifetimeAwardMilestone::sole();
    expect($dist->user->fresh()->status)->toBe('terminated')
        ->and($milestone->status)->toBe(LifetimeAwardMilestone::STATUS_CANCELLED)
        ->and($milestone->notes)->toBe('Cooling-off cancellation.')
        ->and(app(WalletService::class)->balancePaise($dist->id))->toBe(0)
        ->and(WalletLedgerEntry::where('type', 'reversal')->where('reference_id', $milestone->id)->count())->toBe(1);

    Notification::assertSentTo($dist->user, LifetimeAwardWithdrawnNotification::class,
        fn (LifetimeAwardWithdrawnNotification $n): bool => $n->reason === 'Cooling-off cancellation.');
});

it('withdraws a credited award through the real termination service', function (): void {
    Notification::fake();
    $dist = Distributor::factory()->create();
    awardQualify($dist->id, 1, '2026-03-01');
    awardClose('2026-03-01', [$dist->id => [1]]);

    app(TerminateDistributor::class)($dist->id, User::factory()->create()->id, 'Confirmed fraud.');

    $milestone = LifetimeAwardMilestone::sole();
    expect($milestone->status)->toBe(LifetimeAwardMilestone::STATUS_CANCELLED)
        ->and($milestone->notes)->toBe('Account terminated.')
        ->and(app(WalletService::class)->balancePaise($dist->id))->toBe(0);

    Notification::assertSentTo($dist->user, LifetimeAwardWithdrawnNotification::class);
});
