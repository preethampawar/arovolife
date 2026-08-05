<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

return new class extends Migration
{
    public function up(): void
    {
        // The "1+2 rule" is retired in favour of the AO-GO offer (KP
        // 2026-08-05): void every not-yet-paid future-month carry-forward
        // qualification. Historical (already-paid or past-month) rows stay for
        // audit; the seeder sets carry_forward_months = 0 so no new rows form.
        $count = DB::table('rank_qualifications')
            ->where('is_carry_forward', true)
            ->where('status', 'qualified')
            ->where('month_start', '>=', now()->startOfMonth()->toDateString())
            ->update(['status' => 'voided', 'updated_at' => now()]);

        if ($count > 0) {
            Log::info('rank.carry_forward.retired', ['voided_rows' => $count]);
        }
    }

    public function down(): void
    {
        // Irreversible data migration — voided carry-forwards are not restored.
    }
};
