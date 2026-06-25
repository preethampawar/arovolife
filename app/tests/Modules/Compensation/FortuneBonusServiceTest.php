<?php

declare(strict_types=1);

use App\Modules\Compensation\Models\FortuneBonusParticipant;
use App\Modules\Compensation\Models\FortuneBonusResult;
use App\Modules\Compensation\Models\WalletLedgerEntry;
use App\Modules\Compensation\Services\FortuneBonusService;
use App\Modules\Identity\Models\Distributor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    disableTestForeignKeys();
});

function seedGsbCredit(int $distributorId, string $date, int $slab = 1): void
{
    DB::table('gsb_cutoff_results')->insert([
        'distributor_id' => $distributorId,
        'cutoff_date' => $date,
        'left_bv_paise' => 30_000_000,
        'right_bv_paise' => 30_000_000,
        'weaker_bv_paise' => 30_000_000,
        'slab' => $slab,
        'gross_gsb_paise' => 100_000,
        'admin_charge_paise' => 3_000,
        'tds_paise' => 5_000,
        'net_gsb_paise' => 92_000,
        'power_cf_before_paise' => 0,
        'power_cf_after_paise' => 0,
        'power_side_after' => null,
        'slab1_weaker_cf_before_paise' => 0,
        'slab1_weaker_cf_after_paise' => 0,
        'status' => 'credited',
        'failure_reason' => null,
        'created_at' => now()->toDateTimeString(),
        'updated_at' => now()->toDateTimeString(),
    ]);
}

function seedPersonalBvForFortune(int $distributorId, int $bvPaise, string $date = '2026-06-10'): void
{
    static $fakeOrderId = 800000;
    DB::table('bv_ledger_entries')->insert([
        'distributor_id' => $distributorId,
        'order_id' => $fakeOrderId++,
        'bv_paise' => $bvPaise,
        'type' => 'accrual',
        'effective_at' => $date.' 12:00:00',
        'created_at' => now()->toDateTimeString(),
        'updated_at' => now()->toDateTimeString(),
    ]);
}

function seedRankQualForFortune(int $distributorId, int $rank, string $monthStart): void
{
    DB::table('rank_qualifications')->insert([
        'distributor_id' => $distributorId,
        'rank_number' => $rank,
        'month_start' => $monthStart,
        'occurrence_in_month' => 1,
        'is_carry_forward' => false,
        'carry_forward_from_month' => null,
        'status' => 'qualified',
        'created_at' => now()->toDateTimeString(),
        'updated_at' => now()->toDateTimeString(),
    ]);
}

it('levelFromPosition returns correct matrix level', function (): void {
    expect(FortuneBonusParticipant::levelFromPosition(1))->toBe(0);
    expect(FortuneBonusParticipant::levelFromPosition(2))->toBe(1);
    expect(FortuneBonusParticipant::levelFromPosition(4))->toBe(1);
    expect(FortuneBonusParticipant::levelFromPosition(5))->toBe(2);
    expect(FortuneBonusParticipant::levelFromPosition(13))->toBe(2);
    expect(FortuneBonusParticipant::levelFromPosition(14))->toBe(3);
    expect(FortuneBonusParticipant::levelFromPosition(40))->toBe(3);
    expect(FortuneBonusParticipant::levelFromPosition(41))->toBe(4);
    expect(FortuneBonusParticipant::levelFromPosition(121))->toBe(4);
    expect(FortuneBonusParticipant::levelFromPosition(122))->toBe(5);
    expect(FortuneBonusParticipant::levelFromPosition(364))->toBe(5);
    expect(FortuneBonusParticipant::levelFromPosition(365))->toBe(6);
    expect(FortuneBonusParticipant::levelFromPosition(1094))->toBe(7);
    expect(FortuneBonusParticipant::levelFromPosition(3281))->toBe(8);
    expect(FortuneBonusParticipant::levelFromPosition(9842))->toBe(9);
});

it('enrollEligible enrolls a non-ranked distributor with 600 BV and 1 GSB slab', function (): void {
    $dist = Distributor::factory()->create();
    $month = Carbon::parse('2026-06-01');

    seedPersonalBvForFortune($dist->id, 60_000);
    seedGsbCredit($dist->id, '2026-06-05');

    $svc = app(FortuneBonusService::class);
    $result = $svc->enrollEligible($month);

    expect($result['enrolled'])->toBe(1);

    $participant = FortuneBonusParticipant::where('distributor_id', $dist->id)->first();
    expect($participant)->not->toBeNull();
    expect($participant->position)->toBe(1);
    expect($participant->matrix_level)->toBe(0);
    expect($participant->eligibility_tier)->toBe('non_ranked');
    expect($participant->month_start)->toBe('2026-06-01');
});

it('does not enroll a distributor with insufficient personal BV', function (): void {
    $dist = Distributor::factory()->create();
    $month = Carbon::parse('2026-06-01');

    seedPersonalBvForFortune($dist->id, 50_000); // below 60,000 paise threshold
    seedGsbCredit($dist->id, '2026-06-05');

    $svc = app(FortuneBonusService::class);
    $result = $svc->enrollEligible($month);

    expect($result['enrolled'])->toBe(0);
    expect(FortuneBonusParticipant::count())->toBe(0);
});

it('does not enroll a rank-1 distributor with fewer than 4 GSB slabs', function (): void {
    $dist = Distributor::factory()->create();
    $month = Carbon::parse('2026-06-01');

    seedPersonalBvForFortune($dist->id, 100_000);
    seedGsbCredit($dist->id, '2026-06-05', 1);
    seedGsbCredit($dist->id, '2026-06-06', 2);
    seedGsbCredit($dist->id, '2026-06-07', 3); // only 3 slabs, needs 4
    seedRankQualForFortune($dist->id, 1, '2026-06-01');

    $svc = app(FortuneBonusService::class);
    $result = $svc->enrollEligible($month);

    expect($result['enrolled'])->toBe(0);
});

it('does not enroll a rank-6 distributor (ineligible)', function (): void {
    $dist = Distributor::factory()->create();
    $month = Carbon::parse('2026-06-01');

    seedPersonalBvForFortune($dist->id, 200_000);
    foreach (range(1, 12) as $day) {
        seedGsbCredit($dist->id, '2026-06-'.str_pad((string) $day, 2, '0', STR_PAD_LEFT));
    }
    seedRankQualForFortune($dist->id, 6, '2026-06-01');

    $svc = app(FortuneBonusService::class);
    $result = $svc->enrollEligible($month);

    expect($result['enrolled'])->toBe(0);
    expect(FortuneBonusParticipant::count())->toBe(0);
});

it('assigns positions in FCFS order by first GSB credit date', function (): void {
    $dist1 = Distributor::factory()->create();
    $dist2 = Distributor::factory()->create();
    $dist3 = Distributor::factory()->create();
    $month = Carbon::parse('2026-06-01');

    // dist2 earned GSB first, dist3 second, dist1 last
    seedPersonalBvForFortune($dist1->id, 60_000);
    seedPersonalBvForFortune($dist2->id, 60_000);
    seedPersonalBvForFortune($dist3->id, 60_000);

    seedGsbCredit($dist2->id, '2026-06-03');
    seedGsbCredit($dist3->id, '2026-06-07');
    seedGsbCredit($dist1->id, '2026-06-10');

    $svc = app(FortuneBonusService::class);
    $svc->enrollEligible($month);

    $p2 = FortuneBonusParticipant::where('distributor_id', $dist2->id)->first();
    $p3 = FortuneBonusParticipant::where('distributor_id', $dist3->id)->first();
    $p1 = FortuneBonusParticipant::where('distributor_id', $dist1->id)->first();

    expect($p2->position)->toBe(1);
    expect($p3->position)->toBe(2);
    expect($p1->position)->toBe(3);
});

it('runForMonth credits correct wallet amount for a level-0 participant', function (): void {
    $dist = Distributor::factory()->create();
    $month = Carbon::parse('2026-06-01');

    // Manually enroll at position 1 (level 0)
    FortuneBonusParticipant::create([
        'distributor_id' => $dist->id,
        'month_start' => '2026-06-01',
        'position' => 1,
        'matrix_level' => 0,
        'eligibility_tier' => 'non_ranked',
        'first_gsb_date' => '2026-06-05',
        'enrolled_at' => now(),
    ]);

    $svc = app(FortuneBonusService::class);
    $result = $svc->runForMonth($month);

    expect($result['credited'])->toBe(1);

    $bonusResult = FortuneBonusResult::where('distributor_id', $dist->id)->first();
    expect($bonusResult)->not->toBeNull();
    expect($bonusResult->gross_paise)->toBe(339);
    expect($bonusResult->tds_paise)->toBe((int) round(339 * 0.05)); // 17
    expect($bonusResult->net_paise)->toBe(339 - (int) round(339 * 0.05)); // 322
    expect($bonusResult->status)->toBe(FortuneBonusResult::STATUS_CREDITED);

    $ledger = WalletLedgerEntry::where('distributor_id', $dist->id)->where('type', 'fortune_credit')->first();
    expect($ledger)->not->toBeNull();
    expect($ledger->amount_paise)->toBe($bonusResult->net_paise);
});

it('runForMonth is idempotent — re-running does not double-credit', function (): void {
    $dist = Distributor::factory()->create();
    $month = Carbon::parse('2026-06-01');

    FortuneBonusParticipant::create([
        'distributor_id' => $dist->id,
        'month_start' => '2026-06-01',
        'position' => 1,
        'matrix_level' => 0,
        'eligibility_tier' => 'non_ranked',
        'first_gsb_date' => '2026-06-05',
        'enrolled_at' => now(),
    ]);

    $svc = app(FortuneBonusService::class);
    $svc->runForMonth($month);
    $svc->runForMonth($month);

    expect(FortuneBonusResult::where('distributor_id', $dist->id)->count())->toBe(1);
    expect(WalletLedgerEntry::where('distributor_id', $dist->id)->where('type', 'fortune_credit')->count())->toBe(1);
});

it('runForMonth marks level-9 participant as skipped with no wallet credit', function (): void {
    $dist = Distributor::factory()->create();
    $month = Carbon::parse('2026-06-01');

    FortuneBonusParticipant::create([
        'distributor_id' => $dist->id,
        'month_start' => '2026-06-01',
        'position' => 9842, // first position at level 9
        'matrix_level' => 9,
        'eligibility_tier' => 'non_ranked',
        'first_gsb_date' => '2026-06-05',
        'enrolled_at' => now(),
    ]);

    $svc = app(FortuneBonusService::class);
    $result = $svc->runForMonth($month);

    expect($result['credited'])->toBe(0);
    expect($result['skipped_no_bonus'])->toBe(1);

    $bonusResult = FortuneBonusResult::where('distributor_id', $dist->id)->first();
    expect($bonusResult)->not->toBeNull();
    expect($bonusResult->status)->toBe(FortuneBonusResult::STATUS_SKIPPED);
    expect($bonusResult->net_paise)->toBe(0);

    expect(WalletLedgerEntry::where('distributor_id', $dist->id)->where('type', 'fortune_credit')->count())->toBe(0);
});
