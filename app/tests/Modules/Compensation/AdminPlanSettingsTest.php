<?php

declare(strict_types=1);

use App\Modules\Compensation\Events\CompensationPlanChanged;
use App\Modules\Compliance\Models\AuditLog;
use App\Modules\Identity\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
    app(PermissionRegistrar::class)->forgetCachedPermissions();
});

function planAdmin(string $role): User
{
    $user = User::create([
        'full_name' => 'Plan '.$role,
        'email' => 'plan-'.uniqid().'@test.com',
        'phone_e164' => '+9180000'.rand(10000, 99999),
        'password_hash' => bcrypt('x'),
        'password_set_at' => now(),
        'status' => 'active',
        'email_verified_at' => now(),
    ]);
    $user->assignRole($role);

    return $user;
}

it('renders the plan-settings page for an admin with the four editors', function () {
    $res = $this->actingAs(planAdmin('admin'))
        ->get(route('admin.compensation.plan-settings.index'))
        ->assertOk();

    // Tabs for every editor are always present (only the active tab's section
    // body renders — the page defaults to the GSB tab).
    $res->assertSee('GSB Slabs')
        ->assertSee('Rank Tiers')
        ->assertSee('Fortune Bonus')
        ->assertSee('GSB slabs')          // active GSB section heading
        ->assertSee('Score value (₹)');   // per-slab score value field (KP 2026-07-21)

    // The rank/fortune tab sections render when their tab is selected.
    $this->actingAs(planAdmin('admin'))
        ->get(route('admin.compensation.plan-settings.index', ['tab' => 'ranks']))
        ->assertOk()
        ->assertSee('Rank tiers');
    $this->actingAs(planAdmin('admin'))
        ->get(route('admin.compensation.plan-settings.index', ['tab' => 'fortune']))
        ->assertOk()
        ->assertSee('Fortune Bonus — matrix levels')
        ->assertSee('Fortune Bonus — eligibility tiers');
});

it('persists a GSB slab edit, writes an audit log, and dispatches the domain event', function () {
    Event::fake([CompensationPlanChanged::class]);

    $this->actingAs(planAdmin('developer'))
        ->withoutMiddleware(PreventRequestForgery::class)
        ->post(route('admin.compensation.plan-settings.gsb-slab.update', 2), [
            'title' => 'Dealer',
            'title_min_bv_paise' => 500_000,
            'matched_bv_paise' => 3_600_000,
            'score' => 12,                 // 12 × ₹250 = ₹3,000
            'score_value_paise' => 25_000, // ₹250 per score point
            'msb_score' => 20,             // Mentorship points for this slab
            'agp_per_occurrence' => 5,
            'carry_forward_lifetime' => 0,
            'is_active' => 1,
        ])
        ->assertRedirect(route('admin.compensation.plan-settings.index'));

    // Bonus recomputed from score × per-slab score value (₹250 → 25,000 paise).
    $row = DB::table('gsb_slabs')->where('slab', 2)->first();
    expect((int) $row->score)->toBe(12);
    expect((int) $row->score_value_paise)->toBe(25_000);
    expect((int) $row->bonus_paise)->toBe(12 * 25_000);
    expect((int) $row->msb_score)->toBe(20);

    expect(AuditLog::where('action', 'compensation.plan.gsb_slab.updated')->exists())->toBeTrue();
    Event::assertDispatched(CompensationPlanChanged::class, fn ($e) => $e->area === 'gsb_slab' && $e->key === '2');
});

it('hides the score inputs for pro-rated slabs 3–7 and shows the pool note', function () {
    $res = $this->actingAs(planAdmin('developer'))
        ->get(route('admin.compensation.plan-settings.index'))
        ->assertOk();

    // Slabs 3–7 render read-only score displays instead of inputs (KP 2026-07-29).
    $res->assertSee('Variable (pool)');
    expect(substr_count($res->getContent(), 'name="score_value_paise"'))->toBe(2); // slabs 1–2 only
});

it('no longer offers an MSB score value field and explains how both pools price', function () {
    $res = $this->actingAs(planAdmin('developer'))
        ->get(route('admin.compensation.plan-settings.index'))
        ->assertOk();

    // The per-slab MSB value was removed with KP's 2026-07-30 pool engine.
    $res->assertDontSee('name="msb_score_value_paise"', false);
    $res->assertSee('name="msb_score"', false);

    // …and the page explains what replaced it.
    $res->assertSee('How GSB and MSB are calculated');
    $res->assertSee("the day's total MSB points", false);
});

it('edits the fortune matrix ladder in points per member, not rupees', function () {
    // The fixed per-level rupee bonus (bonus_paise) died with KP's 2026-08-07
    // pool + points rework: a level is now worth N points per downline member,
    // and rupees are derived monthly from the pool.
    $res = $this->actingAs(planAdmin('developer'))
        ->get(route('admin.compensation.plan-settings.index', ['tab' => 'fortune']))
        ->assertOk()
        ->assertSee('Points per member (depth 1)')
        ->assertSee('name="points_per_member"', false)
        ->assertSee('name="payout_mode"', false)
        ->assertSee('name="cap_paise"', false);

    $res->assertDontSee('name="bonus_paise"', false);
});

it('persists a fortune level edit — points, payout mode and cap — with audit log and domain event', function () {
    Event::fake([CompensationPlanChanged::class]);

    $this->actingAs(planAdmin('developer'))
        ->withoutMiddleware(PreventRequestForgery::class)
        ->post(route('admin.compensation.plan-settings.fortune-level.update', 3), [
            'points_per_member' => 11,
            'payout_mode' => 'capped',
            'cap_paise' => 2_500_000,
            'is_active' => 1,
        ])
        ->assertRedirect(route('admin.compensation.plan-settings.index'));

    $row = DB::table('fortune_bonus_levels')->where('level', 3)->first();
    expect((int) $row->points_per_member)->toBe(11)
        ->and((string) $row->payout_mode)->toBe('capped')
        ->and((int) $row->cap_paise)->toBe(2_500_000);

    expect(AuditLog::where('action', 'compensation.plan.fortune_level.updated')->exists())->toBeTrue();
    Event::assertDispatched(CompensationPlanChanged::class, fn ($e) => $e->area === 'fortune_level' && $e->key === '3');
});

it('rejects a fortune level edit without points per member', function () {
    $before = (int) DB::table('fortune_bonus_levels')->where('level', 2)->value('points_per_member');

    $this->actingAs(planAdmin('developer'))
        ->withoutMiddleware(PreventRequestForgery::class)
        ->post(route('admin.compensation.plan-settings.fortune-level.update', 2), [
            'bonus_paise' => 5_100, // the dropped column — not a substitute
            'payout_mode' => 'capped',
            'cap_paise' => 3_000_000,
            'is_active' => 1,
        ])
        ->assertSessionHasErrors('points_per_member');

    expect((int) DB::table('fortune_bonus_levels')->where('level', 2)->value('points_per_member'))->toBe($before);
});

it('rejects capped mode without a cap, and a cap below the minimum commission', function () {
    $this->actingAs(planAdmin('developer'))
        ->withoutMiddleware(PreventRequestForgery::class)
        ->post(route('admin.compensation.plan-settings.fortune-level.update', 2), [
            'points_per_member' => 8,
            'payout_mode' => 'capped',
            'is_active' => 1,
        ])
        ->assertSessionHasErrors('cap_paise');

    // The cap includes the ₹30 minimum, so ₹20 is invalid.
    $this->actingAs(planAdmin('developer'))
        ->withoutMiddleware(PreventRequestForgery::class)
        ->post(route('admin.compensation.plan-settings.fortune-level.update', 2), [
            'points_per_member' => 8,
            'payout_mode' => 'capped',
            'cap_paise' => 2000,
            'is_active' => 1,
        ])
        ->assertSessionHasErrors('cap_paise');
});

it('nulls the cap when a level switches to residual mode', function () {
    $this->actingAs(planAdmin('developer'))
        ->withoutMiddleware(PreventRequestForgery::class)
        ->post(route('admin.compensation.plan-settings.fortune-level.update', 6), [
            'points_per_member' => 4,
            'payout_mode' => 'residual',
            'cap_paise' => 500_000, // sent but ignored for residual
            'is_active' => 1,
        ])
        ->assertRedirect(route('admin.compensation.plan-settings.index'));

    $row = DB::table('fortune_bonus_levels')->where('level', 6)->first();
    expect((string) $row->payout_mode)->toBe('residual')
        ->and($row->cap_paise)->toBeNull();
});

it('rejects a crafted POST that tries to reinstate the MSB score value', function () {
    $before = DB::table('gsb_slabs')->where('slab', 1)->first();

    $this->actingAs(planAdmin('developer'))
        ->withoutMiddleware(PreventRequestForgery::class)
        ->post(route('admin.compensation.plan-settings.gsb-slab.update', 1), [
            'title' => (string) $before->title,
            'title_min_bv_paise' => (int) $before->title_min_bv_paise,
            'matched_bv_paise' => (int) $before->matched_bv_paise,
            'score' => (int) $before->score,
            'score_value_paise' => (int) $before->score_value_paise,
            'msb_score' => (int) $before->msb_score,
            'msb_score_value_paise' => 99_999,  // column is gone — must not be written
            'agp_per_occurrence' => (int) $before->agp_per_occurrence,
            'carry_forward_lifetime' => 1,
            'is_active' => 1,
        ])
        ->assertRedirect(route('admin.compensation.plan-settings.index'));

    $columns = (array) DB::table('gsb_slabs')->where('slab', 1)->first();
    expect($columns)->not->toHaveKey('msb_score_value_paise');
});

it('ignores score and score value on a crafted POST for a pro-rated slab', function () {
    $before = DB::table('gsb_slabs')->where('slab', 3)->first();

    $this->actingAs(planAdmin('developer'))
        ->withoutMiddleware(PreventRequestForgery::class)
        ->post(route('admin.compensation.plan-settings.gsb-slab.update', 3), [
            'title' => 'Wholesaler',
            'title_min_bv_paise' => (int) $before->title_min_bv_paise,
            'matched_bv_paise' => (int) $before->matched_bv_paise,
            'score' => 999,                 // must be ignored server-side
            'score_value_paise' => 99_999,  // must be ignored server-side
            'msb_score' => (int) $before->msb_score,
            'agp_per_occurrence' => (int) $before->agp_per_occurrence,
            'carry_forward_lifetime' => 0,
            'is_active' => 1,
        ])
        ->assertRedirect(route('admin.compensation.plan-settings.index'));

    $after = DB::table('gsb_slabs')->where('slab', 3)->first();
    expect((int) $after->score)->toBe((int) $before->score);
    expect((int) $after->score_value_paise)->toBe((int) $before->score_value_paise);
    expect((int) $after->bonus_paise)->toBe((int) $before->bonus_paise); // nominal ceiling preserved
});

it('forbids every business admin role from editing the plan', function (string $role) {
    // Editing the plan changes what distributors are paid, so it belongs to
    // platform configuration — including the full `admin` super-role, whose
    // Gate::before bypass deliberately cannot open a `role:` gate.
    $this->actingAs(planAdmin($role))
        ->withoutMiddleware(PreventRequestForgery::class)
        ->post(route('admin.compensation.plan-settings.fortune-level.update', 0), [
            'points_per_member' => 999,
            'is_active' => 1,
        ])
        ->assertForbidden();
})->with(['admin', 'admin-compliance', 'admin-finance', 'admin-operations']);

it('lets an admin view the plan for monitoring but renders no editable form', function () {
    $res = $this->actingAs(planAdmin('admin'))
        ->get(route('admin.compensation.plan-settings.index'))
        ->assertOk()
        ->assertSee('GSB slabs');

    // Values are visible (monitoring) but every field sits inside a disabled
    // fieldset and no Save control is rendered on any plan form.
    expect($res->getContent())->toContain('<fieldset class="contents" disabled')
        ->and($res->getContent())->not->toContain('hover:bg-brand-700">Save<');

    // Editing instructions must not be shown to someone who cannot edit.
    $res->assertDontSee('press <strong>Edit</strong>', false);
});

it('updates a comp.* scalar via the generic settings endpoint', function () {
    $this->actingAs(planAdmin('developer'))
        ->withoutMiddleware(PreventRequestForgery::class)
        ->post(route('admin.settings.update', 'comp.tds.rate_bp'), ['value' => '600'])
        ->assertRedirect();

    expect(DB::table('settings')->where('key', 'comp.tds.rate_bp')->value('value'))->toBe('600');
    expect(AuditLog::where('action', 'admin.settings.changed')->exists())->toBeTrue();
});
