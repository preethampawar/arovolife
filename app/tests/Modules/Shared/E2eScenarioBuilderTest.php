<?php

declare(strict_types=1);

use App\Modules\Identity\Models\User;
use App\Modules\Shared\Crypto\PiiCrypter;
use App\Modules\Shared\Testing\Scenarios\E2eScenarioBuilder;
use App\Modules\Shared\Testing\Scenarios\E2eScenarios;
use Database\Seeders\ProductCatalogSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * The e2e money fixtures are hand-worked from what this builder writes, so its
 * Genos and its orders must be exactly what the scenario says — and never
 * stamped with the ambient clock.
 */
uses(RefreshDatabase::class);

beforeEach(function (): void {
    // gsb-basic adds one login per back-office role.
    $this->seed([ProductCatalogSeeder::class, RolesAndPermissionsSeeder::class]);
});

it('places the gsb-basic partners in the Genos with a closure row per ancestor', function (): void {
    Carbon::setTestNow('2026-09-15 14:30:00');
    $builder = new E2eScenarioBuilder;
    E2eScenarios::get('gsb-basic')($builder);
    $world = $builder->result();
    ['root' => $root, 'left' => $left, 'right' => $right] = $world['partners'];

    expect(DB::table('distributors')->whereIn('id', [$left['id'], $right['id']])->orderBy('id')->pluck('placement_side')->all())->toBe(['L', 'R'])
        ->and(DB::table('distributors')->where('id', $left['id'])->value('placement_parent_id'))->toBe($root['id'])
        ->and(DB::table('genealogy_closure')->where('ancestor_id', $root['id'])->orderBy('descendant_id')->pluck('depth')->all())->toBe([0, 1, 1])
        // The root's self-reference update must not take the ambient clock (ON UPDATE CURRENT_TIMESTAMP).
        ->and((string) DB::table('distributors')->where('id', $root['id'])->value('updated_at'))->toStartWith('2026-06-01 09:00:00')
        ->and(Hash::check(E2eScenarioBuilder::PASSWORD, (string) DB::table('users')->where('id', $root['user_id'])->value('password_hash')))->toBeTrue();
});

it('writes each order as paid with its BV ledger entry at the scenario instant', function (): void {
    $builder = new E2eScenarioBuilder;
    E2eScenarios::get('gsb-basic')($builder);
    $order = $builder->result()['orders']['left-buy'];
    $variantBv = (int) DB::table('product_variants')->where('variant_sku', 'AV-SH-001-V1')->value('bv_paise');

    expect($order['bv_paise'])->toBe($variantBv * 19)
        ->and(DB::table('orders')->where('id', $order['id'])->value('status'))->toBe('paid')
        ->and((string) DB::table('orders')->where('id', $order['id'])->value('paid_at'))->toStartWith('2026-06-03 11:00:00')
        ->and((int) DB::table('bv_ledger_entries')->where('order_id', $order['id'])->value('bv_paise'))->toBe($order['bv_paise']);
});

it('refuses a partner placed without a side, or a duplicate key', function (): void {
    $builder = (new E2eScenarioBuilder)->partner('root', joinedAt: '2026-06-01 09:00:00');

    expect(fn () => $builder->partner('child', 'root'))->toThrow(InvalidArgumentException::class, 'needs both a parent and a side')
        ->and(fn () => $builder->partner('root'))->toThrow(InvalidArgumentException::class, 'already exists');
});

it('gives every partner a bank account the payout batch can decrypt, and staff their one role', function (): void {
    $builder = new E2eScenarioBuilder;
    E2eScenarios::get('gsb-basic')($builder);
    $world = $builder->result();
    $ciphertext = (string) DB::table('distributors')->where('id', $world['partners']['right']['id'])->value('bank_account_enc');
    $finance = User::query()->findOrFail($world['staff']['finance']['user_id']);

    expect(PiiCrypter::decryptString($ciphertext))->toBe('500000000003')
        ->and($finance->getRoleNames()->all())->toBe(['admin-finance'])
        ->and(DB::table('distributors')->where('user_id', $finance->id)->exists())->toBeFalse();
});
