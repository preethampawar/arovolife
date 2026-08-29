<?php

declare(strict_types=1);

namespace Tests\Feature\Compliance;

use App\Modules\Compensation\Services\CompensationPlanSettingsService;
use App\Modules\Compensation\Services\WalletService;
use App\Modules\Identity\Models\Distributor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Hard rule 2 — no credit, bonus, pool entry or reward without a product sale.
 * DSR 2021 Rule 5(1)(c).
 *
 * **This test asserted nothing for five months.** It was written in Phase 2
 * against a `commissions` table that Phase 4 was expected to add. Phase 4
 * shipped a different model — `wallet_ledger_entries` plus per-engine result
 * tables — so the table never appeared, the `markTestSkipped` at the top fired
 * on every run, and the single automated guard for the most important rule on
 * the platform sat green and empty. The T-6.2 sign-off found it.
 *
 * It now tests the model that exists. Three layers, because no one of them is
 * sufficient:
 *
 *  1. **The anchor.** All bonus money is a function of BV, and every BV row
 *     carries a non-nullable `order_id` foreign key to `orders`. That is what
 *     makes "only from product sales" true structurally rather than by
 *     convention.
 *  2. **The registry.** Every credit type in the wallet enum must be
 *     classified, in this file, with the sale it traces to. A new type is a
 *     failing test until somebody writes down where its money comes from —
 *     which is the point at which a recruitment-derived payout would have to
 *     be argued for out loud instead of merged quietly.
 *  3. **The behaviour.** Engine credits carry a reference to the result row
 *     they came from, so an auditor can walk credit → result → BV → order.
 */
final class CommissionHasProductSaleTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Every wallet credit type, and the product sale it traces back to.
     *
     * Adding a type to the enum without adding it here fails HR2-02. That is
     * deliberate: the failure is the review.
     *
     * @var array<string, string>
     */
    private const CREDIT_TRACE = [
        'gsb_credit' => 'gsb_cutoff_results → group BV → bv_ledger_entries.order_id',
        'mb_credit' => 'msb_daily_results → the day\'s BV pool → bv_ledger_entries.order_id',
        'gbb_credit' => 'gbb_monthly_results → the month\'s BV pool → bv_ledger_entries.order_id',
        'rank_credit' => 'rank_bonus_results → rank qualification, itself a BV threshold',
        'fortune_credit' => 'fortune_bonus_results → the month\'s BV pool',
        'adc_credit' => 'adc_monthly_results → centre BV',
        'awards_credit' => 'lifetime_award_milestones → lifetime BV thresholds',
        'franchise_credit' => 'franchise_commission_result_orders.order_id → orders (a delivered order)',
    ];

    /**
     * Non-credit types. They move money out or reverse it; they never create
     * an entitlement, so hard rule 2 has nothing to say about them.
     *
     * `manual_credit` is the exception that has to be named: it is an admin
     * adjustment, it is NOT sale-derived, and it exists for corrections. It is
     * listed here rather than in CREDIT_TRACE so that nobody reads this file
     * and concludes it has a product-sale story. Its control is the audit log
     * and dual authorisation, not this test.
     *
     * @var array<int, string>
     */
    private const NON_ENTITLEMENT_TYPES = [
        'payout_debit',
        'repurchase_deduction',
        'rank_cap_forfeit',
        'income_cap_forfeit',
        'manual_credit',
        'reversal',
        // A debit, not a credit: CheckoutService writes it with a negative
        // amount_paise when the repurchase wallet pays for an order. It spends
        // an entitlement that some other type already created and traced; it
        // creates none of its own.
        'repurchase_wallet_used',
    ];

    /** HR2-01: BV cannot exist without an order. */
    public function test_hr2_01_every_bv_row_is_anchored_to_an_order(): void
    {
        $this->assertTrue(Schema::hasTable('bv_ledger_entries'));

        $column = collect(Schema::getColumns('bv_ledger_entries'))
            ->firstWhere('name', 'order_id');

        $this->assertNotNull($column, 'bv_ledger_entries.order_id must exist');
        $this->assertFalse(
            $column['nullable'],
            'bv_ledger_entries.order_id MUST be NOT NULL — it is the only thing making '
            .'"commissions are a function of product sales only" structurally true (DSR Rule 5(1)(c)).',
        );

        // And it must actually point at orders, not merely hold a number.
        $foreignKeys = collect(Schema::getForeignKeys('bv_ledger_entries'))
            ->filter(fn (array $fk): bool => in_array('order_id', $fk['columns'], true));

        $this->assertNotEmpty(
            $foreignKeys,
            'bv_ledger_entries.order_id must be a foreign key to orders, or a BV row can '
            .'reference a sale that does not exist.',
        );
        $this->assertSame('orders', $foreignKeys->first()['foreign_table']);
    }

    /** HR2-02: every credit type is classified with the sale it comes from. */
    public function test_hr2_02_every_wallet_credit_type_traces_to_a_product_sale(): void
    {
        // The plan's own grouping is the source of truth, not the database
        // enum: it is what the admin-charge and cap engines read, so a credit
        // type missing from it is already broken, and unlike the enum it reads
        // identically on every driver.
        $declared = array_merge(
            CompensationPlanSettingsService::GROUP_A_TYPES,
            CompensationPlanSettingsService::GROUP_B_TYPES,
            CompensationPlanSettingsService::GROUP_C_TYPES,
            CompensationPlanSettingsService::GROUP_D_TYPES,
        );

        sort($declared);
        $classified = array_keys(self::CREDIT_TRACE);
        sort($classified);

        $this->assertSame(
            $declared,
            $classified,
            "The set of credit types and the set of documented product-sale traces have diverged.\n"
            .'Every type that credits a distributor must trace to a product sale (hard rule 2, '
            .'DSR Rule 5(1)(c)). Add it to CREDIT_TRACE with the chain from the credit back to '
            .'orders — or, if it does not create an entitlement, to NON_ENTITLEMENT_TYPES and out '
            .'of the plan groups.',
        );
    }

    /**
     * HR2-02b: the database agrees with the plan grouping.
     *
     * MySQL only — SQLite stores the widened column as an unconstrained
     * string, so there is nothing to read. Stated rather than skipped
     * silently: this half of the check does not run in the default suite.
     */
    public function test_hr2_02b_the_database_enum_matches_the_plan_grouping(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('The type enum is only constrained on MySQL; HR2-02 covers the plan grouping on every driver.');
        }

        $column = collect(Schema::getColumns('wallet_ledger_entries'))->firstWhere('name', 'type');
        preg_match_all("/'([^']+)'/", (string) ($column['type'] ?? ''), $matches);
        $declared = $matches[1];

        $this->assertNotEmpty($declared, 'Could not read the wallet_ledger_entries type enum.');

        $unclassified = array_diff($declared, array_keys(self::CREDIT_TRACE), self::NON_ENTITLEMENT_TYPES);

        $this->assertSame(
            [],
            array_values($unclassified),
            'Unclassified wallet ledger type(s): '.implode(', ', $unclassified),
        );

        // And the other direction, which is the half that was missing. Every
        // type migration restates the whole enum by hand, so one of them can
        // silently drop a value another added -- exactly what happened to
        // `franchise_credit` between 2026_08_16_130100 and 2026_08_28_200000.
        // Asserting only that declared values are classified cannot see that:
        // a classified value absent from the enum is invisible by construction.
        // The engine writing it then fails with "1265 Data truncated" and
        // credits nothing.
        $undeclared = array_diff(
            array_merge(array_keys(self::CREDIT_TRACE), self::NON_ENTITLEMENT_TYPES),
            $declared,
        );

        $this->assertSame(
            [],
            array_values($undeclared),
            'Classified type(s) missing from the wallet_ledger_entries enum: '.implode(', ', $undeclared),
        );
    }

    /**
     * HR2-03: the wallet writer refuses a sale-derived credit with no reference.
     *
     * Enforced in `WalletService::credit()` rather than only asserted over
     * existing rows: a data check passes trivially on an empty database, and
     * the thing worth preventing is the credit being written in the first
     * place.
     */
    public function test_hr2_03_a_sale_derived_credit_without_a_reference_is_refused(): void
    {
        $wallet = app(WalletService::class);

        foreach (array_keys(self::CREDIT_TRACE) as $type) {
            try {
                $wallet->credit(distributorId: 1, amountPaise: 1000, type: $type);
                $this->fail("Wallet accepted a {$type} with no reference — such a credit cannot be traced to a sale.");
            } catch (\InvalidArgumentException $e) {
                $this->assertStringContainsString('traceable back to the product sale', $e->getMessage());
            }
        }
    }

    /** HR2-03b: an admin correction is exempt, because it has no result row. */
    public function test_hr2_03b_manual_credit_is_exempt_and_says_so(): void
    {
        $this->assertContains('manual_credit', self::NON_ENTITLEMENT_TYPES);

        // It must still be writable — the exemption is the point of the type.
        // The factory self-roots (sponsor_id = 0), so the FK is deferred for
        // the insert exactly as the other suites do it. PRAGMA foreign_keys is
        // a no-op inside SQLite's transaction; defer_foreign_keys is not.
        disableTestForeignKeys();
        $distributor = Distributor::factory()->create();

        $entry = app(WalletService::class)->credit(
            distributorId: (int) $distributor->id,
            amountPaise: 1000,
            type: 'manual_credit',
            memo: 'correction',
        );

        $this->assertSame('manual_credit', $entry->type);
    }

    /** HR2-03c: no orphaned engine credit exists in the data either. */
    public function test_hr2_03c_no_engine_credit_row_lacks_its_reference(): void
    {
        $orphans = DB::table('wallet_ledger_entries')
            ->whereIn('type', array_keys(self::CREDIT_TRACE))
            ->where(function ($query): void {
                $query->whereNull('reference_id')->orWhereNull('reference_type');
            })
            ->count();

        $this->assertSame(0, $orphans, 'An engine credit exists that cannot be tied back to a sale.');
    }

    /** HR2-04: the sale reference itself still exists. */
    public function test_hr2_04_order_items_remain_the_product_sale_reference(): void
    {
        $this->assertTrue(Schema::hasTable('order_items'));
        $this->assertTrue(Schema::hasColumn('order_items', 'order_id'));
        $this->assertTrue(Schema::hasColumn('order_items', 'product_variant_id'));
        $this->assertTrue(Schema::hasColumn('orders', 'paid_at'));
    }
}
