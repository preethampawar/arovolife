<?php

declare(strict_types=1);
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Removes the orphaned legacy per-event admin-charge cap setting
     * (`comp.admin_charge.cap_paise` = ₹30,000). It was superseded by the
     * per-cycle weekly/monthly ₹25,000 ceilings (KP 2026-06-30 Round-5); the
     * deduction helper that read it and its registry entry have been deleted,
     * so the row is dead config. Removing it stops it showing in the admin
     * advanced-settings table and prevents any accidental reintroduction.
     */
    public function up(): void
    {
        DB::table('settings')->where('key', 'comp.admin_charge.cap_paise')->delete();
    }

    public function down(): void
    {
        DB::table('settings')->updateOrInsert(
            ['key' => 'comp.admin_charge.cap_paise'],
            ['value' => '3000000', 'version' => 1, 'updated_at' => now(), 'created_at' => now()],
        );
    }
};
