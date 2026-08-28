<?php

declare(strict_types=1);

/**
 * Agreement §21 — termination after twelve continuous months without a sale.
 *
 * INA-001: a distributor past twelve months with no sale is dormant; one inside it is not
 * INA-002: the clock runs from the last sale when that is later than the effective date
 * INA-003: a cancelled or refunded order is not a sale and does not reset the clock
 * INA-004: the sweep issues a seven-day notice and emails it
 * INA-005: the sweep never terminates without an expired notice first
 * INA-006: a sale inside the notice window withdraws the notice completely
 * INA-007: an expired notice terminates the account and records the reason
 * INA-008: the re-registration wait follows the highest rank ever achieved
 * INA-009: an unranked distributor carries no re-registration wait
 * INA-010: the sweep writes nothing while the master switch is off
 * INA-011: notices are idempotent — a second sweep does not reissue or restart them
 * INA-012: the dormancy admin page renders and a notice can be withdrawn with a reason
 */

use App\Modules\Compliance\Notifications\InactivityTerminationNoticeNotification;
use App\Modules\Compliance\Services\InactivityTerminationService;
use App\Modules\Identity\Models\Distributor;
use App\Modules\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    disableTestForeignKeys();
});

// ─── helpers ─────────────────────────────────────────────────────────────────

function inaDistributor(string $joinedAgo = '-18 months'): Distributor
{
    return Distributor::factory()->create([
        'effective_date' => Carbon::parse($joinedAgo),
        'status' => 'active',
    ]);
}

/** Minimal order row — only the columns the dormancy query reads. */
function inaOrder(Distributor $distributor, string $paidAt, string $status = 'delivered'): void
{
    static $sequence = 0;
    $sequence++;

    DB::table('orders')->insert([
        'order_no' => 'ORD-INA-'.str_pad((string) $sequence, 5, '0', STR_PAD_LEFT),
        // `customer_id` is NOT NULL and the dormancy query never reads it, so a
        // sentinel keeps the fixture honest about what it is exercising.
        'customer_id' => 1,
        'attributed_distributor_id' => $distributor->id,
        'attribution_source' => 'direct',
        'payment_method' => 'online',
        'status' => $status,
        'idempotency_key' => 'ina-'.$sequence.'-'.uniqid(),
        'paid_at' => Carbon::parse($paidAt),
        'created_at' => Carbon::parse($paidAt),
        'updated_at' => Carbon::parse($paidAt),
    ]);
}

function inaEnableSweep(): void
{
    DB::table('settings')->updateOrInsert(
        ['key' => 'termination.inactivity_sweep_enabled'],
        ['value' => 'true', 'updated_at' => now()]
    );
}

function inaService(): InactivityTerminationService
{
    return app(InactivityTerminationService::class);
}

function inaStaff(): User
{
    return User::create([
        'full_name' => 'Compliance Officer',
        'email' => 'ina-staff-'.uniqid().'@test.com',
        'phone_e164' => '+91'.random_int(7000000000, 9999999999),
        'password_hash' => bcrypt('x'),
        'status' => 'active',
        'email_verified_at' => now(),
    ]);
}

// ─── tests ───────────────────────────────────────────────────────────────────

it('INA-001: a distributor past twelve months with no sale is dormant; one inside it is not', function () {
    $old = inaDistributor('-13 months');
    $recent = inaDistributor('-6 months');

    expect(inaService()->assess($old)->isDormant)->toBeTrue()
        ->and(inaService()->assess($recent)->isDormant)->toBeFalse();
});

it('INA-002: the clock runs from the last sale when that is later than the effective date', function () {
    $distributor = inaDistributor('-30 months');
    inaOrder($distributor, '-2 months');

    $assessment = inaService()->assess($distributor);

    expect($assessment->isDormant)->toBeFalse()
        ->and($assessment->lastSaleAt?->toDateString())->toBe(Carbon::parse('-2 months')->toDateString())
        ->and($assessment->clockRunningFrom->toDateString())->toBe(Carbon::parse('-2 months')->toDateString());
});

it('INA-003: a cancelled or refunded order is not a sale and does not reset the clock', function () {
    $distributor = inaDistributor('-20 months');
    inaOrder($distributor, '-1 month', 'cancelled');
    inaOrder($distributor, '-2 months', 'refunded');

    $assessment = inaService()->assess($distributor);

    expect($assessment->lastSaleAt)->toBeNull()
        ->and($assessment->isDormant)->toBeTrue();
});

it('INA-004: the sweep issues a seven-day notice and emails it', function () {
    Notification::fake();
    inaEnableSweep();

    $distributor = inaDistributor('-14 months');

    $this->artisan('distributors:inactivity-sweep')->assertSuccessful();

    $distributor->refresh();

    expect($distributor->inactivity_notice_at)->not->toBeNull()
        ->and($distributor->inactivity_notice_at->diffInDays($distributor->inactivity_notice_expires_at))->toBe(7.0)
        // Notice only. §21 promises notice BEFORE termination, so a sweep that
        // closed the account in the same pass would breach the agreement even
        // though it reached the same end state.
        ->and($distributor->terminated_at)->toBeNull();

    Notification::assertSentTo($distributor->user, InactivityTerminationNoticeNotification::class);
});

it('INA-005: the sweep never terminates without an expired notice first', function () {
    Notification::fake();
    inaEnableSweep();

    $distributor = inaDistributor('-36 months');

    $this->artisan('distributors:inactivity-sweep')->assertSuccessful();
    $this->artisan('distributors:inactivity-sweep')->assertSuccessful();

    expect($distributor->fresh()->terminated_at)->toBeNull()
        ->and($distributor->fresh()->user->status)->toBe('active');
});

it('INA-006: a sale inside the notice window withdraws the notice completely', function () {
    Notification::fake();
    inaEnableSweep();

    $distributor = inaDistributor('-14 months');
    $this->artisan('distributors:inactivity-sweep')->assertSuccessful();

    expect($distributor->fresh()->inactivity_notice_at)->not->toBeNull();

    inaOrder($distributor, 'now');
    $this->artisan('distributors:inactivity-sweep')->assertSuccessful();

    $distributor->refresh();

    expect($distributor->inactivity_notice_at)->toBeNull()
        ->and($distributor->inactivity_notice_expires_at)->toBeNull()
        ->and($distributor->terminated_at)->toBeNull();
});

it('INA-007: an expired notice terminates the account and records the reason', function () {
    Notification::fake();
    inaEnableSweep();

    $distributor = inaDistributor('-14 months');
    $this->artisan('distributors:inactivity-sweep')->assertSuccessful();

    // Age the notice past its window.
    $distributor->forceFill([
        'inactivity_notice_at' => Carbon::now()->subDays(8),
        'inactivity_notice_expires_at' => Carbon::now()->subDay(),
    ])->save();

    $this->artisan('distributors:inactivity-sweep')->assertSuccessful();

    $distributor->refresh();

    expect($distributor->terminated_at)->not->toBeNull()
        ->and($distributor->status)->toBe('inactive')
        ->and($distributor->user->status)->toBe('terminated')
        ->and($distributor->termination_reason)->toContain('§21');
});

it('INA-008: the re-registration wait follows the highest rank ever achieved', function () {
    Notification::fake();
    inaEnableSweep();

    $silver = inaDistributor('-14 months');
    $diamond = inaDistributor('-14 months');

    // Rank 1 (Silver Partner) and rank 5 (Diamond Partner).
    foreach ([[$silver, 1], [$diamond, 5]] as [$distributor, $rank]) {
        DB::table('rank_qualifications')->insert([
            'distributor_id' => $distributor->id,
            'rank_number' => $rank,
            'month_start' => Carbon::now()->subMonths(20)->startOfMonth()->toDateString(),
            'status' => 'qualified',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    foreach ([$silver, $diamond] as $distributor) {
        $distributor->forceFill([
            'inactivity_notice_at' => Carbon::now()->subDays(8),
            'inactivity_notice_expires_at' => Carbon::now()->subDay(),
        ])->save();

        inaService()->terminate($distributor->fresh());
    }

    expect($silver->fresh()->reregistration_allowed_from->toDateString())
        ->toBe(Carbon::now()->addYear()->startOfDay()->toDateString())
        ->and($diamond->fresh()->reregistration_allowed_from->toDateString())
        ->toBe(Carbon::now()->addYears(2)->startOfDay()->toDateString());
});

it('INA-009: an unranked distributor carries no re-registration wait', function () {
    Notification::fake();

    $distributor = inaDistributor('-14 months');
    $distributor->forceFill([
        'inactivity_notice_at' => Carbon::now()->subDays(8),
        'inactivity_notice_expires_at' => Carbon::now()->subDay(),
    ])->save();

    inaService()->terminate($distributor->fresh());

    // They never held a rank, so there is no position for the wait to protect.
    expect($distributor->fresh()->reregistration_allowed_from)->toBeNull();
});

it('INA-010: the sweep writes nothing while the master switch is off', function () {
    Notification::fake();

    $distributor = inaDistributor('-24 months');

    $this->artisan('distributors:inactivity-sweep')->assertSuccessful();

    expect($distributor->fresh()->inactivity_notice_at)->toBeNull();
    Notification::assertNothingSent();
});

it('INA-011: notices are idempotent — a second sweep does not reissue or restart them', function () {
    Notification::fake();
    inaEnableSweep();

    $distributor = inaDistributor('-14 months');
    $this->artisan('distributors:inactivity-sweep')->assertSuccessful();

    $issuedAt = $distributor->fresh()->inactivity_notice_at;

    $this->travel(2)->hours();
    $this->artisan('distributors:inactivity-sweep')->assertSuccessful();

    expect($distributor->fresh()->inactivity_notice_at->equalTo($issuedAt))->toBeTrue();
    Notification::assertSentToTimes($distributor->user, InactivityTerminationNoticeNotification::class, 1);
});

it('INA-012: the dormancy admin page renders and a notice can be withdrawn with a reason', function () {
    Notification::fake();
    inaEnableSweep();
    $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);

    $staff = inaStaff();
    $staff->assignRole('admin-compliance');

    $distributor = inaDistributor('-14 months');
    $this->artisan('distributors:inactivity-sweep')->assertSuccessful();

    $this->actingAs($staff)->get(route('admin.dormancy.index'))->assertOk()
        ->assertSee($distributor->adn, false);

    $this->actingAs($staff)->post(route('admin.dormancy.withdraw', $distributor->id), [
        'reason' => 'Sale was attributed to the wrong ADN; correction in progress.',
    ])->assertRedirect();

    expect($distributor->fresh()->inactivity_notice_at)->toBeNull();
});
