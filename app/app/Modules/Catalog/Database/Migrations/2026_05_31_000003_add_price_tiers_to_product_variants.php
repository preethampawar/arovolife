<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_variants', function (Blueprint $table): void {
            // Karonix-style pricing tiers, all in paise (integer money).
            //   cost_paise          — already exists (what the company paid)
            //   landing_price_paise — landed cost incl. freight/duties
            //   distributor_price_paise — price a distributor pays
            //   sale_price_paise / mrp_paise — already exist (customer-facing)
            // BV remains admin-set per variant (no formula — user decision).
            // Guards make this idempotent so a partially-migrated DB self-heals.
            if (! Schema::hasColumn('product_variants', 'landing_price_paise')) {
                $table->bigInteger('landing_price_paise')->default(0)->after('cost_paise');
            }
            if (! Schema::hasColumn('product_variants', 'distributor_price_paise')) {
                $table->bigInteger('distributor_price_paise')->default(0)->after('landing_price_paise');
            }
        });
    }

    public function down(): void
    {
        Schema::table('product_variants', function (Blueprint $table): void {
            $table->dropColumn(['landing_price_paise', 'distributor_price_paise']);
        });
    }
};
