<?php

declare(strict_types=1);

use App\Modules\Commerce\Models\BvLedgerEntry;
use App\Modules\Compensation\Models\EngineRun;
use App\Modules\Compensation\Models\GsbCutoffResult;
use App\Modules\Compensation\Services\EngineStatusService;
use App\Modules\Compliance\Models\AuditLog;
use App\Modules\Identity\Models\Distributor;
use App\Modules\Identity\Models\User;
use App\Modules\Shared\Features\GenosSalesBonusFeature;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Laravel\Pennant\Feature;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Feature::for(null)->activate(GenosSalesBonusFeature::class);
    disableTestForeignKeys();
    $this->seed(RolesAndPermissionsSeeder::class);
});

afterEach(function (): void {
    Carbon::setTestNow(null);
});

function retryAdmin(): User
{
    $user = User::create([
        'full_name' => 'Retry Admin',
        'email' => 'retry-admin-'.uniqid().'@test.com',
        'phone_e164' => '+91'.str_pad((string) random_int(7000000000, 9999999999), 10, '0'),
        'password_hash' => bcrypt('x'),
        'status' => 'active',
        'email_verified_at' => now(),
    ]);
    $user->assignRole('admin');

    return $user;
}

/** A cut-off row inserted raw, in the storage shape the controller looks up. */
function cutoffRow(int $distributorId, string $date, string $status): int
{
    return DB::table('gsb_cutoff_results')->insertGetId([
        'distributor_id' => $distributorId,
        'cutoff_date' => $date,
        'left_bv_paise' => 0,
        'right_bv_paise' => 0,
        'weaker_bv_paise' => 0,
        'gross_gsb_paise' => 0,
        'admin_charge_paise' => 0,
        'tds_paise' => 0,
        'repurchase_deduction_paise' => 0,
        'net_gsb_paise' => 0,
        'power_cf_before_paise' => 0,
        'power_cf_after_paise' => 0,
        'slab1_weaker_cf_before_paise' => 0,
        'slab1_weaker_cf_after_paise' => 0,
        'status' => $status,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

it('refuses a retry the chain has run past, and says so on screen instead of a 500', function () {
    $distributor = Distributor::factory()->create();
    BvLedgerEntry::create([
        'distributor_id' => $distributor->id,
        'order_id' => 999_999,
        'bv_paise' => 300_000,
        'type' => 'accrual',
        'effective_at' => now(),
    ]);

    // The night that failed, and the idle-batch row the next night wrote for
    // them anyway — `no_match` advances the carry-forward store, which is what
    // closes the retry window for the whole roster after one chain run (R-91).
    cutoffRow($distributor->id, '2026-08-14', GsbCutoffResult::STATUS_FAILED);
    cutoffRow($distributor->id, '2026-08-15', GsbCutoffResult::STATUS_NO_MATCH);

    $response = $this->from(route('admin.compensation.manual-controls.index'))
        ->actingAs(retryAdmin())
        ->post(route('admin.compensation.manual-controls.retry'), [
            'adn' => $distributor->adn,
            'date' => '2026-08-14',
            'reason' => 'Night failed on a bank decrypt error.',
        ]);

    // Back to the form with the refusal readable, not a blank "Something went
    // wrong" 500 — the runbook tells the admin to forward this text.
    $response->assertRedirect(route('admin.compensation.manual-controls.index'));
    expect(session('error'))->toContain('later cut-off already advanced the carry-forward store');

    // The failed row survives the refused retry: the controller deletes it
    // inside the transaction that then rolled back.
    expect(GsbCutoffResult::where('distributor_id', $distributor->id)
        ->whereDate('cutoff_date', '2026-08-14')
        ->where('status', GsbCutoffResult::STATUS_FAILED)
        ->exists())->toBeTrue();

    // And the attempt is on the record, written outside the transaction so the
    // rollback could not take it with it.
    $audit = AuditLog::where('action', 'compensation.cutoff.manual_retry_refused')->first();

    expect($audit)->not->toBeNull()
        ->and($audit->subject_id)->toBe($distributor->id)
        ->and($audit->details['date'])->toBe('2026-08-14')
        ->and($audit->details['reason'])->toBe('Night failed on a bank decrypt error.')
        ->and($audit->details['refusal'])->toContain('recompute-all')
        // Nothing changed, so neither side claims a state.
        ->and($audit->before_hash)->toBeNull()
        ->and($audit->after_hash)->toBeNull();
});

it('still retries a failed night while nothing later has advanced the store', function () {
    $distributor = Distributor::factory()->create();
    BvLedgerEntry::create([
        'distributor_id' => $distributor->id,
        'order_id' => 999_998,
        'bv_paise' => 300_000,
        'type' => 'accrual',
        'effective_at' => now(),
    ]);

    cutoffRow($distributor->id, '2026-08-14', GsbCutoffResult::STATUS_FAILED);

    $this->actingAs(retryAdmin())
        ->post(route('admin.compensation.manual-controls.retry'), [
            'adn' => $distributor->adn,
            'date' => '2026-08-14',
            'reason' => 'Night failed on a bank decrypt error.',
        ])
        ->assertRedirect(route('admin.compensation.distributors.show', $distributor));

    // The failed row was replaced, not kept, and the success path audited.
    expect(GsbCutoffResult::where('distributor_id', $distributor->id)
        ->whereDate('cutoff_date', '2026-08-14')
        ->where('status', GsbCutoffResult::STATUS_FAILED)
        ->exists())->toBeFalse()
        ->and(AuditLog::where('action', 'compensation.cutoff.manual_retry')->exists())->toBeTrue()
        ->and(AuditLog::where('action', 'compensation.cutoff.manual_retry_refused')->exists())->toBeFalse();
});

it('states the nightly run as instants, not as a rule the admin has to apply', function () {
    // 09:00 on the 19th: tonight's run has been, tomorrow's has not. The retry
    // window an admin is deciding about sits between those two times, so both
    // are on the page rather than a bare "00:05 IST".
    Carbon::setTestNow(Carbon::parse('2026-09-19 09:00', 'Asia/Kolkata'));

    EngineRun::create([
        'engine_key' => EngineStatusService::CHAIN_KEY,
        'period_start' => '2026-09-19',
        'status' => EngineRun::STATUS_SUCCEEDED,
        'trigger' => 'scheduler',
        'started_at' => Carbon::parse('2026-09-19 00:05', 'Asia/Kolkata'),
        'finished_at' => Carbon::parse('2026-09-19 00:07', 'Asia/Kolkata'),
    ]);

    $this->actingAs(retryAdmin())
        ->get(route('admin.compensation.manual-controls.index', ['action' => 'retry']))
        ->assertOk()
        ->assertSee('19 Sep 2026, 00:05 IST')
        ->assertSee('20 Sep 2026, 00:05 IST');
});
