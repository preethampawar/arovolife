<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Rename the settings key `commerce.self_purchase.earns_pv` →
 * `commerce.self_purchase.earns_bv`. The platform is BV-only; the old key name
 * was the last lingering "pv" reference. The stored value (if any) is carried
 * across so the admin toggle keeps its state.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('settings')
            ->where('key', 'commerce.self_purchase.earns_pv')
            ->update(['key' => 'commerce.self_purchase.earns_bv']);
    }

    public function down(): void
    {
        DB::table('settings')
            ->where('key', 'commerce.self_purchase.earns_bv')
            ->update(['key' => 'commerce.self_purchase.earns_pv']);
    }
};
