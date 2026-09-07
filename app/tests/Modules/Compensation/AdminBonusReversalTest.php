<?php

declare(strict_types=1);

use App\Modules\Compensation\Models\GsbCutoffResult;
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

function reversalAdmin(): User
{
    $user = User::create([
        'full_name' => 'Reversal Admin',
        'email' => 'reversal-admin-'.uniqid().'@test.com',
        'phone_e164' => '+91'.str_pad((string) random_int(7000000000, 9999999999), 10, '0'),
        'password_hash' => bcrypt('x'),
        'status' => 'active',
        'email_verified_at' => now(),
    ]);
    $user->assignRole('admin');

    return $user;
}

/**
 * A credited GSB row for 14 August with its wallet entries actually written:
 * ₹50,000 gross, 10% (₹5,000) held back, ₹45,000 net in the main wallet.
 *
 * @return array{0: Distributor, 1: GsbCutoffResult}
 */
function creditedGsbRow(): array
{
    $distributor = Distributor::factory()->create();

    // Inserted raw, not through the model: the `date` cast serialises to
    // 'Y-m-d 00:00:00', which a MySQL DATE column truncates back to a bare date
    // but SQLite stores verbatim — and the controller looks the row up by
    // toDateString(). The raw insert reproduces the production storage shape.
    $id = DB::table('gsb_cutoff_results')->insertGetId([
        'distributor_id' => $distributor->id,
        'cutoff_date' => '2026-08-14',
        'left_bv_paise' => 2_000_000,
        'right_bv_paise' => 1_600_000,
        'weaker_bv_paise' => 1_600_000,
        'slab' => 2,
        'score' => 16,
        'gross_gsb_paise' => 5_000_000,
        'admin_charge_paise' => 0,
        'tds_paise' => 0,
        'repurchase_deduction_paise' => 500_000,
        'net_gsb_paise' => 4_500_000,
        'status' => GsbCutoffResult::STATUS_CREDITED,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $result = GsbCutoffResult::findOrFail($id);

    app(WalletService::class)->creditWithRepurchaseDeduction(
        distributorId: $distributor->id,
        grossPaise: 5_000_000,
        bonusType: 'gsb_credit',
        referenceId: $result->id,
        referenceType: 'gsb_cutoff_result',
        bonusMonth: Carbon::create(2026, 8, 1),
        earnedOn: Carbon::create(2026, 8, 20),
    );

    return [$distributor, $result];
}

it('reverses both wallets and audits the repurchase side', function () {
    [$distributor, $result] = creditedGsbRow();
    $wallet = app(WalletService::class);

    expect($wallet->balancePaise($distributor->id))->toBe(4_500_000)
        ->and($wallet->repurchaseWalletBalancePaise($distributor->id))->toBe(500_000);

    $this->actingAs(reversalAdmin())
        ->post(route('admin.compensation.manual-controls.reverse'), [
            'adn' => $distributor->adn,
            'date' => '2026-08-14',
            'reason' => 'Slab was matched against a reversed order.',
        ])
        ->assertRedirect(route('admin.compensation.distributors.show', $distributor));

    expect($result->fresh()->status)->toBe(GsbCutoffResult::STATUS_REVERSED)
        ->and($wallet->balancePaise($distributor->id))->toBe(0)
        // The whole point: the repurchase wallet no longer holds a share of a
        // bonus that no longer exists, so the wallet-zero gates pass again.
        ->and($wallet->repurchaseWalletBalancePaise($distributor->id))->toBe(0)
        ->and($wallet->repurchaseDeductionForMonthPaise($distributor->id, Carbon::create(2026, 8, 1)))->toBe(0);

    $audit = AuditLog::where('action', 'compensation.gsb.reversed')->sole();

    expect($audit->details['amount_paise'])->toBe(4_500_000)
        ->and($audit->details['wallet_before'])->toBe(4_500_000)
        ->and($audit->details['wallet_after'])->toBe(0)
        ->and($audit->details['repurchase_deduction_paise'])->toBe(500_000)
        ->and($audit->details['repurchase_reversed_paise'])->toBe(500_000)
        ->and($audit->details['repurchase_shortfall_paise'])->toBe(0)
        ->and($audit->details['repurchase_wallet_before'])->toBe(500_000)
        ->and($audit->details['repurchase_wallet_after'])->toBe(0)
        ->and($audit->details['already_reversed'])->toBeFalse();
});

it('records the shortfall when the repurchase credit was already spent', function () {
    [$distributor, $result] = creditedGsbRow();
    $wallet = app(WalletService::class);

    $wallet->debit($distributor->id, 300_000, 'repurchase_wallet_used', walletRef(), 'order', 'Applied at checkout');

    $this->actingAs(reversalAdmin())
        ->post(route('admin.compensation.manual-controls.reverse'), [
            'adn' => $distributor->adn,
            'date' => '2026-08-14',
            'reason' => 'Slab was matched against a reversed order.',
        ])
        ->assertRedirect(route('admin.compensation.distributors.show', $distributor));

    $audit = AuditLog::where('action', 'compensation.gsb.reversed')->sole();

    expect($audit->details['repurchase_reversed_paise'])->toBe(200_000)
        ->and($audit->details['repurchase_shortfall_paise'])->toBe(300_000)
        ->and($wallet->repurchaseWalletBalancePaise($distributor->id))->toBe(0)
        ->and($result->fresh()->status)->toBe(GsbCutoffResult::STATUS_REVERSED);
});

it('cannot reverse the same cut-off row twice', function () {
    [$distributor] = creditedGsbRow();
    $wallet = app(WalletService::class);
    $admin = reversalAdmin();

    $payload = [
        'adn' => $distributor->adn,
        'date' => '2026-08-14',
        'reason' => 'Slab was matched against a reversed order.',
    ];

    $this->actingAs($admin)->post(route('admin.compensation.manual-controls.reverse'), $payload)->assertRedirect();

    // The row is no longer `credited`, so the second submit finds nothing to
    // reverse rather than debiting the wallet a second time.
    $this->actingAs($admin)->post(route('admin.compensation.manual-controls.reverse'), $payload)->assertNotFound();

    expect($wallet->balancePaise($distributor->id))->toBe(0)
        ->and($wallet->repurchaseWalletBalancePaise($distributor->id))->toBe(0)
        ->and(AuditLog::where('action', 'compensation.gsb.reversed')->count())->toBe(1);
});
