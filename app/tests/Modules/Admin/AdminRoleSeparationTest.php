<?php

declare(strict_types=1);

use App\Modules\Grievance\Enums\EscalationLevel;
use App\Modules\Grievance\Enums\TicketCategory;
use App\Modules\Grievance\Enums\TicketChannel;
use App\Modules\Grievance\Enums\TicketStatus;
use App\Modules\Grievance\Models\Ticket;
use App\Modules\Identity\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

/**
 * R-17 separation of duties: `admin` is a super-admin (Gate::before bypass);
 * the scoped roles carry only their own permission — admin-finance can't freeze,
 * admin-compliance can't record payments, admin-operations can do neither.
 */
beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
    app(PermissionRegistrar::class)->forgetCachedPermissions();
});

function arsUser(string $role): User
{
    $user = User::create([
        'full_name' => 'Role '.$role,
        'email' => 'ars-'.uniqid().'@test.com',
        'phone_e164' => '+9180000'.rand(10000, 99999),
        'password_hash' => bcrypt('x'),
        'password_set_at' => now(),
        'status' => 'active',
        'email_verified_at' => now(),
    ]);
    $user->assignRole($role);

    return $user;
}

it('R17-perm: each scoped role holds only its own permission', function (): void {
    $ops = arsUser('admin-operations');
    $fin = arsUser('admin-finance');
    $comp = arsUser('admin-compliance');
    $super = arsUser('admin');

    expect($ops->can('placement.decide'))->toBeTrue();
    expect($ops->can('finance.record'))->toBeFalse();
    expect($ops->can('compliance.discipline'))->toBeFalse();

    expect($fin->can('finance.record'))->toBeTrue();
    expect($fin->can('compliance.discipline'))->toBeFalse();
    expect($fin->can('placement.decide'))->toBeFalse();

    expect($comp->can('compliance.discipline'))->toBeTrue();
    expect($comp->can('finance.record'))->toBeFalse();
    expect($comp->can('placement.decide'))->toBeFalse();

    // Super-admin bypasses everything via Gate::before.
    expect($super->can('placement.decide'))->toBeTrue();
    expect($super->can('finance.record'))->toBeTrue();
    expect($super->can('compliance.discipline'))->toBeTrue();
});

it('R17-http: admin-finance is forbidden from the block (compliance) action', function (): void {
    $this->actingAs(arsUser('admin-finance'))
        ->withoutMiddleware(PreventRequestForgery::class)
        ->post(route('admin.distributors.freeze', 1), ['reason' => 'x'])
        ->assertForbidden();
});

it('R17-http: admin-operations is forbidden from the block action', function (): void {
    $this->actingAs(arsUser('admin-operations'))
        ->withoutMiddleware(PreventRequestForgery::class)
        ->post(route('admin.distributors.freeze', 1), ['reason' => 'x'])
        ->assertForbidden();
});

it('R17-http: admin-compliance passes the block gate (not forbidden)', function (): void {
    // 999999 doesn't exist → the controller 404/redirects, but crucially it is
    // NOT a 403: the permission gate let admin-compliance through.
    $status = $this->actingAs(arsUser('admin-compliance'))
        ->withoutMiddleware(PreventRequestForgery::class)
        ->post(route('admin.distributors.freeze', 999999), ['reason' => 'x'])
        ->status();

    expect($status)->not->toBe(403);
});

it('R17-http: admin-finance is forbidden from activate/deactivate (same discipline family as block)', function (): void {
    $user = arsUser('admin-finance');

    $this->actingAs($user)
        ->withoutMiddleware(PreventRequestForgery::class)
        ->post(route('admin.distributors.activate', 1))
        ->assertForbidden();

    $this->actingAs($user)
        ->withoutMiddleware(PreventRequestForgery::class)
        ->post(route('admin.distributors.deactivate', 1))
        ->assertForbidden();
});

it('R17-http: admin-operations is forbidden from activate/deactivate', function (): void {
    $user = arsUser('admin-operations');

    $this->actingAs($user)
        ->withoutMiddleware(PreventRequestForgery::class)
        ->post(route('admin.distributors.activate', 1))
        ->assertForbidden();

    $this->actingAs($user)
        ->withoutMiddleware(PreventRequestForgery::class)
        ->post(route('admin.distributors.deactivate', 1))
        ->assertForbidden();
});

it('R17-http: admin-compliance passes the activate/deactivate gate (not forbidden)', function (): void {
    $user = arsUser('admin-compliance');

    $activate = $this->actingAs($user)
        ->withoutMiddleware(PreventRequestForgery::class)
        ->post(route('admin.distributors.activate', 999999))
        ->status();

    $deactivate = $this->actingAs($user)
        ->withoutMiddleware(PreventRequestForgery::class)
        ->post(route('admin.distributors.deactivate', 999999))
        ->status();

    expect($activate)->not->toBe(403);
    expect($deactivate)->not->toBe(403);
});

it('R17-http: a non-admin-family user cannot reach the admin area at all', function (): void {
    $user = User::create([
        'full_name' => 'Plain', 'email' => 'ars-plain-'.uniqid().'@test.com',
        'phone_e164' => '+9180000'.rand(10000, 99999), 'password_hash' => bcrypt('x'),
        'password_set_at' => now(), 'status' => 'active', 'email_verified_at' => now(),
    ]);

    $this->actingAs($user)->get(route('admin.dashboard'))->assertForbidden();
});

/**
 * R-100 — the sidebar Grievances badge must be filtered on the same rule as the
 * queue it links to.
 *
 * The queue's own "Open (N)" tab already hides ethics, conduct and privacy
 * tickets from anyone without `compliance.discipline`
 * (`AdminGrievanceController::applyVisibility()`). The badge did not, so an
 * operations officer could read the sensitive count straight off the
 * difference between the two numbers — the exact disclosure the filtering
 * exists to prevent, recovered by subtraction.
 */
function arsTicket(string $category): void
{
    $n = random_int(100000, 999999);

    Ticket::create([
        'ticket_no' => "GR-ARS-{$n}",
        'subject' => 'Test grievance',
        'body' => 'Body',
        'category' => $category,
        'severity' => 'medium',
        'status' => TicketStatus::Open,
        'channel' => TicketChannel::Web,
        'reporter_name' => 'Reporter',
        'is_anonymous' => false,
        'escalation_level' => EscalationLevel::CustomerCare,
        'third_party_dependent' => false,
        'sla_first_response_at' => now()->addDays(5),
        'sla_resolution_at' => now()->addDays(30),
    ]);
}

/**
 * The number in the badge on the Grievances nav item: 0 when the item is
 * rendered without one, null when there is no item at all.
 *
 * Cut to the item's own closing `</a>` rather than a fixed window. A fixed
 * window is what made the first version of this helper return null: between
 * the href and the badge sit a long class attribute, the active rail and an
 * inline lucide SVG, which together run well past any round number you would
 * think to guess. Stopping at `</a>` also keeps it from reading the next nav
 * item's badge, which is the failure that would have mattered.
 */
function arsGrievanceBadge(string $body): ?int
{
    if (preg_match('~admin/grievances".*?</a>~s', $body, $item) !== 1) {
        return null;
    }

    return preg_match('/admin-nav-badge[^>]*>\s*([0-9,]+)\s*</', $item[0], $m) === 1
        ? (int) str_replace(',', '', $m[1])
        : 0;
}

it('R17-grievance-badge: operations reads a filtered count, so the sensitive total cannot be derived', function (): void {
    arsTicket(TicketCategory::Refund->value);

    // Five sensitive ones, so the two totals are far enough apart that a
    // coincidence cannot make this pass.
    foreach (TicketCategory::sensitiveValues() as $category) {
        arsTicket($category);
    }

    $sensitiveCount = count(TicketCategory::sensitiveValues());

    $ops = arsUser('admin-operations');
    $comp = arsUser('admin-compliance');

    $opsBody = $this->actingAs($ops)->get(route('admin.dashboard'))->assertOk()->getContent();
    $compBody = $this->actingAs($comp)->get(route('admin.dashboard'))->assertOk()->getContent();

    // Operations holds `grievance.handle`, so the item is there — with only the
    // one non-sensitive ticket counted.
    expect(arsGrievanceBadge($opsBody))->toBe(1)
        ->and(arsGrievanceBadge($compBody))->toBe(1 + $sensitiveCount);

    // And the two viewers must not share a cache entry. A single global key
    // would serve whichever number was computed first to everyone, which is
    // worse than not caching: it would hand operations the compliance total.
    expect(Cache::get('admin.grievances.unsettled_count.general'))->toBe(1)
        ->and(Cache::get('admin.grievances.unsettled_count.all'))->toBe(1 + $sensitiveCount);
});
