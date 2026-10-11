<?php

declare(strict_types=1);

/**
 * arovolife Family Card (R-117): a display-only dashboard card + printable page,
 * and My Profile → Family members (up to five, consent per member, parent /
 * guardian consent for a child, erased on termination / cooling-off / turns 18).
 */

use App\Modules\Admin\Events\DistributorTerminated;
use App\Modules\Compliance\Models\AuditLog;
use App\Modules\Compliance\Services\CancelCoolingOff;
use App\Modules\Identity\Models\Distributor;
use App\Modules\Identity\Models\DistributorFamilyMember;
use App\Modules\Identity\Models\User;
use App\Modules\Shared\Features\FamilyCardFeature;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Pennant\Feature;

uses(RefreshDatabase::class);

function familyCardDistributor(string $userStatus = 'active'): Distributor
{
    disableTestForeignKeys();
    try {
        $distributor = Distributor::factory()->create();
    } finally {
        enableTestForeignKeys();
    }
    DB::table('genealogy_closure')->insert(['ancestor_id' => $distributor->id, 'descendant_id' => $distributor->id, 'depth' => 0]);
    $distributor->user->forceFill(['status' => $userStatus])->save();

    return $distributor->fresh();
}

function addFamilyMember(Distributor $distributor, array $overrides = [])
{
    return test()->actingAs($distributor->user)
        ->withoutMiddleware(PreventRequestForgery::class)
        ->post(route('profile.family-members.store'), array_merge([
            'full_name' => 'Lakshmi Devi',
            'relation' => 'parent',
            'is_minor' => '0',
            'consent' => '1',
        ], $overrides));
}

function seedFamilyMember(Distributor $distributor, array $overrides = []): DistributorFamilyMember
{
    return DistributorFamilyMember::create(array_merge([
        'distributor_id' => $distributor->id,
        'full_name' => 'Seeded Member',
        'relation' => 'sibling',
        'is_minor' => false,
        'consent_given_at' => now(),
        'consent_version' => DistributorFamilyMember::CONSENT_VERSION,
        'consent_text_hash' => DistributorFamilyMember::consentTextHash(false),
        'consented_by_user_id' => $distributor->user_id,
    ], $overrides));
}

it('is on by default — the card shows without anyone switching the flag on', function (): void {
    $distributor = familyCardDistributor();

    $this->actingAs($distributor->user)->get(route('dashboard'))
        ->assertOk()
        ->assertSee('arovolife Family Card')
        ->assertSee(route('my.family-card'));
});

it('opens My Family Card from the sidebar: the card, front and back, with a download link', function (): void {
    $distributor = familyCardDistributor();

    $this->actingAs($distributor->user)->get(route('my.family-card'))
        ->assertOk()
        ->assertSee($distributor->adn)
        ->assertSee('1 + 5 family members')
        ->assertSee('It is not an insurance policy')
        ->assertSee(route('family-card.show'))
        ->assertSee('Download PDF / Print');
});

it('leaves no trace of the Family Card while the flag is off', function (): void {
    Feature::for(null)->deactivate(FamilyCardFeature::class);
    $distributor = familyCardDistributor();

    $this->actingAs($distributor->user)->get(route('dashboard'))->assertOk()->assertDontSee('Family Card');
    $this->actingAs($distributor->user)->get(route('family-card.show'))->assertNotFound();
    $this->actingAs($distributor->user)->get(route('my.family-card'))->assertNotFound();
    $this->actingAs($distributor->user)->get(route('profile.show'))->assertOk()->assertDontSee('Family members');
    addFamilyMember($distributor)->assertNotFound();
    expect(DistributorFamilyMember::count())->toBe(0);
});

it('shows the card with name, ADN and the fixed 1 + 5 — never a family member\'s name', function (): void {
    Feature::for(null)->activate(FamilyCardFeature::class);
    $distributor = familyCardDistributor();
    seedFamilyMember($distributor, ['full_name' => 'Hidden Relative']);

    $this->actingAs($distributor->user)->get(route('dashboard'))
        ->assertOk()
        ->assertSee('arovolife Family Card')
        ->assertSee($distributor->adn)
        ->assertSee('1 + 5 family members')
        ->assertSee('Not an insurance policy')
        ->assertDontSee('Hidden Relative');

    $this->actingAs($distributor->user)->get(route('family-card.show'))
        ->assertOk()
        ->assertSee($distributor->adn)
        ->assertSee('1 + 5 family members')
        ->assertSee('It is not an insurance policy')
        ->assertDontSee('Hidden Relative');
});

it('shows no card for a terminated ADN', function (): void {
    Feature::for(null)->activate(FamilyCardFeature::class);
    $distributor = familyCardDistributor('terminated');

    $this->actingAs($distributor->user)->get(route('family-card.show'))->assertRedirect(route('dashboard'));
});

it('adds a family member with consent, encrypts the name and audits without it', function (): void {
    Feature::for(null)->activate(FamilyCardFeature::class);
    $distributor = familyCardDistributor();

    addFamilyMember($distributor)->assertRedirect(route('profile.show').'#family-members');

    $member = DistributorFamilyMember::sole();
    expect($member->full_name)->toBe('Lakshmi Devi')
        ->and($member->relation)->toBe('parent')
        ->and($member->is_minor)->toBeFalse()
        ->and($member->guardian_consent_at)->toBeNull()
        ->and($member->consent_version)->toBe(DistributorFamilyMember::CONSENT_VERSION)
        ->and($member->consent_text_hash)->toBe(DistributorFamilyMember::consentTextHash(false))
        ->and($member->consented_by_user_id)->toBe($distributor->user_id);

    $raw = DB::table('distributor_family_members')->where('id', $member->id)->value('full_name');
    expect($raw)->not->toContain('Lakshmi');

    $audit = AuditLog::where('action', 'profile.family_member.added')->sole();
    expect(json_encode($audit->details))->not->toContain('Lakshmi')
        ->and($audit->details['relation'])->toBe('parent');

    $this->actingAs($distributor->user)->get(route('profile.show'))->assertOk()->assertSee('Lakshmi Devi')->assertSee('1 of 5 added');
});

it('refuses a family member without the consent declaration', function (): void {
    Feature::for(null)->activate(FamilyCardFeature::class);
    $distributor = familyCardDistributor();

    addFamilyMember($distributor, ['consent' => null])->assertSessionHasErrors('consent');
    addFamilyMember($distributor, ['relation' => 'neighbour'])->assertSessionHasErrors('relation');
    expect(DistributorFamilyMember::count())->toBe(0);
});

it('needs parent/guardian consent and the turns-18 year for a child', function (): void {
    Feature::for(null)->activate(FamilyCardFeature::class);
    $distributor = familyCardDistributor();
    $year = (int) now('Asia/Kolkata')->year;

    addFamilyMember($distributor, ['relation' => 'child', 'is_minor' => '1', 'turns_18_year' => $year + 5])
        ->assertSessionHasErrors('guardian_consent');
    addFamilyMember($distributor, ['relation' => 'child', 'is_minor' => '1', 'guardian_consent' => '1'])
        ->assertSessionHasErrors('turns_18_year');
    expect(DistributorFamilyMember::count())->toBe(0);

    addFamilyMember($distributor, ['relation' => 'child', 'is_minor' => '1', 'turns_18_year' => $year + 5, 'guardian_consent' => '1'])
        ->assertSessionHasNoErrors();

    $child = DistributorFamilyMember::sole();
    expect($child->is_minor)->toBeTrue()
        ->and($child->turns_18_year)->toBe($year + 5)
        ->and($child->guardian_consent_at)->not->toBeNull()
        ->and($child->consent_text_hash)->toBe(DistributorFamilyMember::consentTextHash(true));
});

it('refuses a child until KYC is approved', function (): void {
    Feature::for(null)->activate(FamilyCardFeature::class);
    $distributor = familyCardDistributor('pending');

    addFamilyMember($distributor, ['relation' => 'child', 'is_minor' => '1', 'turns_18_year' => (int) now()->year + 3, 'guardian_consent' => '1'])
        ->assertSessionHasErrors('is_minor');
    expect(DistributorFamilyMember::count())->toBe(0);
});

it('refuses a sixth family member', function (): void {
    Feature::for(null)->activate(FamilyCardFeature::class);
    $distributor = familyCardDistributor();
    foreach (range(1, 5) as $i) {
        seedFamilyMember($distributor);
    }

    addFamilyMember($distributor)->assertSessionHasErrors('full_name');
    expect(DistributorFamilyMember::count())->toBe(5);
});

it('lets the distributor remove their own member — even with the flag off — but not another distributor\'s', function (): void {
    Feature::for(null)->deactivate(FamilyCardFeature::class);
    $owner = familyCardDistributor();
    $other = familyCardDistributor();
    $mine = seedFamilyMember($owner);
    $theirs = seedFamilyMember($other);

    $this->actingAs($owner->user)->get(route('profile.show'))->assertOk()->assertSee('Seeded Member')->assertDontSee('Add family member');

    $this->actingAs($owner->user)->withoutMiddleware(PreventRequestForgery::class)
        ->delete(route('profile.family-members.destroy', $theirs->id))
        ->assertNotFound();
    expect(DistributorFamilyMember::whereKey($theirs->id)->exists())->toBeTrue();

    $this->actingAs($owner->user)->withoutMiddleware(PreventRequestForgery::class)
        ->delete(route('profile.family-members.destroy', $mine->id))
        ->assertRedirect();
    expect(DistributorFamilyMember::whereKey($mine->id)->exists())->toBeFalse()
        ->and(AuditLog::where('action', 'profile.family_member.removed')->count())->toBe(1);
});

it('erases family members on termination and on cooling-off cancellation', function (): void {
    $terminated = familyCardDistributor();
    seedFamilyMember($terminated);
    DistributorTerminated::dispatch($terminated->id, null, 'test', now());
    expect(DistributorFamilyMember::where('distributor_id', $terminated->id)->count())->toBe(0);

    $cancelled = familyCardDistributor();
    seedFamilyMember($cancelled);
    app(CancelCoolingOff::class)($cancelled->id, $cancelled->user_id);
    expect(DistributorFamilyMember::where('distributor_id', $cancelled->id)->count())->toBe(0);
});

it('erases a child after the year they turn 18, and leaves the rest', function (): void {
    $distributor = familyCardDistributor();
    $year = (int) now('Asia/Kolkata')->year;
    $adult = seedFamilyMember($distributor);
    $grownUp = seedFamilyMember($distributor, ['is_minor' => true, 'turns_18_year' => $year - 1, 'guardian_consent_at' => now()]);
    $stillChild = seedFamilyMember($distributor, ['is_minor' => true, 'turns_18_year' => $year, 'guardian_consent_at' => now()]);

    $this->artisan('family:expire-minor-consents')->assertSuccessful();

    expect(DistributorFamilyMember::pluck('id')->all())->toEqualCanonicalizing([$adult->id, $stillChild->id])
        ->and(AuditLog::where('action', 'family_member.minor_consent_expired')->count())->toBe(1);
});

function familyCardStaff(string $role): User
{
    test()->seed(RolesAndPermissionsSeeder::class);
    $staff = User::create([
        'full_name' => 'Staff '.$role,
        'email' => $role.'-'.uniqid().'@test.com',
        'phone_e164' => '+91'.str_pad((string) random_int(7000000000, 9999999999), 10, '0'),
        'password_hash' => bcrypt('x'),
        'status' => 'active',
    ]);
    $staff->assignRole($role);

    return $staff;
}

it('shows family members on the admin distributor page only to admin-compliance, who can erase one (audited, no name)', function (): void {
    $distributor = familyCardDistributor();
    $member = seedFamilyMember($distributor, ['full_name' => 'Ravi Kumar']);

    $finance = familyCardStaff('admin-finance');
    $this->actingAs($finance)->get(route('admin.distributors.show', $distributor->id))
        ->assertOk()->assertDontSee('Family Card members')->assertDontSee('Ravi Kumar');
    $this->actingAs($finance)->withoutMiddleware(PreventRequestForgery::class)
        ->delete(route('admin.distributors.family-members.erase', [$distributor->id, $member->id]))
        ->assertForbidden();
    expect(DistributorFamilyMember::whereKey($member->id)->exists())->toBeTrue();

    $compliance = familyCardStaff('admin-compliance');
    $other = familyCardDistributor();
    $this->actingAs($compliance)->withoutMiddleware(PreventRequestForgery::class)
        ->delete(route('admin.distributors.family-members.erase', [$other->id, $member->id]))
        ->assertNotFound();
    expect(DistributorFamilyMember::whereKey($member->id)->exists())->toBeTrue();

    $this->actingAs($compliance)->get(route('admin.distributors.show', $distributor->id))
        ->assertOk()->assertSee('Family Card members')->assertSee('Ravi Kumar');
    $this->actingAs($compliance)->withoutMiddleware(PreventRequestForgery::class)
        ->delete(route('admin.distributors.family-members.erase', [$distributor->id, $member->id]))
        ->assertRedirect();

    expect(DistributorFamilyMember::whereKey($member->id)->exists())->toBeFalse();
    $audit = AuditLog::where('action', 'admin.family_member.erased')->sole();
    expect($audit->actor_id)->toBe($compliance->id)
        ->and($audit->before_hash)->not->toBeNull()
        ->and($audit->after_hash)->toBeNull()
        ->and(json_encode($audit->details))->not->toContain('Ravi');
});
