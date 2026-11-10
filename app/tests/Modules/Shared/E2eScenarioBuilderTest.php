<?php

declare(strict_types=1);

use App\Console\Actions\SeedReservedTreeAction;
use App\Modules\Genealogy\Support\ReservedAdns;
use App\Modules\Identity\Models\User;
use App\Modules\Shared\Crypto\PiiCrypter;
use App\Modules\Shared\Testing\Scenarios\E2eScenarioBuilder;
use App\Modules\Shared\Testing\Scenarios\E2eScenarios;
use Database\Seeders\ContentPageSeeder;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;

/**
 * The e2e worlds are built through the real signup and checkout code, so the
 * browser suite exercises what a partner actually goes through. These pin
 * that: registration side effects, a real paid order, and the scenario clock.
 */
uses(RefreshDatabase::class);

beforeEach(function (): void {
    // The e2e base: the standard seed, the published plan page registration
    // records consent against, and the reserved company block.
    $this->seed(DatabaseSeeder::class);
    $this->artisan('content:publish', ['slug' => ContentPageSeeder::HELD_SLUGS]);
    app(SeedReservedTreeAction::class)->buildFresh();
    Storage::fake('kyc');
    Mail::fake();
    Notification::fake();
});

function gsbBasicWorld(): array
{
    Carbon::setTestNow('2026-09-15 14:30:00');
    $builder = new E2eScenarioBuilder;
    E2eScenarios::get('gsb-basic')($builder);

    return $builder->result();
}

it('registers each partner through finalise and KYC approval, under the company block', function (): void {
    $world = gsbBasicWorld();
    ['root' => $root, 'left' => $left, 'right' => $right] = $world['partners'];
    $companyLeaf = (int) DB::table('distributors')->where('id', $root['id'])->value('placement_parent_id');

    expect(DB::table('distributors')->whereIn('id', [$left['id'], $right['id']])->orderBy('id')->pluck('placement_side')->all())->toBe(['L', 'R'])
        ->and(DB::table('distributors')->where('id', $left['id'])->value('placement_parent_id'))->toBe($root['id'])
        ->and(DB::table('distributors')->where('id', $companyLeaf)->value('adn'))->toBeIn(ReservedAdns::all())
        ->and(DB::table('genealogy_closure')->where('ancestor_id', $root['id'])->orderBy('descendant_id')->pluck('depth')->all())->toBe([0, 1, 1])
        ->and(DB::table('cooling_off_events')->where('distributor_id', $left['id'])->count())->toBe(1)
        ->and(DB::table('consents')->where('distributor_id', $left['id'])->count())->toBeGreaterThan(0)
        ->and(DB::table('users')->where('id', $left['user_id'])->value('status'))->toBe('active')
        // The scenario instant, not the ambient clock (2026-09-15).
        ->and((string) DB::table('distributors')->where('id', $root['id'])->value('effective_date'))->toStartWith('2026-06-01 09:00:00')
        ->and((string) DB::table('distributors')->where('id', $left['id'])->value('cooling_off_end_at'))->toStartWith('2026-07-01 09:10:00')
        ->and(Hash::check(E2eScenarioBuilder::PASSWORD, (string) DB::table('users')->where('id', $root['user_id'])->value('password_hash')))->toBeTrue()
        ->and(Carbon::now()->toDateTimeString())->toBe('2026-09-15 14:30:00');
});

it('places each order through checkout and pays it through the stub gateway at the scenario instant', function (): void {
    $order = gsbBasicWorld()['orders']['left-buy'];
    $variantBv = (int) DB::table('product_variants')->where('variant_sku', 'AV-SH-001-V1')->value('bv_paise');

    expect($order['bv_paise'])->toBe($variantBv * 19)
        ->and(DB::table('orders')->where('id', $order['id'])->value('status'))->toBe('paid')
        ->and((string) DB::table('orders')->where('id', $order['id'])->value('paid_at'))->toStartWith('2026-06-03 11:00:00')
        ->and(DB::table('payment_intents')->where('order_id', $order['id'])->value('status'))->toBe('captured')
        ->and((int) DB::table('bv_ledger_entries')->where('order_id', $order['id'])->sum('bv_paise'))->toBe($order['bv_paise'])
        ->and((string) DB::table('invoices')->where('order_id', $order['id'])->value('issued_at'))->toStartWith('2026-06-03 11:00:00');
});

it('refuses a partner placed without a side, or a duplicate key', function (): void {
    $builder = (new E2eScenarioBuilder)->partner('root', joinedAt: '2026-06-01 09:00:00');

    expect(fn () => $builder->partner('child', 'root'))->toThrow(InvalidArgumentException::class, 'needs both a parent and a side')
        ->and(fn () => $builder->partner('root'))->toThrow(InvalidArgumentException::class, 'already exists');
});

it('gives every partner a bank account the payout batch can decrypt, and staff their one role', function (): void {
    $world = gsbBasicWorld();
    $ciphertext = (string) DB::table('distributors')->where('id', $world['partners']['right']['id'])->value('bank_account_enc');
    $finance = User::query()->findOrFail($world['staff']['finance']['user_id']);

    expect(PiiCrypter::decryptString($ciphertext))->toBe('500000000003')
        ->and($finance->getRoleNames()->all())->toBe(['admin-finance'])
        ->and(DB::table('distributors')->where('user_id', $finance->id)->exists())->toBeFalse();
});
