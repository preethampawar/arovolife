<?php

declare(strict_types=1);

namespace Tests\Feature\Compliance;

use App\Modules\Commerce\Models\Customer;
use App\Modules\Commerce\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Hard Rule 1: free joining. The registration flow must NEVER
 * produce an order or a commission row.
 *
 * This is a structural assertion: the Identity module controllers
 * must not reference Commerce classes at all.
 */
final class RegistrationHasZeroOrdersTest extends TestCase
{
    use RefreshDatabase;

    public function test_registration_namespace_has_no_commerce_imports(): void
    {
        $regControllers = glob(base_path('app/Modules/Identity/Http/Controllers/Registration/*.php'));
        $this->assertNotEmpty($regControllers, 'Registration controllers must exist.');

        foreach ($regControllers as $path) {
            $src = (string) file_get_contents($path);
            $this->assertStringNotContainsString(
                'App\\Modules\\Commerce',
                $src,
                basename($path).' must not import from Commerce.',
            );
        }
    }

    public function test_no_orders_or_commerce_customers_exist_after_fresh_migration(): void
    {
        // After `RefreshDatabase`, before any explicit seeding beyond framework
        $this->assertSame(0, Order::count());
        $this->assertSame(0, Customer::count());
    }
}
