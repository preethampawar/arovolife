<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Give every still-open cycle the full 30-day window the current rule
     * grants it.
     *
     * The pre-2026-09-06 engine dated a window `cycle_start_date + cycle_days
     * − 1`; `RepurchaseCycleService::openCycle()` now dates it
     * `cycle_start_date + cycle_days`, and the client's examples (7 Jul → 6
     * Aug) pin the new arithmetic. Nothing re-dated the windows that were
     * already open when that shipped, so those distributors are judged a day
     * early and their next window opens a day early — on a rule that was
     * changed precisely because it was wrong.
     *
     * Closed windows are left exactly as they were: their verdict is already
     * frozen, and moving the goalposts of a window someone has already been
     * judged against is worse than the off-by-one. `resolved_at IS NULL` is
     * the test — an unresolved window has not been judged yet, whether or not
     * its due date has passed.
     *
     * Idempotent: it only moves a row whose due date is exactly one day short
     * of the current rule, which after one run no row is. Row-by-row in PHP
     * rather than a date expression, because MySQL and SQLite spell date
     * arithmetic differently and the affected set is tiny.
     */
    private const CYCLE_DAYS = 30;

    public function up(): void
    {
        DB::table('repurchase_cycles')
            ->whereNull('resolved_at')
            ->select('id', 'cycle_start_date', 'due_date')
            ->orderBy('id')
            ->chunkById(500, function ($cycles): void {
                foreach ($cycles as $cycle) {
                    $start = Carbon::parse((string) $cycle->cycle_start_date)->startOfDay();
                    $due = Carbon::parse((string) $cycle->due_date)->startOfDay();

                    $short = $start->copy()->addDays(self::CYCLE_DAYS - 1);

                    if (! $due->isSameDay($short)) {
                        continue;
                    }

                    DB::table('repurchase_cycles')
                        ->where('id', $cycle->id)
                        ->update([
                            'due_date' => $start->copy()->addDays(self::CYCLE_DAYS)->toDateString(),
                            'updated_at' => Carbon::now(),
                        ]);
                }
            });
    }

    public function down(): void
    {
        // Forward-only: the old due date was the defect, and an open window
        // re-dated back would re-introduce it.
    }
};
