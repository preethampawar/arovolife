<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Registry fields agreed with the client on 2026-08-30
 * (docs/compensation/adc-centre-registry-spec-2026-08-30.md §D).
 *
 * Extends the shipped `arete_centers` table rather than adding a parallel
 * one. The legacy free-text `location` / `district` columns are kept and
 * copied into `address_line_1` / `city` so nothing already entered is lost.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('arete_centers', function (Blueprint $table): void {
            $table->string('centre_type', 20)->default('company')->after('name');
            $table->string('address_line_1', 255)->nullable()->after('location');
            $table->string('address_line_2', 255)->nullable()->after('address_line_1');
            $table->string('landmark', 150)->nullable()->after('address_line_2');
            $table->string('city', 100)->nullable()->after('landmark');
            $table->string('property_type', 20)->nullable()->after('state');
            $table->unsignedInteger('premises_sqft')->nullable()->after('property_type');
            $table->decimal('distance_to_nearest_adc_km', 6, 1)->nullable()->after('premises_sqft');
            $table->time('opening_time')->nullable()->after('distance_to_nearest_adc_km');
            $table->time('closing_time')->nullable()->after('opening_time');
            $table->string('weekly_off', 10)->nullable()->after('closing_time');
            $table->string('contact_person', 150)->nullable()->after('weekly_off');
            $table->string('contact_number', 20)->nullable()->after('contact_person');
            $table->string('alternate_contact_number', 20)->nullable()->after('contact_number');
            $table->timestamp('deactivated_at')->nullable()->after('is_company_default');
            $table->string('deactivation_reason', 500)->nullable()->after('deactivated_at');

            $table->index('state', 'idx_ac_state');
            $table->index('city', 'idx_ac_city');
            $table->index(['status', 'centre_type'], 'idx_ac_status_type');
        });

        // Legacy → registry columns. `district` was the city/district of the
        // centre; `location` was a free-text address.
        DB::table('arete_centers')->whereNull('city')->whereNotNull('district')
            ->update(['city' => DB::raw('district')]);
        DB::table('arete_centers')->whereNull('address_line_1')->whereNotNull('location')
            ->update(['address_line_1' => DB::raw('location')]);
        DB::table('arete_centers')->whereNotNull('assigned_distributor_id')
            ->update(['centre_type' => 'distributor']);
    }

    public function down(): void
    {
        Schema::table('arete_centers', function (Blueprint $table): void {
            $table->dropIndex('idx_ac_state');
            $table->dropIndex('idx_ac_city');
            $table->dropIndex('idx_ac_status_type');
            $table->dropColumn([
                'centre_type', 'address_line_1', 'address_line_2', 'landmark', 'city',
                'property_type', 'premises_sqft', 'distance_to_nearest_adc_km',
                'opening_time', 'closing_time', 'weekly_off', 'contact_person',
                'contact_number', 'alternate_contact_number', 'deactivated_at',
                'deactivation_reason',
            ]);
        });
    }
};
