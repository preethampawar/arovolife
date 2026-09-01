<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The ADC bonus now runs on BV attributed from orders collected at the center
 * (orders.arete_center_id), not on the BV of its members. Rename the columns
 * to reflect the new source so the report is self-documenting.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('adc_bonus_results', function (Blueprint $table): void {
            $table->renameColumn('member_count', 'order_count');
            $table->renameColumn('total_member_bv_paise', 'total_attributed_bv_paise');
        });
    }

    public function down(): void
    {
        Schema::table('adc_bonus_results', function (Blueprint $table): void {
            $table->renameColumn('order_count', 'member_count');
            $table->renameColumn('total_attributed_bv_paise', 'total_member_bv_paise');
        });
    }
};
