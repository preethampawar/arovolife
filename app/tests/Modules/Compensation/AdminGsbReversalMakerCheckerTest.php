<?php

declare(strict_types=1);

use App\Modules\Compensation\Models\GsbCutoffResult;
use App\Modules\Compensation\Models\GsbReversalRequest;
use App\Modules\Compensation\Services\WalletService;
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

/** @param  'admin'|'admin-compliance'  $role */
function reversalStaff(string $role = 'admin'): User
{
    $user = User::create([
        'full_name' => 'Reversal Staff',
        'email' => 'reversal-'.uniqid().'@test.com',
        'phone_e164' => '+91'.str_pad((string) random_int(7000000000, 9999999999), 10, '0'),
        'password_hash' => bcrypt('x'),
        'status' => 'active',
        'email_verified_at' => now(),
    ]);
    $user->assignRole($role);

    return $user;
}

/**
 * A credited GSB day with the wallet entry behind it, so a reversal has
 * something real to walk back.
 *
 * @return array{0: Distributor, 1: int}
 */
function creditedGsbDay(int $netPaise = 250_000, string $date = '2026-08-14'): array
{
    $distributor = Distributor::factory()->create();

    $resultId = DB::table('gsb_cutoff_results')->insertGetId([
        'distributor_id' => $distributor->id,
        'cutoff_date' => $date,
        'left_bv_paise' => 0,
        'right_bv_paise' => 0,
        'weaker_bv_paise' => 0,
        'gross_gsb_paise' => $netPaise,
        'admin_charge_paise' => 0,
        'tds_paise' => 0,
        'repurchase_deduction_paise' => 0,
        'net_gsb_paise' => $netPaise,
        'power_cf_before_paise' => 0,
        'power_cf_after_paise' => 0,
        'slab1_weaker_cf_before_paise' => 0,
        'slab1_weaker_cf_after_paise' => 0,
        'status' => GsbCutoffResult::STATUS_CREDITED,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    app(WalletService::class)->credit(
        distributorId: $distributor->id,
        amountPaise: $netPaise,
        type: 'gsb_credit',
        referenceId: $resultId,
        referenceType: 'gsb_cutoff_result',
        bonusMonth: Carbon::parse($date)->startOfMonth(),
        earnedOn: Carbon::parse($date),
    );

    return [$distributor, $resultId];
}

it('raises a request instead of reversing, and moves no money', function () {
    [$distributor, $resultId] = creditedGsbDay();
    $wallet = app(WalletService::class);
    $before = $wallet->balancePaise($distributor->id);

    $this->actingAs(reversalStaff('admin-compliance'))
        ->post(route('admin.compensation.manual-controls.reverse'), [
            'adn' => $distributor->adn,
            'date' => '2026-08-14',
            'reason' => 'Credited against an order that was later cancelled.',
        ])
        ->assertRedirect(route('admin.compensation.manual-controls.index'));

    $pending = GsbReversalRequest::where('gsb_cutoff_result_id', $resultId)->first();

    expect($pending)->not->toBeNull()
        ->and($pending->status)->toBe(GsbReversalRequest::STATUS_PENDING)
        // Snapshotted so the approver signs off the figure the requester saw.
        ->and($pending->net_gsb_paise)->toBe(250_000)
        // Nothing moved: the credit stands and so does the balance.
        ->and($wallet->balancePaise($distributor->id))->toBe($before)
        ->and(GsbCutoffResult::find($resultId)->status)->toBe(GsbCutoffResult::STATUS_CREDITED)
        ->and(AuditLog::where('action', 'compensation.gsb.reversal_requested')->exists())->toBeTrue()
        ->and(AuditLog::where('action', 'compensation.gsb.reversed')->exists())->toBeFalse();
});

it('refuses to let the requester approve their own reversal', function () {
    [$distributor, $resultId] = creditedGsbDay();
    $maker = reversalStaff();   // holds both permissions, as a full admin does
    $wallet = app(WalletService::class);
    $before = $wallet->balancePaise($distributor->id);

    $this->actingAs($maker)->post(route('admin.compensation.manual-controls.reverse'), [
        'adn' => $distributor->adn,
        'date' => '2026-08-14',
        'reason' => 'Credited against an order that was later cancelled.',
    ]);

    $pending = GsbReversalRequest::where('gsb_cutoff_result_id', $resultId)->firstOrFail();

    $this->from(route('admin.compensation.manual-controls.index'))
        ->actingAs($maker)
        ->post(route('admin.compensation.manual-controls.reversals.approve', $pending->id))
        ->assertRedirect(route('admin.compensation.manual-controls.index'));

    expect(session('error'))->toContain('cannot also approve it');

    // The whole point: the money did not move on one person's say-so.
    expect($pending->fresh()->status)->toBe(GsbReversalRequest::STATUS_PENDING)
        ->and(GsbCutoffResult::find($resultId)->status)->toBe(GsbCutoffResult::STATUS_CREDITED)
        ->and($wallet->balancePaise($distributor->id))->toBe($before)
        ->and(AuditLog::where('action', 'compensation.gsb.reversal_self_approval_refused')->exists())->toBeTrue();
});

it('reverses once a second admin approves, and records both hands', function () {
    [$distributor, $resultId] = creditedGsbDay();
    $maker = reversalStaff('admin-compliance');
    $checker = reversalStaff();
    $wallet = app(WalletService::class);
    $before = $wallet->balancePaise($distributor->id);

    $this->actingAs($maker)->post(route('admin.compensation.manual-controls.reverse'), [
        'adn' => $distributor->adn,
        'date' => '2026-08-14',
        'reason' => 'Credited against an order that was later cancelled.',
    ]);

    $pending = GsbReversalRequest::where('gsb_cutoff_result_id', $resultId)->firstOrFail();

    $this->actingAs($checker)
        ->post(route('admin.compensation.manual-controls.reversals.approve', $pending->id))
        ->assertRedirect(route('admin.compensation.distributors.show', $distributor->id));

    $decided = $pending->fresh();
    $audit = AuditLog::where('action', 'compensation.gsb.reversed')->firstOrFail();

    expect($decided->status)->toBe(GsbReversalRequest::STATUS_APPROVED)
        ->and($decided->decided_by)->toBe($checker->id)
        ->and($decided->decided_at)->not->toBeNull()
        ->and(GsbCutoffResult::find($resultId)->status)->toBe(GsbCutoffResult::STATUS_REVERSED)
        ->and($wallet->balancePaise($distributor->id))->toBe($before - 250_000)
        // The audit row answers "who asked" and "who signed" without a join.
        ->and($audit->details['requested_by'])->toBe($maker->id)
        ->and($audit->details['approved_by'])->toBe($checker->id)
        ->and($audit->details['reversal_request_id'])->toBe($decided->id);
});

it('keeps the checker permission out of the role that raises the request', function () {
    [$distributor, $resultId] = creditedGsbDay();

    $this->actingAs(reversalStaff('admin-compliance'))
        ->post(route('admin.compensation.manual-controls.reverse'), [
            'adn' => $distributor->adn,
            'date' => '2026-08-14',
            'reason' => 'Credited against an order that was later cancelled.',
        ]);

    $pending = GsbReversalRequest::where('gsb_cutoff_result_id', $resultId)->firstOrFail();

    // admin-compliance is the maker role. Even on someone else's request it
    // cannot sign one off — that is the route half of maker-checker, and it is
    // what stops two compliance admins covering for each other.
    $this->actingAs(reversalStaff('admin-compliance'))
        ->post(route('admin.compensation.manual-controls.reversals.approve', $pending->id))
        ->assertForbidden();

    expect($pending->fresh()->status)->toBe(GsbReversalRequest::STATUS_PENDING)
        ->and(GsbCutoffResult::find($resultId)->status)->toBe(GsbCutoffResult::STATUS_CREDITED);
});

it('refuses an approval once the credit has changed under the request', function () {
    [$distributor, $resultId] = creditedGsbDay();
    $maker = reversalStaff('admin-compliance');

    $this->actingAs($maker)->post(route('admin.compensation.manual-controls.reverse'), [
        'adn' => $distributor->adn,
        'date' => '2026-08-14',
        'reason' => 'Credited against an order that was later cancelled.',
    ]);

    $pending = GsbReversalRequest::where('gsb_cutoff_result_id', $resultId)->firstOrFail();

    // The figure the approver was shown is no longer the figure on the row.
    DB::table('gsb_cutoff_results')->where('id', $resultId)->update(['net_gsb_paise' => 190_000]);

    $wallet = app(WalletService::class);
    $before = $wallet->balancePaise($distributor->id);

    $this->from(route('admin.compensation.manual-controls.index'))
        ->actingAs(reversalStaff())
        ->post(route('admin.compensation.manual-controls.reversals.approve', $pending->id))
        ->assertRedirect(route('admin.compensation.manual-controls.index'));

    expect(session('error'))->toContain('no longer matches the credit')
        ->and($pending->fresh()->status)->toBe(GsbReversalRequest::STATUS_PENDING)
        ->and(GsbCutoffResult::find($resultId)->status)->toBe(GsbCutoffResult::STATUS_CREDITED)
        ->and($wallet->balancePaise($distributor->id))->toBe($before);
});

it('refuses to approve a credit the payout batch has already paid', function () {
    [$distributor, $resultId] = creditedGsbDay();
    $maker = reversalStaff('admin-compliance');

    $this->actingAs($maker)->post(route('admin.compensation.manual-controls.reverse'), [
        'adn' => $distributor->adn,
        'date' => '2026-08-14',
        'reason' => 'Credited against an order that was later cancelled.',
    ]);

    $pending = GsbReversalRequest::where('gsb_cutoff_result_id', $resultId)->firstOrFail();

    // The weekly batch swept it between the request and the approval — exactly
    // the window maker-checker widened.
    DB::table('wallet_ledger_entries')
        ->where('type', 'gsb_credit')
        ->where('reference_id', $resultId)
        ->update(['swept_by_payout_batch_id' => 77]);

    $wallet = app(WalletService::class);
    $before = $wallet->balancePaise($distributor->id);

    $this->from(route('admin.compensation.manual-controls.index'))
        ->actingAs(reversalStaff())
        ->post(route('admin.compensation.manual-controls.reversals.approve', $pending->id))
        ->assertRedirect(route('admin.compensation.manual-controls.index'));

    // A debit does not recall a transfer, so the wallet is not driven short of
    // money that has already gone to a bank.
    expect(session('error'))->toContain('already swept into payout batch #77')
        ->and($pending->fresh()->status)->toBe(GsbReversalRequest::STATUS_PENDING)
        ->and(GsbCutoffResult::find($resultId)->status)->toBe(GsbCutoffResult::STATUS_CREDITED)
        ->and($wallet->balancePaise($distributor->id))->toBe($before);
});

it('answers a request whose credit vanished under it instead of a raw 404', function () {
    [$distributor, $resultId] = creditedGsbDay();

    $this->actingAs(reversalStaff('admin-compliance'))
        ->post(route('admin.compensation.manual-controls.reverse'), [
            'adn' => $distributor->adn,
            'date' => '2026-08-14',
            'reason' => 'Credited against an order that was later cancelled.',
        ]);

    $pending = GsbReversalRequest::where('gsb_cutoff_result_id', $resultId)->firstOrFail();

    // The R-91 §14c rebuild deletes result rows by design.
    DB::table('gsb_cutoff_results')->where('id', $resultId)->delete();

    $this->from(route('admin.compensation.manual-controls.index'))
        ->actingAs(reversalStaff())
        ->post(route('admin.compensation.manual-controls.reversals.approve', $pending->id))
        ->assertRedirect(route('admin.compensation.manual-controls.index'));

    expect(session('error'))->toContain('no longer a settled, unreversed GSB day');
});

it('refuses a second pending request for the same credit', function () {
    [$distributor, $resultId] = creditedGsbDay();

    $payload = [
        'adn' => $distributor->adn,
        'date' => '2026-08-14',
        'reason' => 'Credited against an order that was later cancelled.',
    ];

    $this->actingAs(reversalStaff('admin-compliance'))
        ->post(route('admin.compensation.manual-controls.reverse'), $payload);

    $this->from(route('admin.compensation.manual-controls.index'))
        ->actingAs(reversalStaff('admin-compliance'))
        ->post(route('admin.compensation.manual-controls.reverse'), $payload);

    expect(session('error'))->toContain('already waiting for approval')
        ->and(GsbReversalRequest::where('gsb_cutoff_result_id', $resultId)->count())->toBe(1);
});

it('closes a request on rejection without touching the credit', function () {
    [$distributor, $resultId] = creditedGsbDay();
    $wallet = app(WalletService::class);
    $before = $wallet->balancePaise($distributor->id);

    $this->actingAs(reversalStaff('admin-compliance'))
        ->post(route('admin.compensation.manual-controls.reverse'), [
            'adn' => $distributor->adn,
            'date' => '2026-08-14',
            'reason' => 'Credited against an order that was later cancelled.',
        ]);

    $pending = GsbReversalRequest::where('gsb_cutoff_result_id', $resultId)->firstOrFail();

    $this->actingAs(reversalStaff())
        ->post(route('admin.compensation.manual-controls.reversals.reject', $pending->id), [
            'decision_note' => 'The order was reinstated, so the credit stands.',
        ])
        ->assertRedirect(route('admin.compensation.manual-controls.index'));

    expect($pending->fresh()->status)->toBe(GsbReversalRequest::STATUS_REJECTED)
        ->and(GsbCutoffResult::find($resultId)->status)->toBe(GsbCutoffResult::STATUS_CREDITED)
        ->and($wallet->balancePaise($distributor->id))->toBe($before)
        ->and(AuditLog::where('action', 'compensation.gsb.reversal_rejected')->exists())->toBeTrue();
});
