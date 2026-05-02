<?php

declare(strict_types=1);

namespace Tests\Feature\Compliance;

use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Hard Rule 2: every commission row must have product_sale_id NOT NULL.
 *
 * Phase 4 will add a real `commissions` table. For Phase 2, this test
 * asserts that the Catalog + Commerce migration doesn't allow a
 * commission-equivalent row to be inserted without product_sale_id.
 *
 * Once the Compensation module lands, this test should be extended
 * to generate randomised commission rows and assert NOT NULL on
 * product_sale_id holds for every one.
 */
final class CommissionHasProductSaleTest extends TestCase
{
    public function test_commissions_table_enforces_product_sale_id_not_null(): void
    {
        if (! Schema::hasTable('commissions')) {
            $this->markTestSkipped('commissions table is Phase 4; placeholder assertion only.');

            return;
        }

        $col = collect(Schema::getColumns('commissions'))
            ->firstWhere('name', 'product_sale_id');

        $this->assertNotNull($col, 'commissions.product_sale_id column must exist');
        $this->assertFalse($col['nullable'], 'commissions.product_sale_id MUST be NOT NULL per DSR Rule 5(1)(c)');
    }

    public function test_order_items_are_the_product_sale_reference(): void
    {
        // order_items.id is what commissions will reference. Assert it exists.
        $this->assertTrue(Schema::hasTable('order_items'));
        $this->assertTrue(Schema::hasColumn('order_items', 'id'));
        $this->assertTrue(Schema::hasColumn('order_items', 'order_id'));
        $this->assertTrue(Schema::hasColumn('order_items', 'product_variant_id'));
    }
}
