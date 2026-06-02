<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Purge PV from the commerce line-item snapshots. The platform is BV-only;
 * `pv_paise` was a dead duplicate of `bv_paise`.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Idempotent: only drop where still present (self-heals a re-run).
        if (Schema::hasColumn('cart_items', 'pv_paise')) {
            Schema::table('cart_items', function (Blueprint $table): void {
                $table->dropColumn('pv_paise');
            });
        }
        if (Schema::hasColumn('order_items', 'pv_paise')) {
            Schema::table('order_items', function (Blueprint $table): void {
                $table->dropColumn('pv_paise');
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasColumn('cart_items', 'pv_paise')) {
            Schema::table('cart_items', function (Blueprint $table): void {
                $table->bigInteger('pv_paise')->default(0);
            });
        }
        if (! Schema::hasColumn('order_items', 'pv_paise')) {
            Schema::table('order_items', function (Blueprint $table): void {
                $table->bigInteger('pv_paise')->default(0);
            });
        }
    }
};
