<?php

declare(strict_types=1);

use App\Modules\Identity\Models\User;
use App\Modules\Shared\Features\GrowthBoosterBonusFeature;
use App\Modules\Shared\Features\MentorshipBonusFeature;
use App\Modules\Shared\Features\RankBonusFeature;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Pennant\Feature;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

/**
 * Task 14 Step 2 (L2 + L8): the four point-value / royalty caps are whole
 * rupees, and the Rank pass-1 ceiling is at least 1. The Save endpoint refuses
 * anything else, so a value the engines would refuse never reaches them.
 */
function awrSeedDeveloper(): User
{
    Role::firstOrCreate(['name' => 'developer', 'guard_name' => 'web']);
    $user = User::create([
        'full_name' => 'Settings Developer',
        'email' => 'awr-dev-'.uniqid().'@example.com',
        'phone_e164' => '+9180000'.rand(10000, 99999),
        'password_hash' => bcrypt('Adm1n!Pass#2026Test'),
        'password_set_at' => now(),
        'status' => 'active',
        'email_verified_at' => now(),
    ]);
    $user->assignRole('developer');

    return $user;
}

function awrSeedSetting(string $key, string $value): void
{
    DB::table('settings')->updateOrInsert(
        ['key' => $key],
        ['value' => $value, 'version' => 1, 'updated_at' => now()],
    );
}

beforeEach(function (): void {
    Feature::for(null)->activate(MentorshipBonusFeature::class);
    Feature::for(null)->activate(GrowthBoosterBonusFeature::class);
    Feature::for(null)->activate(RankBonusFeature::class);
    $this->actingAs(awrSeedDeveloper())->withoutMiddleware(PreventRequestForgery::class);
});

dataset('whole-rupee caps', [
    'MSB point value cap' => ['comp.msb.point_value_cap_paise', '12000'],
    'Mentorship Royalty daily cap' => ['comp.msb.royalty_failed_daily_cap_paise', '360000'],
    'GBB point value cap' => ['comp.gbb.point_value_cap_paise', '24000'],
    'Rank point value cap' => ['comp.rank.point_value_cap_paise', '20000'],
]);

it('refuses a cap that is not a whole rupee and keeps the stored value', function (string $key, string $stored): void {
    awrSeedSetting($key, $stored);

    $this->post('/admin/settings/'.$key, ['value' => '24050'])
        ->assertRedirect(route('admin.settings'))
        ->assertSessionHasErrors(['value' => 'Enter a whole-rupee amount (a multiple of 100 paise).']);

    expect(DB::table('settings')->where('key', $key)->value('value'))->toBe($stored);
})->with('whole-rupee caps');

it('accepts a whole-rupee cap and the neutralise value', function (string $key, string $stored): void {
    awrSeedSetting($key, $stored);

    foreach (['24000', '100000000'] as $value) {
        $this->post('/admin/settings/'.$key, ['value' => $value])
            ->assertRedirect(route('admin.settings'))
            ->assertSessionHasNoErrors();

        expect(DB::table('settings')->where('key', $key)->value('value'))->toBe($value);
    }
})->with('whole-rupee caps');

it('refuses a pass-1 rank ceiling of 0 and keeps the stored value', function (): void {
    awrSeedSetting('comp.rank.first_pass_max_rank', '3');

    $this->post('/admin/settings/comp.rank.first_pass_max_rank', ['value' => '0'])
        ->assertRedirect(route('admin.settings'))
        ->assertSessionHasErrors(['value' => 'Ranks priced in pass 1 (1..N) must be between 1 and 9.']);

    expect(DB::table('settings')->where('key', 'comp.rank.first_pass_max_rank')->value('value'))->toBe('3');
});

it('accepts every pass-1 rank ceiling from 1 to 9', function (int $value): void {
    awrSeedSetting('comp.rank.first_pass_max_rank', '3');

    $this->post('/admin/settings/comp.rank.first_pass_max_rank', ['value' => (string) $value])
        ->assertRedirect(route('admin.settings'))
        ->assertSessionHasNoErrors();

    expect(DB::table('settings')->where('key', 'comp.rank.first_pass_max_rank')->value('value'))->toBe((string) $value);
})->with(range(1, 9));
