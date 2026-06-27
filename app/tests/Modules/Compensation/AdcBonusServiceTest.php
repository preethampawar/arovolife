<?php

declare(strict_types=1);

use App\Modules\Compensation\Models\AdcBonusResult;
use App\Modules\Compensation\Models\AreteCenter;
use App\Modules\Compensation\Models\AreteCenterMember;
use App\Modules\Compensation\Models\WalletLedgerEntry;
use App\Modules\Compensation\Services\AreteDevelopmentCenterBonusService;
use App\Modules\Compensation\Services\CompensationPlanSettingsService;
use App\Modules\Identity\Models\Distributor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    disableTestForeignKeys();
});

function makeActiveCenter(int $assignedDistributorId, string $name = 'Test Center'): AreteCenter
{
    return AreteCenter::create([
        'name' => $name,
        'location' => null,
        'assigned_distributor_id' => $assignedDistributorId,
        'status' => AreteCenter::STATUS_ACTIVE,
        'approved_at' => null,
        'notes' => null,
    ]);
}

function addCenterMember(int $centerId, int $distributorId, string $from = '2026-01-01', ?string $to = null): void
{
    AreteCenterMember::create([
        'center_id' => $centerId,
        'distributor_id' => $distributorId,
        'effective_from' => $from,
        'effective_to' => $to,
    ]);
}

function seedMemberBv(int $distributorId, int $bvPaise, string $date = '2026-06-15'): void
{
    static $orderId = 900000;
    DB::table('bv_ledger_entries')->insert([
        'distributor_id' => $distributorId,
        'order_id' => $orderId++,
        'bv_paise' => $bvPaise,
        'type' => 'accrual',
        'effective_at' => $date.' 12:00:00',
        'created_at' => now()->toDateTimeString(),
        'updated_at' => now()->toDateTimeString(),
    ]);
}

it('credits 3% of member BV to the assigned distributor', function (): void {
    $assignee = Distributor::factory()->create();
    $member = Distributor::factory()->create();
    $month = Carbon::parse('2026-06-01');

    $center = makeActiveCenter($assignee->id);
    addCenterMember($center->id, $member->id);
    seedMemberBv($member->id, 1_000_000); // 1,000 BV → gross = 30,000 paise = ₹300

    $svc = app(AreteDevelopmentCenterBonusService::class);
    $result = $svc->runForMonth($month);

    expect($result['credited'])->toBe(1)
        ->and($result['skipped_no_bv'])->toBe(0);

    $bonus = AdcBonusResult::where('center_id', $center->id)->first();
    expect($bonus)->not->toBeNull();
    expect($bonus->total_member_bv_paise)->toBe(1_000_000);
    expect($bonus->gross_paise)->toBe(30_000);                    // 3% of 1,000,000
    expect($bonus->admin_charge_paise)->toBe(900);                // 3% admin of 30,000 (KP 2026-06-27)
    expect($bonus->tds_paise)->toBe((int) round((30_000 - 900) * 0.05)); // 5% of (gross − admin) = 1,455
    expect($bonus->net_paise)->toBe(30_000 - 900 - 1_455);        // 27,645
    expect($bonus->status)->toBe(AdcBonusResult::STATUS_CREDITED);

    $ledger = WalletLedgerEntry::where('distributor_id', $assignee->id)
        ->where('type', 'adc_credit')->first();
    expect($ledger)->not->toBeNull();
    expect($ledger->amount_paise)->toBe($bonus->net_paise);
});

it('applies the monthly cap of ₹1,00,000 (10,000,000 paise)', function (): void {
    $assignee = Distributor::factory()->create();
    $member = Distributor::factory()->create();
    $month = Carbon::parse('2026-06-01');

    $center = makeActiveCenter($assignee->id);
    addCenterMember($center->id, $member->id);
    // 1,000,000,000 paise → 3% = 30,000,000 paise → cap at 10,000,000
    seedMemberBv($member->id, 1_000_000_000);

    $svc = app(AreteDevelopmentCenterBonusService::class);
    $svc->runForMonth($month);

    $bonus = AdcBonusResult::where('center_id', $center->id)->first();
    expect($bonus->gross_paise)->toBe(10_000_000); // capped at ₹1,00,000
    expect($bonus->admin_charge_paise)->toBe(300_000); // 3% of 10,000,000 (< ₹30,000 cap)
    expect($bonus->tds_paise)->toBe((int) round((10_000_000 - 300_000) * 0.05)); // 485,000
    expect($bonus->net_paise)->toBe(10_000_000 - 300_000 - 485_000); // 9,215,000
});

it('exempts ADC from the admin charge when the applies_to toggle is off', function (): void {
    DB::table('settings')->insert([
        'key' => 'comp.admin_charge.applies_to_adc', 'value' => 'false', 'version' => 1,
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $assignee = Distributor::factory()->create();
    $member = Distributor::factory()->create();
    $month = Carbon::parse('2026-06-01');

    $center = makeActiveCenter($assignee->id);
    addCenterMember($center->id, $member->id);
    seedMemberBv($member->id, 1_000_000); // gross = 30,000

    app(AreteDevelopmentCenterBonusService::class)->runForMonth($month);

    $bonus = AdcBonusResult::where('center_id', $center->id)->first();
    expect($bonus->admin_charge_paise)->toBe(0);                  // toggle off → no admin charge
    expect($bonus->tds_paise)->toBe((int) round(30_000 * 0.05));  // TDS on full gross = 1,500
    expect($bonus->net_paise)->toBe(30_000 - 1_500);              // 28,500
});

it('finalizes a zero-net result as credited and stays idempotent', function (): void {
    // TDS at 100% drives net to 0 (gross 30,000 − admin 900 − tds 29,100 = 0).
    DB::table('settings')->insert([
        'key' => 'comp.tds.rate_bp', 'value' => '10000', 'version' => 1,
        'created_at' => now(), 'updated_at' => now(),
    ]);
    app()->forgetInstance(CompensationPlanSettingsService::class);

    $assignee = Distributor::factory()->create();
    $member = Distributor::factory()->create();
    $month = Carbon::parse('2026-06-01');

    $center = makeActiveCenter($assignee->id);
    addCenterMember($center->id, $member->id);
    seedMemberBv($member->id, 1_000_000); // gross = 30,000

    $svc = app(AreteDevelopmentCenterBonusService::class);
    $first = $svc->runForMonth($month);
    $second = $svc->runForMonth($month); // re-run must not reprocess

    $bonus = AdcBonusResult::where('center_id', $center->id)->first();
    expect($bonus->net_paise)->toBe(0);
    expect($bonus->status)->toBe(AdcBonusResult::STATUS_CREDITED);     // finalized despite net 0
    expect($first['credited'])->toBe(1);
    expect($second['credited'])->toBe(0);                              // idempotent: already credited
    expect(AdcBonusResult::where('center_id', $center->id)->count())->toBe(1);
    expect(WalletLedgerEntry::where('type', 'adc_credit')->where('distributor_id', $assignee->id)->count())->toBe(0); // no payout
});

it('skips a center with no member BV in the month', function (): void {
    $assignee = Distributor::factory()->create();
    $member = Distributor::factory()->create();
    $month = Carbon::parse('2026-06-01');

    $center = makeActiveCenter($assignee->id);
    addCenterMember($center->id, $member->id);
    // No BV seeded

    $svc = app(AreteDevelopmentCenterBonusService::class);
    $result = $svc->runForMonth($month);

    expect($result['credited'])->toBe(0)
        ->and($result['skipped_no_bv'])->toBe(1);

    expect(AdcBonusResult::count())->toBe(0);
    expect(WalletLedgerEntry::where('type', 'adc_credit')->count())->toBe(0);
});

it('skips a center with no members', function (): void {
    $assignee = Distributor::factory()->create();
    $month = Carbon::parse('2026-06-01');

    makeActiveCenter($assignee->id);

    $svc = app(AreteDevelopmentCenterBonusService::class);
    $result = $svc->runForMonth($month);

    expect($result['credited'])->toBe(0)
        ->and($result['skipped_no_bv'])->toBe(1);
});

it('excludes BV outside the month window', function (): void {
    $assignee = Distributor::factory()->create();
    $member = Distributor::factory()->create();
    $month = Carbon::parse('2026-06-01');

    $center = makeActiveCenter($assignee->id);
    addCenterMember($center->id, $member->id);
    seedMemberBv($member->id, 500_000, '2026-05-31'); // prior month — should be excluded
    seedMemberBv($member->id, 200_000, '2026-07-01'); // next month — should be excluded

    $svc = app(AreteDevelopmentCenterBonusService::class);
    $result = $svc->runForMonth($month);

    expect($result['credited'])->toBe(0)
        ->and($result['skipped_no_bv'])->toBe(1);
});

it('excludes members whose membership ended before the month', function (): void {
    $assignee = Distributor::factory()->create();
    $member = Distributor::factory()->create();
    $month = Carbon::parse('2026-06-01');

    $center = makeActiveCenter($assignee->id);
    addCenterMember($center->id, $member->id, '2026-01-01', '2026-05-31'); // ended before month
    seedMemberBv($member->id, 1_000_000, '2026-06-15');

    $svc = app(AreteDevelopmentCenterBonusService::class);
    $result = $svc->runForMonth($month);

    expect($result['credited'])->toBe(0)
        ->and($result['skipped_no_bv'])->toBe(1);
});

it('excludes members who joined after the month ended', function (): void {
    $assignee = Distributor::factory()->create();
    $member = Distributor::factory()->create();
    $month = Carbon::parse('2026-06-01');

    $center = makeActiveCenter($assignee->id);
    addCenterMember($center->id, $member->id, '2026-07-01'); // effective next month
    seedMemberBv($member->id, 1_000_000, '2026-06-15');

    $svc = app(AreteDevelopmentCenterBonusService::class);
    $result = $svc->runForMonth($month);

    expect($result['credited'])->toBe(0)
        ->and($result['skipped_no_bv'])->toBe(1);
});

it('is idempotent — re-running does not double-credit', function (): void {
    $assignee = Distributor::factory()->create();
    $member = Distributor::factory()->create();
    $month = Carbon::parse('2026-06-01');

    $center = makeActiveCenter($assignee->id);
    addCenterMember($center->id, $member->id);
    seedMemberBv($member->id, 1_000_000);

    $svc = app(AreteDevelopmentCenterBonusService::class);
    $svc->runForMonth($month);
    $svc->runForMonth($month);

    expect(AdcBonusResult::where('center_id', $center->id)->count())->toBe(1);
    expect(WalletLedgerEntry::where('distributor_id', $assignee->id)
        ->where('type', 'adc_credit')->count())->toBe(1);
});

it('aggregates BV across multiple members', function (): void {
    $assignee = Distributor::factory()->create();
    $member1 = Distributor::factory()->create();
    $member2 = Distributor::factory()->create();
    $month = Carbon::parse('2026-06-01');

    $center = makeActiveCenter($assignee->id);
    addCenterMember($center->id, $member1->id);
    addCenterMember($center->id, $member2->id);
    seedMemberBv($member1->id, 500_000);
    seedMemberBv($member2->id, 300_000);

    $svc = app(AreteDevelopmentCenterBonusService::class);
    $svc->runForMonth($month);

    $bonus = AdcBonusResult::where('center_id', $center->id)->first();
    expect($bonus->total_member_bv_paise)->toBe(800_000);
    expect($bonus->member_count)->toBe(2);
    expect($bonus->gross_paise)->toBe((int) floor(800_000 * 0.03)); // 24,000
});

it('skips inactive centers', function (): void {
    $assignee = Distributor::factory()->create();
    $member = Distributor::factory()->create();
    $month = Carbon::parse('2026-06-01');

    $center = AreteCenter::create([
        'name' => 'Inactive Center',
        'location' => null,
        'assigned_distributor_id' => $assignee->id,
        'status' => AreteCenter::STATUS_INACTIVE,
        'approved_at' => null,
        'notes' => null,
    ]);
    addCenterMember($center->id, $member->id);
    seedMemberBv($member->id, 1_000_000);

    $svc = app(AreteDevelopmentCenterBonusService::class);
    $result = $svc->runForMonth($month);

    expect($result['credited'])->toBe(0);
    expect(AdcBonusResult::count())->toBe(0);
});
