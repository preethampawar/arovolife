<?php

declare(strict_types=1);

namespace App\Modules\Compensation\Support;

use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Shared `--month=YYYY-MM` parsing for the monthly compensation commands.
 *
 * Every one of them means the same thing by the option and defaults to the
 * same month, so they must agree on how it is read: the close and the payout
 * close are chained together, and a command that resolved "no --month" to a
 * different month than its neighbours would credit one month and pay another.
 *
 * For use by {@see Command} subclasses only — it calls `option()` and
 * `error()`.
 *
 * @mixin Command
 */
trait ResolvesMonthOption
{
    /**
     * The month named by `--month`, or the calendar month that has just ended.
     * Null (with the error already printed) when the option is malformed.
     */
    private function resolveMonth(): ?Carbon
    {
        $raw = trim((string) ($this->option('month') ?? ''));

        if ($raw === '') {
            return Carbon::now('Asia/Kolkata')->startOfMonth()->subMonthNoOverflow()->startOfDay();
        }

        if (preg_match('/^\d{4}-\d{2}$/', $raw) !== 1) {
            $this->error("--month must be in YYYY-MM format, got: {$raw}");

            return null;
        }

        return Carbon::createFromFormat('Y-m-d', $raw.'-01')->startOfDay();
    }
}
