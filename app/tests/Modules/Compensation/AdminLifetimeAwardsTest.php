<?php

declare(strict_types=1);

use App\Modules\Compensation\Models\LifetimeAwardMilestone;
use App\Modules\Compensation\Models\WalletLedgerEntry;
use App\Modules\Compliance\Models\AuditLog;
use App\Modules\Identity\Models\Distributor;
use App\Modules\Identity\Models\User;
use App\Modules\Shared\Features\LifetimeAwardsFeature;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
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

/** @param  array<string, mixed>  $overrides */
function lifetimeAwardMilestone(array $overrides = []): LifetimeAwardMilestone
{
    return LifetimeAwardMilestone::create(array_merge([
        'distributor_id' => Distributor::factory()->create()->id,
        'rank_number' => 6,
        'tranche' => 2,
        'amount_paise' => 93_240_000,
        'triggered_month' => '2026-08-01',
        'qualification_count' => 2,
        'award_description' => 'Blue Diamond Partner — tranche B, merchandise per plan',
        'status' => LifetimeAwardMilestone::STATUS_PENDING,
    ], $overrides));
}

it('marks a releasable tranche delivered as merchandise and never credits the wallet', function (): void {
    $milestone = lifetimeAwardMilestone();

    $this->actingAs(lifetimeAwardsAdmin())
        ->withoutMiddleware(PreventRequestForgery::class)
        // A stale form that still posts the retired cash option changes nothing.
        ->post(route('admin.lifetime-awards.deliver', $milestone->id), ['disbursement_type' => 'cash', 'notes' => 'Handed over'])
        ->assertRedirect(route('admin.lifetime-awards.index'));

    $milestone->refresh();
    expect($milestone->status)->toBe(LifetimeAwardMilestone::STATUS_DELIVERED)
        ->and($milestone->delivered_at)->not->toBeNull()
        ->and($milestone->notes)->toBe('Handed over')
        ->and($milestone->amount_paise)->toBe(93_240_000)
        // The cash columns are gone (2026_10_09_101000).
        ->and(Schema::hasColumn('lifetime_award_milestones', 'disbursement_type'))->toBeFalse()
        ->and(Schema::hasColumn('lifetime_award_milestones', 'gross_paise'))->toBeFalse()
        ->and(WalletLedgerEntry::where('distributor_id', $milestone->distributor_id)->count())->toBe(0);

    $audit = AuditLog::query()->where('action', 'admin.lifetime_award.delivered')->where('subject_id', $milestone->id)->firstOrFail();
    expect($audit->details['amount_paise'])->toBe(93_240_000)
        ->and($audit->details)->not->toHaveKey('disbursement_type');
});

it('refuses to deliver a tranche the rank has not been qualified for enough times', function (): void {
    $milestone = lifetimeAwardMilestone(['tranche' => 3, 'qualification_count' => 2]);

    $this->actingAs(lifetimeAwardsAdmin())
        ->withoutMiddleware(PreventRequestForgery::class)
        ->post(route('admin.lifetime-awards.deliver', $milestone->id))
        ->assertStatus(422);

    expect($milestone->refresh()->status)->toBe(LifetimeAwardMilestone::STATUS_PENDING);
});

it('lists the tranche, its amount and the release-rule-changed flag, with no cash option', function (): void {
    lifetimeAwardMilestone(['released_rule_changed_at' => '2026-10-09 10:00:00']);

    $this->actingAs(lifetimeAwardsAdmin())
        ->get(route('admin.lifetime-awards.index'))
        ->assertOk()
        ->assertSee('Tranche')
        ->assertSee('₹9,32,400')
        ->assertSee('Release rule changed on 09 Oct 2026')
        ->assertDontSee('<option value="cash">', false);
});
