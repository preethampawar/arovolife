<?php

declare(strict_types=1);

use App\Modules\Commerce\Models\BvLedgerEntry;
use App\Modules\Compensation\Models\MentorshipBonusResult;
use App\Modules\Compensation\Models\MsbDailyPool;
use App\Modules\Compensation\Services\CompensationPlanSettingsService;
use App\Modules\Compensation\Services\MsbDailyPoolService;
use App\Modules\Identity\Models\Distributor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    disableTestForeignKeys();
});

afterEach(function (): void {
    Illuminate\Support\Carbon::setTestNow();
});

/** Put $bvPaise of company-wide BV on the given date. */
function seedCompanyBv(int $bvPaise, ?Carbon\Carbon $date = null): void
{
    BvLedgerEntry::create([
        'distributor_id' => Distributor::factory()->create()->id,
        'order_id' => random_int(800_000, 899_999),
        'bv_paise' => $bvPaise,
        'type' => $bvPaise < 0 ? 'reversal' : 'accrual',
        'effective_at' => ($date ?? today())->copy()->setTime(12, 0),
    ]);
}

it("reproduces KP's worked example: 1,00,000 BV, 60 points → ₹50 per point", function () {
    seedCompanyBv(10_000_000);   // 1,00,000 BV

    // Two sponsors matched slab 1 (21 + 21) and one matched slab 2 (18).
    $pool = app(MsbDailyPoolService::class)->freezePoolForDate(today(), 21 + 21 + 18);

    expect($pool->company_bv_paise)->toBe(10_000_000);
    expect($pool->pool_rate_bp)->toBe(300);
    expect($pool->pool_paise)->toBe(300_000);        // ₹3,000
    expect($pool->total_points)->toBe(60);
    expect($pool->point_value_paise)->toBe(5_000);   // ₹50
    expect($pool->payout_paise)->toBe(300_000);      // 60 × ₹50 = the whole pool
    expect($pool->leftover_paise)->toBe(0);

    // The individual incomes KP lists: ₹1,050 + ₹1,050 + ₹900 = ₹3,000.
    expect(21 * $pool->point_value_paise)->toBe(105_000);
    expect(18 * $pool->point_value_paise)->toBe(90_000);
});

it("reproduces KP's second sheet: same pool over 75 points → ₹40 per point", function () {
    seedCompanyBv(10_000_000);

    // Earners A-21, B-18, C-15, D-12, E-9.
    $pool = app(MsbDailyPoolService::class)->freezePoolForDate(today(), 21 + 18 + 15 + 12 + 9);

    expect($pool->total_points)->toBe(75);
    expect($pool->point_value_paise)->toBe(4_000);   // ₹40
    expect($pool->payout_paise)->toBe(300_000);      // 840+720+600+480+360 = ₹3,000
    expect($pool->leftover_paise)->toBe(0);
});

it('floors a fractional point value to whole rupees and leaves the remainder with the company', function () {
    seedCompanyBv(10_000_000);   // ₹3,000 pool

    $pool = app(MsbDailyPoolService::class)->freezePoolForDate(today(), 61);

    // 3,000 ÷ 61 = ₹49.18 → ₹49.
    expect($pool->point_value_paise)->toBe(4_900);
    expect($pool->payout_paise)->toBe(298_900);
    expect($pool->leftover_paise)->toBe(1_100);      // ₹11 stays with the company
});

it('clamps a negative-BV day to a ₹0 point value rather than a negative one', function () {
    seedCompanyBv(2_000_000);
    seedCompanyBv(-5_000_000);   // refund-heavy day: net −30,000 BV

    $pool = app(MsbDailyPoolService::class)->freezePoolForDate(today(), 60);

    expect($pool->company_bv_paise)->toBeLessThan(0);
    expect($pool->pool_paise)->toBe(0);
    expect($pool->point_value_paise)->toBe(0);
    expect($pool->payout_paise)->toBe(0);
});

it('freezes a ₹0 value on a day nobody accrued points, leaving the pool unspent', function () {
    seedCompanyBv(10_000_000);

    $pool = app(MsbDailyPoolService::class)->freezePoolForDate(today(), 0);

    expect($pool->total_points)->toBe(0);
    expect($pool->point_value_paise)->toBe(0);
    expect($pool->payout_paise)->toBe(0);
    expect($pool->leftover_paise)->toBe(300_000);    // the whole pool
});

it('is idempotent — a re-run returns the original row untouched', function () {
    // Freeze a CLOSED day (yesterday), as the scheduled 00:10 run does. A row
    // frozen before its day ends is premature and replaceable instead — see
    // the premature-freeze tests below.
    seedCompanyBv(10_000_000, today()->subDay());
    $svc = app(MsbDailyPoolService::class);

    $first = $svc->freezePoolForDate(today()->subDay(), 60);

    // A late reversal lands and more points accrue afterwards: neither may move the day.
    seedCompanyBv(90_000_000, today()->subDay());
    $second = $svc->freezePoolForDate(today()->subDay(), 600);

    expect($second->id)->toBe($first->id);
    expect($second->point_value_paise)->toBe(5_000);
    expect($second->total_points)->toBe(60);
    expect(MsbDailyPool::count())->toBe(1);
});

it('refuses to update a frozen pool row', function () {
    seedCompanyBv(10_000_000);
    $pool = app(MsbDailyPoolService::class)->freezePoolForDate(today(), 60);

    expect(fn () => $pool->update(['point_value_paise' => 1]))
        ->toThrow(LogicException::class);
});

it('writes an audit row when the day is frozen', function () {
    seedCompanyBv(10_000_000);
    app(MsbDailyPoolService::class)->freezePoolForDate(today(), 60);

    $audit = DB::table('audit_log')->where('action', 'msb.pool.frozen')->first();

    expect($audit)->not->toBeNull();
    $details = json_decode((string) $audit->details, true);
    expect($details['point_value_paise'])->toBe(5_000);
    expect($details['total_points'])->toBe(60);
});

it('honours an admin change to the pool rate on the next unfrozen day', function () {
    seedCompanyBv(10_000_000, today()->subDay());
    DB::table('settings')->updateOrInsert(['key' => 'comp.msb.pool_rate_bp'], ['value' => '600']);

    $pool = app(MsbDailyPoolService::class)->freezePoolForDate(today()->subDay(), 60);

    expect($pool->pool_rate_bp)->toBe(600);
    expect($pool->pool_paise)->toBe(600_000);        // 6% of 1,00,000 BV
    expect($pool->point_value_paise)->toBe(10_000);  // ₹100
});

// Same premature-freeze rule as the GSB pool (staging incident, 24 Aug 2026):
// a cut-off run while the day was still in flight froze a ₹0 point value
// (zero points accrued yet), and the scheduled run would then pay every real
// mentor ₹0. A row frozen before its day ended, with nobody credited against
// it, is replaced by the next full run's freeze.
it('replaces a pool frozen before the day ended when nobody was credited against it', function () {
    $date = Illuminate\Support\Carbon::parse('2026-08-24');
    $service = app(MsbDailyPoolService::class);

    Illuminate\Support\Carbon::setTestNow($date->copy()->setTime(23, 27));
    $premature = $service->freezePoolForDate($date, 0);

    expect($premature->point_value_paise)->toBe(0);

    seedCompanyBv(10_000_000, $date);

    Illuminate\Support\Carbon::setTestNow($date->copy()->addDay()->setTime(0, 10));
    $healed = $service->freezePoolForDate($date, 60);

    expect($healed->id)->not->toBe($premature->id);
    expect($healed->company_bv_paise)->toBe(10_000_000);
    expect($healed->point_value_paise)->toBe(5_000);
    expect(MsbDailyPool::count())->toBe(1);
    expect(DB::table('audit_log')->where('action', 'msb.pool.refrozen')->exists())->toBeTrue();
});

it('keeps a premature pool once a mentor was credited against it', function () {
    $date = Illuminate\Support\Carbon::parse('2026-08-24');
    $service = app(MsbDailyPoolService::class);

    Illuminate\Support\Carbon::setTestNow($date->copy()->setTime(23, 27));
    $premature = $service->freezePoolForDate($date, 0);

    MentorshipBonusResult::create([
        'sponsor_id' => Distributor::factory()->create()->id,
        'sponsee_id' => Distributor::factory()->create()->id,
        'cutoff_date' => $date->toDateString(),
        'sponsee_gsb_paise' => 200_000,
        'slab' => 1,
        'msb_points' => 21,
        'msb_point_value_paise' => 0,
        'mb_gross_paise' => 0,
        'mb_admin_charge_paise' => 0,
        'mb_tds_paise' => 0,
        'status' => MentorshipBonusResult::STATUS_CREDITED,
    ]);
    seedCompanyBv(10_000_000, $date);

    Illuminate\Support\Carbon::setTestNow($date->copy()->addDay()->setTime(0, 10));
    $kept = $service->freezePoolForDate($date, 60);

    expect($kept->id)->toBe($premature->id);
    expect($kept->point_value_paise)->toBe(0);
    expect(DB::table('audit_log')->where('action', 'msb.pool.refrozen')->exists())->toBeFalse();
});

// Client 2026-10-09: the MSB point value is capped (default ₹120); the excess
// stays with the company as leftover.
it('caps the MSB point value at comp.msb.point_value_cap_paise (client example 1: 150 → 120)', function (): void {
    // 50L BV × 3% = 1,50,000; 1,000 points → raw ₹150 → capped ₹120
    seedCompanyBv(500_000_000, Carbon\Carbon::parse('2026-07-10 12:00:00'));
    $pool = app(MsbDailyPoolService::class)->freezePoolForDate(Illuminate\Support\Carbon::parse('2026-07-10'), 1_000);

    expect($pool->raw_point_value_paise)->toBe(15_000)
        ->and($pool->point_value_paise)->toBe(12_000)
        ->and($pool->point_value_cap_paise)->toBe(12_000)
        ->and($pool->payout_paise)->toBe(12_000 * 1_000)
        ->and($pool->leftover_paise)->toBe(15_000_000 - 12_000_000);

    $details = json_decode((string) DB::table('audit_log')->where('action', 'msb.pool.frozen')->value('details'), true);
    expect($details['raw_point_value_paise'])->toBe(15_000)
        ->and($details['point_value_cap_paise'])->toBe(12_000);
});

it('leaves a sub-cap value alone (client example 2: 108.5383 → 108)', function (): void {
    seedCompanyBv(500_000_000, Carbon\Carbon::parse('2026-07-11 12:00:00'));
    $pool = app(MsbDailyPoolService::class)->freezePoolForDate(Illuminate\Support\Carbon::parse('2026-07-11'), 1_382);

    expect($pool->point_value_paise)->toBe(10_800)->and($pool->raw_point_value_paise)->toBe(10_800);
});

it('a negative-BV day freezes a zero value, never a negative one', function (): void {
    seedCompanyBv(-100_000_00, Carbon\Carbon::parse('2026-07-12 12:00:00'));
    $pool = app(MsbDailyPoolService::class)->freezePoolForDate(Illuminate\Support\Carbon::parse('2026-07-12'), 40);

    expect($pool->point_value_paise)->toBe(0)->and($pool->payout_paise)->toBe(0)->and($pool->leftover_paise)->toBe(0);
});

it('refuses to price the day when the cap setting is below ₹1 or not a whole rupee, writing nothing', function (string $cap): void {
    seedCompanyBv(500_000_000, Carbon\Carbon::parse('2026-07-10 12:00:00'));
    DB::table('settings')->updateOrInsert(['key' => 'comp.msb.point_value_cap_paise'], ['value' => $cap]);

    expect(fn () => app(MsbDailyPoolService::class)->freezePoolForDate(Illuminate\Support\Carbon::parse('2026-07-10'), 1_000))
        ->toThrow(RuntimeException::class);

    expect(MsbDailyPool::count())->toBe(0);
    expect(DB::table('audit_log')->where('action', 'msb.pool.frozen')->exists())->toBeFalse();
})->with(['zero' => '0', 'under ₹1' => '99', 'not a whole rupee' => '12050']);

// The cap is read BEFORE a premature row is replaced: a bad setting must not
// delete the provisional row or write a refreeze audit row on its way to the error.
it('refuses before touching a premature row — nothing deleted, no refreeze audit row', function (): void {
    $date = Illuminate\Support\Carbon::parse('2026-08-24');

    Illuminate\Support\Carbon::setTestNow($date->copy()->setTime(23, 27));
    $premature = app(MsbDailyPoolService::class)->freezePoolForDate($date, 0);

    seedCompanyBv(10_000_000, $date);
    DB::table('settings')->updateOrInsert(['key' => 'comp.msb.point_value_cap_paise'], ['value' => '0']);
    app()->forgetInstance(CompensationPlanSettingsService::class);
    app()->forgetInstance(MsbDailyPoolService::class);

    Illuminate\Support\Carbon::setTestNow($date->copy()->addDay()->setTime(0, 10));
    expect(fn () => app(MsbDailyPoolService::class)->freezePoolForDate($date, 60))
        ->toThrow(RuntimeException::class);

    expect(MsbDailyPool::count())->toBe(1);
    expect(MsbDailyPool::first()->id)->toBe($premature->id);
    expect(DB::table('audit_log')->where('action', 'msb.pool.refrozen')->exists())->toBeFalse();
});

it('freezes the cap on the row — a later setting change never moves a frozen day', function (): void {
    seedCompanyBv(500_000_000, Carbon\Carbon::parse('2026-07-10 12:00:00'));
    $date = Illuminate\Support\Carbon::parse('2026-07-10');

    $first = app(MsbDailyPoolService::class)->freezePoolForDate($date, 1_000);

    // The admin raises the cap afterwards; a fresh service (and a fresh
    // settings cache) still returns the day exactly as it was frozen.
    DB::table('settings')->updateOrInsert(['key' => 'comp.msb.point_value_cap_paise'], ['value' => '20000']);
    app()->forgetInstance(CompensationPlanSettingsService::class);
    app()->forgetInstance(MsbDailyPoolService::class);

    $second = app(MsbDailyPoolService::class)->freezePoolForDate($date, 1_000);

    expect($second->id)->toBe($first->id);
    expect($second->point_value_cap_paise)->toBe(12_000);
    expect($second->point_value_paise)->toBe(12_000);
    expect($second->raw_point_value_paise)->toBe(15_000);
    expect(MsbDailyPool::count())->toBe(1);
});
