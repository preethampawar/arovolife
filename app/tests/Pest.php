<?php

use App\Modules\Compensation\Models\PayoutBatch;
use App\Modules\Compensation\Models\PayoutLineItem;
use App\Modules\Identity\Models\Distributor;
use Database\Seeders\ContentPageSeeder;
use Database\Seeders\FortuneBonusLevelsSeeder;
use Database\Seeders\FortuneBonusTiersSeeder;
use Database\Seeders\GsbSlabsSeeder;
use Database\Seeders\LifetimeAwardRewardsSeeder;
use Database\Seeders\RankTiersSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind different classes or traits.
|
*/

pest()->extend(TestCase::class)
    ->in('Feature', 'Modules');

/*
|--------------------------------------------------------------------------
| Compensation plan config tables
|--------------------------------------------------------------------------
|
| The compensation engines read their slab/rank/fortune ladders from DB tables
| (gsb_slabs, rank_tiers, fortune_bonus_levels, fortune_bonus_tiers) via
| CompensationPlanSettingsService instead of hardcoded constants. RefreshDatabase
| starts each test with empty tables, so seed the KP-confirmed defaults before
| every Compensation test. Scalar settings (rates, caps) fall back to the
| service's built-in registry defaults, so only the tables need seeding here.
| Individual tests may override a seeded row to prove a value is read from config.
|
*/
pest()->beforeEach(function (): void {
    seedCompensationPlanTables();
})->in('Modules/Compensation');

function seedCompensationPlanTables(): void
{
    foreach ([
        GsbSlabsSeeder::class,
        RankTiersSeeder::class,
        FortuneBonusLevelsSeeder::class,
        FortuneBonusTiersSeeder::class,
        LifetimeAwardRewardsSeeder::class,
    ] as $seeder) {
        (new $seeder)->run();
    }
}

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

function something()
{
    // ..
}

/**
 * Disable foreign-key enforcement on the active test connection.
 *
 * Tests seed self-referential rows (root distributors whose sponsor_id /
 * placement_parent_id point at their own id), which is impossible while
 * the FK constraints are armed.
 *
 *  - On MySQL we flip the session-level `FOREIGN_KEY_CHECKS` switch.
 *  - On SQLite the `PRAGMA foreign_keys` knob is ignored inside an active
 *    transaction (and `RefreshDatabase` wraps every test in one), so we
 *    use `PRAGMA defer_foreign_keys = ON` instead. That flag defers FK
 *    validation until COMMIT, by which time the seed code has stamped
 *    sponsor_id / placement_parent_id back to the row's own id and the
 *    constraint is satisfied. The flag auto-resets at transaction end,
 *    so the matching `enableTestForeignKeys()` is a no-op on SQLite but
 *    still required on MySQL.
 */
function disableTestForeignKeys(): void
{
    $driver = DB::getDriverName();

    if ($driver === 'mysql') {
        DB::statement('SET FOREIGN_KEY_CHECKS=0');
    } elseif ($driver === 'sqlite') {
        DB::statement('PRAGMA defer_foreign_keys = ON');
    }
}

function enableTestForeignKeys(): void
{
    $driver = DB::getDriverName();

    if ($driver === 'mysql') {
        DB::statement('SET FOREIGN_KEY_CHECKS=1');
    }
    // SQLite: defer_foreign_keys auto-resets at the end of the txn.
}

/**
 * A distinct wallet-ledger reference for a test fixture.
 *
 * `uniq_wallet_ledger_source (type, reference_type, reference_id)` is the
 * engines' idempotency guard — a rerun cannot credit the same result row
 * twice. Fixtures standing in for engine output therefore need a fresh
 * reference per credit, exactly as a real engine would supply.
 */
function walletRef(): int
{
    static $sequence = 0;

    return ++$sequence;
}

/**
 * Publish the four legal documents a registration consents to.
 *
 * `ConsentDocuments` refuses to record a consent when the content page is not
 * published, deliberately — a fallback would let registration proceed while
 * writing a consent that points at nothing, which is the defect R-51 was
 * about. Registration therefore genuinely depends on the content seeder having
 * run, in tests exactly as in production.
 */
function seedConsentDocuments(): void
{
    // `$this->seed()` rather than instantiating the seeder: it wires the
    // console the seeder writes its summary to, which is null otherwise.
    test()->seed(ContentPageSeeder::class);

    // The seeder holds `compensation` unpublished (R-75, DSA §6.2) and one of
    // the four consents points at it, so a registration test has to do what a
    // deploy does: name the page it means to publish.
    publishHeldContentPages();
}

/**
 * Publish the policy pages `ContentPageSeeder` deliberately leaves in draft.
 *
 * The seeder holds `compensation` back so that no blanket path — `db:seed`,
 * `platform:reset`, this bootstrap — can publish an un-notified §6.2 plan
 * amendment as a side effect. A test that needs the published page does what
 * a deploy does: `php artisan content:publish compensation`.
 */
function publishHeldContentPages(): void
{
    (new ContentPageSeeder)->publish(ContentPageSeeder::HELD_SLUGS);
}

/*
|--------------------------------------------------------------------------
| Payout fixtures
|--------------------------------------------------------------------------
|
| Shared by the payout gateway, manual-settlement and bank-file tests. Both
| batches are approved: a batch nobody signed off is not a payment
| instruction, and none of the settlement paths act on one.
*/

function setGatewaySetting(string $key, string $value): void
{
    DB::table('settings')->updateOrInsert(
        ['key' => $key],
        ['value' => $value, 'version' => 1, 'updated_at' => now()],
    );
}

/**
 * A batch with one pending line item for a distributor with the given ADN.
 *
 * `$daysAgo` moves the batch date: one weekly batch per date, so a test needing
 * two batches has to place them on different days.
 */
function reconcileFixture(string $adn, int $daysAgo = 0): array
{
    $batch = PayoutBatch::create([
        'batch_type' => PayoutBatch::TYPE_WEEKLY,
        'batch_date' => now()->subDays($daysAgo)->toDateString(),
        'status' => PayoutBatch::STATUS_APPROVED,
        'approved_at' => now(),
    ]);

    $distributor = Distributor::factory()->create(['adn' => $adn]);

    $line = PayoutLineItem::create([
        'payout_batch_id' => $batch->id,
        'distributor_id' => $distributor->id,
        'wallet_balance_paise' => 100_000,
        'gross_paise' => 100_000,
        'repurchase_deduction_paise' => 0,
        'admin_charge_paise' => 0,
        'tds_paise' => 0,
        'net_transferred_paise' => 100_000,
        'status' => PayoutLineItem::STATUS_PENDING,
    ]);

    return [$batch, $line];
}

function uploadCsv(string $contents): UploadedFile
{
    $path = tempnam(sys_get_temp_dir(), 'neft').'.csv';
    file_put_contents($path, $contents);

    return new UploadedFile($path, 'bank-response.csv', 'text/csv', null, true);
}

/** A dispatched line item awaiting its payout webhook. */
function dispatchedFixture(string $payoutId): array
{
    $batch = PayoutBatch::create([
        'batch_type' => PayoutBatch::TYPE_WEEKLY,
        'batch_date' => now()->toDateString(),
        'status' => PayoutBatch::STATUS_DISPATCHED,
        'approved_at' => now(),
    ]);

    $line = PayoutLineItem::create([
        'payout_batch_id' => $batch->id,
        'distributor_id' => Distributor::factory()->create()->id,
        'wallet_balance_paise' => 100_000,
        'gross_paise' => 100_000,
        'repurchase_deduction_paise' => 0,
        'admin_charge_paise' => 0,
        'tds_paise' => 0,
        'net_transferred_paise' => 100_000,
        'status' => PayoutLineItem::STATUS_PENDING,
        'razorpay_payout_id' => $payoutId,
        'dispatched_at' => now(),
    ]);

    return [$batch, $line];
}

/**
 * Fake the local `catalog` disk. Storage::fake() drops the disk's `url`, so
 * pass it back — url() then yields the real …/storage/catalog/<key> shape.
 */
function fakeCatalogDisk(): void
{
    Storage::fake('catalog', ['url' => config('filesystems.disks.catalog.url')]);
}

/*
| Distributor UI changes (2026-09-28) — shared fixtures. Prefixed `ui` so they
| never collide with per-file helpers.
*/
function uiDistributor(array $attrs = []): array
{
    $user = \App\Modules\Identity\Models\User::create([
        'full_name' => $attrs['full_name'] ?? 'Ui Tester',
        'email' => 'ui-'.uniqid().'@example.com',
        'phone_e164' => '+91944'.str_pad((string) random_int(0, 9999999), 7, '0', STR_PAD_LEFT),
        'password_hash' => \Illuminate\Support\Facades\Hash::make('ui-test-pwd-2026'),
        'password_set_at' => now(),
        'status' => 'active',
        'email_verified_at' => now(),
    ]);

    disableTestForeignKeys();
    try {
        $now = now()->format('Y-m-d H:i:s.v');
        $id = \Illuminate\Support\Facades\DB::table('distributors')->insertGetId([
            'user_id' => $user->id,
            'adn' => (string) random_int(100000000, 999999999),
            'pan_hash' => random_bytes(32),
            'pan_last4' => '0000',
            'bank_account_enc' => 'stub',
            'bank_ifsc' => 'SBIN0000000',
            'sponsor_id' => 0,
            'placement_parent_id' => 0,
            'placement_side' => null,
            'side_chosen_by' => 'referral_default',
            'depth' => 0,
            'effective_date' => $now,
            'cooling_off_end_at' => now()->addDays(30)->format('Y-m-d H:i:s.v'),
            'state' => 'TS',
            'is_primary_couple' => 0,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        \Illuminate\Support\Facades\DB::table('distributors')->where('id', $id)
            ->update(['sponsor_id' => $id, 'placement_parent_id' => $id]);
    } finally {
        enableTestForeignKeys();
    }
    \Illuminate\Support\Facades\DB::table('genealogy_closure')
        ->insert(['ancestor_id' => $id, 'descendant_id' => $id, 'depth' => 0]);

    return ['user' => $user, 'id' => $id];
}

function uiPlaceUnder(int $parentId, string $side, int $childId): void
{
    \Illuminate\Support\Facades\DB::table('distributors')->where('id', $childId)
        ->update(['placement_parent_id' => $parentId, 'placement_side' => $side, 'sponsor_id' => $parentId]);

    $rows = \Illuminate\Support\Facades\DB::table('genealogy_closure')
        ->where('descendant_id', $parentId)->get(['ancestor_id', 'depth']);
    foreach ($rows as $row) {
        \Illuminate\Support\Facades\DB::table('genealogy_closure')->insert([
            'ancestor_id' => $row->ancestor_id, 'descendant_id' => $childId, 'depth' => $row->depth + 1,
        ]);
    }
}

function uiPaidSelfOrder(int $distributorId, int $bvPaise, \Illuminate\Support\Carbon $paidAt, string $status = 'paid'): int
{
    disableTestForeignKeys();
    try {
        $orderId = \Illuminate\Support\Facades\DB::table('orders')->insertGetId([
            'order_no' => 'UI'.random_int(10000000, 99999999),
            'idempotency_key' => (string) \Illuminate\Support\Str::uuid(),
            'customer_id' => 0,
            'attributed_distributor_id' => $distributorId,
            'attribution_source' => 'logged_in',
            'payment_method' => 'online',
            'status' => $status,
            'self_consumption' => true,
            'subtotal_paise' => 100000,
            'total_paise' => 100000,
            'placed_at' => $paidAt,
            'paid_at' => $paidAt,
            'created_at' => $paidAt,
            'updated_at' => $paidAt,
        ]);
    } finally {
        enableTestForeignKeys();
    }
    \Illuminate\Support\Facades\DB::table('bv_ledger_entries')->insert([
        'distributor_id' => $distributorId,
        'order_id' => $orderId,
        'type' => 'accrual',
        'bv_paise' => $bvPaise,
        'effective_at' => $paidAt,
        'created_at' => $paidAt,
        'updated_at' => $paidAt,
    ]);

    return $orderId;
}

/** Put $paise into a distributor's repurchase wallet (balance = deductions − usages). */
function uiRepurchaseWallet(int $distributorId, int $paise): void
{
    \App\Modules\Compensation\Models\WalletLedgerEntry::create([
        'distributor_id' => $distributorId,
        'type' => 'repurchase_deduction',
        'amount_paise' => $paise,
        'reference_id' => walletRef(),
        'reference_type' => 'test',
        'memo' => 'UI test repurchase credit',
    ]);
}

/** A Customer row owned by $user, so CheckoutController::ownsOrder() is true for orders pointing at it. */
function uiCustomerFor(\App\Modules\Identity\Models\User $user, int $distributorId): int
{
    return \App\Modules\Commerce\Models\Customer::create([
        'user_id' => $user->id,
        'distributor_id' => $distributorId,
        'display_name' => $user->full_name,
    ])->id;
}
