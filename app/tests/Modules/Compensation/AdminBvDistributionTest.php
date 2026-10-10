<?php

declare(strict_types=1);

use App\Modules\Identity\Models\User;
use App\Modules\Shared\Features\GenosSalesBonusFeature;
use App\Modules\Shared\Features\LifetimeAwardsFeature;
use App\Modules\Shared\Features\RankBonusFeature;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Pennant\Feature;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    disableTestForeignKeys();
    $this->seed(RolesAndPermissionsSeeder::class);
});

function bvDistAdmin(): User
{
    $user = User::create([
        'full_name' => 'BV Dist Admin',
        'email' => 'bv-dist-'.uniqid().'@test.com',
        'phone_e164' => '+91'.str_pad((string) random_int(7000000000, 9999999999), 10, '0'),
        'password_hash' => bcrypt('x'),
        'status' => 'active',
        'email_verified_at' => now(),
    ]);
    $user->assignRole('admin');

    return $user;
}

function bvDistEntry(int $bvPaise, string $effectiveAt): void
{
    DB::table('bv_ledger_entries')->insert([
        'distributor_id' => 1,
        'order_id' => random_int(1, 1_000_000),
        'bv_paise' => $bvPaise,
        'type' => $bvPaise < 0 ? 'reversal' : 'accrual',
        'effective_at' => $effectiveAt,
        'created_at' => now()->toDateTimeString(),
        'updated_at' => now()->toDateTimeString(),
    ]);
}

it('splits the period company BV across the switched-on bonuses at their configured rates', function (): void {
    Feature::for(null)->activate(GenosSalesBonusFeature::class);
    Feature::for(null)->activate(RankBonusFeature::class);
    Feature::for(null)->activate(LifetimeAwardsFeature::class);

    bvDistEntry(1_000_000, '2026-10-05 12:00:00');   // 10,000 BV
    bvDistEntry(-200_000, '2026-10-06 12:00:00');    // −2,000 BV reversal
    bvDistEntry(5_000_000, '2026-09-30 12:00:00');   // previous month, excluded

    $html = $this->actingAs(bvDistAdmin())
        ->get(route('admin.compensation.bv-distribution', ['period' => 'month', 'date' => '2026-10-10']))
        ->assertOk()
        ->assertSee('8,000 BV')                 // company BV
        ->assertSee('Genos Sales Bonus')
        ->assertSee('3,600')                    // 45%
        ->assertSee('Rank Bonus')
        ->assertSee('1,600')                    // 20% (Rank) and 20% (Awards)
        ->assertSee('Awards &amp; Rewards', false)
        ->assertDontSee('Mentorship Bonus')     // flag off — no trace
        ->assertSee('data-bv-distribution-table', false)
        ->getContent();

    expect(substr_count($html, 'data-bv-distribution-bonus='))->toBe(3);
});

it('narrows to a single day', function (): void {
    Feature::for(null)->activate(GenosSalesBonusFeature::class);
    bvDistEntry(1_000_000, '2026-10-05 12:00:00');
    bvDistEntry(500_000, '2026-10-06 12:00:00');

    $this->actingAs(bvDistAdmin())
        ->get(route('admin.compensation.bv-distribution', ['period' => 'day', 'date' => '2026-10-06']))
        ->assertOk()
        ->assertSee('5,000 BV')
        ->assertSee('2,250');                   // 45% of 5,000
});

it('rejects an unknown period', function (): void {
    $this->actingAs(bvDistAdmin())
        ->get(route('admin.compensation.bv-distribution', ['period' => 'decade']))
        ->assertSessionHasErrors('period');
});
