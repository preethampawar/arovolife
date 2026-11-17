<?php

declare(strict_types=1);

use App\Modules\Compensation\Models\LifetimeAwardMilestone;
use App\Modules\Compensation\Models\PayoutBatch;
use App\Modules\Compensation\Models\PayoutLineItem;
use App\Modules\Compensation\Models\WalletLedgerEntry;
use App\Modules\Compensation\Notifications\LifetimeAwardWithdrawnNotification;
use App\Modules\Compensation\Services\WalletService;
use App\Modules\Compliance\Models\AuditLog;
use App\Modules\Identity\Models\Distributor;
use App\Modules\Identity\Models\User;
use App\Modules\Shared\Features\LifetimeAwardsFeature;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Route;
use Laravel\Pennant\Feature;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    disableTestForeignKeys();
    $this->seed(RolesAndPermissionsSeeder::class);
    Feature::for(null)->activate(LifetimeAwardsFeature::class);
});

function lifetimeAwardsAdmin(): User
{
    $user = User::create([
        'full_name' => 'Lifetime Awards Admin',
        'email' => 'lta-'.uniqid().'@test.com',
        'phone_e164' => '+91'.str_pad((string) random_int(7000000000, 9999999999), 10, '0'),
        'password_hash' => bcrypt('x'),
        'status' => 'active',
        'email_verified_at' => now(),
    ]);
    $user->assignRole('admin');

    return $user;
}

/**
 * A credited cash award with its wallet row, as the Rank Bonus close leaves it.
 *
 * @param  array<string, mixed>  $overrides
 */
function lifetimeAwardMilestone(array $overrides = []): LifetimeAwardMilestone
{
    $milestone = LifetimeAwardMilestone::create(array_merge([
        'distributor_id' => Distributor::factory()->create()->id,
        'rank_number' => 6,
        'tranche' => 1,
        'amount_paise' => 93_240_000,
        'triggered_month' => '2026-08-01',
        'achieved_month' => '2026-08-01',
        'qualification_count' => 1,
        'award_description' => 'Blue Diamond Partner Lifetime Award',
        'status' => LifetimeAwardMilestone::STATUS_CREDITED,
        'credited_at' => now(),
    ], $overrides));

    if ($milestone->status === LifetimeAwardMilestone::STATUS_CREDITED) {
        app(WalletService::class)->credit(
            $milestone->distributor_id, $milestone->amount_paise, 'awards_credit', $milestone->id,
            'lifetime_award_milestone', 'Lifetime Award', bonusMonth: Carbon::parse('2026-08-01'),
        );
    }

    return $milestone;
}

it('lists each award with its amount, the month it was earned for and the batch that paid it', function (): void {
    $paid = lifetimeAwardMilestone();
    $batch = PayoutBatch::create(['batch_type' => PayoutBatch::TYPE_MONTHLY, 'batch_date' => '2026-09-01', 'status' => PayoutBatch::STATUS_COMPLETED]);
    WalletLedgerEntry::where('reference_id', $paid->id)->where('type', 'awards_credit')->update(['swept_by_payout_batch_id' => $batch->id]);

    $this->actingAs(lifetimeAwardsAdmin())
        ->get(route('admin.lifetime-awards.index'))
        ->assertOk()
        ->assertSee('₹9,32,400')
        ->assertSee('Aug 2026')
        ->assertSee('#'.$batch->id.' — 01 Sep 2026')
        ->assertSee('TDS only')
        ->assertDontSee('Tranche')
        ->assertDontSee('Mark Delivered');
});

it('shows an award as Paid only once its payout line was transferred, and as in payout before that', function (): void {
    $credited = lifetimeAwardMilestone(['rank_number' => 1]);
    $inPayout = lifetimeAwardMilestone(['rank_number' => 2]);
    $paid = lifetimeAwardMilestone(['rank_number' => 3]);
    $batch = PayoutBatch::create(['batch_type' => PayoutBatch::TYPE_MONTHLY, 'batch_date' => '2026-09-01', 'status' => PayoutBatch::STATUS_PROCESSING]);

    foreach ([[$inPayout, PayoutLineItem::STATUS_PENDING], [$paid, PayoutLineItem::STATUS_TRANSFERRED]] as [$milestone, $lineStatus]) {
        WalletLedgerEntry::where('reference_id', $milestone->id)->where('type', 'awards_credit')->update(['swept_by_payout_batch_id' => $batch->id]);
        PayoutLineItem::create([
            'payout_batch_id' => $batch->id, 'distributor_id' => $milestone->distributor_id,
            'gross_paise' => $milestone->amount_paise, 'admin_charge_paise' => 0, 'tds_paise' => 0,
            'wallet_balance_paise' => 0, 'repurchase_deduction_paise' => 0, 'retry_count' => 0,
            'net_transferred_paise' => $milestone->amount_paise, 'status' => $lineStatus,
        ]);
    }

    $this->actingAs(lifetimeAwardsAdmin())
        ->get(route('admin.lifetime-awards.index'))
        ->assertOk()
        // Rows run rank 1 → 3; the status cell comes before the batch cell.
        ->assertSeeInOrder(['Paid in batch', 'Credited', 'In payout — awaiting bank', '#'.$batch->id, 'Paid', '#'.$batch->id]);

    expect($credited->fresh()->status)->toBe(LifetimeAwardMilestone::STATUS_CREDITED);
});

it('has no delivery or reward-catalogue routes any more', function (): void {
    expect(Route::has('admin.lifetime-awards.deliver'))->toBeFalse()
        ->and(Route::has('admin.lifetime-awards.catalog'))->toBeFalse()
        ->and(Route::has('admin.lifetime-awards.catalog.update'))->toBeFalse();
});

it('withdraws an unpaid credited award with a reason: wallet credit reversed, audited, partner told', function (): void {
    Notification::fake();
    $milestone = lifetimeAwardMilestone();

    $this->actingAs(lifetimeAwardsAdmin())->get(route('admin.lifetime-awards.index'))->assertOk()->assertSee('Withdraw');

    $this->actingAs(lifetimeAwardsAdmin())
        ->withoutMiddleware(PreventRequestForgery::class)
        ->post(route('admin.lifetime-awards.withdraw', $milestone->id), ['reason' => 'Terminated for breach of the Code of Ethics.'])
        ->assertRedirect(route('admin.lifetime-awards.index'));

    $milestone->refresh();
    expect($milestone->status)->toBe(LifetimeAwardMilestone::STATUS_CANCELLED)
        ->and($milestone->notes)->toBe('Terminated for breach of the Code of Ethics.')
        ->and(app(WalletService::class)->balancePaise($milestone->distributor_id))->toBe(0);

    $audit = AuditLog::query()->where('action', 'admin.lifetime_award.withdrawn')->where('subject_id', $milestone->id)->firstOrFail();
    expect($audit->details['reason'])->toBe('Terminated for breach of the Code of Ethics.')
        ->and($audit->details['wallet_reversed'])->toBeTrue()
        ->and($audit->before_hash)->not->toBeNull()
        ->and($audit->after_hash)->not->toBeNull();

    // An adverse decision is told, never left to be found: reason + grievance route.
    $user = $milestone->distributor->user;
    if ($user !== null) {
        Notification::assertSentTo($user, LifetimeAwardWithdrawnNotification::class,
            fn (LifetimeAwardWithdrawnNotification $n): bool => $n->reason === 'Terminated for breach of the Code of Ethics.');
    }
});

it('withdraws a pending award that was never credited', function (): void {
    $milestone = lifetimeAwardMilestone(['status' => LifetimeAwardMilestone::STATUS_PENDING, 'credited_at' => null]);

    $this->actingAs(lifetimeAwardsAdmin())
        ->withoutMiddleware(PreventRequestForgery::class)
        ->post(route('admin.lifetime-awards.withdraw', $milestone->id), ['reason' => 'Correction'])
        ->assertRedirect(route('admin.lifetime-awards.index'));

    expect($milestone->fresh()->status)->toBe(LifetimeAwardMilestone::STATUS_CANCELLED);
});

it('refuses to withdraw an award the payout has already swept', function (): void {
    $milestone = lifetimeAwardMilestone();
    $batch = PayoutBatch::create(['batch_type' => PayoutBatch::TYPE_MONTHLY, 'batch_date' => '2026-09-01', 'status' => PayoutBatch::STATUS_PENDING]);
    WalletLedgerEntry::where('reference_id', $milestone->id)->where('type', 'awards_credit')->update(['swept_by_payout_batch_id' => $batch->id]);

    $this->actingAs(lifetimeAwardsAdmin())
        ->withoutMiddleware(PreventRequestForgery::class)
        ->post(route('admin.lifetime-awards.withdraw', $milestone->id), ['reason' => 'x'])
        ->assertStatus(409)
        ->assertSee('This award has already been paid.');

    expect($milestone->fresh()->status)->toBe(LifetimeAwardMilestone::STATUS_CREDITED);
});

it('never withdraws merchandise already handed over, and needs a reason', function (): void {
    $delivered = lifetimeAwardMilestone(['status' => LifetimeAwardMilestone::STATUS_DELIVERED, 'delivered_at' => now()]);
    $credited = lifetimeAwardMilestone();
    $admin = lifetimeAwardsAdmin();

    $this->actingAs($admin)->withoutMiddleware(PreventRequestForgery::class)
        ->post(route('admin.lifetime-awards.withdraw', $delivered->id), ['reason' => 'x'])
        ->assertSessionHas('error');
    $this->actingAs($admin)->withoutMiddleware(PreventRequestForgery::class)
        ->post(route('admin.lifetime-awards.withdraw', $credited->id), [])
        ->assertSessionHasErrors('reason');

    expect($delivered->fresh()->status)->toBe(LifetimeAwardMilestone::STATUS_DELIVERED)
        ->and($credited->fresh()->status)->toBe(LifetimeAwardMilestone::STATUS_CREDITED);
});

it('keeps withdrawing an award out of the finance role', function (): void {
    $milestone = lifetimeAwardMilestone();
    $finance = lifetimeAwardsAdmin();
    $finance->syncRoles(['admin-finance']);

    $this->actingAs($finance)->withoutMiddleware(PreventRequestForgery::class)
        ->post(route('admin.lifetime-awards.withdraw', $milestone->id), ['reason' => 'x'])
        ->assertForbidden();

    expect($milestone->fresh()->status)->toBe(LifetimeAwardMilestone::STATUS_CREDITED);
});
