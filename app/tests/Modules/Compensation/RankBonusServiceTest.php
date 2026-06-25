<?php

declare(strict_types=1);

use App\Modules\Commerce\Models\Order;
use App\Modules\Compensation\Models\LifetimeAwardMilestone;
use App\Modules\Compensation\Models\RankBonusResult;
use App\Modules\Compensation\Models\RankQualification;
use App\Modules\Compensation\Models\WalletLedgerEntry;
use App\Modules\Compensation\Services\RankBonusService;
use App\Modules\Identity\Models\Distributor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    disableTestForeignKeys();
});

function seedRankOrder(int $totalPaise, Carbon $createdAt): void
{
    Order::create([
        'order_no' => 'ORD-'.rand(10000, 99999),
        'customer_id' => 1,
        'attributed_distributor_id' => null,
        'status' => Order::STATUS_DELIVERED,
        'payment_method' => 'online',
        'subtotal_paise' => $totalPaise,
        'gst_paise' => 0,
        'discount_paise' => 0,
        'shipping_paise' => 0,
        'total_paise' => $totalPaise,
        'self_consumption' => false,
        'idempotency_key' => Str::uuid()->toString(),
        'created_at' => $createdAt,
        'updated_at' => $createdAt,
    ]);
}

function seedRankQualification(int $distributorId, int $rank, string $monthStart, int $occurrence = 1, bool $carryForward = false): void
{
    RankQualification::create([
        'distributor_id' => $distributorId,
        'rank_number' => $rank,
        'month_start' => $monthStart,
        'occurrence_in_month' => $occurrence,
        'is_carry_forward' => $carryForward,
        'status' => RankQualification::STATUS_QUALIFIED,
    ]);
}

it('returns zero credited when no qualifiers exist', function (): void {
    $month = Carbon::parse('2026-06-01');
    seedRankOrder(10_000_000, $month->copy()->addDays(5));

    $svc = app(RankBonusService::class);
    $result = $svc->runForMonth($month);

    expect($result['credited'])->toBe(0);
    expect(RankBonusResult::count())->toBe(0);
});

it('calculates correct pool as percentage of company turnover', function (): void {
    $dist = Distributor::factory()->create();
    $month = Carbon::parse('2026-06-01');
    $monthStart = '2026-06-01';

    // Turnover = 100,000,000 paise (₹10 lakh). Rank 1 pool = 7% = 7,000,000 paise.
    seedRankOrder(100_000_000, $month->copy()->addDays(5));
    seedRankQualification($dist->id, rank: 1, monthStart: $monthStart, occurrence: 1);

    $svc = app(RankBonusService::class);
    $svc->runForMonth($month);

    $result = RankBonusResult::where('distributor_id', $dist->id)
        ->where('rank_number', 1)
        ->first();

    expect($result)->not->toBeNull();
    expect($result->pool_paise)->toBe(7_000_000);
    expect($result->gross_paise)->toBe(7_000_000);
});

it('applies admin charge as min(3% of gross, ₹30,000)', function (): void {
    $dist = Distributor::factory()->create();
    $month = Carbon::parse('2026-06-01');
    $monthStart = '2026-06-01';

    // Small pool: 7% of 1,000,000 = 70,000 paise. Admin = floor(70,000 * 0.03) = 2,100.
    seedRankOrder(1_000_000, $month->copy()->addDays(5));
    seedRankQualification($dist->id, rank: 1, monthStart: $monthStart, occurrence: 1);

    $svc = app(RankBonusService::class);
    $svc->runForMonth($month);

    $result = RankBonusResult::where('distributor_id', $dist->id)->where('rank_number', 1)->first();
    $expectedAdminCharge = min((int) floor($result->gross_paise * 0.03), 3_000_000);

    expect($result->admin_charge_paise)->toBe($expectedAdminCharge);
    expect($result->admin_charge_paise)->toBeLessThanOrEqual(3_000_000);
});

it('caps admin charge at ₹30,000 (3,000,000 paise) for very large gross amounts', function (): void {
    $dist = Distributor::factory()->create();
    $month = Carbon::parse('2026-06-01');
    $monthStart = '2026-06-01';

    // Turnover = 10,000,000,000 paise → rank-1 pool = 700,000,000 paise.
    // 3% of 700,000,000 = 21,000,000 → capped at 3,000,000.
    seedRankOrder(10_000_000_000, $month->copy()->addDays(5));
    seedRankQualification($dist->id, rank: 1, monthStart: $monthStart, occurrence: 1);

    $svc = app(RankBonusService::class);
    $svc->runForMonth($month);

    $result = RankBonusResult::where('distributor_id', $dist->id)->where('rank_number', 1)->first();

    expect($result->admin_charge_paise)->toBe(3_000_000);
});

it('applies 5% TDS on gross (not on gross minus admin charge)', function (): void {
    $dist = Distributor::factory()->create();
    $month = Carbon::parse('2026-06-01');
    $monthStart = '2026-06-01';

    seedRankOrder(100_000_000, $month->copy()->addDays(5));
    seedRankQualification($dist->id, rank: 1, monthStart: $monthStart, occurrence: 1);

    $svc = app(RankBonusService::class);
    $svc->runForMonth($month);

    $result = RankBonusResult::where('distributor_id', $dist->id)->where('rank_number', 1)->first();
    $expectedTds = (int) round($result->gross_paise * 0.05);

    expect($result->tds_paise)->toBe($expectedTds);
    expect($result->net_paise)->toBe($result->gross_paise - $result->admin_charge_paise - $result->tds_paise);
});

it('credits wallet with rank_credit type', function (): void {
    $dist = Distributor::factory()->create();
    $month = Carbon::parse('2026-06-01');
    $monthStart = '2026-06-01';

    seedRankOrder(100_000_000, $month->copy()->addDays(5));
    seedRankQualification($dist->id, rank: 1, monthStart: $monthStart, occurrence: 1);

    $svc = app(RankBonusService::class);
    $svc->runForMonth($month);

    $ledger = WalletLedgerEntry::where('distributor_id', $dist->id)
        ->where('type', 'rank_credit')
        ->first();

    expect($ledger)->not->toBeNull();
    expect($ledger->amount_paise)->toBeGreaterThan(0);
});

it('is idempotent — re-running the same month does not double-credit', function (): void {
    $dist = Distributor::factory()->create();
    $month = Carbon::parse('2026-06-01');
    $monthStart = '2026-06-01';

    seedRankOrder(100_000_000, $month->copy()->addDays(5));
    seedRankQualification($dist->id, rank: 1, monthStart: $monthStart, occurrence: 1);

    $svc = app(RankBonusService::class);
    $svc->runForMonth($month);
    $svc->runForMonth($month);

    expect(RankBonusResult::where('distributor_id', $dist->id)->where('rank_number', 1)->count())->toBe(1);
    expect(WalletLedgerEntry::where('distributor_id', $dist->id)->where('type', 'rank_credit')->count())->toBe(1);
});

it('creates a LifetimeAwardMilestone on first rank achievement', function (): void {
    $dist = Distributor::factory()->create();
    $month = Carbon::parse('2026-06-01');
    $monthStart = '2026-06-01';

    seedRankOrder(100_000_000, $month->copy()->addDays(5));
    seedRankQualification($dist->id, rank: 1, monthStart: $monthStart, occurrence: 1);

    $svc = app(RankBonusService::class);
    $svc->runForMonth($month);

    $milestone = LifetimeAwardMilestone::where('distributor_id', $dist->id)
        ->where('rank_number', 1)
        ->first();

    expect($milestone)->not->toBeNull();
    expect($milestone->status)->toBe(LifetimeAwardMilestone::STATUS_PENDING);
    expect($milestone->award_description)->toContain('Silver Partner');
});

it('does not create a duplicate LifetimeAwardMilestone on second qualification', function (): void {
    $dist = Distributor::factory()->create();
    $month1 = Carbon::parse('2026-06-01');
    $month2 = Carbon::parse('2026-07-01');

    seedRankOrder(100_000_000, $month1->copy()->addDays(5));
    seedRankQualification($dist->id, rank: 1, monthStart: '2026-06-01', occurrence: 1);

    seedRankOrder(100_000_000, $month2->copy()->addDays(5));
    seedRankQualification($dist->id, rank: 1, monthStart: '2026-07-01', occurrence: 1);

    $svc = app(RankBonusService::class);
    $svc->runForMonth($month1);
    $svc->runForMonth($month2);

    expect(LifetimeAwardMilestone::where('distributor_id', $dist->id)->where('rank_number', 1)->count())->toBe(1);
});
